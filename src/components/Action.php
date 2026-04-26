<?php

declare(strict_types=1);

namespace ognistyi\sync\components;

final class Action
{
    public const UPSERT = 'upsert';
    public const DELETE = 'delete';
    public const ACK = 'ack';

    private function __construct()
    {
    }
}
