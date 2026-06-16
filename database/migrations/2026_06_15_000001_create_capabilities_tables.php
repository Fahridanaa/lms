<?php

use App\Models\Capability;
use App\Models\Role;
use App\Models\RoleCapability;
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
        Schema::create('capabilities', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('shortname', 100)->unique();
            $table->timestamps();
        });

        Schema::create('role_capabilities', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('capability_id');
            $table->timestamps();

            $table->unique(['role_id', 'capability_id']);

            $table->foreign('role_id')->references('id')->on('roles')->onDelete('cascade');
            $table->foreign('capability_id')->references('id')->on('capabilities')->onDelete('cascade');
        });

        // Seed fixed capability set
        $caps = [
            ['name' => 'View Course', 'shortname' => 'course:view'],
            ['name' => 'View Module', 'shortname' => 'module:view'],
            ['name' => 'Ignore Module Availability', 'shortname' => 'module:ignore-availability'],
            ['name' => 'View Quiz', 'shortname' => 'quiz:view'],
            ['name' => 'Attempt Quiz', 'shortname' => 'quiz:attempt'],
            ['name' => 'View Assignment', 'shortname' => 'assignment:view'],
            ['name' => 'Submit Assignment', 'shortname' => 'assignment:submit'],
            ['name' => 'Grade Assignment', 'shortname' => 'assignment:grade'],
            ['name' => 'View Gradebook', 'shortname' => 'gradebook:view'],
            ['name' => 'Update Grade', 'shortname' => 'grade:update'],
            ['name' => 'View Completion', 'shortname' => 'completion:view'],
        ];

        $capabilityIds = [];
        foreach ($caps as $c) {
            $capabilityIds[$c['shortname']] = Capability::create($c)->id;
        }

        // Role-capability mappings
        $managerRole = Role::where('shortname', 'manager')->first();
        $instructorRole = Role::where('shortname', 'instructor')->first();
        $studentRole = Role::where('shortname', 'student')->first();

        if ($managerRole) {
            // Manager gets ALL capabilities
            foreach ($capabilityIds as $capId) {
                RoleCapability::create([
                    'role_id' => $managerRole->id,
                    'capability_id' => $capId,
                ]);
            }
        }

        if ($instructorRole) {
            // Instructor gets all except quiz:attempt, assignment:submit
            $excludedForInstructor = ['quiz:attempt', 'assignment:submit'];
            // Plus module:ignore-availability
            foreach ($caps as $c) {
                if (in_array($c['shortname'], $excludedForInstructor)) {
                    continue;
                }
                RoleCapability::create([
                    'role_id' => $instructorRole->id,
                    'capability_id' => $capabilityIds[$c['shortname']],
                ]);
            }
        }

        if ($studentRole) {
            // Student gets: course:view, module:view, quiz:view, quiz:attempt,
            // assignment:view, assignment:submit, completion:view
            $studentCaps = [
                'course:view', 'module:view', 'quiz:view', 'quiz:attempt',
                'assignment:view', 'assignment:submit', 'completion:view',
            ];
            foreach ($studentCaps as $sc) {
                RoleCapability::create([
                    'role_id' => $studentRole->id,
                    'capability_id' => $capabilityIds[$sc],
                ]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('role_capabilities');
        Schema::dropIfExists('capabilities');
    }
};
