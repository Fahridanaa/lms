<?php

namespace App\Console\Commands;

use App\Models\Assignment;
use App\Models\AssignmentOverride;
use App\Models\Course;
use App\Models\CourseCompletion;
use App\Models\CourseCompletionCriterion;
use App\Models\CourseEnrollment;
use App\Models\CourseGroupingGroup;
use App\Models\CourseGroupMember;
use App\Models\Grade;
use App\Models\GradeItem;
use App\Models\LearningModule;
use App\Models\Material;
use App\Models\ModuleAvailabilityRule;
use App\Models\ModuleCompletion;
use App\Models\Quiz;
use App\Models\QuizGrade;
use App\Models\QuizOverride;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\Submission;
use App\Models\User;
use App\Services\CourseAccessService;
use App\Services\ModuleAvailabilityService;
use Illuminate\Console\Command;

class GenerateK6Fixtures extends Command
{
    protected $signature = 'benchmark:generate-k6-fixtures
        {--output= : Output path for the generated fixtures file (default: tests/Benchmark/k6/fixtures.js)}';

    protected $description = 'Generate relationship-valid k6 fixture data from the seeded database';

    public function handle(): int
    {
        $outputPath = $this->option('output')
            ?: base_path('tests/Benchmark/k6/fixtures.js');

        $this->info('Generating k6 fixtures from database...');

        $instructorRole = Role::where('shortname', 'instructor')->first();
        $studentRole = Role::where('shortname', 'student')->first();

        $instructorIds = [];
        if ($instructorRole) {
            $instructorIds = RoleAssignment::where('role_id', $instructorRole->id)
                ->select('user_id')->distinct()
                ->orderBy('user_id')->pluck('user_id')->values()->all();
        }

        $studentIds = [];
        if ($studentRole) {
            $studentIds = RoleAssignment::where('role_id', $studentRole->id)
                ->select('user_id')->distinct()
                ->orderBy('user_id')->pluck('user_id')->values()->all();
        }

        $allCourseIds = Course::query()
            ->orderBy('id')->pluck('id')->values()->all();

        $activeCourseIds = Course::where('is_active', true)
            ->orderBy('id')->pluck('id')->values()->all();

        $enrolledPairs = CourseEnrollment::where('role', 'student')
            ->where('status', 'active')
            ->whereHas('course', fn ($q) => $q->where('is_active', true))
            ->orderBy('user_id')->orderBy('course_id')
            ->get(['user_id', 'course_id'])
            ->map(fn ($e) => ['studentId' => $e->user_id, 'courseId' => $e->course_id])
            ->values()->all();

        if (empty($enrolledPairs)) {
            $this->error('No active student enrollments found in active courses. Seed the database first.');

            return Command::FAILURE;
        }

        $instructorCoursePairs = Course::where('is_active', true)
            ->orderBy('id')
            ->get()
            ->map(fn ($c) => ['instructorId' => $c->instructor_id, 'courseId' => $c->id])
            ->values()->all();

        $materialByCourse = Material::where('is_active', true)
            ->whereHas('course', fn ($q) => $q->where('is_active', true))
            ->orderBy('id')
            ->get(['id', 'course_id'])
            ->groupBy('course_id')
            ->map(fn ($items) => $items->pluck('id')->values()->all())
            ->toArray();

        $quizByCourse = Quiz::where('is_active', true)
            ->whereHas('course', fn ($q) => $q->where('is_active', true))
            ->orderBy('id')
            ->get(['id', 'course_id'])
            ->groupBy('course_id')
            ->map(fn ($items) => $items->pluck('id')->values()->all())
            ->toArray();

        $assignmentByCourse = Assignment::where('is_active', true)
            ->whereHas('course', fn ($q) => $q->where('is_active', true))
            ->orderBy('id')
            ->get(['id', 'course_id'])
            ->groupBy('course_id')
            ->map(fn ($items) => $items->pluck('id')->values()->all())
            ->toArray();

        // ─── Activity Pool Coverage Validation ────────────────────────────────
        $enrolledCourseIds = collect($enrolledPairs)
            ->pluck('courseId')
            ->unique()
            ->values()
            ->all();

        $activityPools = [
            'MATERIAL_BY_COURSE' => $materialByCourse,
            'QUIZ_BY_COURSE' => $quizByCourse,
            'ASSIGNMENT_BY_COURSE' => $assignmentByCourse,
        ];

        foreach ($activityPools as $poolName => $pool) {
            $missing = [];
            foreach ($enrolledCourseIds as $cid) {
                if (empty($pool[$cid])) {
                    $missing[] = $cid;
                }
            }
            if (! empty($missing)) {
                $this->error(sprintf(
                    'Empty required pool: %s. The following enrolled courses have no active records: %s',
                    $poolName,
                    implode(', ', $missing)
                ));

                return Command::FAILURE;
            }
        }

        $gradingTargets = Submission::where('status', 'graded')
            ->whereHas('assignment.course', fn ($q) => $q->where('is_active', true))
            ->with('assignment:id,course_id')
            ->orderBy('id')
            ->get(['id', 'assignment_id', 'user_id'])
            ->map(function ($s) {
                $course = Course::find($s->assignment->course_id);

                return [
                    'instructorId' => $course->instructor_id,
                    'courseId' => $s->assignment->course_id,
                    'assignmentId' => $s->assignment_id,
                    'submissionId' => $s->id,
                    'studentId' => $s->user_id,
                ];
            })
            ->values()->all();

        if (empty($gradingTargets)) {
            $this->error('Empty required pool: GRADING_TARGETS. No graded submissions found in active courses. Seed graded assignment submissions (status=graded) before generating k6 fixtures.');

            return Command::FAILURE;
        }

        $gradeUpdateTargets = Grade::where('status', 'final')
            ->whereHas('course', fn ($q) => $q->where('is_active', true))
            ->whereHas('gradeItem', fn ($q) => $q->where('locked', false))
            ->with('gradeItem:id,course_id,locked')
            ->orderBy('id')
            ->get(['id', 'course_id', 'user_id', 'grade_item_id', 'max_score'])
            ->map(function ($g) {
                $course = Course::find($g->course_id);

                return [
                    'instructorId' => $course->instructor_id,
                    'courseId' => $g->course_id,
                    'gradeId' => $g->id,
                    'gradeItemId' => $g->grade_item_id,
                    'studentId' => $g->user_id,
                    'maxScore' => (float) $g->max_score,
                ];
            })
            ->values()->all();

        if (empty($gradeUpdateTargets)) {
            $this->error('Empty required pool: GRADE_UPDATE_TARGETS. No unlocked final grades found in active courses. Seed grade items (unlocked) with final grades before generating k6 fixtures.');

            return Command::FAILURE;
        }

        $unauthorizedGradeUpdateTargets = $this->buildUnauthorizedGradeUpdateTargets(
            $gradeUpdateTargets, $activeCourseIds
        );

        if (empty($unauthorizedGradeUpdateTargets)) {
            $this->error('Empty required pool: UNAUTHORIZED_GRADE_UPDATE_TARGETS. No instructor found who is unauthorized for courses with unlocked final grades. Ensure at least one instructor does not own/teach a course that has unlocked grade items with final grades.');

            return Command::FAILURE;
        }

        $suspendedPairs = CourseEnrollment::where('role', 'student')
            ->where('status', 'suspended')
            ->whereHas('course', fn ($q) => $q->where('is_active', true))
            ->orderBy('user_id')->orderBy('course_id')
            ->get(['user_id', 'course_id'])
            ->map(fn ($e) => ['studentId' => $e->user_id, 'courseId' => $e->course_id])
            ->values()->all();

        $nonEnrolledPairs = [];
        $allStudentIds = collect($studentIds);
        foreach ($activeCourseIds as $cid) {
            $enrolledIds = CourseEnrollment::where('course_id', $cid)
                ->where('role', 'student')->pluck('user_id');
            $notEnrolled = $allStudentIds->diff($enrolledIds)->take(2);
            foreach ($notEnrolled as $sid) {
                $nonEnrolledPairs[] = ['studentId' => $sid, 'courseId' => $cid];
            }
            if (count($nonEnrolledPairs) >= 10) {
                break;
            }
        }
        $nonEnrolledPairs = array_slice($nonEnrolledPairs, 0, 10);

        // ─── Purpose-built fixture pools (Plan 003) ──────────────────────────

        $groupRestrictedModuleTargets = $this->buildGroupRestrictedModuleTargets($enrolledPairs);
        $prerequisiteLockedTargets = $this->buildPrerequisiteLockedTargets($enrolledPairs);
        $prerequisiteUnlockTargets = $this->buildPrerequisiteUnlockTargets($enrolledPairs);
        $minGradeLockedTargets = $this->buildMinGradeLockedTargets($enrolledPairs);
        $hiddenModuleTargets = $this->buildHiddenModuleTargets($enrolledPairs);

        // ─── Plan 002: Actor-Aware Readable Target Pools ──────────────────
        $readableMaterialTargets = $this->buildReadableMaterialTargets($enrolledPairs);
        $readableQuizTargets = $this->buildReadableQuizTargets($enrolledPairs);
        $readableAssignmentTargets = $this->buildReadableAssignmentTargets($enrolledPairs);

        if (empty($readableMaterialTargets)) {
            $this->error('Empty required pool: READABLE_MATERIAL_TARGETS. No material targets found that are fully available for enrolled students. Ensure seeded materials have visible, active learning modules with passing availability rules.');

            return Command::FAILURE;
        }
        if (empty($readableQuizTargets)) {
            $this->error('Empty required pool: READABLE_QUIZ_TARGETS. No quiz targets found that are fully available for enrolled students.');

            return Command::FAILURE;
        }
        if (empty($readableAssignmentTargets)) {
            $this->error('Empty required pool: READABLE_ASSIGNMENT_TARGETS. No assignment targets found that are fully available for enrolled students.');

            return Command::FAILURE;
        }

        // ─── Plan 001: Actor-Aware Writable Target Pools ─────────────────
        $writableMaterialDownloadTargets = $this->buildWritableMaterialDownloadTargets($enrolledPairs);
        $writableAssignmentSubmissionTargets = $this->buildWritableAssignmentSubmissionTargets($enrolledPairs);
        $writableQuizAttemptTargets = $this->buildWritableQuizAttemptTargets($enrolledPairs);

        if (empty($writableMaterialDownloadTargets)) {
            $this->error('Empty required pool: WRITABLE_MATERIAL_DOWNLOAD_TARGETS. No downloadable material targets found that are fully available for enrolled students.');

            return Command::FAILURE;
        }
        if (empty($writableAssignmentSubmissionTargets)) {
            $this->error('Empty required pool: WRITABLE_ASSIGNMENT_SUBMISSION_TARGETS. No submittable assignment targets found for enrolled students.');

            return Command::FAILURE;
        }
        if (empty($writableQuizAttemptTargets)) {
            $this->error('Empty required pool: WRITABLE_QUIZ_ATTEMPT_TARGETS. No attempt-startable quiz targets found for enrolled students.');

            return Command::FAILURE;
        }

        $lockedGradeTargets = $this->buildLockedGradeTargets();
        $quizOverrideTargets = $this->buildQuizOverrideTargets();
        $assignmentOverrideTargets = $this->buildAssignmentOverrideTargets();
        $suspendedAccessTargets = $this->buildSuspendedAccessTargets();
        $nonEnrolledAccessTargets = $this->buildNonEnrolledAccessTargets($activeCourseIds, $studentIds);

        // ─── Plan 006: New Benchmark Target Pools ──────────────────────────────
        $quizDetailAttemptTargets = $this->buildQuizDetailAttemptTargets();
        $quizAggregateGradeTargets = $this->buildQuizAggregateGradeTargets();
        $gradeCategoryReadTargets = $this->buildGradeCategoryReadTargets();
        $markerGradeTargets = $this->buildMarkerGradeTargets();
        $groupingRestrictedModuleTargets = $this->buildGroupingRestrictedModuleTargets($enrolledPairs);
        $nestedAvailabilityLockedTargets = $this->buildNestedAvailabilityLockedTargets($enrolledPairs);
        $nestedAvailabilityUnlockTargets = $this->buildNestedAvailabilityUnlockTargets($enrolledPairs);

        // ─── Course Completion Check Targets (Plan 02) ────────────────────────
        $courseCompletionCheckTargets = $this->buildCourseCompletionCheckTargets($enrolledPairs);

        if (empty($courseCompletionCheckTargets)) {
            $this->warn('COURSE_COMPLETION_CHECK_TARGETS is empty. No course completion criteria found for enrolled students.');
        }

        // ─── Plan 003: Learning Module Integrity Validation ────────────────────
        $integrityResult = $this->validateLearningModuleIntegrity(
            ['material', $materialByCourse],
            ['quiz', $quizByCourse],
            ['assignment', $assignmentByCourse],
            ['material', $groupRestrictedModuleTargets],
            ['quiz', $prerequisiteLockedTargets],
            ['assignment', $prerequisiteUnlockTargets],
            ['material', $minGradeLockedTargets],
            ['material', $hiddenModuleTargets],
            ['assignment', $gradingTargets],
            ['material', $readableMaterialTargets],
            ['quiz', $readableQuizTargets],
            ['assignment', $readableAssignmentTargets],
            ['material', $writableMaterialDownloadTargets],
            ['quiz', $writableQuizAttemptTargets],
            ['assignment', $writableAssignmentSubmissionTargets],
        );

        if ($integrityResult !== null) {
            $this->error($integrityResult);

            return Command::FAILURE;
        }

        $js = $this->render(
            $instructorIds, $studentIds, $allCourseIds, $activeCourseIds,
            $enrolledPairs, $instructorCoursePairs,
            $materialByCourse, $quizByCourse, $assignmentByCourse,
            $gradingTargets, $gradeUpdateTargets, $unauthorizedGradeUpdateTargets,
            $suspendedPairs, $nonEnrolledPairs,
            $groupRestrictedModuleTargets, $prerequisiteLockedTargets, $prerequisiteUnlockTargets,
            $minGradeLockedTargets, $hiddenModuleTargets, $lockedGradeTargets,
            $quizOverrideTargets, $assignmentOverrideTargets,
            $suspendedAccessTargets, $nonEnrolledAccessTargets,
            $readableMaterialTargets, $readableQuizTargets, $readableAssignmentTargets,
            $writableMaterialDownloadTargets, $writableAssignmentSubmissionTargets, $writableQuizAttemptTargets,
            $quizDetailAttemptTargets, $quizAggregateGradeTargets, $gradeCategoryReadTargets,
            $markerGradeTargets, $groupingRestrictedModuleTargets,
            $nestedAvailabilityLockedTargets, $nestedAvailabilityUnlockTargets,
            $courseCompletionCheckTargets,
        );

        file_put_contents($outputPath, $js);

        $this->info(sprintf('Wrote %s (%d bytes)', $outputPath, strlen($js)));
        $this->newLine();
        $this->table(['Pool', 'Count'], [
            ['INSTRUCTOR_IDS', count($instructorIds)],
            ['STUDENT_IDS', count($studentIds)],
            ['ACTIVE_COURSE_IDS', count($activeCourseIds)],
            ['ENROLLED_PAIRS', count($enrolledPairs)],
            ['INSTRUCTOR_COURSE_PAIRS', count($instructorCoursePairs)],
            ['MATERIAL_BY_COURSE (courses)', count($materialByCourse)],
            ['QUIZ_BY_COURSE (courses)', count($quizByCourse)],
            ['ASSIGNMENT_BY_COURSE (courses)', count($assignmentByCourse)],
            ['GRADING_TARGETS', count($gradingTargets)],
            ['GRADE_UPDATE_TARGETS', count($gradeUpdateTargets)],
            ['UNAUTHORIZED_GRADE_UPDATE_TARGETS', count($unauthorizedGradeUpdateTargets)],
            ['SUSPENDED_PAIRS', count($suspendedPairs)],
            ['NON_ENROLLED_PAIRS', count($nonEnrolledPairs)],
            ['GROUP_RESTRICTED_MODULE_TARGETS', count($groupRestrictedModuleTargets)],
            ['PREREQUISITE_LOCKED_TARGETS', count($prerequisiteLockedTargets)],
            ['PREREQUISITE_UNLOCK_TARGETS', count($prerequisiteUnlockTargets)],
            ['MIN_GRADE_LOCKED_TARGETS', count($minGradeLockedTargets)],
            ['HIDDEN_MODULE_TARGETS', count($hiddenModuleTargets)],
            ['LOCKED_GRADE_TARGETS', count($lockedGradeTargets)],
            ['QUIZ_OVERRIDE_TARGETS', count($quizOverrideTargets)],
            ['ASSIGNMENT_OVERRIDE_TARGETS', count($assignmentOverrideTargets)],
            ['SUSPENDED_ACCESS_TARGETS', count($suspendedAccessTargets)],
            ['NON_ENROLLED_ACCESS_TARGETS', count($nonEnrolledAccessTargets)],
            ['QUIZ_DETAIL_ATTEMPT_TARGETS', count($quizDetailAttemptTargets)],
            ['QUIZ_AGGREGATE_GRADE_TARGETS', count($quizAggregateGradeTargets)],
            ['GRADE_CATEGORY_READ_TARGETS', count($gradeCategoryReadTargets)],
            ['MARKER_GRADE_TARGETS', count($markerGradeTargets)],
            ['GROUPING_RESTRICTED_MODULE_TARGETS', count($groupingRestrictedModuleTargets)],
            ['NESTED_AVAILABILITY_LOCKED_TARGETS', count($nestedAvailabilityLockedTargets)],
            ['NESTED_AVAILABILITY_UNLOCK_TARGETS', count($nestedAvailabilityUnlockTargets)],
            ['COURSE_COMPLETION_CHECK_TARGETS', count($courseCompletionCheckTargets)],
            ['READABLE_MATERIAL_TARGETS', count($readableMaterialTargets)],
            ['READABLE_QUIZ_TARGETS', count($readableQuizTargets)],
            ['READABLE_ASSIGNMENT_TARGETS', count($readableAssignmentTargets)],
            ['WRITABLE_MATERIAL_DOWNLOAD_TARGETS', count($writableMaterialDownloadTargets)],
            ['WRITABLE_ASSIGNMENT_SUBMISSION_TARGETS', count($writableAssignmentSubmissionTargets)],
            ['WRITABLE_QUIZ_ATTEMPT_TARGETS', count($writableQuizAttemptTargets)],
        ]);

        return Command::SUCCESS;
    }

