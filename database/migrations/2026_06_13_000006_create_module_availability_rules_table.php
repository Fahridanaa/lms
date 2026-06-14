<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('module_availability_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('learning_module_id')->constrained()->cascadeOnDelete();
            $table->string('rule_type', 32);
            $table->foreignId('required_module_id')->nullable()->constrained('learning_modules')->nullOnDelete();
            $table->unsignedBigInteger('grade_item_id')->nullable()->index();
            $table->foreignId('course_group_id')->nullable()->constrained()->nullOnDelete();
            $table->string('operator', 32)->nullable();
            $table->string('value', 255)->nullable();
            $table->timestamps();

            $table->index(['learning_module_id', 'rule_type']);
            $table->index('rule_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_availability_rules');
    }
};
