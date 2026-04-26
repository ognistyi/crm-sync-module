<?php

declare(strict_types=1);

namespace ognistyi\sync\tests\Unit;

use ognistyi\sync\contracts\SyncHandlerInterface;
use ognistyi\sync\SyncModule;
use PHPUnit\Framework\TestCase;
use Yii;
use yii\base\InvalidConfigException;
use yii\console\Application;

class SyncModuleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        new Application([
            'id' => 'test',
            'basePath' => sys_get_temp_dir(),
        ]);
    }

    protected function tearDown(): void
    {
        Yii::$app = null;
        parent::tearDown();
    }

    public function testEntityNamesPreserveRegistrationOrder(): void
    {
        $module = $this->makeModule([
            'companies' => DummySyncHandler::class,
            'users' => DummySyncHandler::class,
            'roles' => DummySyncHandler::class,
        ]);

        self::assertSame(['companies', 'users', 'roles'], $module->getEntityNames());
    }

    public function testGetHandlerResolvesByEntity(): void
    {
        $module = $this->makeModule(['companies' => DummySyncHandler::class]);

        self::assertInstanceOf(DummySyncHandler::class, $module->getHandler('companies'));
    }

    public function testGetHandlerThrowsForUnknownEntity(): void
    {
        $module = $this->makeModule(['companies' => DummySyncHandler::class]);

        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessageMatches('/No sync handler.+orders/');
        $module->getHandler('orders');
    }

    public function testGetRbacHandlerThrowsWhenNotConfigured(): void
    {
        $module = $this->makeModule([]);

        $this->expectException(InvalidConfigException::class);
        $module->getRbacHandler();
    }

    /**
     * @param array<string, class-string<SyncHandlerInterface>> $handlers
     */
    private function makeModule(array $handlers): SyncModule
    {
        return new SyncModule('sync', null, [
            'source' => 'crm',
            'inboxQueue' => 'sync.inbox.crm',
            'handlers' => $handlers,
        ]);
    }
}

final class DummySyncHandler implements SyncHandlerInterface
{
    public function getModelClass(): string
    {
        return \stdClass::class;
    }

    public function getEntityName(): string
    {
        return 'dummies';
    }

    public function fieldsOut(\yii\db\ActiveRecord $model): array
    {
        return [];
    }

    public function mapIncoming(array $data): array
    {
        return [];
    }

    public function find(string $crmId): ?\yii\db\ActiveRecord
    {
        return null;
    }

    public function findOrCreate(string $crmId): \yii\db\ActiveRecord
    {
        throw new \RuntimeException('not used');
    }
}
