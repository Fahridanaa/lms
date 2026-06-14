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
        Schema::create('file_records', function (Blueprint $table) {
            $table->id();
            $table->string('owner_type', 64);
            $table->unsignedBigInteger('owner_id');
            $table->foreignId('uploader_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('component', 64)->nullable();
            $table->string('file_path', 512);
            $table->string('mime_type', 128)->nullable();
            $table->unsignedBigInteger('file_size')->default(0);
            $table->string('checksum', 64)->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->boolean('visible')->default(true);
            $table->timestamps();

            $table->index(['owner_type', 'owner_id']);
            $table->index(['uploader_id']);
            $table->index('checksum');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('file_records');
    }
};
