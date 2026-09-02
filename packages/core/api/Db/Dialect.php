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

    /**
     * An insert-or-skip statement: insert $insertCols, and on a conflict over
     * $conflictCols, do nothing (row stays as-is, no error). Unlike upsert(),
     * carries no update columns — used for migration imports that must not
     * clobber timestamps set at insert time.
     */
    public function insertIgnore(string $table, array $insertCols, array $conflictCols): string;

    /** MySQL needs an explicit key-length prefix to index a long path column; others don't. */
    public function pathIndexColumnExpr(string $col): string;

    /** Binary (case-sensitive, byte-exact) collation suffix for a column definition. */
    public function binaryCollationSuffix(): string;

    /** Row-locking suffix for a SELECT used inside a transaction. */
    public function forUpdateSuffix(): string;

    /**
     * The `ESCAPE '...'` clause to pair with a `LIKE ?` bound to a value built
     * by escapeLike() (which escapes literal `\`/`%`/`_` with a backslash).
     * MySQL's string-literal parser itself decodes backslash sequences, so a
     * literal single backslash needs TWO backslash characters in the SQL text
     * sent to the server (`ESCAPE '\\'`) — one backslash there is a MySQL
     * syntax error (it starts an escaped-quote that never closes). SQLite and
     * Postgres (default standard_conforming_strings) take backslash literally
     * inside a single-quoted string, so one backslash character is already a
     * valid 1-char string (`ESCAPE '\'`) — two there is a SQLite "ESCAPE
     * expression must be a single character" error. Never share this literal
     * across dialects. Postgres additionally can't use that plain `'\'`
     * form once a query has more than one such clause: PDO_PGSQL's own
     * client-side placeholder scanner mis-tracks quote boundaries around a
     * bare backslash-before-quote and desyncs `?` → `$n` numbering, so
     * PgsqlDialect uses the `E'\\'` escape-string form instead.
     */
    public function likeEscapeClause(): string;
}
