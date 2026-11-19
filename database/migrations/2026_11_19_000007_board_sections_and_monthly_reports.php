<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The IT board gets the requirement the IT Manager writes once a request is approved;
 * managers get one improvement report per month each, kept for good.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ideas', function (Blueprint $table) {
            $table->longText('requirement')->nullable();
            $table->timestamp('done_at')->nullable();
        });

        Schema::create('monthly_reports', function (Blueprint $table) {
            $table->id();
            $table->string('agent', 40);
            $table->string('month', 7);
            $table->string('title', 200);
            $table->longText('body');
            $table->timestamps();
            $table->unique(['agent', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monthly_reports');
        Schema::table('ideas', function (Blueprint $table) {
            $table->dropColumn(['requirement', 'done_at']);
        });
    }
};
