<?php

declare(strict_types=1);

namespace ognistyi\sync\console;

use ognistyi\sync\components\AckProcessor;
use ognistyi\sync\components\Action;
use ognistyi\sync\components\Exporter;
use ognistyi\sync\components\Importer;
use ognistyi\sync\contracts\RbacSyncHandlerInterface;
use ognistyi\sync\SyncModule;
use Ramsey\Uuid\Uuid;
use yii\base\InvalidConfigException;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * Console commands for the sync module.
 *
 * Usage:
 *   ./yii sync/listen                  daemon — consume inbox queue
 *   ./yii sync/all                     ship every record where sync_at IS NULL
 *   ./yii sync/entity companies        ship one entity
 *   ./yii sync/rbac                    snapshot roles + permissions
 */
class SyncController extends Controller
{
    public $defaultAction = 'listen';

    /** Override default batch size for bulk syncs. */
    public int $batchSize = 0;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['batchSize']);
    }

    public function actionListen(): int
    {
        $module = $this->module();
        $transport = $module->getTransport();
        $importer = new Importer($module);
        $ackProcessor = new AckProcessor($module);

        Console::output("Listening on queue: {$module->inboxQueue}");

        $transport->consume($module->inboxQueue, function (array $message) use ($module, $importer, $ackProcessor, $transport): void {
            $action = (string) ($message['action'] ?? '');
            $entity = (string) ($message['entity'] ?? '');

            if ($action === Action::ACK) {
                $ackProcessor->process($message);
                Console::output("ACK processed: {$entity}");
                return;
            }

            $results = $importer->import($message);

            $ack = [
                'message_id' => Uuid::uuid4()->toString(),
                'source' => $module->source,
                'timestamp' => time(),
                'entity' => $entity,
                'action' => Action::ACK,
                'batch' => $results,
            ];
            $transport->publish($module->outboxQueue, $ack);

            $ok = count(array_filter($results, static fn(array $r): bool => $r['status'] === 'ok'));
            $err = count($results) - $ok;
            Console::output("{$entity}: {$ok} ok, {$err} errors");

            foreach ($results as $result) {
                if ($result['status'] !== 'ok') {
                    Console::output("  ERR [{$result['crm_id']}]: " . ($result['error'] ?? 'unknown'));
                }
            }
        });

        return ExitCode::OK;
    }

    public function actionAll(): int
    {
        $module = $this->module();
        $exporter = $module->getExporter();
        $batchSize = $this->batchSize > 0 ? $this->batchSize : $module->batchSize;

        foreach ($module->getEntityNames() as $entity) {
            $this->syncEntity($module, $exporter, $entity, $batchSize);
        }

        if ($module->rbacHandler !== null) {
            $this->syncRbac($module, $exporter);
        }

        return ExitCode::OK;
    }

    public function actionEntity(string $name): int
    {
        $module = $this->module();
        $exporter = $module->getExporter();
        $batchSize = $this->batchSize > 0 ? $this->batchSize : $module->batchSize;

        $this->syncEntity($module, $exporter, $name, $batchSize);

        return ExitCode::OK;
    }

    public function actionRbac(): int
    {
        $module = $this->module();
        $this->syncRbac($module, $module->getExporter());
        return ExitCode::OK;
    }

    private function syncEntity(SyncModule $module, Exporter $exporter, string $entity, int $batchSize): void
    {
        $handler = $module->getHandler($entity);
        $modelClass = $handler->getModelClass();

        Console::output("Syncing entity: {$entity}");

        $query = $modelClass::find()->where(['sync_at' => null]);
        $total = (int) $query->count();
        $sent = 0;

        foreach ($query->batch($batchSize) as $models) {
            $exporter->exportBatch($handler, $models, Action::UPSERT);
            $sent += count($models);
            Console::output("  Sent {$sent}/{$total}");
        }

        Console::output("  Done: {$entity} ({$sent} records)");
    }

    private function syncRbac(SyncModule $module, Exporter $exporter): void
    {
        /** @var RbacSyncHandlerInterface $handler */
        $handler = $module->getRbacHandler();
        $items = $handler->exportAll();
        Console::output('Syncing RBAC: ' . count($items) . ' items');

        $module->getTransport()->publish($module->outboxQueue, [
            'message_id' => Uuid::uuid4()->toString(),
            'source' => $module->source,
            'timestamp' => time(),
            'entity' => $handler->getEntityName(),
            'action' => Action::UPSERT,
            'batch' => $items,
        ]);

        Console::output('  Done: RBAC');
    }

    private function module(): SyncModule
    {
        if (!$this->module instanceof SyncModule) {
            throw new InvalidConfigException(
                'SyncController must be loaded via SyncModule. Register it under "modules", not "controllerMap".'
            );
        }

        return $this->module;
    }
}
