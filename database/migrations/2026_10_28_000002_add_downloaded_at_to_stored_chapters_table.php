<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 6 "Recently downloaded" sort: when a chapter's status first turns `done`,
     * distinct from `last_opened_at` (reading, not downloading).
     */
    public function up(): void
    {
        Schema::table('stored_chapters', function (Blueprint $table) {
            $table->timestamp('downloaded_at')->nullable()->after('last_opened_at');
        });
    }

    public function down(): void
    {
        Schema::table('stored_chapters', function (Blueprint $table) {
            $table->dropColumn('downloaded_at');
        });
    }
};
