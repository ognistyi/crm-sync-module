<?php

declare(strict_types=1);

namespace ognistyi\sync;

use ognistyi\sync\components\Exporter;
use ognistyi\sync\contracts\RbacSyncHandlerInterface;
use ognistyi\sync\contracts\SyncHandlerInterface;
use ognistyi\sync\contracts\SyncTransportInterface;
use ognistyi\sync\transport\RabbitMqTransport;
use Yii;
use yii\base\InvalidConfigException;
use yii\base\Module;

/**
 * Sync module entry point. Configured per host system (CRM, Brokerage).
 *
 * @property-read string[] $entityNames
 */
class SyncModule extends Module
{
    /** Sender system identifier — stamped on every outbound message. */
    public string $source;

    /** Inbox queue this system owns and listens on. */
    public string $inboxQueue;

    /** Outbound queue — where we publish data and ACKs (typically the LAdmin inbox). */
    public string $outboxQueue;

    /** Default batch size for ./yii sync/all. */
    public int $batchSize = 50;

    /**
     * Map of entity name => SyncHandlerInterface FQCN.
     *
     * @var array<string, class-string<SyncHandlerInterface>>
     */
    public array $handlers = [];

    /** Optional FQCN of a RbacSyncHandlerInterface implementation. */
    public ?string $rbacHandler = null;

    /** Lazy-built shared transport. Override via setTransport() for testing. */
    private ?SyncTransportInterface $transport = null;

    /** Lazy-built shared exporter. */
    private ?Exporter $exporter = null;

    public function init(): void
    {
        parent::init();
        $this->controllerNamespace = 'ognistyi\\sync\\console';
    }

    /**
     * Resolve a handler instance by entity name.
     *
     * @throws InvalidConfigException If no handler is registered for the entity.
     */
    public function getHandler(string $entity): SyncHandlerInterface
    {
        if (!isset($this->handlers[$entity])) {
            throw new InvalidConfigException("No sync handler registered for entity: {$entity}");
        }

        return Yii::createObject($this->handlers[$entity]);
    }

    /**
     * @throws InvalidConfigException If no RBAC handler is configured.
     */
    public function getRbacHandler(): RbacSyncHandlerInterface
    {
        if ($this->rbacHandler === null) {
            throw new InvalidConfigException('No RBAC handler configured for sync module.');
        }

        return Yii::createObject($this->rbacHandler);
    }

    /**
     * Entity names in handler-registration order — used by sync/all to satisfy FK ordering.
     *
     * @return string[]
     */
    public function getEntityNames(): array
    {
        return array_keys($this->handlers);
    }

    public function getTransport(): SyncTransportInterface
    {
        if ($this->transport === null) {
            $cfg = Yii::$app->params['rabbit_admin_panel'] ?? [];
            $this->transport = new RabbitMqTransport(
                host: (string) ($cfg['host'] ?? '127.0.0.1'),
                port: (int) ($cfg['port'] ?? 5672),
                user: (string) ($cfg['user'] ?? 'guest'),
                password: (string) ($cfg['password'] ?? 'guest'),
                vhost: (string) ($cfg['vhost'] ?? '/'),
            );
        }
        return $this->transport;
    }

    public function setTransport(SyncTransportInterface $transport): void
    {
        $this->transport = $transport;
        $this->exporter = null;
    }

    public function getExporter(): Exporter
    {
        if ($this->exporter === null) {
            $this->exporter = new Exporter($this->source, $this->outboxQueue, $this->getTransport());
        }
        return $this->exporter;
    }
}
