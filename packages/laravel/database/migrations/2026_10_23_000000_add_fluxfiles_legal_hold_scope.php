<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirrors packages/core/db/migrations/0009_add_legal_hold_scope.sql — the scope
 * of the token that placed a legal hold, so a narrower-scoped token can't
 * release a hold an admin placed (legal-hold module ≥ 1.1.0).
 */
return new class extends Migration
{
    public function up(): void
    {
        $connection = config('fluxfiles.db_connection');

        Schema::connection($connection)->table('fluxfiles_legal_holds', function (Blueprint $table) {
            $table->text('placed_by_scope')->nullable();
        });
    }

    public function down(): void
    {
        $connection = config('fluxfiles.db_connection');

        Schema::connection($connection)->table('fluxfiles_legal_holds', function (Blueprint $table) {
            $table->dropColumn('placed_by_scope');
        });
    }
};
