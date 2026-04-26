<?php

declare(strict_types=1);

namespace ognistyi\sync\tests\Unit\components;

use ognistyi\sync\components\AckProcessor;
use ognistyi\sync\components\Status;
use ognistyi\sync\components\SyncContext;
use ognistyi\sync\contracts\SyncHandlerInterface;
use ognistyi\sync\SyncModule;
use PHPUnit\Framework\TestCase;
use yii\db\ActiveRecord;

class AckProcessorTest extends TestCase
{
    private SyncModule $module;
    private SyncHandlerInterface $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->module = $this->createMock(SyncModule::class);
        $this->handler = $this->createMock(SyncHandlerInterface::class);
        $this->module->method('getHandler')->with('companies')->willReturn($this->handler);
        SyncContext::$isSyncing = false;
    }

    protected function tearDown(): void
    {
        SyncContext::$isSyncing = false;
        parent::tearDown();
    }

    public function testOkStatusUpdatesSyncAtOnExistingRecord(): void
    {
        $model = $this->createMock(ActiveRecord::class);
        $model->expects(self::once())
            ->method('updateAttributes')
            ->with(self::callback(function (array $attrs): bool {
                self::assertArrayHasKey('sync_at', $attrs);
                self::assertGreaterThan(0, $attrs['sync_at']);
                return true;
            }));

        $this->handler->method('find')->with('uuid-1')->willReturn($model);

        $processor = new AckProcessor($this->module);
        $processor->process([
            'entity' => 'companies',
            'action' => 'ack',
            'batch' => [['crm_id' => 'uuid-1', 'status' => Status::OK]],
        ]);
    }

    public function testErrorStatusDoesNotUpdateSyncAt(): void
    {
        $model = $this->createMock(ActiveRecord::class);
        $model->expects(self::never())->method('updateAttributes');

        $this->handler->method('find')->willReturn($model);

        $processor = new AckProcessor($this->module);
        $processor->process([
            'entity' => 'companies',
            'action' => 'ack',
            'batch' => [['crm_id' => 'uuid-1', 'status' => Status::ERROR, 'error' => 'validation']],
        ]);
    }

    public function testMissingLocalRecordDoesNotThrow(): void
    {
        $this->handler->method('find')->willReturn(null);

        $processor = new AckProcessor($this->module);

        $this->expectNotToPerformAssertions();
        $processor->process([
            'entity' => 'companies',
            'action' => 'ack',
            'batch' => [['crm_id' => 'uuid-missing', 'status' => Status::OK]],
        ]);
    }

    public function testIsSyncingFlagSetWhileUpdating(): void
    {
        $observed = null;
        $model = $this->createMock(ActiveRecord::class);
        $model->method('updateAttributes')->willReturnCallback(function () use (&$observed): int {
            $observed = SyncContext::$isSyncing;
            return 1;
        });
        $this->handler->method('find')->willReturn($model);

        $processor = new AckProcessor($this->module);
        $processor->process([
            'entity' => 'companies',
            'action' => 'ack',
            'batch' => [['crm_id' => 'uuid-1', 'status' => Status::OK]],
        ]);

        self::assertTrue($observed);
        self::assertFalse(SyncContext::$isSyncing);
    }
}
