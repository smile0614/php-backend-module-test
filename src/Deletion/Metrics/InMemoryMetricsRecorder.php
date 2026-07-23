<?php

declare(strict_types=1);

namespace Shared\Deletion\Metrics;

final class InMemoryMetricsRecorder implements MetricsRecorderInterface
{
    /** @var list<array{type:string, metric:string, value:float|int, tags:array<string,string|int>}> */
    private array $records = [];

    public function increment(string $metric, int $value = 1, array $tags = []): void
    {
        $this->records[] = ['type' => 'increment', 'metric' => $metric, 'value' => $value, 'tags' => $tags];
    }

    public function timing(string $metric, float $milliseconds, array $tags = []): void
    {
        $this->records[] = ['type' => 'timing', 'metric' => $metric, 'value' => $milliseconds, 'tags' => $tags];
    }

    public function distribution(string $metric, float $value, array $tags = []): void
    {
        $this->records[] = ['type' => 'distribution', 'metric' => $metric, 'value' => $value, 'tags' => $tags];
    }

    /**
     * @return list<array{type:string, metric:string, value:float|int, tags:array<string,string|int>}>
     */
    public function all(): array
    {
        return $this->records;
    }

    /**
     * @return list<array{type:string, metric:string, value:float|int, tags:array<string,string|int>}>
     */
    public function forMetric(string $metric): array
    {
        return array_values(array_filter($this->records, static fn (array $r): bool => $r['metric'] === $metric));
    }

    public function reset(): void
    {
        $this->records = [];
    }
}
