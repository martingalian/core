<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Removed - indexes moved to 2025_10_03_223438_add_deletion_process_indexes.php
     * to avoid duplication.
     */
    public function up(): void
    {
        // All indexes from this migration are now in 2025_10_03_223438
        // which has better naming conventions (idx_p_* prefix)
    }
};
