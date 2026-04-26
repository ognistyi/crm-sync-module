<?php

declare(strict_types=1);

namespace ognistyi\sync\tests\Unit\behaviors;

use ognistyi\sync\behaviors\SyncBehavior;
use ognistyi\sync\components\Action;
use ognistyi\sync\components\Exporter;
use ognistyi\sync\components\SyncContext;
use ognistyi\sync\contracts\SyncHandlerInterface;
use PHPUnit\Framework\TestCase;
use yii\db\ActiveRecord;

class SyncBehaviorTest extends TestCase
{
    private Exporter $exporter;
    private SyncHandlerInterface $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->exporter = $this->createMock(Exporter::class);
        $this->handler = $this->createMock(SyncHandlerInterface::class);
        SyncContext::$isSyncing = false;
    }

    protected function tearDown(): void
    {
        SyncContext::$isSyncing = false;
        parent::tearDown();
    }

    public function testAfterInsertExportsUpsert(): void
    {
        $model = $this->createMock(ActiveRecord::class);
        $this->exporter->expects(self::once())
            ->method('export')
            ->with($this->handler, $model, Action::UPSERT);

        $this->makeBehavior($model)->afterInsert();
    }

    public function testAfterUpdateResetsSyncAtAndExportsUpsert(): void
    {
        $model = $this->createMock(ActiveRecord::class);
        $model->method('hasAttribute')->with('sync_at')->willReturn(true);
        $model->expects(self::once())
            ->method('updateAttributes')
            ->with(['sync_at' => null]);

        $this->exporter->expects(self::once())
            ->method('export')
            ->with($this->handler, $model, Action::UPSERT);

        $this->makeBehavior($model)->afterUpdate();
    }

    public function testAfterDeleteExportsDelete(): void
    {
        $model = $this->createMock(ActiveRecord::class);
        $this->exporter->expects(self::once())
            ->method('export')
            ->with($this->handler, $model, Action::DELETE);

        $this->makeBehavior($model)->afterDelete();
    }

    public function testIsSyncingFlagPreventsAllExports(): void
    {
        SyncContext::$isSyncing = true;
        $model = $this->createMock(ActiveRecord::class);
        $model->expects(self::never())->method('updateAttributes');
        $this->exporter->expects(self::never())->method('export');

        $behavior = $this->makeBehavior($model);
        $behavior->afterInsert();
        $behavior->afterUpdate();
        $behavior->afterDelete();
    }

    public function testNoExporterAvailableIsNoOp(): void
    {
        $model = $this->createMock(ActiveRecord::class);
        $behavior = new SyncBehavior();
        $behavior->handler = 'unused';
        $behavior->exporter = null;
        $behavior->owner = $model;

        $this->expectNotToPerformAssertions();
        // Yii::$app is null in this test, so resolveExporter returns null.
        $behavior->afterInsert();
    }

    private function makeBehavior(ActiveRecord $owner): SyncBehavior
    {
        $behavior = new SyncBehavior();
        $behavior->handler = 'unused';
        $behavior->exporter = $this->exporter;
        $behavior->resolvedHandler = $this->handler;
        $behavior->owner = $owner;
        return $behavior;
    }
}
