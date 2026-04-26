<?php

declare(strict_types=1);

namespace ognistyi\sync\behaviors;

use ognistyi\sync\components\Action;
use ognistyi\sync\components\Exporter;
use ognistyi\sync\components\SyncContext;
use ognistyi\sync\contracts\SyncHandlerInterface;
use ognistyi\sync\SyncModule;
use Yii;
use yii\base\Behavior;
use yii\db\ActiveRecord;

/**
 * Attaches to a syncable AR model. On afterInsert/Update/Delete it routes
 * the change to Exporter so it propagates to the central inbox.
 *
 * Loop guard: SyncContext::$isSyncing — set by Importer/AckProcessor while
 * they write to local models; this behavior skips export under that flag.
 */
class SyncBehavior extends Behavior
{
    /** Handler FQCN matching this model. Set in behaviors() config. */
    public string $handler;

    /** Injected exporter (e.g. for tests). Resolved from sync module if null. */
    public ?Exporter $exporter = null;

    /** Cached resolved handler instance. */
    public ?SyncHandlerInterface $resolvedHandler = null;

    public function events(): array
    {
        return [
            ActiveRecord::EVENT_AFTER_INSERT => 'afterInsert',
            ActiveRecord::EVENT_AFTER_UPDATE => 'afterUpdate',
            ActiveRecord::EVENT_AFTER_DELETE => 'afterDelete',
        ];
    }

    public function afterInsert(): void
    {
        $this->triggerExport(Action::UPSERT);
    }

    public function afterUpdate(): void
    {
        if (SyncContext::$isSyncing) {
            return;
        }

        /** @var ActiveRecord $owner */
        $owner = $this->owner;
        // Local user-initiated change — invalidate sync_at; AckProcessor will set it again on confirmed delivery.
        // updateAttributes() bypasses afterUpdate events, so no recursion.
        if ($owner->hasAttribute('sync_at')) {
            $owner->updateAttributes(['sync_at' => null]);
        }

        $this->triggerExport(Action::UPSERT);
    }

    public function afterDelete(): void
    {
        $this->triggerExport(Action::DELETE);
    }

    private function triggerExport(string $action): void
    {
        if (SyncContext::$isSyncing) {
            return;
        }

        $exporter = $this->exporter ?? $this->resolveExporter();
        if ($exporter === null) {
            return;
        }

        $handler = $this->resolvedHandler ?? Yii::createObject($this->handler);
        $this->resolvedHandler = $handler;

        /** @var ActiveRecord $owner */
        $owner = $this->owner;
        $exporter->export($handler, $owner, $action);
    }

    private function resolveExporter(): ?Exporter
    {
        $module = Yii::$app?->getModule('sync');
        if (!$module instanceof SyncModule) {
            return null;
        }
        return $module->getExporter();
    }
}
