<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The website plan: every page of a website the IT Manager plans, with what it
 * shows and who it is for, approved by the owner page by page, then tracked
 * through building to going live.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_pages', function (Blueprint $table) {
            $table->id();
            $table->string('title', 150);
            $table->string('path', 150)->nullable();
            $table->text('purpose');
            $table->text('content');
            $table->string('audience', 150)->nullable();
            $table->string('status', 20)->default('planned')->index();
            $table->text('owner_note')->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });

        // The project as the owner described it, so the IT Manager plans the right thing.
        $owner = User::where('is_owner', true)->orderBy('id')->first();
        if ($owner !== null && ! DB::table('facts')->where('text', 'like', 'Gulf website project:%')->exists()) {
            DB::table('facts')->insert([
                'text' => 'Gulf website project: a new public website where customers across the Gulf book a limousine and pay online, and each booking lands in the ERP. The IT Manager plans every page; the owner approves page by page; Claude Code builds it from the approved plan. It stays hidden (password, not on Google) until the owner says it is ready.',
                'agents' => json_encode(['it', 'pln', 'mkt', 'limo']),
                'confirmed_by' => $owner->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_pages');
    }
};
