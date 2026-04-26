<?php

declare(strict_types=1);

namespace ognistyi\sync\contracts;

interface SyncTransportInterface
{
    /**
     * Publish a message to the named queue. Implementations must be idempotent for connection state.
     *
     * @param array<string, mixed> $message
     */
    public function publish(string $queue, array $message): void;

    /**
     * Consume from a queue. Blocking. Callback receives the decoded message body.
     *
     * @param callable(array<string, mixed>): void $callback
     */
    public function consume(string $queue, callable $callback): void;

    public function disconnect(): void;
}