    /**
     * Build a pool of unauthorized grade-update targets.
     *
     * For each valid grade update target, pick an instructor who is NOT
     * authorized to grade that course (not the owner, no active instructor
     * enrollment). The pool is sorted deterministically and capped at ~20.
     */
    private function buildUnauthorizedGradeUpdateTargets(array $validTargets, array $activeCourseIds): array
    {
        // Build a set of (courseId => [instructorIds]) for authorized instructors
        $instructorRole = Role::where('shortname', 'instructor')->first();
        $authorizedByCourse = [];
        if ($instructorRole) {
            $instructorAssignments = RoleAssignment::where('role_id', $instructorRole->id)
                ->whereHas('context', fn ($q) => $q->where('contextlevel', \App\Models\Context::LEVEL_COURSE))
                ->with('context')
                ->get();

            foreach ($instructorAssignments as $assignment) {
                $courseId = $assignment->context->instance_id;
                $authorizedByCourse[$courseId][] = $assignment->user_id;
            }
        }

        $targets = [];
        $allInstructorIds = collect($this->getAllInstructorIds());

        foreach ($validTargets as $target) {
            $courseId = $target['courseId'];
            $authorizedIds = collect($authorizedByCourse[$courseId] ?? []);

            $unauthorizedIds = $allInstructorIds->diff($authorizedIds->unique())->values();

            if ($unauthorizedIds->isEmpty()) {
                continue;
            }

            foreach ($unauthorizedIds as $instructorId) {
                $targets[] = [
                    'instructorId' => $instructorId,
                    'courseId' => $target['courseId'],
                    'gradeId' => $target['gradeId'],
                    'gradeItemId' => $target['gradeItemId'],
                    'studentId' => $target['studentId'],
                    'maxScore' => $target['maxScore'] ?? 100,
                    'expectedStatus' => 403,
                ];
            }
        }

        // Sort deterministically by grade ID + instructor ID
        usort($targets, fn ($a, $b) => $a['gradeId'] <=> $b['gradeId']
            ?: $a['instructorId'] <=> $b['instructorId']
        );

        // Cap at ~20, spread across grades (take every Nth element)
        if (count($targets) > 20) {
            $step = (int) floor(count($targets) / 20);
            $targets = array_values(array_map(
                fn ($i) => $targets[$i],
                range(0, min(19, count($targets) - 1) * $step, $step)
            ));
        }

        return $targets;
    }

