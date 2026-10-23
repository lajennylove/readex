<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->json('value');
        });

        Schema::create('collections', function (Blueprint $table) {
            $table->uuid('manga_id')->primary();
            $table->boolean('favorite')->default(false);
            $table->uuid('preferred_group_id')->nullable();
            $table->boolean('paused')->default(false);
            $table->timestamp('created_at');
        });

        Schema::create('reading_progress', function (Blueprint $table) {
            $table->uuid('chapter_id')->primary();
            $table->uuid('manga_id');
            $table->unsignedInteger('page')->default(0);
            $table->unsignedInteger('page_count')->default(0);
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('updated_at');
            $table->index(['manga_id', 'updated_at']);
        });

        Schema::create('stored_chapters', function (Blueprint $table) {
            $table->uuid('chapter_id')->primary();
            $table->string('tier');
            $table->string('quality');
            $table->string('status');
            $table->unsignedInteger('pages_done')->default(0);
            $table->unsignedInteger('page_count')->default(0);
            $table->unsignedBigInteger('bytes')->default(0);
            $table->timestamp('last_opened_at')->nullable();
            $table->text('error')->nullable();
        });
    }

    public function down(): void
    {
        foreach (['stored_chapters', 'reading_progress', 'collections', 'settings'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
