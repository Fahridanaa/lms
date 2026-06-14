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
        Schema::create('course_grouping_groups', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('course_grouping_id');
            $table->unsignedBigInteger('course_group_id');
            $table->timestamps();

            $table->foreign('course_grouping_id')->references('id')->on('course_groupings')->onDelete('cascade');
            $table->foreign('course_group_id')->references('id')->on('course_groups')->onDelete('cascade');
            $table->unique(['course_grouping_id', 'course_group_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('course_grouping_groups');
    }
};
