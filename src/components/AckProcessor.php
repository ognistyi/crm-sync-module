<?php

declare(strict_types=1);

namespace ognistyi\sync\components;

use ognistyi\sync\contracts\SyncHandlerInterface;
use ognistyi\sync\models\SyncLog;
use ognistyi\sync\SyncModule;
use Throwable;
use yii\base\InvalidConfigException;

/**
 * Processes inbound ACK messages — confirms delivery on the sender side
 * by stamping sync_at on local records that the receiver saved successfully.
 */
class AckProcessor
{
    public function __construct(private readonly SyncModule $module)
    {
    }

    /**
     * @param array<string, mixed> $message
     */
    public function process(array $message): void
    {
        $entity = (string) ($message['entity'] ?? '');
        /** @var list<array<string, mixed>> $batch */
        $batch = $message['batch'] ?? [];

        try {
            $handler = $this->module->getHandler($entity);
        } catch (InvalidConfigException) {
            $this->logEntries($message, $batch);
            return;
        }

        foreach ($batch as $item) {
            $this->processItem($handler, $item, $message);
        }
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed> $message
     */
    private function processItem(SyncHandlerInterface $handler, array $item, array $message): void
    {
        $crmId = (string) ($item['crm_id'] ?? '');
        $status = (string) ($item['status'] ?? '');

        if ($status === Status::OK) {
            try {
                $model = $handler->find($crmId);
                if ($model !== null) {
                    SyncContext::$isSyncing = true;
                    $model->updateAttributes(['sync_at' => time()]);
                    SyncContext::$isSyncing = false;
                }
            } catch (Throwable) {
                SyncContext::$isSyncing = false;
            }
        }

        $this->logEntry($message, $item);
    }

    /**
     * @param array<string, mixed> $message
     * @param list<array<string, mixed>> $batch
     */
    private function logEntries(array $message, array $batch): void
    {
        foreach ($batch as $item) {
            $this->logEntry($message, $item);
        }
    }

    /**
     * @param array<string, mixed> $message
     * @param array<string, mixed> $item
     */
    private function logEntry(array $message, array $item): void
    {
        try {
            $log = new SyncLog();
            $log->message_id = (string) ($message['message_id'] ?? '');
            $log->direction = SyncLog::DIRECTION_IN;
            $log->entity = (string) ($message['entity'] ?? '');
            $log->crm_id = (string) ($item['crm_id'] ?? '');
            $log->action = 'ack';
            $log->status = ($item['status'] ?? '') === Status::OK ? Status::OK : Status::ERROR;
            $log->error = isset($item['error']) ? (string) $item['error'] : null;
            $log->save(false);
        } catch (Throwable) {
            // Logging must never break the ACK flow.
        }
    }
}
