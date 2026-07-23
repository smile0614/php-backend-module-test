<?php

declare(strict_types=1);

namespace Shared\Deletion\Tests\Support;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

final class MutableClock implements ClockInterface
{
    private DateTimeImmutable $now;

    public function __construct(string $start = '2026-01-01 00:00:00.000000')
    {
        $this->now = new DateTimeImmutable($start);
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function advanceMs(int $milliseconds): void
    {
        $this->now = $this->now->modify(sprintf('+%d milliseconds', $milliseconds));
    }
}
