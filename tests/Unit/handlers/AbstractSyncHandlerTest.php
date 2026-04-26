<?php

declare(strict_types=1);

namespace ognistyi\sync\tests\Unit\handlers;

use ognistyi\sync\handlers\AbstractSyncHandler;
use PHPUnit\Framework\TestCase;
use yii\db\ActiveRecord;

class AbstractSyncHandlerTest extends TestCase
{
    public function testGetEntityNameAndModelClass(): void
    {
        $handler = $this->makeHandler(coreFields: ['name']);

        self::assertSame('widgets', $handler->getEntityName());
        self::assertSame(\stdClass::class, $handler->getModelClass());
    }

    public function testFieldsOutEmitsCoreFieldsByName(): void
    {
        $handler = $this->makeHandler(coreFields: ['name', 'mc_number']);
        $model = $this->createMock(ActiveRecord::class);
        $model->method('getAttribute')->willReturnMap([
            ['name', 'Acme Corp'],
            ['mc_number', 'MC-100'],
        ]);
        $model->method('hasAttribute')->willReturn(false);

        $out = $handler->fieldsOut($model);

        self::assertSame(['name' => 'Acme Corp', 'mc_number' => 'MC-100'], $out);
    }

    public function testFieldsOutAppliesAliasesForLocalLookup(): void
    {
        $handler = $this->makeHandler(
            coreFields: ['manager_id'],
            aliases: ['manager_id' => 'manager'],
        );
        $model = $this->createMock(ActiveRecord::class);
        $model->method('getAttribute')->willReturnMap([['manager', 42]]);
        $model->method('hasAttribute')->willReturn(false);

        $out = $handler->fieldsOut($model);

        self::assertSame(['manager_id' => 42], $out);
    }

    public function testFieldsOutIncludesExtraDataWhenCatchAllEnabled(): void
    {
        $handler = $this->makeHandler(
            coreFields: ['name'],
            extraDataCatchAll: true,
        );
        $model = $this->createMock(ActiveRecord::class);
        $model->method('getAttribute')->willReturnMap([
            ['name', 'Acme'],
            ['extra_data', ['legacy_flag' => 1]],
        ]);
        $model->method('hasAttribute')->with('extra_data')->willReturn(true);

        $out = $handler->fieldsOut($model);

        self::assertSame(['name' => 'Acme', 'extra_data' => ['legacy_flag' => 1]], $out);
    }

    public function testMapIncomingMapsCoreFieldsAndDropsUnknown(): void
    {
        $handler = $this->makeHandler(coreFields: ['name', 'mc_number']);

        $attrs = $handler->mapIncoming([
            'name' => 'Acme',
            'mc_number' => 'MC-1',
            'unknown' => 'should be ignored',
        ]);

        self::assertSame(['name' => 'Acme', 'mc_number' => 'MC-1'], $attrs);
    }

    public function testMapIncomingAppliesAliasInReverse(): void
    {
        $handler = $this->makeHandler(
            coreFields: ['manager_id'],
            aliases: ['manager_id' => 'manager'],
        );

        $attrs = $handler->mapIncoming(['manager_id' => 7]);

        self::assertSame(['manager' => 7], $attrs);
    }

    public function testMapIncomingCollectsUnknownKeysIntoExtraDataWhenCatchAllEnabled(): void
    {
        $handler = $this->makeHandler(
            coreFields: ['name'],
            extraDataCatchAll: true,
        );

        $attrs = $handler->mapIncoming([
            'name' => 'Acme',
            'legacy_flag' => 1,
            'misc' => 'data',
        ]);

        self::assertSame('Acme', $attrs['name']);
        self::assertSame(['legacy_flag' => 1, 'misc' => 'data'], $attrs['extra_data']);
    }

    public function testMapIncomingMergesIncomingExtraDataWithUnknownKeys(): void
    {
        $handler = $this->makeHandler(
            coreFields: ['name'],
            extraDataCatchAll: true,
        );

        $attrs = $handler->mapIncoming([
            'name' => 'Acme',
            'extra_data' => ['flag_a' => 1],
            'flag_b' => 2,
        ]);

        self::assertSame(['flag_a' => 1, 'flag_b' => 2], $attrs['extra_data']);
    }

    public function testFindReturnsExistingRecord(): void
    {
        $existing = $this->createMock(ActiveRecord::class);
        $handler = $this->makeHandler(coreFields: [], existing: $existing);

        self::assertSame($existing, $handler->find('uuid-1'));
    }

    public function testFindReturnsNullWhenAbsent(): void
    {
        $handler = $this->makeHandler(coreFields: [], existing: null);

        self::assertNull($handler->find('uuid-missing'));
    }

    public function testFindOrCreateReturnsExistingRecord(): void
    {
        $existing = $this->createMock(ActiveRecord::class);
        $existing->expects(self::never())->method('setAttribute');

        $handler = $this->makeHandler(coreFields: [], existing: $existing);

        self::assertSame($existing, $handler->findOrCreate('uuid-1'));
    }

    public function testFindOrCreateInstantiatesNewWithCrmId(): void
    {
        $newOne = $this->createMock(ActiveRecord::class);
        $newOne->expects(self::once())->method('setAttribute')->with('crm_id', 'uuid-2');

        $handler = $this->makeHandler(coreFields: [], existing: null, newRecord: $newOne);

        self::assertSame($newOne, $handler->findOrCreate('uuid-2'));
    }

    /**
     * @param string[] $coreFields
     * @param array<string, string> $aliases
     */
    private function makeHandler(
        array $coreFields,
        array $aliases = [],
        bool $extraDataCatchAll = false,
        ?ActiveRecord $existing = null,
        ?ActiveRecord $newRecord = null,
    ): AbstractSyncHandler {
        return new class($coreFields, $aliases, $extraDataCatchAll, $existing, $newRecord) extends AbstractSyncHandler {
            protected string $modelClass = \stdClass::class;
            protected string $entityName = 'widgets';

            public function __construct(
                array $coreFields,
                array $aliases,
                bool $extraDataCatchAll,
                private ?ActiveRecord $existingMock,
                private ?ActiveRecord $newRecordMock,
            ) {
                $this->coreFields = $coreFields;
                $this->aliases = $aliases;
                $this->extraDataCatchAll = $extraDataCatchAll;
            }

            public function find(string $crmId): ?ActiveRecord
            {
                return $this->existingMock;
            }

            protected function makeNew(): ActiveRecord
            {
                return $this->newRecordMock ?? throw new \LogicException('No new record stub configured.');
            }
        };
    }
}
