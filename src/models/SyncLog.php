<?php

declare(strict_types=1);

namespace ognistyi\sync\models;

use yii\db\ActiveRecord;

/**
 * @property int         $id
 * @property string      $message_id
 * @property string      $direction
 * @property string      $entity
 * @property string      $crm_id
 * @property string      $action
 * @property string      $status
 * @property string|null $error
 * @property array|null  $payload
 * @property string      $created_at
 */
class SyncLog extends ActiveRecord
{
    public const DIRECTION_IN = 'in';
    public const DIRECTION_OUT = 'out';

    public static function tableName(): string
    {
        return '{{%sync_logs}}';
    }

    public function rules(): array
    {
        return [
            [['message_id', 'direction', 'entity', 'crm_id', 'action', 'status'], 'required'],
            [['message_id', 'crm_id'], 'string', 'max' => 36],
            [['entity'], 'string', 'max' => 50],
            [['direction'], 'in', 'range' => [self::DIRECTION_IN, self::DIRECTION_OUT]],
            [['action'], 'in', 'range' => ['upsert', 'delete', 'ack']],
            [['status'], 'in', 'range' => ['sent', 'ok', 'error']],
            [['error'], 'string'],
            [['payload'], 'safe'],
        ];
    }
}
