<?php

declare(strict_types=1);

namespace ognistyi\sync\handlers;

use ognistyi\sync\contracts\SyncHandlerInterface;
use yii\db\ActiveRecord;

abstract class AbstractSyncHandler implements SyncHandlerInterface
{
    /** Local AR class. Subclass must set. */
    protected string $modelClass;

    /** Entity name used in sync messages. Subclass must set. */
    protected string $entityName;

    /**
     * Field names that round-trip 1:1 between local model and message data.
     *
     * @var string[]
     */
    protected array $coreFields = [];

    /**
     * Map of message-key => local-column for fields whose names differ.
     * Direction is bi-directional: applied symmetrically in fieldsOut and mapIncoming.
     *
     * @var array<string, string>
     */
    protected array $aliases = [];

    /**
     * If true, mapIncoming captures any non-core key into model->extra_data,
     * and fieldsOut emits the local extra_data column.
     */
    protected bool $extraDataCatchAll = false;

    public function getModelClass(): string
    {
        return $this->modelClass;
    }

    public function getEntityName(): string
    {
        return $this->entityName;
    }

    public function fieldsOut(ActiveRecord $model): array
    {
        $out = [];
        foreach ($this->coreFields as $messageKey) {
            $local = $this->aliases[$messageKey] ?? $messageKey;
            $out[$messageKey] = $model->getAttribute($local);
        }
        if ($this->extraDataCatchAll && $model->hasAttribute('extra_data')) {
            $out['extra_data'] = $model->getAttribute('extra_data');
        }
        return $out;
    }

    public function mapIncoming(array $data): array
    {
        $attrs = [];
        $extra = [];

        foreach ($data as $key => $value) {
            if (in_array($key, $this->coreFields, true)) {
                $local = $this->aliases[$key] ?? $key;
                $attrs[$local] = $value;
            } elseif ($key === 'extra_data') {
                $extra = array_merge($extra, (array) $value);
            } elseif ($this->extraDataCatchAll) {
                $extra[$key] = $value;
            }
        }

        if ($this->extraDataCatchAll && $extra !== []) {
            $attrs['extra_data'] = $extra;
        }

        return $attrs;
    }

    public function find(string $crmId): ?ActiveRecord
    {
        /** @var class-string<ActiveRecord> $cls */
        $cls = $this->modelClass;
        return $cls::findOne(['crm_id' => $crmId]);
    }

    public function findOrCreate(string $crmId): ActiveRecord
    {
        $model = $this->find($crmId);
        if ($model !== null) {
            return $model;
        }
        $model = $this->makeNew();
        $model->setAttribute('crm_id', $crmId);
        return $model;
    }

    protected function makeNew(): ActiveRecord
    {
        $cls = $this->modelClass;
        return new $cls();
    }
}
