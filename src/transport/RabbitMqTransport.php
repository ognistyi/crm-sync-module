<?php

declare(strict_types=1);

namespace ognistyi\sync\transport;

use ognistyi\sync\contracts\SyncTransportInterface;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

class RabbitMqTransport implements SyncTransportInterface
{
    private ?AMQPStreamConnection $connection = null;

    private ?AMQPChannel $channel = null;

    public function __construct(
        private string $host,
        private int $port,
        private string $user,
        private string $password,
        private string $vhost = '/',
    ) {
    }

    public function publish(string $queue, array $message): void
    {
        $channel = $this->getChannel();
        $this->declareQueue($channel, $queue);

        $body = json_encode($message, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $amqp = new AMQPMessage($body, [
            'content_type' => 'application/json',
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
        ]);

        $channel->basic_publish($amqp, '', $queue);
    }

    public function consume(string $queue, callable $callback): void
    {
        $channel = $this->getChannel();
        $this->declareQueue($channel, $queue);
        $channel->basic_qos(0, 1, false);

        $channel->basic_consume(
            $queue,
            '',
            false,
            false,
            false,
            false,
            function (AMQPMessage $msg) use ($callback): void {
                $data = json_decode($msg->getBody(), true, 512, JSON_THROW_ON_ERROR);
                $callback($data);
                $msg->ack();
            },
        );

        while ($channel->is_consuming()) {
            $channel->wait();
        }
    }

    public function disconnect(): void
    {
        $this->channel?->close();
        $this->connection?->close();
        $this->channel = null;
        $this->connection = null;
    }

    protected function getChannel(): AMQPChannel
    {
        if ($this->channel === null || !($this->connection?->isConnected())) {
            $this->connection = $this->newConnection();
            $this->channel = $this->connection->channel();
        }

        return $this->channel;
    }

    protected function newConnection(): AMQPStreamConnection
    {
        return new AMQPStreamConnection($this->host, $this->port, $this->user, $this->password, $this->vhost);
    }

    private function declareQueue(AMQPChannel $channel, string $queue): void
    {
        $channel->queue_declare($queue, false, true, false, false);
    }
}
