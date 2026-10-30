<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The rate limiter's hits table for FLUXFILES_STORAGE_BACKEND=db.
 *
 * Named `fluxfiles_rate_limits` like every other adapter table, so it can never
 * collide with a host-app table called `rate_limits`. The controller hands core's
 * `Db\RateLimiterDbStorage` the connection prefix + this name. Only this table is
 * ever created or dropped here.
 */
return new class extends Migration
{
    public function up(): void
    {
        $connection = config('fluxfiles.db_connection');
        if (Schema::connection($connection)->hasTable('fluxfiles_rate_limits')) {
            return;
        }

        Schema::connection($connection)->create('fluxfiles_rate_limits', function (Blueprint $table) {
            $table->id();
            $table->string('identifier', 191);
            $table->string('bucket', 16);
            $table->bigInteger('ts');

            $table->index(['identifier', 'bucket', 'ts'], 'idx_fxrl_identifier_bucket_ts');
        });
    }

    public function down(): void
    {
        Schema::connection(config('fluxfiles.db_connection'))->dropIfExists('fluxfiles_rate_limits');
    }
};
