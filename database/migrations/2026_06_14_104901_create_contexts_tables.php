<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('contexts', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('contextlevel');
            $table->unsignedBigInteger('instance_id');
            $table->string('path', 255);
            $table->unsignedSmallInteger('depth');
            $table->timestamps();

            $table->unique(['contextlevel', 'instance_id']);
            $table->index('path');
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('shortname', 100)->unique();
            $table->string('archetype', 30);
            $table->timestamps();
        });

        Schema::create('role_assignments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('context_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamps();

            $table->unique(['role_id', 'context_id', 'user_id']);
            $table->index(['user_id', 'context_id']);

            $table->foreign('role_id')->references('id')->on('roles')->onDelete('cascade');
            $table->foreign('context_id')->references('id')->on('contexts')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });

        // Seed default roles
        DB::table('roles')->insert([
            ['name' => 'Manager', 'shortname' => 'manager', 'archetype' => 'manager', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Instructor', 'shortname' => 'instructor', 'archetype' => 'teacher', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Student', 'shortname' => 'student', 'archetype' => 'student', 'created_at' => now(), 'updated_at' => now()],
        ]);

        // Create system context
        DB::table('contexts')->insert([
            'contextlevel' => 10,
            'instance_id' => 0,
            'path' => '/1',
            'depth' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('role_assignments');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('contexts');
    }
};
