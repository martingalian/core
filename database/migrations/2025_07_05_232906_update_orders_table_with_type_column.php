<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('position_side')
                ->nullable()
                ->comment('Used to define the type of position direction, for the hybrid hedging strategy')
                ->after('side');
        });

        Schema::table('positions', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
