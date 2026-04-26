<?php

declare(strict_types=1);

namespace ognistyi\sync\contracts;

use yii\db\ActiveRecord;

interface SyncHandlerInterface
{
    public function getModelClass(): string;

    public function getEntityName(): string;

    /**
     * Extract message-shaped fields from a local model for outbound sync.
     *
     * @return array<string, mixed>
     */
    public function fieldsOut(ActiveRecord $model): array;

    /**
     * Translate incoming message data into local-model attributes.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function mapIncoming(array $data): array;

    /**
     * Find an existing record by crm_id, or null if absent.
     */
    public function find(string $crmId): ?ActiveRecord;

    /**
     * Find existing record by crm_id or instantiate a new one with that id.
     */
    public function findOrCreate(string $crmId): ActiveRecord;
}
