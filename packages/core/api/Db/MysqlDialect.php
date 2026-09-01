<?php

declare(strict_types=1);

namespace FluxFiles\Db;

class MysqlDialect implements Dialect
{
    public function name(): string
    {
        return 'mysql';
    }

    public function quoteIdent(string $ident): string
    {
        return '`' . str_replace('`', '``', $ident) . '`';
    }

    public function autoIncrementDdl(): string
    {
        return 'BIGINT AUTO_INCREMENT PRIMARY KEY';
    }

    public function jsonType(): string
    {
        return 'JSON';
    }

    public function boolLiteral(bool $value): string
    {
        return $value ? '1' : '0';
    }

    public function upsert(string $table, array $insertCols, array $conflictCols, array $updateCols): string
    {
        $cols = implode(', ', $insertCols);
        $placeholders = implode(', ', array_fill(0, count($insertCols), '?'));
        $sets = implode(', ', array_map(static fn($c) => "{$c} = VALUES({$c})", $updateCols));
        return "INSERT INTO {$table} ({$cols}) VALUES ({$placeholders}) ON DUPLICATE KEY UPDATE {$sets}";
    }

    public function pathIndexColumnExpr(string $col): string
    {
        return "{$col}(191)";
    }

    public function binaryCollationSuffix(): string
    {
        return ' COLLATE utf8mb4_bin';
    }

    public function forUpdateSuffix(): string
    {
        return ' FOR UPDATE';
    }
}
