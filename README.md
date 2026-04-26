# ognistyi/crm-sync-module

Universal handler-based bidirectional sync module for Yii2. Drop-in extension for syncing organizational data (companies, offices, users, RBAC, etc.) between a Yii2 master system and a central admin panel via RabbitMQ.

## Features

- **Universal envelope** — single message format (`upsert | delete | ack`) for all entities
- **Per-record ACK** — `sync_at` timestamp confirms delivery on both sides
- **Hybrid handler model** — declarative `$coreFields` / `$aliases` for simple entities, full method override for complex ones
- **Initial bulk sync** — `./yii sync/all` ships every record with `sync_at IS NULL`
- **Loop-safe** — `SyncBehavior::$isSyncing` flag prevents bounce-back during ACK processing
- **Inbox-per-system topology** — single inbound queue per system, ACK travels through same channel as data

## Installation

```bash
composer require ognistyi/crm-sync-module
```

## Configuration

In your application `common/config/main.php`:

```php
'modules' => [
    'sync' => [
        'class' => \globallog\sync\SyncModule::class,
        'source' => 'crm',                         // your system identifier
        'inboxQueue' => 'sync.inbox.crm',          // queue this system listens on
        'batchSize' => 50,
        'handlers' => [
            'companies' => \app\sync\handlers\CompanySyncHandler::class,
            'users'     => \app\sync\handlers\UserSyncHandler::class,
            // ... entity => handler class
        ],
        'rbacHandler' => \app\sync\handlers\RbacSyncHandler::class,
    ],
],
```

In `common/config/params.php`:

```php
'rabbit_admin_panel' => [
    'host'     => '127.0.0.1',
    'port'     => 5672,
    'user'     => 'guest',
    'password' => 'guest',
    'vhost'    => '/',
],
```

## Defining a handler

Simple entity (1:1 fields):

```php
namespace app\sync\handlers;

use globallog\sync\handlers\AbstractSyncHandler;
use app\models\Companies;

class CompanySyncHandler extends AbstractSyncHandler
{
    protected string $modelClass = Companies::class;
    protected string $entityName = 'companies';
    protected array $coreFields = ['name', 'mc_number', 'phone_number', 'address', 'is_archived'];
}
```

Entity with field rename (local column differs from message key):

```php
class DepartmentSyncHandler extends AbstractSyncHandler
{
    protected string $modelClass = Departments::class;
    protected string $entityName = 'departments';
    protected array $coreFields = ['name', 'company_id', 'manager_id', 'is_archived'];
    protected array $aliases    = ['manager_id' => 'manager']; // message key -> local column
}
```

Complex entity (override methods):

```php
class UserSyncHandler extends AbstractSyncHandler
{
    protected string $modelClass = User::class;
    protected string $entityName = 'users';
    protected array $coreFields = ['username', 'email', 'first_name', 'last_name'];

    public function mapIncoming(array $data): array
    {
        $attrs = parent::mapIncoming($data);
        // Custom logic for JSON-shaped fields
        return $attrs;
    }
}
```

## Console commands

```bash
./yii sync/listen                    # daemon: listen on inbox queue
./yii sync/all --batchSize=50        # initial sync of every record where sync_at IS NULL
./yii sync/entity companies          # bulk sync of one entity
./yii sync/rbac                      # sync roles & permissions via authManager
```

## Testing

```bash
composer install
vendor/bin/phpunit
```
