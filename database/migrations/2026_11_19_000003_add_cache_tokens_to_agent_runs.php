<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prompt caching bills reading from and writing to the cache at their own prices, so
 * each run records them apart from ordinary input and output.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_runs', function (Blueprint $table) {
            $table->unsignedInteger('cache_write_tokens')->default(0);
            $table->unsignedInteger('cache_read_tokens')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('agent_runs', function (Blueprint $table) {
            $table->dropColumn(['cache_write_tokens', 'cache_read_tokens']);
        });
    }
};
