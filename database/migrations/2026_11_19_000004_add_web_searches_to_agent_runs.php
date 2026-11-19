<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Web searches are billed per search on top of tokens, so each run counts them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_runs', function (Blueprint $table) {
            $table->unsignedSmallInteger('web_searches')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('agent_runs', function (Blueprint $table) {
            $table->dropColumn('web_searches');
        });
    }
};
