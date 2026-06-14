<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('module_availability_rules', function (Blueprint $table) {
            $table->foreign('grade_item_id')
                ->references('id')
                ->on('grade_items')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('module_availability_rules', function (Blueprint $table) {
            $table->dropForeign(['grade_item_id']);
        });
    }
};
