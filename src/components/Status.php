<?php

declare(strict_types=1);

namespace ognistyi\sync\components;

final class Status
{
    public const SENT = 'sent';
    public const OK = 'ok';
    public const ERROR = 'error';

    private function __construct()
    {
    }
}
