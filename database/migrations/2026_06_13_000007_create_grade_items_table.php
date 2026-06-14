<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grade_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->string('item_type', 32); // assignment, quiz, manual
            $table->unsignedBigInteger('item_id')->nullable(); // FK to assignments or quizzes
            $table->string('name', 255);
            $table->decimal('max_score', 10, 2)->default(100);
            $table->decimal('pass_score', 10, 2)->nullable();
            $table->decimal('weight', 8, 4)->default(1.0000);
            $table->boolean('hidden')->default(false);
            $table->boolean('locked')->default(false);
            $table->string('source', 64)->nullable(); // assignment, quiz, manual
            $table->timestamps();

            $table->unique(['course_id', 'item_type', 'item_id']);
            $table->index(['course_id', 'hidden']);
            $table->index(['course_id', 'item_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grade_items');
    }
};
