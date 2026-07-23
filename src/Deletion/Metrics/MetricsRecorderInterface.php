<?php

declare(strict_types=1);

namespace Shared\Deletion\Metrics;

interface MetricsRecorderInterface
{
    /**
     * @param array<string, string|int> $tags
     */
    public function increment(string $metric, int $value = 1, array $tags = []): void;

    /**
     * @param array<string, string|int> $tags
     */
    public function timing(string $metric, float $milliseconds, array $tags = []): void;

    /**
     * @param array<string, string|int> $tags
     */
    public function distribution(string $metric, float $value, array $tags = []): void;
}
