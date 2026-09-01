<?php

declare(strict_types=1);

namespace FluxFiles\Db;

interface Dialect
{
    public function name(): string;

    public function quoteIdent(string $ident): string;

    /** Column DDL fragment for an auto-incrementing primary key column. */
    public function autoIncrementDdl(): string;

    /** Column type for a JSON-ish text blob. */
    public function jsonType(): string;

    public function boolLiteral(bool $value): string;

    /**
     * An upsert statement: insert $insertCols, and on a conflict over
     * $conflictCols, set every column in $updateCols to its new value.
     * Returns the full SQL string with positional (?) placeholders in the
     * order: all $insertCols values, then (for mysql) nothing extra — the
     * VALUES() references reuse the same bound params.
     */
    public function upsert(string $table, array $insertCols, array $conflictCols, array $updateCols): string;

    /** MySQL needs an explicit key-length prefix to index a long path column; others don't. */
    public function pathIndexColumnExpr(string $col): string;

    /** Binary (case-sensitive, byte-exact) collation suffix for a column definition. */
    public function binaryCollationSuffix(): string;

    /** Row-locking suffix for a SELECT used inside a transaction. */
    public function forUpdateSuffix(): string;
}
