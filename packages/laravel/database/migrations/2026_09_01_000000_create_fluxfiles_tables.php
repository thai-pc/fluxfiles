<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FluxFiles DB storage backend tables (FLUXFILES_STORAGE_BACKEND=db).
 * Column shapes mirror packages/core/db/migrations/0001-0004_*.sql exactly —
 * that's the reference schema LaravelDbMetadataHandler is a semantic port of.
 */
return new class extends Migration
{
    /**
     * `path`/`fluxfiles_directories.path` are TEXT columns — MySQL/MariaDB
     * refuse a plain index on a TEXT column without a key-length prefix, so
     * this mirrors core's Dialect::pathIndexColumnExpr(): MySQL indexes
     * path(191), SQLite/Postgres index the full column.
     */
    private function createPathIndex(?string $connection, string $table): void
    {
        $conn = DB::connection($connection);
        $driver = $conn->getDriverName();
        $indexName = "idx_{$table}_disk_path";
        $col = $driver === 'mysql' ? 'path(191)' : 'path';
        $conn->statement("CREATE INDEX {$indexName} ON {$table} (disk, {$col})");
    }

    public function up(): void
    {
        $connection = config('fluxfiles.db_connection');

        Schema::connection($connection)->create('fluxfiles_file_metadata', function (Blueprint $table) {
            $table->id();
            $table->string('disk', 64);
            $table->string('owner', 191)->nullable();
            $table->text('path');
            $table->char('path_hash', 64);
            $table->text('title')->nullable();
            $table->text('alt_text')->nullable();
            $table->text('caption')->nullable();
            $table->text('tags')->nullable();
            $table->string('mime', 191)->nullable();
            $table->bigInteger('size')->nullable();
            $table->integer('width')->nullable();
            $table->integer('height')->nullable();
            $table->string('file_hash', 64)->nullable();
            $table->smallInteger('watermarked')->nullable();
            $table->string('object_uuid', 64)->nullable();
            $table->bigInteger('created_at')->nullable();
            $table->bigInteger('modified_at')->nullable();
            $table->json('extra')->nullable();

            $table->unique(['disk', 'path_hash'], 'idx_file_metadata_disk_path_hash');
            $table->index(['disk', 'owner'], 'idx_file_metadata_disk_owner');
            $table->index(['disk', 'file_hash'], 'idx_file_metadata_disk_file_hash');
        });
        $this->createPathIndex($connection, 'fluxfiles_file_metadata');

        Schema::connection($connection)->create('fluxfiles_directories', function (Blueprint $table) {
            $table->id();
            $table->string('disk', 64);
            $table->text('path');
            $table->char('path_hash', 64);
            $table->bigInteger('created_at')->nullable();

            $table->unique(['disk', 'path_hash'], 'idx_directories_disk_path_hash');
        });
        $this->createPathIndex($connection, 'fluxfiles_directories');

        Schema::connection($connection)->create('fluxfiles_trash', function (Blueprint $table) {
            $table->string('disk', 64);
            $table->string('id', 64);
            $table->string('owner', 191)->nullable();
            $table->text('original_key');
            $table->string('basename', 512)->nullable();
            $table->smallInteger('is_dir')->default(0);
            $table->bigInteger('size')->nullable();
            $table->bigInteger('deleted_at')->nullable();
            $table->json('variants')->nullable();
            $table->json('meta')->nullable();
            $table->json('files')->nullable();
            $table->json('dirs')->nullable();

            $table->primary(['disk', 'id']);
            $table->index(['disk', 'owner'], 'idx_trash_disk_owner');
            $table->index(['disk', 'deleted_at'], 'idx_trash_disk_deleted_at');
        });

        Schema::connection($connection)->create('fluxfiles_audit_log', function (Blueprint $table) {
            $table->id();
            $table->string('disk', 64);
            $table->string('owner', 191)->nullable();
            $table->string('action', 191);
            $table->text('file_key')->nullable();
            $table->string('ip', 64)->nullable();
            $table->text('user_agent')->nullable();
            $table->text('detail')->nullable();
            $table->bigInteger('created_at');

            $table->index(['disk', 'owner', 'created_at'], 'idx_audit_log_disk_owner_created_at');
            $table->index(['disk', 'created_at'], 'idx_audit_log_disk_created_at');
            $table->index(['disk', 'action', 'created_at'], 'idx_audit_log_disk_action_created_at');
        });
    }

    public function down(): void
    {
        $connection = config('fluxfiles.db_connection');

        Schema::connection($connection)->dropIfExists('fluxfiles_audit_log');
        Schema::connection($connection)->dropIfExists('fluxfiles_trash');
        Schema::connection($connection)->dropIfExists('fluxfiles_directories');
        Schema::connection($connection)->dropIfExists('fluxfiles_file_metadata');
    }
};
