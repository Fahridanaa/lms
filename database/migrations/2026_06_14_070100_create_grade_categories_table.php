<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grade_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('grade_categories')->nullOnDelete();
            $table->string('name');
            $table->unsignedInteger('depth')->default(0);
            $table->string('path')->nullable();
            $table->string('aggregation_method')->default('weighted_mean');
            $table->decimal('weight', 10, 4)->default(1.0);
            $table->boolean('hidden')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grade_categories');
    }
};
