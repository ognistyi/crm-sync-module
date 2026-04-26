<?php

declare(strict_types=1);

namespace ognistyi\sync\components;

/**
 * Shared loop-prevention flag. Set to true while Importer/AckProcessor write to local models,
 * so that SyncBehavior doesn't bounce changes back to the queue.
 */
final class SyncContext
{
    public static bool $isSyncing = false;

    private function __construct()
    {
    }
}
