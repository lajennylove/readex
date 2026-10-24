<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The download queue (spec §11.3), shared by the UI, the queue worker and the async lane:
     * one row per chapter asked for. `batch` groups a Download all (null: one tapped Download),
     * `position` is its reading order, `started_at` is set when its DownloadChapter is
     * dispatched (null: queued behind the batch's current chapter), `finished_at` when it ended
     * (done, unavailable or failed for good). Stop deletes the row.
     */
    public function up(): void
    {
        Schema::create('downloads', function (Blueprint $table) {
            $table->uuid('chapter_id')->primary();
            $table->uuid('manga_id')->index();
            $table->string('batch')->nullable()->index();
            $table->unsignedInteger('position')->default(0);
            $table->string('priority');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('downloads');
    }
};
