<?php

declare(strict_types=1);

namespace Uzbek\LaravelPartitionManager\Enums;

enum PartitionType: string
{
    case RANGE = 'RANGE';
    case LIST = 'LIST';
    case HASH = 'HASH';

    /**
     * PostgreSQL partition strategy codes from pg_partitioned_table.partstrat.
     */
    public const PG_STRATEGY_RANGE = 'r';
    public const PG_STRATEGY_LIST = 'l';
    public const PG_STRATEGY_HASH = 'h';

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

    /**
     * Create a PartitionType from PostgreSQL's partition strategy code.
     *
     * @param string $pgStrategy The strategy code from pg_partitioned_table.partstrat ('r', 'l', 'h')
     * @return self|null The corresponding PartitionType or null if unknown
     */
    public static function fromPgStrategy(string $pgStrategy): ?self
    {
        return match ($pgStrategy) {
            self::PG_STRATEGY_RANGE => self::RANGE,
            self::PG_STRATEGY_LIST => self::LIST,
            self::PG_STRATEGY_HASH => self::HASH,
            default => null,
        };
    }
}
