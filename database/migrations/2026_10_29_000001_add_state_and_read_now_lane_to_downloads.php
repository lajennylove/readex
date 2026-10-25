<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Unified Downloads controller (Plan 21 / feature/0021):
 *
 * 1) `downloads.state` is the explicit state machine the queue walks:
 *    queued → downloading → done, with paused / canceling branches for user action.
 *    Backfill: already-finished rows become `done`; everything else resets to `queued`
 *    (stale `started_at IS NOT NULL AND finished_at IS NULL` half-states are cleared so
 *    the chain picks them up on the next drain).
 *
 * 2) `read_now_lane` is the comodin: a singleton row (id = 1) whose nullable chapter_id
 *    tracks the one chapter the user tapped Read on and that we are fetching at
 *    Priority::Interactive, in parallel with the background lane. Null = lane idle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('downloads', function (Blueprint $table) {
            $table->string('state')->default('queued')->after('priority');
            $table->index('state');
        });

        // Backfill: clear stale half-states (started but not finished), then set state
        // from the existing timestamp columns.
        DB::table('downloads')
            ->whereNotNull('started_at')
            ->whereNull('finished_at')
            ->update(['started_at' => null]);

        DB::table('downloads')
            ->whereNotNull('started_at')
            ->whereNotNull('finished_at')
            ->update(['state' => 'done']);

        DB::table('downloads')
            ->where(function ($q) {
                $q->whereNull('started_at')->orWhereNull('finished_at');
            })
            ->whereNotIn('state', ['done'])
            ->update(['state' => 'queued']);

        Schema::create('read_now_lane', function (Blueprint $table) {
            // Singleton: exactly one row with id = 1 lives in this table.
            $table->unsignedTinyInteger('id')->primary();
            $table->uuid('chapter_id')->nullable();
            $table->timestamp('started_at')->nullable();
        });

        DB::table('read_now_lane')->insert([
            'id' => 1,
            'chapter_id' => null,
            'started_at' => null,
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('read_now_lane');

        Schema::table('downloads', function (Blueprint $table) {
            $table->dropIndex(['state']);
            $table->dropColumn('state');
        });
    }
};
