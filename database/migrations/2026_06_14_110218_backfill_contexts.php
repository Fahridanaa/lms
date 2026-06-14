<?php

use App\Models\Context;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\LearningModule;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Scans existing courses and learning_modules, creates context records
     * for any that don't have one. Also creates role_assignments from existing
     * course_enrollments and course.instructor_id.
     *
     * This migration is idempotent: skips records that already have matching contexts.
     */
    public function up(): void
    {
        // Find or create system context
        $systemContext = Context::query()
            ->where('contextlevel', Context::LEVEL_SYSTEM)
            ->where('instance_id', 0)
            ->first();

        if ($systemContext === null) {
            $systemContext = Context::query()->create([
                'contextlevel' => Context::LEVEL_SYSTEM,
                'instance_id' => 0,
                'path' => '/1',
                'depth' => 0,
            ]);
        }

        // Get roles
        $studentRole = Role::query()->where('shortname', 'student')->first();
        $instructorRole = Role::query()->where('shortname', 'instructor')->first();
        $managerRole = Role::query()->where('shortname', 'manager')->first();

        // Assign at least one manager at system context
        if ($managerRole !== null) {
            $existingManagerAssignment = RoleAssignment::query()
                ->where('role_id', $managerRole->id)
                ->where('context_id', $systemContext->id)
                ->exists();

            if (! $existingManagerAssignment) {
                // Pick the first user as manager assignee (or use user ID 1)
                $managerUser = User::query()->orderBy('id')->first();
                if ($managerUser !== null) {
                    RoleAssignment::query()->firstOrCreate([
                        'role_id' => $managerRole->id,
                        'context_id' => $systemContext->id,
                        'user_id' => $managerUser->id,
                    ]);
                }
            }
        }

        // Create course contexts
        $courses = Course::query()->get();
        foreach ($courses as $course) {
            $existingContext = Context::query()
                ->where('contextlevel', Context::LEVEL_COURSE)
                ->where('instance_id', $course->id)
                ->first();

            if ($existingContext === null) {
                $courseContext = Context::query()->create([
                    'contextlevel' => Context::LEVEL_COURSE,
                    'instance_id' => $course->id,
                    'path' => '/1/'.$course->id,
                    'depth' => 1,
                ]);
            } else {
                $courseContext = $existingContext;
            }

            // Create role_assignments from course_enrollments (students)
            if ($studentRole !== null) {
                $enrollments = CourseEnrollment::query()
                    ->where('course_id', $course->id)
                    ->where('role', 'student')
                    ->get();

                foreach ($enrollments as $enrollment) {
                    RoleAssignment::query()->firstOrCreate([
                        'role_id' => $studentRole->id,
                        'context_id' => $courseContext->id,
                        'user_id' => $enrollment->user_id,
                    ]);
                }
            }

            // Create role_assignment from course.instructor_id
            if ($instructorRole !== null && $course->instructor_id !== null) {
                RoleAssignment::query()->firstOrCreate([
                    'role_id' => $instructorRole->id,
                    'context_id' => $courseContext->id,
                    'user_id' => $course->instructor_id,
                ]);
            }

            // Create learning module contexts
            $modules = LearningModule::query()->where('course_id', $course->id)->get();
            foreach ($modules as $module) {
                $existingModuleContext = Context::query()
                    ->where('contextlevel', Context::LEVEL_MODULE)
                    ->where('instance_id', $module->id)
                    ->first();

                if ($existingModuleContext === null) {
                    Context::query()->create([
                        'contextlevel' => Context::LEVEL_MODULE,
                        'instance_id' => $module->id,
                        'path' => '/1/'.$course->id.'/'.$module->id,
                        'depth' => 2,
                    ]);
                }
            }
        }
    }

    /**
     * Reverse the migration — remove all backfilled context data.
     *
     * Only removes contexts and role_assignments that were created by the
     * backfill, which is tricky to distinguish. This simply removes all
     * course-level and module-level contexts, and all role_assignments.
     */
    public function down(): void
    {
        // Only remove what was created by this backfill:
        // - All course contexts (level 50) and their role_assignments
        // - All module contexts (level 70)
        $courseContextIds = Context::query()
            ->where('contextlevel', Context::LEVEL_COURSE)
            ->pluck('id');

        $moduleContextIds = Context::query()
            ->where('contextlevel', Context::LEVEL_MODULE)
            ->pluck('id');

        $allContextIds = $courseContextIds->merge($moduleContextIds);

        if ($allContextIds->isNotEmpty()) {
            RoleAssignment::query()->whereIn('context_id', $allContextIds)->delete();
            Context::query()->whereIn('id', $allContextIds)->delete();
        }
    }
};
