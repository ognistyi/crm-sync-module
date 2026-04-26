<?php

declare(strict_types=1);

namespace ognistyi\sync\tests\Unit\components;

use ognistyi\sync\components\Action;
use ognistyi\sync\components\Importer;
use ognistyi\sync\components\Status;
use ognistyi\sync\components\SyncContext;
use ognistyi\sync\contracts\SyncHandlerInterface;
use ognistyi\sync\SyncModule;
use PHPUnit\Framework\TestCase;
use yii\base\InvalidConfigException;
use yii\db\ActiveRecord;

class ImporterTest extends TestCase
{
    private SyncModule $module;
    private SyncHandlerInterface $handler;
    private Importer $importer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->module = $this->createMock(SyncModule::class);
        $this->handler = $this->createMock(SyncHandlerInterface::class);
        $this->module->method('getHandler')->with('companies')->willReturn($this->handler);
        $this->importer = new Importer($this->module);
        SyncContext::$isSyncing = false;
    }

    protected function tearDown(): void
    {
        SyncContext::$isSyncing = false;
        parent::tearDown();
    }

    public function testUpsertSavesModelAndReturnsOk(): void
    {
        $model = $this->createMock(ActiveRecord::class);
        $model->expects(self::once())->method('setAttribute')->with('name', 'Acme');
        $model->method('save')->willReturn(true);

        $this->handler->method('findOrCreate')->with('uuid-1')->willReturn($model);
        $this->handler->method('mapIncoming')->with(['name' => 'Acme'])->willReturn(['name' => 'Acme']);

        $result = $this->importer->import([
            'entity' => 'companies',
            'action' => Action::UPSERT,
            'batch' => [['crm_id' => 'uuid-1', 'data' => ['name' => 'Acme']]],
        ]);

        self::assertSame([['crm_id' => 'uuid-1', 'status' => Status::OK]], $result);
    }

    public function testUpsertValidationFailureReturnsErrorWithFirstErrors(): void
    {
        $model = $this->createMock(ActiveRecord::class);
        $model->method('save')->willReturn(false);
        $model->method('getFirstErrors')->willReturn(['name' => 'Name is required']);

        $this->handler->method('findOrCreate')->willReturn($model);
        $this->handler->method('mapIncoming')->willReturn(['name' => '']);

        $result = $this->importer->import([
            'entity' => 'companies',
            'action' => Action::UPSERT,
            'batch' => [['crm_id' => 'uuid-1', 'data' => ['name' => '']]],
        ]);

        self::assertSame(Status::ERROR, $result[0]['status']);
        self::assertStringContainsString('Name is required', $result[0]['error']);
    }

    public function testDeleteSetsIsArchivedAndSkipsMapIncoming(): void
    {
        $model = $this->createMock(ActiveRecord::class);
        $model->expects(self::once())->method('setAttribute')->with('is_archived', 1);
        $model->method('save')->willReturn(true);

        $this->handler->method('findOrCreate')->willReturn($model);
        $this->handler->expects(self::never())->method('mapIncoming');

        $result = $this->importer->import([
            'entity' => 'companies',
            'action' => Action::DELETE,
            'batch' => [['crm_id' => 'uuid-1']],
        ]);

        self::assertSame(Status::OK, $result[0]['status']);
    }

    public function testUnknownEntityReturnsErrorForEachItem(): void
    {
        $module = $this->createMock(SyncModule::class);
        $module->method('getHandler')->willThrowException(new InvalidConfigException('No sync handler registered for entity: orders'));
        $importer = new Importer($module);

        $result = $importer->import([
            'entity' => 'orders',
            'action' => Action::UPSERT,
            'batch' => [
                ['crm_id' => 'a', 'data' => []],
                ['crm_id' => 'b', 'data' => []],
            ],
        ]);

        self::assertCount(2, $result);
        self::assertSame(Status::ERROR, $result[0]['status']);
        self::assertSame(Status::ERROR, $result[1]['status']);
        self::assertStringContainsString('orders', $result[0]['error']);
    }

    public function testIsSyncingFlagIsSetDuringSaveAndClearedAfter(): void
    {
        $observed = null;
        $model = $this->createMock(ActiveRecord::class);
        $model->method('save')->willReturnCallback(function () use (&$observed): bool {
            $observed = SyncContext::$isSyncing;
            return true;
        });
        $this->handler->method('findOrCreate')->willReturn($model);
        $this->handler->method('mapIncoming')->willReturn([]);

        $this->importer->import([
            'entity' => 'companies',
            'action' => Action::UPSERT,
            'batch' => [['crm_id' => 'uuid-x', 'data' => []]],
        ]);

        self::assertTrue($observed, 'isSyncing must be true during save()');
        self::assertFalse(SyncContext::$isSyncing, 'isSyncing must reset after import');
    }

    public function testIsSyncingClearedEvenWhenSaveThrows(): void
    {
        $model = $this->createMock(ActiveRecord::class);
        $model->method('save')->willThrowException(new \RuntimeException('boom'));
        $this->handler->method('findOrCreate')->willReturn($model);
        $this->handler->method('mapIncoming')->willReturn([]);

        $result = $this->importer->import([
            'entity' => 'companies',
            'action' => Action::UPSERT,
            'batch' => [['crm_id' => 'uuid-x', 'data' => []]],
        ]);

        self::assertSame(Status::ERROR, $result[0]['status']);
        self::assertSame('boom', $result[0]['error']);
        self::assertFalse(SyncContext::$isSyncing);
    }
}
