<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grade_category_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grade_category_id')->constrained()->cascadeOnDelete();
            $table->string('action'); // created, updated, deleted
            $table->string('old_name', 255)->nullable();
            $table->string('new_name', 255)->nullable();
            $table->decimal('old_weight', 10, 4)->nullable();
            $table->decimal('new_weight', 10, 4)->nullable();
            $table->boolean('old_hidden')->nullable();
            $table->boolean('new_hidden')->nullable();
            $table->string('old_aggregation_method', 50)->nullable();
            $table->string('new_aggregation_method', 50)->nullable();
            $table->foreignId('old_parent_id')->nullable()->constrained('grade_categories')->nullOnDelete();
            $table->foreignId('new_parent_id')->nullable()->constrained('grade_categories')->nullOnDelete();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['grade_category_id', 'action'], 'grade_cat_histories_cat_action_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grade_category_histories');
    }
};
