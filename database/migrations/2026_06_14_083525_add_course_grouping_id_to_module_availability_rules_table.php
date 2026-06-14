<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table("module_availability_rules", function (Blueprint $table) {
            $table->foreignId("course_grouping_id")
                ->nullable()
                ->after("course_group_id")
                ->constrained("course_groupings")
                ->nullOnDelete();

            $table->index("course_grouping_id");
        });
    }

    public function down(): void
    {
        Schema::table("module_availability_rules", function (Blueprint $table) {
            $table->dropForeign(["course_grouping_id"]);
            $table->dropIndex(["course_grouping_id"]);
            $table->dropColumn("course_grouping_id");
        });
    }
};
