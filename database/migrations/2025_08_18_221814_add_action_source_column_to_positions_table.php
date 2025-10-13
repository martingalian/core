<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->string('closing_source')
                ->nullable()
                ->comment('Who was the source (user data stream, or watcher) to apply the closing action on this position')
                ->after('was_fast_traded');
        });
    }
};
