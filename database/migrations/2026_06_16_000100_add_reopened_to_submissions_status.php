<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // MySQL-specific: alter the ENUM to include 'reopened'
        DB::statement("ALTER TABLE submissions MODIFY COLUMN status ENUM('draft', 'submitted', 'graded', 'returned', 'reopened') DEFAULT 'submitted'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("ALTER TABLE submissions MODIFY COLUMN status ENUM('draft', 'submitted', 'graded', 'returned') DEFAULT 'submitted'");
    }
};
