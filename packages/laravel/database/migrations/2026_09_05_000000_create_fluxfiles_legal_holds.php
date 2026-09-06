<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirrors packages/core/db/migrations/0007_create_legal_holds.sql — the
 * legal-hold (retention) storage primitive LaravelDbMetadataHandler's
 * allHolds()/getHold()/addHold()/releaseHold()/countActiveHolds()/
 * holdCovering()/holdBlocking() are a direct port of. Free/core storage
 * shape; the paid gate only covers placing/releasing a hold (§2 of
 * docs/RETENTION-LEGAL-HOLD-DESIGN.md), not this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        $connection = config('fluxfiles.db_connection');

        Schema::connection($connection)->create('fluxfiles_legal_holds', function (Blueprint $table) {
            $table->string('disk', 64);
            $table->string('id', 64);
            $table->text('path');
            $table->smallInteger('is_dir')->default(0);
            $table->text('reason')->nullable();
            $table->string('placed_by', 191)->nullable();
            $table->bigInteger('placed_at')->nullable();
            $table->bigInteger('released_at')->nullable();
            $table->string('released_by', 191)->nullable();
            $table->text('release_reason')->nullable();

            $table->primary(['disk', 'id']);
            $table->index(['disk', 'released_at'], 'idx_legal_holds_disk_released_at');
        });
    }

    public function down(): void
    {
        $connection = config('fluxfiles.db_connection');

        Schema::connection($connection)->dropIfExists('fluxfiles_legal_holds');
    }
};
