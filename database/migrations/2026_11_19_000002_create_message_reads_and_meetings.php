<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The team meeting in the Everyone chat: every agent reads the owner's message (a read
 * receipt, kept for good) and takes its turn to answer, argue, or simply mark it read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('messages');
            $table->string('agent', 40);
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['message_id', 'agent']);
        });

        Schema::table('agent_runs', function (Blueprint $table) {
            $table->boolean('meeting')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('agent_runs', function (Blueprint $table) {
            $table->dropColumn('meeting');
        });
        Schema::dropIfExists('message_reads');
    }
};
