<?php

declare(strict_types=1);

namespace ognistyi\sync\components;

use ognistyi\sync\contracts\SyncHandlerInterface;
use ognistyi\sync\SyncModule;
use Throwable;
use yii\base\InvalidConfigException;
use yii\db\ActiveRecord;

class Importer
{
    public function __construct(private readonly SyncModule $module)
    {
    }

    /**
     * Process an incoming sync message and return per-record statuses for ACK.
     *
     * @param array<string, mixed> $message
     * @return list<array{crm_id: string, status: string, error?: string}>
     */
    public function import(array $message): array
    {
        $entity = (string) ($message['entity'] ?? '');
        $action = (string) ($message['action'] ?? '');
        /** @var list<array<string, mixed>> $batch */
        $batch = $message['batch'] ?? [];

        try {
            $handler = $this->module->getHandler($entity);
        } catch (InvalidConfigException $e) {
            return $this->failAll($batch, $e->getMessage());
        }

        $results = [];
        foreach ($batch as $item) {
            $results[] = $this->processItem($handler, $item, $action);
        }

        return $results;
    }

    /**
     * @param array<string, mixed> $item
     * @return array{crm_id: string, status: string, error?: string}
     */
    private function processItem(SyncHandlerInterface $handler, array $item, string $action): array
    {
        $crmId = (string) ($item['crm_id'] ?? '');

        try {
            $model = $handler->findOrCreate($crmId);
            SyncContext::$isSyncing = true;

            if ($action === Action::DELETE) {
                $model->setAttribute('is_archived', 1);
            } else {
                $attrs = $handler->mapIncoming($item['data'] ?? []);
                foreach ($attrs as $name => $value) {
                    $model->setAttribute($name, $value);
                }
            }

            $ok = $model->save();
            SyncContext::$isSyncing = false;

            if (!$ok) {
                $errors = method_exists($model, 'getFirstErrors') ? $model->getFirstErrors() : [];
                return [
                    'crm_id' => $crmId,
                    'status' => Status::ERROR,
                    'error' => implode('; ', array_map('strval', $errors)) ?: 'save() returned false',
                ];
            }

            return ['crm_id' => $crmId, 'status' => Status::OK];
        } catch (Throwable $e) {
            SyncContext::$isSyncing = false;
            return ['crm_id' => $crmId, 'status' => Status::ERROR, 'error' => $e->getMessage()];
        }
    }

    /**
     * @param list<array<string, mixed>> $batch
     * @return list<array{crm_id: string, status: string, error: string}>
     */
    private function failAll(array $batch, string $error): array
    {
        return array_map(
            static fn(array $item): array => [
                'crm_id' => (string) ($item['crm_id'] ?? ''),
                'status' => Status::ERROR,
                'error' => $error,
            ],
            $batch,
        );
    }
}
