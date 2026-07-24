<?php

declare(strict_types=1);

namespace LogHQ\Laravel\Tests;

use LogHQ\Config;
use LogHQ\Transport\Transport;

/** Captures outgoing batches instead of sending them. */
final class MockTransport implements Transport
{
    /** @var list<list<array<string, mixed>>> */
    public array $batches = [];

    public function send(array $entries, Config $config): ?int
    {
        $this->batches[] = $entries;

        return 201;
    }

    /** Every entry across every batch, flattened. @return list<array<string, mixed>> */
    public function entries(): array
    {
        return $this->batches === [] ? [] : array_merge(...$this->batches);
    }

    /** @return array<string, mixed> */
    public function last(): array
    {
        $entries = $this->entries();

        return $entries[\count($entries) - 1] ?? [];
    }
}
