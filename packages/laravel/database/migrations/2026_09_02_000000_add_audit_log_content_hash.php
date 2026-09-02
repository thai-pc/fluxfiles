<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirrors packages/core/db/migrations/0006_add_audit_log_content_hash.sql —
 * a nullable content-hash column + unique (disk, content_hash) index so
 * JsonToDbMigrator's audit import can dedupe idempotently. NULL-safe
 * uniqueness leaves live audit() calls (which never set a hash) unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        $connection = config('fluxfiles.db_connection');

        Schema::connection($connection)->table('fluxfiles_audit_log', function (Blueprint $table) {
            $table->string('content_hash', 64)->nullable();
            $table->unique(['disk', 'content_hash'], 'idx_audit_log_disk_content_hash');
        });
    }

    public function down(): void
    {
        $connection = config('fluxfiles.db_connection');

        Schema::connection($connection)->table('fluxfiles_audit_log', function (Blueprint $table) {
            $table->dropUnique('idx_audit_log_disk_content_hash');
            $table->dropColumn('content_hash');
        });
    }
};
