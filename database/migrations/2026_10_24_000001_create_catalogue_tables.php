<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tags', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('group');
            $table->json('name');
            $table->timestamp('fetched_at')->nullable();
        });

        Schema::create('manga', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->json('title');
            $table->json('alt_titles');
            $table->json('description');
            $table->string('status')->nullable();
            $table->unsignedSmallInteger('year')->nullable();
            $table->string('content_rating');
            $table->json('available_languages');
            $table->string('original_language')->nullable();
            $table->json('tag_ids');
            $table->string('cover_file')->nullable();
            $table->timestamp('fetched_at')->nullable();
            // Set even when MangaDex has no recommendations, so an empty row stays fresh.
            $table->timestamp('recommendations_fetched_at')->nullable();
        });

        Schema::create('manga_lists', function (Blueprint $table) {
            $table->string('list_key');
            $table->unsignedInteger('batch');
            $table->unsignedInteger('position');
            $table->uuid('manga_id');
            // Latest updates only: the chapter the tile opens.
            $table->uuid('chapter_id')->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->primary(['list_key', 'position']);
            $table->unique(['list_key', 'manga_id']);
            $table->index('manga_id');
        });

        Schema::create('chapters', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('manga_id');
            $table->string('volume')->nullable();
            $table->string('number')->nullable();
            $table->string('title')->nullable();
            $table->string('language');
            $table->uuid('group_id')->nullable();
            $table->string('group_name')->nullable();
            $table->unsignedInteger('pages')->default(0);
            $table->string('external_url')->nullable();
            $table->timestamp('readable_at')->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->index(['manga_id', 'language']);
        });

        Schema::create('chapter_feeds', function (Blueprint $table) {
            $table->uuid('manga_id');
            $table->string('language');
            $table->unsignedInteger('total')->default(0);
            $table->timestamp('fetched_at')->nullable();
            $table->primary(['manga_id', 'language']);
        });

        Schema::create('statistics', function (Blueprint $table) {
            $table->uuid('manga_id')->primary();
            $table->float('rating_avg')->nullable();
            $table->float('rating_bayesian')->nullable();
            $table->unsignedInteger('follows')->default(0);
            // Null until the one-manga call; the batched call has no distribution.
            $table->json('distribution')->nullable();
            $table->timestamp('fetched_at')->nullable();
        });

        Schema::create('recommendations', function (Blueprint $table) {
            $table->uuid('manga_id');
            $table->uuid('recommended_id');
            $table->unsignedInteger('position');
            $table->float('score');
            $table->timestamp('fetched_at');
            $table->primary(['manga_id', 'recommended_id']);
        });

        Schema::create('at_home', function (Blueprint $table) {
            $table->uuid('chapter_id')->primary();
            $table->string('base_url');
            $table->string('hash');
            $table->json('data_files');
            $table->json('data_saver_files');
            $table->timestamp('fetched_at');
        });
    }

    public function down(): void
    {
        foreach (['at_home', 'recommendations', 'statistics', 'chapter_feeds', 'chapters', 'manga_lists', 'manga', 'tags'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
