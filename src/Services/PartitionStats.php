<?php

declare(strict_types=1);

namespace Uzbek\LaravelPartitionManager\Services;

use Uzbek\LaravelPartitionManager\Partition;
use Uzbek\LaravelPartitionManager\Traits\SqlHelper;
use Illuminate\Support\Facades\DB;

class PartitionStats
{
    use SqlHelper;

    public static function get(string $table): array
    {
        return DB::select("
            SELECT
                c.relname AS partition_name,
                c.reltuples::bigint AS row_count,
                pg_total_relation_size(c.oid) AS size_bytes,
                pg_size_pretty(pg_total_relation_size(c.oid)) AS size_pretty
            FROM pg_inherits i
            JOIN pg_class c ON c.oid = i.inhrelid
            WHERE i.inhparent = ?::regclass
            ORDER BY c.relname
        ", [$table]);
    }

    public static function boundaries(string $table): array
    {
        $partitions = Partition::getPartitions($table);
        $result = [];

        foreach ($partitions as $partition) {
            $bounds = self::parseBoundaries($partition->partition_expression);
            $result[] = (object) [
                'partition_name' => $partition->partition_name,
                'partition_type' => $bounds['type'] ?? 'UNKNOWN',
                'from_value' => $bounds['from'] ?? null,
                'to_value' => $bounds['to'] ?? null,
                'values' => $bounds['values'] ?? null,
            ];
        }

        return $result;
    }

    public static function parseBoundaries(string $expression): ?array
    {
        if (preg_match("/FOR VALUES FROM \('([^']+)'\) TO \('([^']+)'\)/i", $expression, $matches)) {
            return ['type' => 'RANGE', 'from' => $matches[1], 'to' => $matches[2]];
        }

        if (preg_match("/FOR VALUES IN \((.+)\)/i", $expression, $matches)) {
            return ['type' => 'LIST', 'values' => $matches[1]];
        }

        if (preg_match("/FOR VALUES WITH \(modulus (\d+), remainder (\d+)\)/i", $expression, $matches)) {
            return ['type' => 'HASH', 'modulus' => $matches[1], 'remainder' => $matches[2]];
        }

        if (stripos($expression, 'DEFAULT') !== false) {
            return ['type' => 'DEFAULT'];
        }

        return null;
    }

    public static function healthCheck(string $table): array
    {
        return [
            'gaps' => self::findGaps($table),
            'overlaps' => self::findOverlaps($table),
            'missing_indexes' => self::findMissingIndexes($table),
            'orphan_data' => !empty(self::findGaps($table)),
        ];
    }

    public static function estimateRowCount(string $table): int
    {
        $result = DB::select("
            SELECT reltuples::bigint AS estimate
            FROM pg_class
            WHERE oid = ?::regclass
        ", [$table]);

        return (int) ($result[0]->estimate ?? 0);
    }

    public static function estimateTotalRowCount(string $table): int
    {
        $result = DB::select("
            SELECT SUM(c.reltuples)::bigint AS total
            FROM pg_inherits i
            JOIN pg_class c ON c.oid = i.inhrelid
            WHERE i.inhparent = ?::regclass
        ", [$table]);

        return (int) ($result[0]->total ?? 0);
    }

    public static function findGaps(string $table): array
    {
        $boundaries = self::boundaries($table);
        $ranges = [];

        foreach ($boundaries as $boundary) {
            if ($boundary->partition_type === 'RANGE' && $boundary->from_value && $boundary->to_value) {
                $ranges[] = ['from' => $boundary->from_value, 'to' => $boundary->to_value];
            }
        }

        usort($ranges, fn(array $a, array $b): int => strcmp($a['from'], $b['from']));

        $gaps = [];
        for ($i = 1; $i < count($ranges); $i++) {
            if ($ranges[$i - 1]['to'] < $ranges[$i]['from']) {
                $gaps[] = ['from' => $ranges[$i - 1]['to'], 'to' => $ranges[$i]['from']];
            }
        }

        return $gaps;
    }

    public static function findOverlaps(string $table): array
    {
        $boundaries = self::boundaries($table);
        $overlaps = [];

        for ($i = 0; $i < count($boundaries); $i++) {
            for ($j = $i + 1; $j < count($boundaries); $j++) {
                $a = $boundaries[$i];
                $b = $boundaries[$j];

                if ($a->partition_type !== 'RANGE' || $b->partition_type !== 'RANGE') {
                    continue;
                }

                if ($a->from_value < $b->to_value && $a->to_value > $b->from_value) {
                    $overlaps[] = [
                        'partitions' => [$a->partition_name, $b->partition_name],
                        'range' => "Overlap between {$a->from_value}-{$a->to_value} and {$b->from_value}-{$b->to_value}",
                    ];
                }
            }
        }

        return $overlaps;
    }

    public static function findMissingIndexes(string $table): array
    {
        $parentIndexes = DB::select("SELECT indexname FROM pg_indexes WHERE tablename = ?", [$table]);
        $parentIndexNames = array_column($parentIndexes, 'indexname');

        if (empty($parentIndexNames)) {
            return [];
        }

        $missing = [];
        foreach (Partition::getPartitions($table) as $partition) {
            $partName = preg_replace('/^[^.]+\./', '', $partition->partition_name);
            $partIndexes = DB::select("SELECT indexname FROM pg_indexes WHERE tablename = ?", [$partName]);
            $partIndexNames = array_column($partIndexes, 'indexname');

            foreach ($parentIndexNames as $parentIdx) {
                $hasMatch = false;
                foreach ($partIndexNames as $partIdx) {
                    if (str_contains($partIdx, $partName)) {
                        $hasMatch = true;
                        break;
                    }
                }
                if (!$hasMatch) {
                    $missing[] = $partition->partition_name;
                    break;
                }
            }
        }

        return $missing;
    }

    public static function explainPruning(string $sql, array $bindings = []): array
    {
        $explainResult = DB::select("EXPLAIN (FORMAT JSON) " . $sql, $bindings);
        $plan = json_encode($explainResult, JSON_PRETTY_PRINT);

        $partitionsScanned = [];
        if (preg_match_all('/"Relation Name":\s*"([^"]+)"/', $plan, $matches)) {
            $partitionsScanned = array_unique($matches[1]);
        }

        preg_match('/FROM\s+([^\s,]+)/i', $sql, $tableMatch);
        $totalPartitions = 0;
        if (!empty($tableMatch[1])) {
            $totalPartitions = count(Partition::getPartitions(trim($tableMatch[1], '"\'')));
        }

        return [
            'partitions_scanned' => array_values($partitionsScanned),
            'total_partitions' => $totalPartitions,
            'pruning_effective' => count($partitionsScanned) < $totalPartitions,
            'plan' => $plan,
        ];
    }

    public static function getTree(string $table, int $depth = 0): array
    {
        $partitions = Partition::getPartitions($table);
        $tree = [
            'name' => $table,
            'depth' => $depth,
            'type' => 'root',
            'children' => [],
        ];

        foreach ($partitions as $partition) {
            $bounds = self::parseBoundaries($partition->partition_expression);
            $child = [
                'name' => $partition->partition_name,
                'depth' => $depth + 1,
                'type' => $bounds['type'] ?? 'UNKNOWN',
                'bounds' => $bounds,
                'children' => [],
            ];

            $subPartitions = Partition::getPartitions($partition->partition_name);
            if (!empty($subPartitions)) {
                $subTree = self::getTree($partition->partition_name, $depth + 1);
                $child['children'] = $subTree['children'];
            }

            $tree['children'][] = $child;
        }

        return $tree;
    }

    public static function printTree(string $table): string
    {
        return self::formatTreeNode(self::getTree($table), '', true);
    }

    protected static function formatTreeNode(array $node, string $prefix, bool $isLast): string
    {
        $output = $prefix;

        if ($node['depth'] > 0) {
            $output .= $isLast ? '└── ' : '├── ';
        }

        $output .= $node['name'];

        if (isset($node['bounds'])) {
            $bounds = $node['bounds'];
            if (isset($bounds['from'], $bounds['to'])) {
                $output .= " [{$bounds['from']} → {$bounds['to']}]";
            } elseif (isset($bounds['values'])) {
                $output .= " [IN: {$bounds['values']}]";
            } elseif (isset($bounds['modulus'])) {
                $output .= " [mod {$bounds['modulus']}, rem {$bounds['remainder']}]";
            }
        }

        $output .= "\n";

        $children = $node['children'] ?? [];
        $childCount = count($children);

        foreach ($children as $i => $child) {
            $isChildLast = ($i === $childCount - 1);
            $newPrefix = $prefix . ($node['depth'] > 0 ? ($isLast ? '    ' : '│   ') : '');
            $output .= self::formatTreeNode($child, $newPrefix, $isChildLast);
        }

        return $output;
    }
}
