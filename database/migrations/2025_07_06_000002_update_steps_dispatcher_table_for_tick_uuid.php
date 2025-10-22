<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('steps_dispatcher', function (Blueprint $table) {
            $table->unsignedBigInteger('current_tick_id')->nullable()->comment('Holds active tick during dispatch');
        });
    }
};
