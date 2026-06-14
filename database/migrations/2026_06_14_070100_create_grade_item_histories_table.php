<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grade_item_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grade_item_id')->constrained()->cascadeOnDelete();
            $table->string('action'); // created, updated, deleted
            $table->string('old_name', 255)->nullable();
            $table->string('new_name', 255)->nullable();
            $table->decimal('old_max_score', 10, 2)->nullable();
            $table->decimal('new_max_score', 10, 2)->nullable();
            $table->decimal('old_pass_score', 10, 2)->nullable();
            $table->decimal('new_pass_score', 10, 2)->nullable();
            $table->decimal('old_weight', 10, 4)->nullable();
            $table->decimal('new_weight', 10, 4)->nullable();
            $table->boolean('old_hidden')->nullable();
            $table->boolean('new_hidden')->nullable();
            $table->boolean('old_locked')->nullable();
            $table->boolean('new_locked')->nullable();
            $table->foreignId('old_category_id')->nullable()->constrained('grade_categories')->nullOnDelete();
            $table->foreignId('new_category_id')->nullable()->constrained('grade_categories')->nullOnDelete();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['grade_item_id', 'action'], 'grade_item_histories_item_action_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grade_item_histories');
    }
};
