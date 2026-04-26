<?php

declare(strict_types=1);

namespace ognistyi\sync\contracts;

interface RbacSyncHandlerInterface
{
    public function getEntityName(): string;

    /**
     * Snapshot every RBAC item (roles + permissions + assignments) for outbound sync.
     *
     * @return array<int, array{crm_id: string, data: array<string, mixed>}>
     */
    public function exportAll(): array;

    /**
     * Apply one RBAC item from incoming sync data.
     *
     * @param array<string, mixed> $data
     */
    public function importItem(array $data): bool;
}