    /**
     * Get all instructor IDs (cached for reuse).
     */
    private array $allInstructorIds = [];

    private function getAllInstructorIds(): array
    {
        if (empty($this->allInstructorIds)) {
            $instructorRole = Role::where('shortname', 'instructor')->first();
            if ($instructorRole) {
                $this->allInstructorIds = RoleAssignment::where('role_id', $instructorRole->id)
                    ->select('user_id')->distinct()
                    ->orderBy('user_id')
                    ->pluck('user_id')
                    ->values()
                    ->all();
            }
        }

        return $this->allInstructorIds;
    }

    /* ──────────────────────────────────────────────
     * Plan 002: Actor-Aware Readable Target Pool Builders
     *
     * Each method returns targets where the student has an active enrollment,
     * the course is active, the activity is active, a matching learning module
     * exists and is visible, and all availability rules pass for that student.
     *
     * These replace the course-only activity pools for expected-success k6 reads.
     * ────────────────────────────────────────────── */

    /**
     * Build READABLE_MATERIAL_TARGETS — enrolled students × fully available materials.
     */
    private function buildReadableMaterialTargets(array $enrolledPairs): array
    {
        $courseAccessService = app(CourseAccessService::class);
        $availabilityService = app(ModuleAvailabilityService::class);
        $targets = [];

        $now = now();

        foreach ($enrolledPairs as $pair) {
            $studentId = $pair['studentId'];
            $courseId = $pair['courseId'];

            $materials = Material::where('is_active', true)
                ->where('course_id', $courseId)
                ->get();

            foreach ($materials as $material) {
                // Find the matching learning module
                $module = LearningModule::where('module_type', LearningModule::TYPE_MATERIAL)
                    ->where('module_id', $material->id)
                    ->where('course_id', $courseId)
                    ->first();

                if ($module === null) {
                    continue;
                }

                // Module must be visible
                if (! $module->visible) {
                    continue;
                }

                // Module date window
                if ($module->available_from && $module->available_from->gt($now)) {
                    continue;
                }
                if ($module->available_until && $module->available_until->lt($now)) {
                    continue;
                }

                // For students: full availability check
                $student = User::find($studentId);
                if ($student === null) {
                    continue;
                }

                $availability = $availabilityService->availabilityFor($student, $module);
                if (! $availability['available']) {
                    continue;
                }

                $targets[] = [
                    'studentId' => $studentId,
                    'courseId' => $courseId,
                    'activityType' => 'material',
                    'activityId' => $material->id,
                ];
            }
        }

        return $targets;
    }

    /**
     * Build READABLE_QUIZ_TARGETS — enrolled students × fully available quizzes.
     */
    private function buildReadableQuizTargets(array $enrolledPairs): array
    {
        $availabilityService = app(ModuleAvailabilityService::class);
        $targets = [];

        $now = now();

        foreach ($enrolledPairs as $pair) {
            $studentId = $pair['studentId'];
            $courseId = $pair['courseId'];

            $quizzes = Quiz::where('is_active', true)
                ->where('course_id', $courseId)
                ->get();

            foreach ($quizzes as $quiz) {
                $module = LearningModule::where('module_type', LearningModule::TYPE_QUIZ)
                    ->where('module_id', $quiz->id)
                    ->where('course_id', $courseId)
                    ->first();

                if ($module === null) {
                    continue;
                }
                if (! $module->visible) {
                    continue;
                }
                if ($module->available_from && $module->available_from->gt($now)) {
                    continue;
                }
                if ($module->available_until && $module->available_until->lt($now)) {
                    continue;
                }

                $student = User::find($studentId);
                if ($student === null) {
                    continue;
                }

                $availability = $availabilityService->availabilityFor($student, $module);
                if (! $availability['available']) {
                    continue;
                }

                $targets[] = [
                    'studentId' => $studentId,
                    'courseId' => $courseId,
                    'activityType' => 'quiz',
                    'activityId' => $quiz->id,
                ];
            }
        }

        return $targets;
    }

