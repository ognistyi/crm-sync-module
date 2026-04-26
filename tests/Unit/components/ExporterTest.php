<?php

declare(strict_types=1);

namespace ognistyi\sync\tests\Unit\components;

use ognistyi\sync\components\Action;
use ognistyi\sync\components\Exporter;
use ognistyi\sync\contracts\SyncHandlerInterface;
use ognistyi\sync\contracts\SyncTransportInterface;
use PHPUnit\Framework\TestCase;
use yii\db\ActiveRecord;

class ExporterTest extends TestCase
{
    private SyncTransportInterface $transport;
    private SyncHandlerInterface $handler;
    private Exporter $exporter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transport = $this->createMock(SyncTransportInterface::class);
        $this->handler = $this->createMock(SyncHandlerInterface::class);
        $this->handler->method('getEntityName')->willReturn('companies');
        $this->exporter = new Exporter('crm', 'sync.inbox.ladmin', $this->transport);
    }

    public function testExportSingleRecordPublishesEnvelope(): void
    {
        $model = $this->createMock(ActiveRecord::class);
        $model->method('getAttribute')->with('crm_id')->willReturn('uuid-123');
        $this->handler->method('fieldsOut')->willReturn(['name' => 'Acme', 'mc_number' => 'MC-1']);

        $this->transport->expects(self::once())
            ->method('publish')
            ->with(
                'sync.inbox.ladmin',
                self::callback(function (array $msg): bool {
                    self::assertSame('crm', $msg['source']);
                    self::assertSame('companies', $msg['entity']);
                    self::assertSame(Action::UPSERT, $msg['action']);
                    self::assertCount(1, $msg['batch']);
                    self::assertSame('uuid-123', $msg['batch'][0]['crm_id']);
                    self::assertSame('Acme', $msg['batch'][0]['data']['name']);
                    self::assertNotEmpty($msg['message_id']);
                    self::assertGreaterThan(0, $msg['timestamp']);
                    return true;
                })
            );

        $this->exporter->export($this->handler, $model, Action::UPSERT);
    }

    public function testExportDeleteOmitsFieldsOutCall(): void
    {
        $model = $this->createMock(ActiveRecord::class);
        $model->method('getAttribute')->with('crm_id')->willReturn('uuid-d');
        $this->handler->expects(self::never())->method('fieldsOut');

        $this->transport->expects(self::once())
            ->method('publish')
            ->with(self::anything(), self::callback(function (array $msg): bool {
                self::assertSame(Action::DELETE, $msg['action']);
                self::assertSame([], $msg['batch'][0]['data']);
                return true;
            }));

        $this->exporter->export($this->handler, $model, Action::DELETE);
    }

    public function testExportBatchSendsSingleMessageWithAllRecords(): void
    {
        $m1 = $this->createMock(ActiveRecord::class);
        $m1->method('getAttribute')->with('crm_id')->willReturn('uuid-1');
        $m2 = $this->createMock(ActiveRecord::class);
        $m2->method('getAttribute')->with('crm_id')->willReturn('uuid-2');

        $this->handler->method('fieldsOut')->willReturnOnConsecutiveCalls(
            ['name' => 'A'],
            ['name' => 'B'],
        );

        $this->transport->expects(self::once())
            ->method('publish')
            ->with(self::anything(), self::callback(function (array $msg): bool {
                self::assertCount(2, $msg['batch']);
                self::assertSame('uuid-1', $msg['batch'][0]['crm_id']);
                self::assertSame('uuid-2', $msg['batch'][1]['crm_id']);
                return true;
            }));

        $this->exporter->exportBatch($this->handler, [$m1, $m2], Action::UPSERT);
    }

    public function testExportEmptyBatchIsNoOp(): void
    {
        $this->transport->expects(self::never())->method('publish');

        $this->exporter->exportBatch($this->handler, [], Action::UPSERT);
    }
}
