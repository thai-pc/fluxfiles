<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirrors packages/core/db/migrations/0008_add_audit_chain.sql — the
 * tamper-evident audit chain (FluxFiles\AuditChain): each row's prev/entry
 * hash, plus a one-row-per-disk head the writer locks while appending.
 */
return new class extends Migration
{
    public function up(): void
    {
        $connection = config('fluxfiles.db_connection');

        Schema::connection($connection)->table('fluxfiles_audit_log', function (Blueprint $table) {
            $table->string('prev_hash', 64)->nullable();
            $table->string('entry_hash', 64)->nullable();
        });
        Schema::connection($connection)->create('fluxfiles_audit_chain_head', function (Blueprint $table) {
            $table->string('disk', 64)->primary();
            $table->string('head_hash', 64);
        });
    }

    public function down(): void
    {
        $connection = config('fluxfiles.db_connection');

        Schema::connection($connection)->dropIfExists('fluxfiles_audit_chain_head');
        Schema::connection($connection)->table('fluxfiles_audit_log', function (Blueprint $table) {
            $table->dropColumn(['prev_hash', 'entry_hash']);
        });
    }
};
