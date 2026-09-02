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
        return ' FOR UPDATE';
    }

    public function likeEscapeClause(): string
    {
        // Not a plain '\' literal: PDO_PGSQL's own client-side SQL scanner
        // (used to translate ? into positional $n params) treats a backslash
        // before a closing quote as escaping it, MySQL-style — so a second
        // ESCAPE '\' elsewhere in the same query desyncs its placeholder
        // count and PDO throws "Invalid parameter number". Postgres's E''
        // escape-string form has no bare backslash-quote for that scanner to
        // misparse; E'\\' still decodes server-side to one literal backslash.
        return "ESCAPE E'\\\\'";
    }
}
