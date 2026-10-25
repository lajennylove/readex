<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 8 (Plan-08 §2, D45 §2): one row per configured content source. The built-in
     * row is seeded once by SourceRepository::seedBuiltIn(), is never editable and
     * never deletable (a user may only block/enable it). A user-added source's `config` is a
     * generic JSON blob (base URL / API endpoint, optional credentials) — never a pre-filled
     * or suggested value for any specific third-party site (D45 §2).
     */
    public function up(): void
    {
        Schema::create('sources', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->string('label');
            $table->string('kind'); // 'builtin' | 'user'
            $table->boolean('enabled')->default(true);
            $table->boolean('blocked')->default(false);
            $table->json('config')->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sources');
    }
};
