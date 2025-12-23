<?php

declare(strict_types=1);

namespace Uzbek\LaravelPartitionManager\Services;

use Uzbek\LaravelPartitionManager\Exceptions\PartitionException;

class PartitionExport
{
    public static function getExportCommand(string $partition, string $outputPath, string $format = 'sql'): string
    {
        $dbConfig = config('database.connections.' . config('database.default'));
        $host = escapeshellarg($dbConfig['host'] ?? 'localhost');
        $port = escapeshellarg((string) ($dbConfig['port'] ?? 5432));
        $database = escapeshellarg($dbConfig['database'] ?? '');
        $username = escapeshellarg($dbConfig['username'] ?? '');
        $safePartition = escapeshellarg($partition);
        $safePath = escapeshellarg($outputPath);

        return match ($format) {
            'csv' => "psql -h {$host} -p {$port} -U {$username} -d {$database} -c \"\\COPY {$partition} TO '{$outputPath}' WITH CSV HEADER\"",
            'binary' => "pg_dump -h {$host} -p {$port} -U {$username} -d {$database} -t {$safePartition} -Fc -f {$safePath}",
            default => "pg_dump -h {$host} -p {$port} -U {$username} -d {$database} -t {$safePartition} -f {$safePath}",
        };
    }

    public static function getImportCommands(
        string $table,
        string $inputPath,
        string $from,
        string $to,
        string $format = 'sql'
    ): array {
        $dbConfig = config('database.connections.' . config('database.default'));
        $host = escapeshellarg($dbConfig['host'] ?? 'localhost');
        $port = escapeshellarg((string) ($dbConfig['port'] ?? 5432));
        $database = escapeshellarg($dbConfig['database'] ?? '');
        $username = escapeshellarg($dbConfig['username'] ?? '');
        $safeTable = escapeshellarg($table);
        $safePath = escapeshellarg($inputPath);

        return match ($format) {
            'csv' => [
                "psql -h {$host} -p {$port} -U {$username} -d {$database} -c \"\\COPY {$table} FROM '{$inputPath}' WITH CSV HEADER\"",
            ],
            'binary' => [
                "pg_restore -h {$host} -p {$port} -U {$username} -d {$database} {$safePath}",
                "psql -h {$host} -p {$port} -U {$username} -d {$database} -c \"ALTER TABLE {$safeTable} ATTACH PARTITION ... FOR VALUES FROM ('{$from}') TO ('{$to}')\"",
            ],
            default => [
                "psql -h {$host} -p {$port} -U {$username} -d {$database} -f {$safePath}",
            ],
        };
    }
}
