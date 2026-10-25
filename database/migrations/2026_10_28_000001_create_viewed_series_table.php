<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 6 (D41/D42): "You may still be interested" row. One row per series whose Detail
     * screen was opened, kept separate from `collections` (favorited) and `reading_progress`
     * (read) — a series only lands here from a glance, not an action. `pinned` (D30 Phase 6
     * row) rides along on `collections` since pin/unpin is a Library-grid action on an
     * already-favorited-or-read series, not a new concept of its own.
     */
    public function up(): void
    {
        Schema::create('viewed_series', function (Blueprint $table) {
            $table->uuid('manga_id')->primary();
            $table->timestamp('first_viewed_at');
            $table->timestamp('last_viewed_at');
            $table->index('last_viewed_at');
        });

        if (! Schema::hasColumn('collections', 'pinned')) {
            Schema::table('collections', function (Blueprint $table) {
                $table->boolean('pinned')->default(false)->after('favorite');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('viewed_series');

        if (Schema::hasColumn('collections', 'pinned')) {
            Schema::table('collections', function (Blueprint $table) {
                $table->dropColumn('pinned');
            });
        }
    }
};