    /**
     * Build READABLE_ASSIGNMENT_TARGETS — enrolled students × fully available assignments.
     */
    private function buildReadableAssignmentTargets(array $enrolledPairs): array
    {
        $availabilityService = app(ModuleAvailabilityService::class);
        $targets = [];

        $now = now();

        foreach ($enrolledPairs as $pair) {
            $studentId = $pair['studentId'];
            $courseId = $pair['courseId'];

            $assignments = Assignment::where('is_active', true)
                ->where('course_id', $courseId)
                ->get();

            foreach ($assignments as $assignment) {
                $module = LearningModule::where('module_type', LearningModule::TYPE_ASSIGNMENT)
                    ->where('module_id', $assignment->id)
                    ->where('course_id', $courseId)
                    ->first();

                if ($module === null) {
                    continue;
                }
                if (! $module->visible) {
                    continue;
                }
                if ($module->available_from && $module->available_from->gt($now)) {
                    continue;
                }
                if ($module->available_until && $module->available_until->lt($now)) {
                    continue;
                }

                $student = User::find($studentId);
                if ($student === null) {
                    continue;
                }

                $availability = $availabilityService->availabilityFor($student, $module);
                if (! $availability['available']) {
                    continue;
                }

                $targets[] = [
                    'studentId' => $studentId,
                    'courseId' => $courseId,
                    'activityType' => 'assignment',
                    'activityId' => $assignment->id,
                ];
            }
        }

        return $targets;
    }

    /* ──────────────────────────────────────────────
     * Plan 001: Actor-Aware Writable Target Pool Builders
     *
     * Each method returns targets where the student has an active enrollment,
     * the course is active, the activity is active, a matching learning module
     * exists and is visible, all availability rules pass for that student, and
     * any write-specific constraints are satisfied.
     * ────────────────────────────────────────────── */

    /**
     * Build WRITABLE_MATERIAL_DOWNLOAD_TARGETS — enrolled students × downloadable materials.
     */
    private function buildWritableMaterialDownloadTargets(array $enrolledPairs): array
    {
        $courseAccessService = app(CourseAccessService::class);
        $availabilityService = app(ModuleAvailabilityService::class);
        $targets = [];
        $now = now();

        foreach ($enrolledPairs as $pair) {
            $studentId = $pair['studentId'];
            $courseId = $pair['courseId'];

            $materials = Material::where('is_active', true)
                ->where('course_id', $courseId)
                ->get();

            foreach ($materials as $material) {
                $module = LearningModule::where('module_type', LearningModule::TYPE_MATERIAL)
                    ->where('module_id', $material->id)
                    ->where('course_id', $courseId)
                    ->first();

                if ($module === null) {
                    continue;
                }
                if (! $module->visible) {
                    continue;
                }
                if ($module->available_from && $module->available_from->gt($now)) {
                    continue;
                }
                if ($module->available_until && $module->available_until->lt($now)) {
                    continue;
                }

                $student = User::find($studentId);
                if ($student === null) {
                    continue;
                }

                $availability = $availabilityService->availabilityFor($student, $module);
                if (! $availability['available']) {
                    continue;
                }

                // Ensure active enrolment
                if (! $courseAccessService->isActiveEnrollee($student, $module->course)) {
                    continue;
                }

                $targets[] = [
                    'studentId' => $studentId,
                    'courseId' => $courseId,
                    'activityType' => 'material',
                    'activityId' => $material->id,
                    'expectedStatus' => 200,
                ];
            }
        }

        return $targets;
    }

    /**
     * Build WRITABLE_ASSIGNMENT_SUBMISSION_TARGETS — enrolled students × submittable assignments.
     *
     * Expected-success targets require:
     * - Module availability passes for the student.
     * - Effective available_from allows submission (now >= available_from or null).
     * - Not past effective cutoff_date.
     * - Submission deadline has not passed OR allow_late_submission is true
     *   (for expected-success, prefer active assignments still accepting).
     * - Student does not already have a submission count >= max_attempts
     *   (avoids duplicate-submission 400 responses).
     */
    private function buildWritableAssignmentSubmissionTargets(array $enrolledPairs): array
    {
        $courseAccessService = app(CourseAccessService::class);
        $availabilityService = app(ModuleAvailabilityService::class);
        $targets = [];
        $now = now();

        foreach ($enrolledPairs as $pair) {
            $studentId = $pair['studentId'];
            $courseId = $pair['courseId'];

            $assignments = Assignment::where('is_active', true)
                ->where('course_id', $courseId)
                ->get();

            foreach ($assignments as $assignment) {
                $module = LearningModule::where('module_type', LearningModule::TYPE_ASSIGNMENT)
                    ->where('module_id', $assignment->id)
                    ->where('course_id', $courseId)
                    ->first();

                if ($module === null) {
                    continue;
                }
                if (! $module->visible) {
                    continue;
                }
                if ($module->available_from && $module->available_from->gt($now)) {
                    continue;
                }
                if ($module->available_until && $module->available_until->lt($now)) {
                    continue;
                }

                $student = User::find($studentId);
                if ($student === null) {
                    continue;
                }

                $availability = $availabilityService->availabilityFor($student, $module);
                if (! $availability['available']) {
                    continue;
                }

                if (! $courseAccessService->isActiveEnrollee($student, $module->course)) {
                    continue;
                }

                // Check effective available_from (module level or assignment level)
                $effectiveAvailableFrom = $assignment->available_from;
                if ($effectiveAvailableFrom && $effectiveAvailableFrom->gt($now)) {
                    continue;
                }

                // Check cutoff: only mark as expected-success if not past cutoff
                if ($assignment->cutoff_date && $assignment->cutoff_date->lt($now)) {
                    continue;
                }

                // Exclude students who already exhausted max_attempts
                $existingSubmissionCount = Submission::query()
                    ->where('assignment_id', $assignment->id)
                    ->where('user_id', $studentId)
                    ->count();
                if ($assignment->max_attempts !== null && $existingSubmissionCount >= $assignment->max_attempts) {
                    continue;
                }

                $targets[] = [
                    'studentId' => $studentId,
                    'courseId' => $courseId,
                    'activityType' => 'assignment',
                    'activityId' => $assignment->id,
                    'expectedStatus' => 201,
                ];
            }
        }

        return $targets;
    }

    /**
     * Quiz attempt statuses that count toward max_attempts exhaustion.
     *
     * Must include 'finished' because QuizService::submitQuizAnswers() sets
     * status to 'finished', not 'completed' or 'submitted'.
     */
    private function spentQuizAttemptStatuses(): array
    {
        return ['finished', 'completed', 'submitted'];
    }

    /**
     * Return the controlled set of valid quiz answer options used by benchmark questions.
     *
     * @return array<int, string>
     */
    private function validQuizAnswerOptions(): array
    {
        return ['A', 'B', 'C', 'D'];
    }

    /**
     * Normalize a quiz answer value.
     */
    private function normalizeQuizAnswer(?string $answer): ?string
    {
        $normalized = trim((string) $answer);

        return $normalized === '' ? null : $normalized;
    }

    /**
     * Return the first valid option that is not the normalized correct answer.
     *
     * If the correct answer is missing or outside the valid options set,
     * fall back to the first valid option.
     */
    private function deterministicWrongQuizAnswer(?string $correctAnswer, array $validOptions): string
    {
        $normalized = $this->normalizeQuizAnswer($correctAnswer);

        foreach ($validOptions as $option) {
            if ($option !== $normalized) {
                return $option;
            }
        }

        // Fallback: all options match (shouldn't happen with 4 options).
        return $validOptions[0];
    }

    /**
     * Return a valid answer value, falling back to the first valid option
     * when the given answer is null, empty, or outside the valid options set.
     *
     * This is used on the intended-correct branch to handle questions where
     * correct_answer is missing or contains an unexpected value (e.g. 'E').
     */
    private function validOrFallbackQuizAnswer(?string $answer, array $validOptions): string
    {
        $normalized = $this->normalizeQuizAnswer($answer);

        if ($normalized !== null && in_array($normalized, $validOptions, true)) {
            return $normalized;
        }

        return $validOptions[0];
    }

