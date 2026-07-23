<?php

declare(strict_types=1);

namespace Shared\Deletion\Middleware;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Shared\Deletion\Metrics\MetricsRecorderInterface;

final class MetricsDeletionMiddleware implements DeletionMiddlewareInterface
{
    private const METRIC_DETACH_DURATION   = 'deletion.detach.duration_ms';
    private const METRIC_DETACH_RELATIONS  = 'deletion.detach.relations';
    private const METRIC_CHILDREN_DURATION = 'deletion.children.duration_ms';
    private const METRIC_CHILDREN_DELETED  = 'deletion.children.deleted';
    private const METRIC_CHILDREN_BATCH    = 'deletion.children.batch_size';
    private const METRIC_ROOT_DURATION     = 'deletion.root.duration_ms';
    private const METRIC_ROOT_DELETED      = 'deletion.root.deleted';
    private const METRIC_OP_DURATION       = 'deletion.operation.duration_ms';
    private const METRIC_OP_COMPLETED      = 'deletion.operation.completed';

    private ?DateTimeImmutable $operationStartedAt = null;

    /** @var array<string, DateTimeImmutable> */
    private array $pending = [];

    public function __construct(
        private readonly MetricsRecorderInterface $metrics,
        private readonly ClockInterface $clock,
    ) {
    }

    public function supports(string $entityClass): bool
    {
        return true;
    }

    public function beforeDetachRelations(string $parentClass, string $childClass, array $childIds, array $relation, object $root): void
    {
        $this->startOperationIfNeeded();
        $this->startPhase('detach', $childClass);
    }

    public function afterDetachRelations(string $parentClass, string $childClass, array $childIds, array $relation, object $root): void
    {
        $tags = ['parent' => $parentClass, 'child' => $childClass];

        $this->recordPhaseDuration('detach', $childClass, self::METRIC_DETACH_DURATION, $tags);
        $this->metrics->increment(self::METRIC_DETACH_RELATIONS, count($childIds), $tags);
    }

    public function beforeDeleteChildren(string $childClass, array $childIds, object $root): void
    {
        $this->startOperationIfNeeded();
        $this->startPhase('children', $childClass);
    }

    public function afterDeleteChildren(string $childClass, array $childIds, object $root): void
    {
        $tags  = ['child' => $childClass];
        $count = count($childIds);

        $this->recordPhaseDuration('children', $childClass, self::METRIC_CHILDREN_DURATION, $tags);
        $this->metrics->increment(self::METRIC_CHILDREN_DELETED, $count, $tags);
        $this->metrics->distribution(self::METRIC_CHILDREN_BATCH, (float) $count, $tags);
    }

    public function beforeDeleteRoot(object $root): void
    {
        $this->startOperationIfNeeded();
        $this->startPhase('root', $root::class);
    }

    public function afterDeleteRoot(object $root): void
    {
        $rootClass = $root::class;
        $tags      = ['class' => $rootClass];

        $this->recordPhaseDuration('root', $rootClass, self::METRIC_ROOT_DURATION, $tags);
        $this->metrics->increment(self::METRIC_ROOT_DELETED, 1, $tags);

        if ($this->operationStartedAt !== null) {
            $this->metrics->timing(
                self::METRIC_OP_DURATION,
                $this->elapsedMs($this->operationStartedAt, $this->clock->now()),
                $tags,
            );
        }
        $this->metrics->increment(self::METRIC_OP_COMPLETED, 1, $tags);

        $this->operationStartedAt = null;
        $this->pending            = [];
    }

    private function startOperationIfNeeded(): void
    {
        $this->operationStartedAt ??= $this->clock->now();
    }

    private function startPhase(string $phase, string $class): void
    {
        $this->pending[$phase . '#' . $class] = $this->clock->now();
    }

    /**
     * @param array<string, string|int> $tags
     */
    private function recordPhaseDuration(string $phase, string $class, string $metric, array $tags): void
    {
        $key = $phase . '#' . $class;
        $startedAt = $this->pending[$key] ?? null;
        if ($startedAt === null) {
            return;
        }
        unset($this->pending[$key]);

        $this->metrics->timing($metric, $this->elapsedMs($startedAt, $this->clock->now()), $tags);
    }

    private function elapsedMs(DateTimeImmutable $start, DateTimeImmutable $end): float
    {
        return ((float) $end->format('U.u') - (float) $start->format('U.u')) * 1000.0;
    }
}
