<?php

declare(strict_types=1);

namespace ognistyi\sync\tests\Unit\transport;

use ognistyi\sync\transport\RabbitMqTransport;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use PHPUnit\Framework\TestCase;

class RabbitMqTransportTest extends TestCase
{
    public function testPublishDeclaresQueueAndBroadcastsPersistentJsonMessage(): void
    {
        $channel = $this->createMock(AMQPChannel::class);
        $channel->expects(self::once())
            ->method('queue_declare')
            ->with('sync.inbox.ladmin', false, true, false, false);

        $channel->expects(self::once())
            ->method('basic_publish')
            ->with(
                self::callback(function (AMQPMessage $msg): bool {
                    self::assertSame('application/json', $msg->get('content_type'));
                    self::assertSame(AMQPMessage::DELIVERY_MODE_PERSISTENT, $msg->get('delivery_mode'));
                    self::assertSame(['entity' => 'companies'], json_decode($msg->getBody(), true));
                    return true;
                }),
                '',
                'sync.inbox.ladmin',
            );

        $transport = new TestableRabbitMqTransport($channel);
        $transport->publish('sync.inbox.ladmin', ['entity' => 'companies']);
    }

    public function testPublishEncodesUnicodeWithoutEscaping(): void
    {
        $captured = null;
        $channel = $this->createMock(AMQPChannel::class);
        $channel->method('queue_declare');
        $channel->expects(self::once())
            ->method('basic_publish')
            ->willReturnCallback(function (AMQPMessage $msg) use (&$captured): void {
                $captured = $msg->getBody();
            });

        $transport = new TestableRabbitMqTransport($channel);
        $transport->publish('q', ['name' => 'Київ']);

        self::assertStringContainsString('Київ', $captured ?? '', 'unicode must not be \\u-escaped');
    }
}

final class TestableRabbitMqTransport extends RabbitMqTransport
{
    public function __construct(private AMQPChannel $channelMock)
    {
        parent::__construct('h', 5672, 'u', 'p');
    }

    protected function getChannel(): AMQPChannel
    {
        return $this->channelMock;
    }
}
