<?php

declare(strict_types=1);

// database/migrations/2025_09_14_000000_create_fundings_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fundings', function (Blueprint $table) {
            $table->id();

            $table->string('type')->index();

            $table->decimal('amount', 10, 2);

            $table->timestamp('date_value')->index();

            $table->timestamps();
        });
    }
};
