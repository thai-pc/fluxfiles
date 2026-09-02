<?php

declare(strict_types=1);

namespace FluxFiles\Db;

class SqliteDialect implements Dialect
{
    public function name(): string
    {
        return 'sqlite';
    }

    public function quoteIdent(string $ident): string
    {
        return '"' . str_replace('"', '""', $ident) . '"';
    }

    public function autoIncrementDdl(): string
    {
        return 'INTEGER PRIMARY KEY AUTOINCREMENT';
    }

    public function jsonType(): string
    {
        return 'TEXT';
    }

    public function boolLiteral(bool $value): string
    {
        return $value ? '1' : '0';
    }

    public function upsert(string $table, array $insertCols, array $conflictCols, array $updateCols): string
    {
        $cols = implode(', ', $insertCols);
        $placeholders = implode(', ', array_fill(0, count($insertCols), '?'));
        $conflict = implode(', ', $conflictCols);
        $sets = implode(', ', array_map(static fn($c) => "{$c} = excluded.{$c}", $updateCols));
        return "INSERT INTO {$table} ({$cols}) VALUES ({$placeholders}) ON CONFLICT({$conflict}) DO UPDATE SET {$sets}";
    }

    public function insertIgnore(string $table, array $insertCols, array $conflictCols): string
    {
        $cols = implode(', ', $insertCols);
        $placeholders = implode(', ', array_fill(0, count($insertCols), '?'));
        $conflict = implode(', ', $conflictCols);
        return "INSERT INTO {$table} ({$cols}) VALUES ({$placeholders}) ON CONFLICT({$conflict}) DO NOTHING";
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
        return '';
    }

    public function likeEscapeClause(): string
    {
        return "ESCAPE '\\'";
    }
}
