<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Files attached to chat messages. Kept for good, like the messages themselves,
 * on the private disk: only someone who may read the message may open its files.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained('messages');
            $table->string('name', 200);
            $table->string('path', 300);
            $table->string('mime', 100);
            $table->unsignedInteger('size');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_files');
    }
};
