<?php

declare(strict_types=1);

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
            $table->renameColumn('closing_source', 'closed_by');
        });

        Schema::table('positions', function (Blueprint $table) {
            $table->string('waped_by')
                ->nullable()
                ->after('waped_at');
        });
    }
};