    /**
     * Build WRITABLE_QUIZ_ATTEMPT_TARGETS — enrolled students × attempt-startable quizzes.
     *
     * Each target includes a deterministic answers payload keyed by real question IDs
     * so that QuizService::submitQuizAnswers() exercises per-question write fan-out.
     *
     * Uses question position within the quiz collection (not database ID parity)
     * to decide intended-correct vs intended-wrong, and reads each question's
     * actual correct_answer field. This makes the generator seed-independent.
     *
     * Expected-success targets require:
     * - Module availability passes.
     * - Quiz is_active and currently open (available_from <= now <= available_until).
     * - No in-progress attempt for this student.
     * - Max attempts not exhausted (existing completed attempts < max_attempts).
     */
    private function buildWritableQuizAttemptTargets(array $enrolledPairs): array
    {
        $courseAccessService = app(CourseAccessService::class);
        $availabilityService = app(ModuleAvailabilityService::class);
        $spentStatuses = $this->spentQuizAttemptStatuses();
        $validOptions = $this->validQuizAnswerOptions();
        $targets = [];
        $now = now();

        foreach ($enrolledPairs as $pair) {
            $studentId = $pair['studentId'];
            $courseId = $pair['courseId'];

            $quizzes = Quiz::with('questions')
                ->where('is_active', true)
                ->where('course_id', $courseId)
                ->get();

            foreach ($quizzes as $quiz) {
                $module = LearningModule::where('module_type', LearningModule::TYPE_QUIZ)
                    ->where('module_id', $quiz->id)
                    ->where('course_id', $courseId)
                    ->first();

                if ($module === null) {
                    continue;
                }
                if (! $module->visible) {
                    continue;
                }
                if ($module->available_from && $module->available_from->gt($now)) {
                    continue;
                }
                if ($module->available_until && $module->available_until->lt($now)) {
                    continue;
                }

                $student = User::find($studentId);
                if ($student === null) {
                    continue;
                }

                $availability = $availabilityService->availabilityFor($student, $module);
                if (! $availability['available']) {
                    continue;
                }

                if (! $courseAccessService->isActiveEnrollee($student, $module->course)) {
                    continue;
                }

                // Quiz must be open (available_from/available_until)
                if (! $quiz->isOpen()) {
                    continue;
                }

                // No in-progress attempt blocking start
                $hasInProgress = $quiz->attempts()
                    ->where('user_id', $studentId)
                    ->whereIn('status', ['in_progress', 'started'])
                    ->exists();
                if ($hasInProgress) {
                    continue;
                }

                // Max attempts not exhausted — count all spent statuses
                $completedAttempts = $quiz->attempts()
                    ->where('user_id', $studentId)
                    ->whereIn('status', $spentStatuses)
                    ->count();
                if ($quiz->max_attempts !== null && $completedAttempts >= $quiz->max_attempts) {
                    continue;
                }

                // Build deterministic answers keyed by real question IDs.
                // Use question position (not DB ID parity) to decide
                // intended-correct vs intended-wrong.
                $answers = [];
                foreach ($quiz->questions->values() as $index => $question) {
                    $correctAnswer = $question->correct_answer;
                    $answers[$question->id] = $index % 2 === 0
                        ? $this->validOrFallbackQuizAnswer($correctAnswer, $validOptions)
                        : $this->deterministicWrongQuizAnswer($correctAnswer, $validOptions);
                }

                $targets[] = [
                    'studentId' => $studentId,
                    'courseId' => $courseId,
                    'activityType' => 'quiz',
                    'activityId' => $quiz->id,
                    'expectedStatus' => 201,
                    'answers' => $answers,
                ];
            }
        }

        return $targets;
    }

    private function render(
        array $instructorIds, array $studentIds, array $allCourseIds, array $activeCourseIds,
        array $enrolledPairs, array $instructorCoursePairs,
        array $materialByCourse, array $quizByCourse, array $assignmentByCourse,
        array $gradingTargets, array $gradeUpdateTargets, array $unauthorizedGradeUpdateTargets,
        array $suspendedPairs, array $nonEnrolledPairs,
        array $groupRestrictedModuleTargets, array $prerequisiteLockedTargets, array $prerequisiteUnlockTargets,
        array $minGradeLockedTargets, array $hiddenModuleTargets, array $lockedGradeTargets,
        array $quizOverrideTargets, array $assignmentOverrideTargets,
        array $suspendedAccessTargets, array $nonEnrolledAccessTargets,
        array $readableMaterialTargets, array $readableQuizTargets, array $readableAssignmentTargets,
        array $writableMaterialDownloadTargets, array $writableAssignmentSubmissionTargets, array $writableQuizAttemptTargets,
        array $quizDetailAttemptTargets = [], array $quizAggregateGradeTargets = [],
        array $gradeCategoryReadTargets = [], array $markerGradeTargets = [],
        array $groupingRestrictedModuleTargets = [], array $nestedAvailabilityLockedTargets = [],
        array $nestedAvailabilityUnlockTargets = [],
        array $courseCompletionCheckTargets = [],
    ): string {
        $j = fn ($v) => json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return <<<JS
// ============================================================
// Benchmark Fixture Pools
//
// AUTO-GENERATED by benchmark:generate-k6-fixtures
// Source: deterministic DatabaseSeeder — do not edit by hand.
// Regenerate: sail artisan db:seed --class=DatabaseSeeder && sail artisan benchmark:generate-k6-fixtures
// ============================================================

// ─── Identity Pools ────────────────────────────────────────
const INSTRUCTOR_IDS = {$j($instructorIds)};
const STUDENT_IDS = {$j($studentIds)};
const COURSE_IDS = {$j($allCourseIds)};
const ACTIVE_COURSE_IDS = {$j($activeCourseIds)};

// ─── Enrolled Pools (relationship-valid from DB) ──────────
const ENROLLED_PAIRS = {$j($enrolledPairs)};

const INSTRUCTOR_COURSE_PAIRS = {$j($instructorCoursePairs)};

// ─── Activity Pools (from DB) ──────────────────────────────
const MATERIAL_BY_COURSE = {$j($materialByCourse)};
function materialIdsForCourse(courseId) { return MATERIAL_BY_COURSE[courseId] || []; }

const QUIZ_BY_COURSE = {$j($quizByCourse)};
function quizIdsForCourse(courseId) { return QUIZ_BY_COURSE[courseId] || []; }

const ASSIGNMENT_BY_COURSE = {$j($assignmentByCourse)};
function assignmentIdsForCourse(courseId) { return ASSIGNMENT_BY_COURSE[courseId] || []; }

// ─── Valid Grading Targets (instructor-valid from DB) ──────
const GRADING_TARGETS = {$j($gradingTargets)};

const GRADE_UPDATE_TARGETS = {$j($gradeUpdateTargets)};

const UNAUTHORIZED_GRADE_UPDATE_TARGETS = {$j($unauthorizedGradeUpdateTargets)};

// ─── Controlled Failure Pools ──────────────────────────────
const SUSPENDED_PAIRS = {$j($suspendedPairs)};

const NON_ENROLLED_PAIRS = {$j($nonEnrolledPairs)};

// ─── Plan 002: Actor-Aware Readable Target Pools ───────────
const READABLE_MATERIAL_TARGETS = {$j($readableMaterialTargets)};

const READABLE_QUIZ_TARGETS = {$j($readableQuizTargets)};

const READABLE_ASSIGNMENT_TARGETS = {$j($readableAssignmentTargets)};

// ─── Plan 001: Actor-Aware Writable Target Pools ───────────
const WRITABLE_MATERIAL_DOWNLOAD_TARGETS = {$j($writableMaterialDownloadTargets)};

const WRITABLE_ASSIGNMENT_SUBMISSION_TARGETS = {$j($writableAssignmentSubmissionTargets)};

const WRITABLE_QUIZ_ATTEMPT_TARGETS = {$j($writableQuizAttemptTargets)};

// ─── Plan 003: Purpose-Built Fixture Pools ─────────────────
const GROUP_RESTRICTED_MODULE_TARGETS = {$j($groupRestrictedModuleTargets)};

const PREREQUISITE_LOCKED_TARGETS = {$j($prerequisiteLockedTargets)};

const PREREQUISITE_UNLOCK_TARGETS = {$j($prerequisiteUnlockTargets)};

const MIN_GRADE_LOCKED_TARGETS = {$j($minGradeLockedTargets)};

const HIDDEN_MODULE_TARGETS = {$j($hiddenModuleTargets)};

const LOCKED_GRADE_TARGETS = {$j($lockedGradeTargets)};

const QUIZ_OVERRIDE_TARGETS = {$j($quizOverrideTargets)};

const ASSIGNMENT_OVERRIDE_TARGETS = {$j($assignmentOverrideTargets)};

const SUSPENDED_ACCESS_TARGETS = {$j($suspendedAccessTargets)};

const NON_ENROLLED_ACCESS_TARGETS = {$j($nonEnrolledAccessTargets)};

// ─── Plan 006: New Benchmark Target Pools ───────────────────
const QUIZ_DETAIL_ATTEMPT_TARGETS = {$j($quizDetailAttemptTargets)};

const QUIZ_AGGREGATE_GRADE_TARGETS = {$j($quizAggregateGradeTargets)};

const GRADE_CATEGORY_READ_TARGETS = {$j($gradeCategoryReadTargets)};

const MARKER_GRADE_TARGETS = {$j($markerGradeTargets)};

const GROUPING_RESTRICTED_MODULE_TARGETS = {$j($groupingRestrictedModuleTargets)};

const NESTED_AVAILABILITY_LOCKED_TARGETS = {$j($nestedAvailabilityLockedTargets)};

const NESTED_AVAILABILITY_UNLOCK_TARGETS = {$j($nestedAvailabilityUnlockTargets)};

// ─── Plan 02: Course Completion Check Targets ───────────────
const COURSE_COMPLETION_CHECK_TARGETS = {$j($courseCompletionCheckTargets)};

// ─── Helper: Pick random element ──────────────────────────
function pick(arr) {
  if (!arr || arr.length === 0) {
    throw new Error('Cannot pick from empty fixture pool');
  }
  return arr[Math.floor(Math.random() * arr.length)];
}

// ─── Helper: Generate score within target's maxScore ────
function scoreWithinMax(target) {
  let max = target.maxScore;
  if (max <= 0) return 0;
  let min = Math.floor(max * 0.6);
  min = Math.min(min, max);
  return Math.floor(Math.random() * (max - min + 1)) + min;
}

// ─── Export ────────────────────────────────────────────────
export {
  INSTRUCTOR_IDS,
  STUDENT_IDS,
  COURSE_IDS,
  ACTIVE_COURSE_IDS,
  ENROLLED_PAIRS,
  INSTRUCTOR_COURSE_PAIRS,
  materialIdsForCourse,
  quizIdsForCourse,
  assignmentIdsForCourse,
  GRADING_TARGETS,
  GRADE_UPDATE_TARGETS,
  UNAUTHORIZED_GRADE_UPDATE_TARGETS,
  SUSPENDED_PAIRS,
  NON_ENROLLED_PAIRS,
  READABLE_MATERIAL_TARGETS,
  READABLE_QUIZ_TARGETS,
  READABLE_ASSIGNMENT_TARGETS,
  WRITABLE_MATERIAL_DOWNLOAD_TARGETS,
  WRITABLE_ASSIGNMENT_SUBMISSION_TARGETS,
  WRITABLE_QUIZ_ATTEMPT_TARGETS,
  GROUP_RESTRICTED_MODULE_TARGETS,
  PREREQUISITE_LOCKED_TARGETS,
  PREREQUISITE_UNLOCK_TARGETS,
  MIN_GRADE_LOCKED_TARGETS,
  HIDDEN_MODULE_TARGETS,
  LOCKED_GRADE_TARGETS,
  QUIZ_OVERRIDE_TARGETS,
  ASSIGNMENT_OVERRIDE_TARGETS,
  SUSPENDED_ACCESS_TARGETS,
  NON_ENROLLED_ACCESS_TARGETS,
  QUIZ_DETAIL_ATTEMPT_TARGETS,
  QUIZ_AGGREGATE_GRADE_TARGETS,
  GRADE_CATEGORY_READ_TARGETS,
  MARKER_GRADE_TARGETS,
  GROUPING_RESTRICTED_MODULE_TARGETS,
  NESTED_AVAILABILITY_LOCKED_TARGETS,
  NESTED_AVAILABILITY_UNLOCK_TARGETS,
  COURSE_COMPLETION_CHECK_TARGETS,
  pick,
  scoreWithinMax,
};

// ─── Helper: actor headers ────────────────────────────────
export function headersFor(actorId) {
  return {
    headers: {
      "Content-Type": "application/json",
      "X-Benchmark-Actor-Id": `\${actorId}`,
    },
    timeout: "30s",
  };
}

// ─── Helper: Pick a random enrolled student-course pair ───
export function randomEnrolledPair() {
  return pick(ENROLLED_PAIRS);
}

// ─── Helper: Pick a random instructor-course pair ─────────
export function randomInstructorCoursePair() {
  return pick(INSTRUCTOR_COURSE_PAIRS);
}

// ─── Helper: Resolve activity type to API endpoint path ──
export function activityPath(target) {
  if (target.activityType === 'material') return '/api/materials/' + target.activityId;
  if (target.activityType === 'quiz') return '/api/quizzes/' + target.activityId;
  if (target.activityType === 'assignment') return '/api/assignments/' + target.activityId;
  throw new Error('Unknown activity type: ' + target.activityType);
}

JS;
    }

