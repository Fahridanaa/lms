<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assignment_allocated_markers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assignment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('submission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('marker_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['submission_id', 'marker_id'], 'allocated_markers_sub_marker_unique');
            $table->index('marker_id', 'allocated_markers_marker_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assignment_allocated_markers');
    }
};
