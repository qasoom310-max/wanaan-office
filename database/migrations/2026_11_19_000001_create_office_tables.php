<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The office: agent names, chat messages (append-only, never deleted), each agent's
 * working runs (what the desks show while they work), facts, questions, the IT board
 * and settings such as the AI key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_names', function (Blueprint $table) {
            $table->id();
            $table->string('agent', 40)->unique();
            $table->string('name', 40);
            $table->foreignId('named_by')->constrained('users');
            $table->timestamps();
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            // "secretary", "everyone" or "direct:<agent>".
            $table->string('channel', 60)->index();
            // Whose private conversation this is; null for the shared Everyone chat.
            $table->foreignId('owner_id')->nullable()->constrained('users');
            // Who wrote it: a user (user_id) or an agent (agent).
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->string('agent', 40)->nullable();
            $table->text('body');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['channel', 'owner_id', 'id']);
        });

        Schema::create('agent_runs', function (Blueprint $table) {
            $table->id();
            $table->string('agent', 40)->index();
            $table->string('channel', 60);
            $table->foreignId('owner_id')->nullable()->constrained('users');
            $table->foreignId('message_id')->nullable()->constrained('messages');
            $table->string('status', 20)->default('working')->index();
            $table->string('step', 200)->nullable();
            $table->unsignedTinyInteger('step_number')->default(0);
            $table->unsignedTinyInteger('step_total')->default(1);
            $table->text('error')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->timestamps();
        });

        Schema::create('facts', function (Blueprint $table) {
            $table->id();
            $table->text('text');
            $table->json('agents')->nullable();
            $table->foreignId('confirmed_by')->constrained('users');
            $table->timestamps();
        });

        Schema::create('questions', function (Blueprint $table) {
            $table->id();
            $table->string('agent', 40)->index();
            $table->text('question');
            $table->text('why');
            $table->text('answer')->nullable();
            $table->foreignId('answered_by')->nullable()->constrained('users');
            $table->timestamp('answered_at')->nullable();
            $table->foreignId('fact_id')->nullable()->constrained('facts');
            $table->timestamps();
        });

        Schema::create('ideas', function (Blueprint $table) {
            $table->id();
            $table->string('agent', 40);
            $table->string('title', 200);
            $table->text('why');
            $table->string('size', 10);
            $table->string('status', 10)->default('wait')->index();
            $table->string('note', 200)->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users');
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->string('key', 60)->primary();
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['settings', 'ideas', 'questions', 'facts', 'agent_runs', 'messages', 'agent_names'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