    /**
     * Build COURSE_COMPLETION_CHECK_TARGETS — enrolled students paired with
     * their expected course completion state based on pre-seeded completions.
     *
     * Only considers courses that have completion criteria defined.
     * Only considers students who are actively enrolled.
     */
    private function buildCourseCompletionCheckTargets(array $enrolledPairs): array
    {
        $courseIdsWithCriteria = CourseCompletionCriterion::query()
            ->select('course_id')
            ->distinct()
            ->pluck('course_id')
            ->toArray();

        if (empty($courseIdsWithCriteria)) {
            return [];
        }

        // Pre-load all course completions for these courses
        $completions = CourseCompletion::query()
            ->whereIn('course_id', $courseIdsWithCriteria)
            ->get()
            ->keyBy(fn ($c) => $c->course_id.'-'.$c->user_id);

        // Pre-load criteria counts per course
        $criteriaCounts = CourseCompletionCriterion::query()
            ->whereIn('course_id', $courseIdsWithCriteria)
            ->selectRaw('course_id, COUNT(*) as total')
            ->groupBy('course_id')
            ->pluck('total', 'course_id');

        $targets = [];

        foreach ($enrolledPairs as $pair) {
            $courseId = $pair['courseId'];
            $studentId = $pair['studentId'];

            if (! in_array($courseId, $courseIdsWithCriteria, true)) {
                continue;
            }

            $key = $courseId.'-'.$studentId;
            $completion = $completions->get($key);
            $expectedCompleted = $completion !== null && $completion->timecompleted !== null;

            $targets[] = [
                'studentId' => (int) $studentId,
                'courseId' => (int) $courseId,
                'expectedCompleted' => $expectedCompleted,
                'criteriaTotal' => (int) ($criteriaCounts[$courseId] ?? 0),
            ];
        }

        return $targets;
    }

    /* ──────────────────────────────────────────────
     * Plan 003: Learning Module Integrity Validation
     * ────────────────────────────────────────────── */

    /**
     * Validate that every exported activity endpoint target has exactly one
     * matching learning_modules wrapper. Fails when an activity has zero
     * or more than one matching module.
     *
     * Each argument is a tuple: [string $activityType, array $collection]
     * where $collection is a course-keyed map (e.g. MATERIAL_BY_COURSE)
     * or an array of target objects with activityId/activityType keys.
     *
     * @param  array  ...$typedCollections  Tuples of [type, collection]
     * @return string|null Error message or null on success
     */
    private function validateLearningModuleIntegrity(array ...$typedCollections): ?string
    {
        foreach ($typedCollections as $tuple) {
            [$activityType, $collection] = $tuple;

            if (! in_array($activityType, ['material', 'quiz', 'assignment'], true)) {
                continue;
            }

            $error = $this->validateCollectionModules($activityType, $collection);
            if ($error) {
                return $error;
            }
        }

        return null;
    }

