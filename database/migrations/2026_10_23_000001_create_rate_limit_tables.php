<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rate_buckets', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->float('capacity');
            $table->float('refill_per_sec');
            $table->float('tokens');
            $table->double('updated_at');
            $table->double('blocked_until')->nullable();
            $table->unsignedInteger('learned_limit')->nullable();
            $table->unsignedInteger('strikes')->default(0);
            $table->boolean('probing')->default(false);
            $table->double('adjusted_at')->default(0);
        });

        Schema::create('api_usage', function (Blueprint $table) {
            $table->unsignedBigInteger('minute');
            $table->string('bucket');
            $table->unsignedInteger('requests')->default(0);
            $table->unsignedInteger('http_429')->default(0);
            $table->unsignedInteger('http_403')->default(0);
            $table->primary(['minute', 'bucket']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_usage');
        Schema::dropIfExists('rate_buckets');
    }
};
