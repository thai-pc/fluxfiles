<?php

declare(strict_types=1);

namespace FluxFiles\Db;

class PgsqlDialect implements Dialect
{
    public function name(): string
    {
        return 'pgsql';
    }

    public function quoteIdent(string $ident): string
    {
        return '"' . str_replace('"', '""', $ident) . '"';
    }

    public function autoIncrementDdl(): string
    {
        return 'BIGSERIAL PRIMARY KEY';
    }

    public function jsonType(): string
    {
        return 'JSONB';
    }

    public function boolLiteral(bool $value): string
    {
        return $value ? 'TRUE' : 'FALSE';
    }

    public function upsert(string $table, array $insertCols, array $conflictCols, array $updateCols): string
    {
        $cols = implode(', ', $insertCols);
        $placeholders = implode(', ', array_fill(0, count($insertCols), '?'));
        $conflict = implode(', ', $conflictCols);
        $sets = implode(', ', array_map(static fn($c) => "{$c} = EXCLUDED.{$c}", $updateCols));
        return "INSERT INTO {$table} ({$cols}) VALUES ({$placeholders}) ON CONFLICT({$conflict}) DO UPDATE SET {$sets}";
    }

    public function pathIndexColumnExpr(string $col): string
    {
        return $col;
    }

    public function binaryCollationSuffix(): string
    {
        return '';
    }

    public function forUpdateSuffix(): string
    {
        return ' FOR UPDATE';
    }
}