    /**
     * Validate a single typed collection. Returns first error or null.
     *
     * Handles three shapes:
     *   1. Course-keyed map:  { courseId: [id1, id2, ...], ... }
     *      (int key, list-of-ints value)
     *   2. Target obj array:  [{activityId, activityType?, courseId?, ...}, ...]
     *      (int key, assoc-array value with activityId/assignmentId)
     *   3. Grading targets:   [{assignmentId, courseId, ...}, ...]
     *      (same shape as #2, uses assignmentId as activityId)
     */
    private function validateCollectionModules(string $activityType, array $collection): ?string
    {
        foreach ($collection as $key => $value) {
            if (! is_array($value) || ! is_int($key)) {
                continue;
            }

            // Shape 2/3: target object array — each $item is an assoc array
            if (isset($value['activityId']) || isset($value['assignmentId'])) {
                $itemType = $value['activityType'] ?? $activityType;
                $itemId = $value['activityId'] ?? $value['assignmentId'] ?? null;
                if ($itemId === null) {
                    continue;
                }
                $error = $this->checkSingleModule($itemType, $itemId, $value['courseId'] ?? null);
                if ($error) {
                    return $error;
                }

                continue;
            }

            // Shape 1: course-keyed map — $value is a list of int IDs
            if (array_is_list($value)) {
                foreach ($value as $item) {
                    if (! is_int($item)) {
                        continue;
                    }
                    $error = $this->checkSingleModule($activityType, $item, null);
                    if ($error) {
                        return $error;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Check that an activity has exactly one matching learning module.
     * Returns an error message or null on success.
     */
    private function checkSingleModule(string $activityType, int $activityId, ?int $expectedCourseId): ?string
    {
        $validTypes = [LearningModule::TYPE_MATERIAL, LearningModule::TYPE_QUIZ, LearningModule::TYPE_ASSIGNMENT];

        if (! in_array($activityType, $validTypes, true)) {
            return null; // Skip non-activity types
        }

        $modules = LearningModule::where('module_type', $activityType)
            ->where('module_id', $activityId)
            ->get();

        $count = $modules->count();

        if ($count === 0) {
            return "Learning module integrity: {$activityType} ID {$activityId} has zero matching learning modules.";
        }

        if ($count > 1) {
            $moduleIds = $modules->pluck('id')->implode(', ');

            return "Learning module integrity: {$activityType} ID {$activityId} has {$count} matching learning modules (IDs: {$moduleIds}). Expected exactly one.";
        }

        // Check course match if expectedCourseId is provided
        if ($expectedCourseId !== null) {
            $module = $modules->first();
            if ($module->course_id !== $expectedCourseId) {
                return "Learning module integrity: {$activityType} ID {$activityId} has module course_id {$module->course_id} but expected course_id {$expectedCourseId}.";
            }
        }

        return null;
    }

    /* ──────────────────────────────────────────────
     * Plan 003: Purpose-built pool builders
     * ────────────────────────────────────────────── */

    /**
     * Modules with group restriction × non-member students (expect 404/403).
     */
    private function buildGroupRestrictedModuleTargets(array $enrolledPairs): array
    {
        $groupRules = ModuleAvailabilityRule::where('rule_type', 'group')
            ->whereNotNull('course_group_id')
            ->with('learningModule.course')
            ->get();

        $targets = [];
        foreach ($groupRules as $rule) {
            $module = $rule->learningModule;
            if (! $module || ! $module->course || ! $module->course->is_active) {
                continue;
            }

            $courseId = $module->course_id;
            $groupMemberIds = CourseGroupMember::where('course_group_id', $rule->course_group_id)
                ->pluck('user_id');

            $enrolledStudentIds = collect($enrolledPairs)
                ->where('courseId', $courseId)
                ->pluck('studentId');

            $nonMemberIds = $enrolledStudentIds->diff($groupMemberIds)->values();

            foreach ($nonMemberIds as $sid) {
                $targets[] = [
                    'userId' => $sid,
                    'courseId' => $courseId,
                    'moduleId' => $module->id,
                    'activityType' => $module->module_type,
                    'activityId' => $module->module_id,
                    'expectedStatus' => 404,
                ];
            }
        }

        return $targets;
    }

    /**
     * Modules with prerequisite completion rule × students who have NOT completed it.
     */
    private function buildPrerequisiteLockedTargets(array $enrolledPairs): array
    {
        $completionRules = ModuleAvailabilityRule::where('rule_type', 'completion')
            ->whereNotNull('required_module_id')
            ->with('learningModule.course')
            ->get();

        $targets = [];
        foreach ($completionRules as $rule) {
            $module = $rule->learningModule;
            if (! $module || ! $module->course || ! $module->course->is_active) {
                continue;
            }

            $courseId = $module->course_id;
            $prereqModuleId = $rule->required_module_id;

            // Students who have NOT completed the prerequisite
            $completedUserIds = ModuleCompletion::where('learning_module_id', $prereqModuleId)
                ->where('state', '!=', 'incomplete')
                ->pluck('user_id');

            $enrolledStudentIds = collect($enrolledPairs)
                ->where('courseId', $courseId)
                ->pluck('studentId');

            $lockedUserIds = $enrolledStudentIds->diff($completedUserIds)->values();

            foreach ($lockedUserIds as $sid) {
                $targets[] = [
                    'userId' => $sid,
                    'courseId' => $courseId,
                    'moduleId' => $module->id,
                    'activityType' => $module->module_type,
                    'activityId' => $module->module_id,
                    'expectedStatus' => 404,
                ];
            }
        }

        return $targets;
    }

    /**
     * Modules with prerequisite rule × students who HAVE completed it (expect 200).
     */
    private function buildPrerequisiteUnlockTargets(array $enrolledPairs): array
    {
        $completionRules = ModuleAvailabilityRule::where('rule_type', 'completion')
            ->whereNotNull('required_module_id')
            ->with('learningModule.course')
            ->get();

        $targets = [];
        foreach ($completionRules as $rule) {
            $module = $rule->learningModule;
            if (! $module || ! $module->course || ! $module->course->is_active) {
                continue;
            }

            $courseId = $module->course_id;
            $prereqModuleId = $rule->required_module_id;

            $completedUserIds = ModuleCompletion::where('learning_module_id', $prereqModuleId)
                ->where('state', '!=', 'incomplete')
                ->pluck('user_id');

            $enrolledStudentIds = collect($enrolledPairs)
                ->where('courseId', $courseId)
                ->pluck('studentId');

            $unlockedUserIds = $enrolledStudentIds->filter(fn ($sid) => $completedUserIds->contains($sid))->values();

            foreach ($unlockedUserIds as $sid) {
                $targets[] = [
                    'userId' => $sid,
                    'courseId' => $courseId,
                    'moduleId' => $module->id,
                    'activityType' => $module->module_type,
                    'activityId' => $module->module_id,
                    'expectedStatus' => 200,
                ];
            }
        }

        return $targets;
    }

    /**
     * Modules with min-grade rule × students below the threshold.
     */
    private function buildMinGradeLockedTargets(array $enrolledPairs): array
    {
        $minGradeRules = ModuleAvailabilityRule::where('rule_type', 'min_grade')
            ->whereNotNull('grade_item_id')
            ->with('learningModule.course')
            ->get();

        $targets = [];
        foreach ($minGradeRules as $rule) {
            $module = $rule->learningModule;
            if (! $module || ! $module->course || ! $module->course->is_active) {
                continue;
            }

            $courseId = $module->course_id;
            $gradeItemId = $rule->grade_item_id;
            $threshold = (float) ($rule->value ?: 60);

            $enrolledStudentIds = collect($enrolledPairs)
                ->where('courseId', $courseId)
                ->pluck('studentId');

            foreach ($enrolledStudentIds as $sid) {
                $grade = Grade::where('user_id', $sid)
                    ->where('grade_item_id', $gradeItemId)
                    ->where('status', 'final')
                    ->first();

                if (! $grade || ($grade->percentage ?? 0) < $threshold) {
                    $targets[] = [
                        'userId' => $sid,
                        'courseId' => $courseId,
                        'moduleId' => $module->id,
                        'activityType' => $module->module_type,
                        'activityId' => $module->module_id,
                        'expectedStatus' => 404,
                    ];
                }
            }
        }

        return $targets;
    }

    /**
     * Hidden modules paired with enrolled students.
     */
    private function buildHiddenModuleTargets(array $enrolledPairs): array
    {
        $hiddenModules = LearningModule::where('visible', false)
            ->whereHas('course', fn ($q) => $q->where('is_active', true))
            ->with('course')
            ->get();

        $targets = [];
        foreach ($hiddenModules as $module) {
            $courseId = $module->course_id;

            $studentIds = collect($enrolledPairs)
                ->where('courseId', $courseId)
                ->pluck('studentId');

            foreach ($studentIds as $sid) {
                $targets[] = [
                    'userId' => $sid,
                    'courseId' => $courseId,
                    'moduleId' => $module->id,
                    'activityType' => $module->module_type,
                    'activityId' => $module->module_id,
                    'expectedStatus' => 404,
                ];
            }
        }

        return $targets;
    }

    /**
     * Locked grade items (grade update attempts should fail).
     */
    private function buildLockedGradeTargets(): array
    {
        $lockedItems = GradeItem::where('locked', true)
            ->whereHas('course', fn ($q) => $q->where('is_active', true))
            ->get();

        $targets = [];
        foreach ($lockedItems as $item) {
            $course = $item->course;
            $grades = Grade::where('grade_item_id', $item->id)
                ->where('status', 'final')
                ->get();

            foreach ($grades as $grade) {
                $targets[] = [
                    'gradeId' => $grade->id,
                    'gradeItemId' => $item->id,
                    'courseId' => $item->course_id,
                    'instructorId' => $course->instructor_id,
                    'studentId' => $grade->user_id,
                    'expectedStatus' => 403,
                ];
            }
        }

        return $targets;
    }

    /**
     * Quiz overrides with the user who has the override.
     */
    private function buildQuizOverrideTargets(): array
    {
        return QuizOverride::with('quiz.course')
            ->get()
            ->map(fn ($o) => [
                'userId' => $o->user_id,
                'quizId' => $o->quiz_id,
                'courseId' => $o->quiz?->course_id,
                'maxAttempts' => $o->max_attempts,
                'timeLimit' => $o->time_limit,
            ])
            ->filter(fn ($t) => $t['courseId'] !== null)
            ->values()
            ->all();
    }

    /**
     * Assignment overrides with the user who has the override.
     */
    private function buildAssignmentOverrideTargets(): array
    {
        return AssignmentOverride::with('assignment.course')
            ->get()
            ->map(fn ($o) => [
                'userId' => $o->user_id,
                'assignmentId' => $o->assignment_id,
                'courseId' => $o->assignment?->course_id,
                'dueDate' => $o->due_date?->toISOString(),
                'maxAttempts' => $o->max_attempts,
            ])
            ->filter(fn ($t) => $t['courseId'] !== null)
            ->values()
            ->all();
    }

    /**
     * Suspended students paired with their (inactive) course (expect 403).
     */
    private function buildSuspendedAccessTargets(): array
    {
        return CourseEnrollment::where('role', 'student')
            ->where('status', 'suspended')
            ->whereHas('course', fn ($q) => $q->where('is_active', true))
            ->with('course')
            ->get()
            ->map(fn ($e) => [
                'userId' => $e->user_id,
                'courseId' => $e->course_id,
                'expectedStatus' => 403,
            ])
            ->values()
            ->all();
    }

    /**
     * Non-enrolled students paired with active courses (expect 403).
     */
    private function buildNonEnrolledAccessTargets(array $activeCourseIds, array $studentIds): array
    {
        $allStudentIds = collect($studentIds);
        $targets = [];

        foreach ($activeCourseIds as $cid) {
            $enrolledIds = CourseEnrollment::where('course_id', $cid)
                ->where('role', 'student')
                ->pluck('user_id');

            $notEnrolled = $allStudentIds->diff($enrolledIds)->take(2);

            foreach ($notEnrolled as $sid) {
                $targets[] = [
                    'userId' => $sid,
                    'courseId' => $cid,
                    'expectedStatus' => 403,
                ];
            }
        }

        return array_slice($targets, 0, 20);
    }

    // ─── Plan 006: New Benchmark Target Pool Builders ──────────────────────────

    /**
     * Quiz attempts that have normalized attempt-question/step/step-data rows.
     * These are targets for attempt review reads.
     */
    private function buildQuizDetailAttemptTargets(): array
    {
        return \App\Models\QuizAttempt::query()->where('status', 'finished')
            ->whereHas('attemptQuestions')
            ->with('quiz:id,course_id')
            ->whereHas('quiz.course', fn ($q) => $q->where('is_active', true))
            ->get()
            ->map(fn ($a) => [
                'attemptId' => $a->id,
                'quizId' => $a->quiz_id,
                'userId' => $a->user_id,
                'courseId' => $a->quiz->course_id,
            ])
            ->values()
            ->all();
    }

    /**
     * Quiz-grade pairs for aggregate grade reads.
     */
    private function buildQuizAggregateGradeTargets(): array
    {
        return QuizGrade::with('quiz.course')
            ->get()
            ->map(fn ($qg) => [
                'quizId' => $qg->quiz_id,
                'userId' => $qg->user_id,
                'courseId' => $qg->quiz->course_id,
                'grade' => $qg->grade,
            ])
            ->filter(fn ($t) => $t['courseId'] !== null)
            ->values()
            ->all();
    }

    /**
     * Active courses that have grade categories.
     */
    private function buildGradeCategoryReadTargets(): array
    {
        // Categories don't have a course_id column; courses have course_category_id.
        $courseIds = Course::where('is_active', true)
            ->whereNotNull('course_category_id')
            ->pluck('id')
            ->toArray();

        $targets = [];
        foreach ($courseIds as $cid) {
            $targets[] = ['courseId' => $cid];
        }

        return $targets;
    }

    /**
     * Submissions with marker allocation for marker grading tests.
     */
    private function buildMarkerGradeTargets(): array
    {
        $targets = [];

        $allocatedSubmissions = \App\Models\AssignmentAllocatedMarker::with(['submission.assignment.course'])
            ->get()
            ->groupBy('submission_id');

        foreach ($allocatedSubmissions as $submissionId => $markers) {
            $submission = $markers->first()?->submission;
            if (! $submission || ! $submission->assignment?->course?->is_active) {
                continue;
            }

            foreach ($markers as $marker) {
                $targets[] = [
                    'submissionId' => $submission->id,
                    'markerId' => $marker->marker_id,
                    'assignmentId' => $submission->assignment_id,
                    'courseId' => $submission->assignment->course_id,
                    'studentId' => $submission->user_id,
                ];
            }
        }

        return $targets;
    }

    /**
     * Modules with grouping-based restriction (student not in any group of the grouping).
     */
    private function buildGroupingRestrictedModuleTargets(array $enrolledPairs): array
    {
        $targets = [];

        $groupRuleModules = ModuleAvailabilityRule::where('rule_type', 'group')
            ->whereNotNull('course_group_id')
            ->with('learningModule.course')
            ->get()
            ->groupBy('learning_module_id');

        foreach ($groupRuleModules as $moduleId => $rules) {
            $module = $rules->first()->learningModule;
            if (! $module || ! $module->course || ! $module->course->is_active) {
                continue;
            }

            $courseId = $module->course_id;
            $groupIds = $rules->pluck('course_group_id')->filter()->unique();

            // Check if these groups belong to any grouping
            $hasGrouping = CourseGroupingGroup::whereIn('course_group_id', $groupIds)->exists();
            if (! $hasGrouping) {
                continue;
            }

            $memberIds = CourseGroupMember::whereIn('course_group_id', $groupIds)
                ->pluck('user_id');

            $enrolledIds = collect($enrolledPairs)
                ->where('courseId', $courseId)
                ->pluck('studentId');

            $nonMemberIds = $enrolledIds->diff($memberIds)->values();

            foreach ($nonMemberIds as $sid) {
                $targets[] = [
                    'userId' => $sid,
                    'courseId' => $courseId,
                    'moduleId' => $module->id,
                    'activityType' => $module->module_type,
                    'activityId' => $module->module_id,
                    'expectedStatus' => 404,
                ];
            }
        }

        return $targets;
    }

    /**
     * Modules with nested availability (condition_group) blocking access.
     */
    private function buildNestedAvailabilityLockedTargets(array $enrolledPairs): array
    {
        $targets = [];

        $modulesWithNestedRules = LearningModule::whereHas('availabilityRules', function ($q) {
            $q->whereNotNull('condition_group');
        })->whereHas('course', fn ($q) => $q->where('is_active', true))
            ->with('course')
            ->get();

        $availabilityService = app(ModuleAvailabilityService::class);

        foreach ($modulesWithNestedRules as $module) {
            $courseId = $module->course_id;

            $enrolledIds = collect($enrolledPairs)
                ->where('courseId', $courseId)
                ->pluck('studentId');

            foreach ($enrolledIds as $sid) {
                $student = User::find($sid);
                if (! $student) {
                    continue;
                }

                $availability = $availabilityService->availabilityFor($student, $module);
                if (! $availability['available']) {
                    $targets[] = [
                        'userId' => $sid,
                        'courseId' => $courseId,
                        'moduleId' => $module->id,
                        'activityType' => $module->module_type,
                        'activityId' => $module->module_id,
                        'expectedStatus' => 404,
                    ];
                }
            }
        }

        return $targets;
    }

    /**
     * Modules with nested availability (condition_group) where the student IS unlocked.
     */
    private function buildNestedAvailabilityUnlockTargets(array $enrolledPairs): array
    {
        $targets = [];

        $modulesWithNestedRules = LearningModule::whereHas('availabilityRules', function ($q) {
            $q->whereNotNull('condition_group');
        })->whereHas('course', fn ($q) => $q->where('is_active', true))
            ->with('course')
            ->get();

        $availabilityService = app(ModuleAvailabilityService::class);

        foreach ($modulesWithNestedRules as $module) {
            $courseId = $module->course_id;

            $enrolledIds = collect($enrolledPairs)
                ->where('courseId', $courseId)
                ->pluck('studentId');

            foreach ($enrolledIds as $sid) {
                $student = User::find($sid);
                if (! $student) {
                    continue;
                }

                $availability = $availabilityService->availabilityFor($student, $module);
                if ($availability['available']) {
                    $targets[] = [
                        'userId' => $sid,
                        'courseId' => $courseId,
                        'moduleId' => $module->id,
                        'activityType' => $module->module_type,
                        'activityId' => $module->module_id,
                        'expectedStatus' => 200,
                    ];
                }
            }
        }

        return $targets;
    }
}
