<?php

declare(strict_types=1);

namespace BeaconBox\Tests\Support;

use Psr\Log\AbstractLogger;

/**
 * A PSR-3 logger that keeps everything it was handed.
 *
 * Real, not a mock: the point of these tests is what the SDK *passes* to a logger, so a double
 * that asserts on call counts would prove nothing about the message or the context.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    /**
     * @param mixed                $level
     * @param string|\Stringable   $message
     * @param array<string, mixed> $context
     */
    public function log($level, $message, array $context = []): void
    {
        $this->records[] = [
            'level' => (string) $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }

    /** @return list<string> */
    public function levels(): array
    {
        return array_map(static fn (array $r): string => $r['level'], $this->records);
    }

    /** @return list<string> */
    public function messages(): array
    {
        return array_map(static fn (array $r): string => $r['message'], $this->records);
    }

    /** @return list<string> */
    public function messagesAt(string $level): array
    {
        return array_values(array_map(
            static fn (array $r): string => $r['message'],
            array_filter($this->records, static fn (array $r): bool => $r['level'] === $level),
        ));
    }

    /** @return list<array<string, mixed>> */
    public function contextAt(string $level): array
    {
        return array_values(array_map(
            static fn (array $r): array => $r['context'],
            array_filter($this->records, static fn (array $r): bool => $r['level'] === $level),
        ));
    }

    /** Message text plus every context value, because a leak can hide in either. */
    public function everything(): string
    {
        $parts = [];
        foreach ($this->records as $record) {
            $parts[] = $record['message'];
            $parts[] = print_r($record['context'], true);
        }

        return implode(' ', $parts);
    }
}
