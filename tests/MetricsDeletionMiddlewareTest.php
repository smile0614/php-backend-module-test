<?php

declare(strict_types=1);

namespace Shared\Deletion\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shared\Deletion\Metrics\InMemoryMetricsRecorder;
use Shared\Deletion\Middleware\MetricsDeletionMiddleware;
use Shared\Deletion\Tests\Fixture\Order;
use Shared\Deletion\Tests\Support\MutableClock;

#[CoversClass(MetricsDeletionMiddleware::class)]
final class MetricsDeletionMiddlewareTest extends TestCase
{
    private InMemoryMetricsRecorder $metrics;
    private MutableClock $clock;
    private MetricsDeletionMiddleware $middleware;

    protected function setUp(): void
    {
        $this->metrics    = new InMemoryMetricsRecorder();
        $this->clock      = new MutableClock();
        $this->middleware = new MetricsDeletionMiddleware($this->metrics, $this->clock);
    }

    #[Test]
    public function it_supports_all_entities(): void
    {
        self::assertTrue($this->middleware->supports(Order::class));
    }

    #[Test]
    public function it_records_durations_and_counts_across_a_full_operation(): void
    {
        $root = new Order(1);

        $this->middleware->beforeDetachRelations(Order::class, 'App\\Tag', [100, 101], [], $root);
        $this->clock->advanceMs(5);
        $this->middleware->afterDetachRelations(Order::class, 'App\\Tag', [100, 101], [], $root);

        $this->middleware->beforeDeleteChildren('App\\OrderItem', [10, 11, 12], $root);
        $this->clock->advanceMs(12);
        $this->middleware->afterDeleteChildren('App\\OrderItem', [10, 11, 12], $root);

        $this->middleware->beforeDeleteRoot($root);
        $this->clock->advanceMs(4);
        $this->middleware->afterDeleteRoot($root);

        self::assertEqualsWithDelta(5.0, $this->singleValue('deletion.detach.duration_ms'), 0.5);
        self::assertEqualsWithDelta(12.0, $this->singleValue('deletion.children.duration_ms'), 0.5);
        self::assertEqualsWithDelta(4.0, $this->singleValue('deletion.root.duration_ms'), 0.5);

        self::assertEqualsWithDelta(21.0, $this->singleValue('deletion.operation.duration_ms'), 0.5);

        self::assertSame(2, (int) $this->singleValue('deletion.detach.relations'));
        self::assertSame(3, (int) $this->singleValue('deletion.children.deleted'));
        self::assertSame(3.0, $this->singleValue('deletion.children.batch_size'));
        self::assertSame(1, (int) $this->singleValue('deletion.root.deleted'));
        self::assertSame(1, (int) $this->singleValue('deletion.operation.completed'));
    }

    #[Test]
    public function it_tags_phase_metrics_with_entity_classes(): void
    {
        $root = new Order(1);

        $this->middleware->beforeDeleteChildren('App\\OrderItem', [10], $root);
        $this->clock->advanceMs(3);
        $this->middleware->afterDeleteChildren('App\\OrderItem', [10], $root);

        $record = $this->metrics->forMetric('deletion.children.deleted')[0];
        self::assertSame(['child' => 'App\\OrderItem'], $record['tags']);
    }

    #[Test]
    public function it_resets_state_between_operations(): void
    {
        $root = new Order(1);

        $this->middleware->beforeDeleteRoot($root);
        $this->clock->advanceMs(10);
        $this->middleware->afterDeleteRoot($root);

        $this->middleware->beforeDeleteRoot($root);
        $this->clock->advanceMs(3);
        $this->middleware->afterDeleteRoot($root);

        $durations = array_map(
            static fn (array $r): float => (float) $r['value'],
            $this->metrics->forMetric('deletion.operation.duration_ms'),
        );

        self::assertCount(2, $durations);
        self::assertEqualsWithDelta(10.0, $durations[0], 0.5);
        self::assertEqualsWithDelta(3.0, $durations[1], 0.5, 'вторая операция не должна накапливать время первой');
    }

    #[Test]
    public function it_skips_phase_timing_when_the_before_callback_was_missing(): void
    {
        $root = new Order(1);

        $this->middleware->afterDeleteChildren('App\\OrderItem', [10, 11], $root);

        self::assertSame([], $this->metrics->forMetric('deletion.children.duration_ms'));
        self::assertSame(2, (int) $this->singleValue('deletion.children.deleted'));
    }

    private function singleValue(string $metric): float
    {
        $records = $this->metrics->forMetric($metric);
        self::assertCount(1, $records, sprintf('ожидалась ровно одна запись метрики %s', $metric));

        return (float) $records[0]['value'];
    }
}
