<?php

declare(strict_types=1);

namespace ognistyi\sync\components;

use ognistyi\sync\contracts\SyncHandlerInterface;
use ognistyi\sync\contracts\SyncTransportInterface;
use Ramsey\Uuid\Uuid;
use yii\db\ActiveRecord;

class Exporter
{
    public function __construct(
        private string $source,
        private string $queue,
        private SyncTransportInterface $transport,
    ) {
    }

    public function export(SyncHandlerInterface $handler, ActiveRecord $model, string $action): void
    {
        $this->exportBatch($handler, [$model], $action);
    }

    /**
     * @param ActiveRecord[] $models
     */
    public function exportBatch(SyncHandlerInterface $handler, array $models, string $action): void
    {
        if ($models === []) {
            return;
        }

        $batch = [];
        foreach ($models as $model) {
            $batch[] = [
                'crm_id' => $model->getAttribute('crm_id'),
                'data' => $action === Action::DELETE ? [] : $handler->fieldsOut($model),
            ];
        }

        $this->transport->publish($this->queue, [
            'message_id' => Uuid::uuid4()->toString(),
            'source' => $this->source,
            'timestamp' => time(),
            'entity' => $handler->getEntityName(),
            'action' => $action,
            'batch' => $batch,
        ]);
    }
}
