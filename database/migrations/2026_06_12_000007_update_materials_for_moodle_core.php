<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('materials', function (Blueprint $table) {
            $table->string('mime_type')->nullable()->after('type');
            $table->unsignedInteger('revision')->default(1)->after('mime_type');
            $table->string('checksum')->nullable()->after('revision');
            $table->index(['course_id', 'is_active'], 'materials_course_active_index');
            $table->index('revision', 'materials_revision_index');
        });
    }

    public function down(): void
    {
        Schema::table('materials', function (Blueprint $table) {
            $table->dropIndex('materials_course_active_index');
            $table->dropIndex('materials_revision_index');
            $table->dropColumn(['mime_type', 'revision', 'checksum']);
        });
    }
};
