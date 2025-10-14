<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use Martingalian\Core\Database\Seeders\StepsDispatcherSeeder;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('steps', function (Blueprint $table) {
            $table->string('group')->nullable()->after('type');
            $table->index(['group', 'state', 'dispatch_after'], 'steps_group_state_dispatch_after_idx');
        });

        Schema::table('steps_dispatcher', function (Blueprint $table) {
            $table->string('group')->nullable()->after('id');
            $table->timestamp('last_tick_completed')->nullable()->after('current_tick_id');
            $table->index('last_tick_completed', 'steps_dispatcher_last_tick_completed_idx');
        });

        Schema::table('steps_dispatcher_ticks', function (Blueprint $table) {
            $table->string('group')->nullable()->after('id');
        });

        Artisan::call('db:seed', [
            '--class' => StepsDispatcherSeeder::class,
        ]);
    }
};
