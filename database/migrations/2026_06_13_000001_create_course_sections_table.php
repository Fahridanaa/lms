<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->string('title', 255);
            $table->text('summary')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('visible')->default(true);
            $table->timestamps();

            $table->index(['course_id', 'visible', 'sort_order']);
            $table->index(['course_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_sections');
    }
};
