<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Feature/0035 — ReaDex becomes truly agnostic. The user's one content source
 * URL moves to a single `settings` row (`source_url`). The multi-row `sources`
 * table with its builtin/user kinds, enable/block toggles and health-checked
 * add flow all go away: there is no list, there is one URL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('sources');
    }

    public function down(): void
    {
        Schema::create('sources', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->string('label');
            $table->string('kind');
            $table->boolean('enabled')->default(true);
            $table->boolean('blocked')->default(false);
            $table->json('config')->nullable();
            $table->timestamp('created_at');
        });
    }
};
