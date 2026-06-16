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
        Schema::create('gradebook_recalculations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('course_id');
            $table->string('reason', 100);
            $table->string('source_type', 50);
            $table->unsignedBigInteger('source_id');
            $table->timestamp('marked_at')->nullable();
            $table->timestamp('recalculated_at')->nullable();
            $table->timestamps();

            $table->unique('course_id');

            $table->foreign('course_id')->references('id')->on('courses')->onDelete('cascade');
            $table->index('marked_at');
            $table->index('recalculated_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('gradebook_recalculations');
    }
};
