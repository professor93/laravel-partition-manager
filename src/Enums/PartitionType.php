<?php

declare(strict_types=1);

namespace Uzbek\LaravelPartitionManager\Enums;

enum PartitionType: string
{
    case RANGE = 'RANGE';
    case LIST = 'LIST';
    case HASH = 'HASH';

    public function isRange(): bool
    {
        return $this === self::RANGE;
    }

    public function isList(): bool
    {
        return $this === self::LIST;
    }

    public function isHash(): bool
    {
        return $this === self::HASH;
    }
}
