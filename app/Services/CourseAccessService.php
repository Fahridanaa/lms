<?php

namespace App\Services;

use App\Models\Assignment;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\CourseGroupMember;
use App\Models\LearningModule;
use App\Models\Material;
use App\Models\Quiz;
use App\Models\Submission;
use App\Models\User;

class CourseAccessService
{
    private AuthorizationService $authorizationService;

    private ContextService $contextService;

    public function __construct(AuthorizationService $authorizationService, ContextService $contextService)
    {
        $this->authorizationService = $authorizationService;
        $this->contextService = $contextService;
    }

    /**
     * Resolve the LearningModule for an activity (material, quiz, assignment).
     *
     * @return LearningModule|null
     */
    public function resolveModuleForActivity(object $activity): ?LearningModule
    {
        if ($activity instanceof Material) {
            return LearningModule::query()
                ->where('module_type', LearningModule::TYPE_MATERIAL)
                ->where('module_id', $activity->id)
                ->first();
        }

        if ($activity instanceof Quiz) {
            return LearningModule::query()
                ->where('module_type', LearningModule::TYPE_QUIZ)
                ->where('module_id', $activity->id)
                ->first();
        }

        if ($activity instanceof Assignment) {
            return LearningModule::query()
                ->where('module_type', LearningModule::TYPE_ASSIGNMENT)
                ->where('module_id', $activity->id)
                ->first();
        }

        return null;
    }

    /**
     * Check if an actor can read a specific course.
     */
    public function canReadCourse(User $actor, Course $course): bool
    {
        if (! $course->is_active) {
            return false;
        }

        return $this->isActiveEnrollee($actor, $course)
            || $this->isInstructorForCourse($actor, $course);
    }

    /**
     * Check if an actor can read (see) a specific learning module.
     */
    public function canReadModule(User $actor, LearningModule $module): bool
    {
        $course = $module->course;

        // Instructors can see all modules in their course, including hidden ones
        if ($this->isInstructorForCourse($actor, $course)) {
            return true;
        }

        if (! $module->visible) {
            return false;
        }

        if (! $this->canReadCourse($actor, $course)) {
            return false;
        }

        // Check group-based availability rules with condition_group awareness.
        // Group rules are grouped by condition_group:
        // - Within each group, ALL rules must be satisfied (AND).
        // - Groups are OR-ed: if at least one group fully passes, the pre-filter passes.
        // - A condition_group that has no group rules (e.g., only completion rules)
        //   does not contribute to the group pre-filter — the user can potentially
        //   pass via that group's non-group rules.
        // - Rules with null condition_group are treated as singleton groups.
        // - This matches the OR-between-groups logic in ModuleAvailabilityService::availabilityFor().
        if (! $module->relationLoaded('availabilityRules')) {
            $module->load('availabilityRules');
        }

        $groupRules = $module->availabilityRules->where('rule_type', 'group');
        if ($groupRules->isNotEmpty()) {
            $groups = $groupRules->groupBy(function ($rule) {
                return $rule->condition_group ?? 'singleton_'.$rule->id;
            });

            $anyGroupPassed = false;
            foreach ($groups as $conditionGroupRules) {
                $groupPassed = true;
                foreach ($conditionGroupRules as $rule) {
                    if (! $rule->course_group_id) {
                        continue;
                    }
                    $inGroup = CourseGroupMember::query()
                        ->where('course_group_id', $rule->course_group_id)
                        ->where('user_id', $actor->id)
                        ->exists();

                    if (! $inGroup) {
                        $groupPassed = false;
                        break;
                    }
                }

                if ($groupPassed) {
                    $anyGroupPassed = true;
                    break;
                }
            }

            if (! $anyGroupPassed) {
                // If there are condition_groups WITHOUT any group rules, the user could
                // still potentially pass via those groups. Only block if ALL condition_groups
                // that have group rules fail.
                $allRuleGroups = $module->availabilityRules
                    ->whereNotNull('condition_group')
                    ->pluck('condition_group')
                    ->unique();
                $groupRuleGroups = $groupRules
                    ->whereNotNull('condition_group')
                    ->pluck('condition_group')
                    ->unique();
                $groupsWithOnlyNonGroupRules = $allRuleGroups->diff($groupRuleGroups);

                // Also handle null condition_group singleton rules
                $hasSingletonNullRules = $groupRules->whereNull('condition_group')->isNotEmpty();

                // If some condition_group exists with only non-group rules, allow pass
                // (user could potentially pass via that branch in availabilityFor())
                $nonGroupBranchAvailable = $groupsWithOnlyNonGroupRules->isNotEmpty();

                // If all singleton rules (null condition_group) fail AND no non-group
                // branch exists, block
                if (! $nonGroupBranchAvailable) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Check if an actor can submit to a specific assignment.
     */
    public function canSubmitAssignment(User $actor, Assignment $assignment): bool
    {
        $course = $assignment->course;

        if (! $course->is_active || ! $assignment->is_active) {
            return false;
        }

        $enrollment = $this->enrollment($actor, $course);

        if ($enrollment === null || ! $enrollment->isActive()) {
            return false;
        }

        // Only students can submit
        if ($enrollment->role !== 'student') {
            return false;
        }

        return true;
    }

    /**
     * Check if an actor can attempt a specific quiz.
     */
    public function canAttemptQuiz(User $actor, Quiz $quiz): bool
    {
        $course = $quiz->course;

        if (! $course->is_active || ! $quiz->is_active) {
            return false;
        }

        $enrollment = $this->enrollment($actor, $course);

        if ($enrollment === null || ! $enrollment->isActive()) {
            return false;
        }

        return $enrollment->role === 'student';
    }

    /**
     * Check if an actor can read the gradebook for a course.
     */
    public function canReadGradebook(User $actor, Course $course): bool
    {
        // Instructors can read gradebooks for courses they teach
        if ($this->isInstructorForCourse($actor, $course)) {
            return true;
        }

        // Students can read their own grades (but not the full gradebook)
        return false;
    }

    /**
     * Check if an actor can read (see) an activity through its learning module.
     * Combines course access + module availability rules.
     */
    public function canReadActivity(User $actor, LearningModule $module): bool
    {
        return $this->canReadModule($actor, $module);
    }

    /**
     * Check if an actor can act on an activity (submit, attempt, download) through its module.
     * Stricter than read: also checks date windows, prerequisites, min-grade, and group rules.
     */
    public function canActOnActivity(User $actor, LearningModule $module): bool
    {
        // Must be able to read the module first
        if (! $this->canReadModule($actor, $module)) {
            return false;
        }

        // Check full availability rules via ModuleAvailabilityService
        $availabilityService = app(ModuleAvailabilityService::class);
        $availability = $availabilityService->availabilityFor($actor, $module);

        return $availability['available'];
    }

    /**
     * Check if an actor can grade a specific submission.
     */
    public function canGradeSubmission(User $actor, Submission $submission): bool
    {
        $course = $submission->assignment->course;

        return $this->isInstructorForCourse($actor, $course);
    }

    /* ──────────────────────────────────────────────
     * Centralized Assertions
     * ────────────────────────────────────────────── */

    /**
     * Assert the actor can read a specific activity through its learning module.
     *
     * Combines course readability and module readability (visibility + group
     * restriction). Use this for direct activity detail endpoints (show,
     * questions) before returning activity data.
     *
     * Expects $activity->learningModule to be available (loaded or lazy-loadable).
     *
     * @throws \App\Exceptions\BusinessException
     */
    public function assertActivityReadable(User $actor, object $activity): void
    {
        $module = $activity->learningModule;

        if ($module === null) {
            throw new \App\Exceptions\BusinessException('Activity not found', 404);
        }

        $this->doAssertModuleReadable($actor, $module);
    }

    /**
     * Assert the actor can read a specific activity AND that the activity
     * is fully available (date windows, prerequisites, min-grade, group rules).
     *
     * This is stricter than assertActivityReadable() — it also checks
     * ModuleAvailabilityService rules. Use this for direct activity detail
     * endpoints (show, questions) where the response should match course
     * structure availability (same as assertActivityActionable but named
     * for read-level semantics).
     *
     * Expects $activity->learningModule to be available.
     *
     * @throws BusinessException
     */
    public function assertActivityAvailableForRead(User $actor, object $activity): void
    {
        $module = $activity->learningModule;

        if ($module === null) {
            throw new \App\Exceptions\BusinessException('Activity not found', 404);
        }

        $this->doAssertModuleReadable($actor, $module);

        // Instructors may inspect activities in their own course even when
        // student availability rules (prerequisite, min-grade, date windows)
        // would block them. Only enforce full availability for students.
        if ($this->isInstructorForCourse($actor, $module->course)) {
            return;
        }

        // Full availability check (date windows, prerequisites, min-grade, groups)
        $availabilityService = app(ModuleAvailabilityService::class);
        $availability = $availabilityService->availabilityFor($actor, $module);

        if (! $availability['available']) {
            throw new \App\Exceptions\BusinessException('Activity not available', 404);
        }
    }

    /**
     * Assert the actor can act on (submit, attempt) a specific activity.
     * Stricter than assertActivityReadable: also checks date windows,
     * prerequisites, min-grade, and group availability rules.
     *
     * Expects $activity->learningModule to be available.
     *
     * @throws \App\Exceptions\BusinessException
     */
    public function assertActivityActionable(User $actor, object $activity): void
    {
        $module = $activity->learningModule;

        if ($module === null) {
            throw new \App\Exceptions\BusinessException('Activity not found', 404);
        }

        $this->doAssertModuleReadable($actor, $module);

        // Full availability check (date windows, prerequisites, min-grade, groups)
        $availabilityService = app(\App\Services\ModuleAvailabilityService::class);
        $availability = $availabilityService->availabilityFor($actor, $module);

        if (! $availability['available']) {
            throw new \App\Exceptions\BusinessException('Activity not available', 404);
        }
    }

    /**
     * Internal: assert module-level readability (course access + module visibility
     * + group restriction).
     *
     * @throws \App\Exceptions\BusinessException
     */
    private function doAssertModuleReadable(User $actor, LearningModule $module): void
    {
        // Ensure course is available
        if (! $module->relationLoaded('course')) {
            $module->load('course');
        }

        $course = $module->course;

        if (! $this->canReadCourse($actor, $course)) {
            throw new \App\Exceptions\BusinessException('Access denied', 403);
        }

        if (! $this->canReadModule($actor, $module)) {
            throw new \App\Exceptions\BusinessException('Module not accessible', 404);
        }
    }

    /* ──────────────────────────────────────────────
     * Helpers
     * ────────────────────────────────────────────── */

    /**
     * Whether the actor has an active student enrolment in the course.
     * Uses context-based authorization: checks if the user has the 'student'
     * role at the course context.
     */
    public function isActiveEnrollee(User $actor, Course $course): bool
    {
        $courseContext = $this->contextService->find(
            \App\Models\Context::LEVEL_COURSE,
            $course->id
        );

        if ($courseContext === null) {
            // Fallback to flat enrollment check if context not yet created
            return $this->flatEnrolleeCheck($actor, $course);
        }

        return $this->authorizationService->userHasRoleAt($actor, 'student', $courseContext);
    }

    /**
     * Whether the actor is an instructor for the course.
     * Uses context-based authorization: checks if the user has the 'instructor'
     * role at the course context or is the course owner.
     */
    public function isInstructorForCourse(User $actor, Course $course): bool
    {
        // Course owner is always an instructor
        if ($course->instructor_id === $actor->id) {
            return true;
        }

        $courseContext = $this->contextService->find(
            \App\Models\Context::LEVEL_COURSE,
            $course->id
        );

        if ($courseContext === null) {
            // Fallback to flat enrollment check if context not yet created
            return $this->flatInstructorCheck($actor, $course);
        }

        return $this->authorizationService->userHasRoleAt($actor, 'instructor', $courseContext);
    }

    /**
     * Get the enrollment record for an actor in a course.
     */
    private function enrollment(User $actor, Course $course): ?CourseEnrollment
    {
        /** @var CourseEnrollment|null $enrollment */
        $enrollment = CourseEnrollment::query()
            ->where('course_id', $course->id)
            ->where('user_id', $actor->id)
            ->first();

        return $enrollment;
    }

    /**
     * Fallback: flat enrollment-based active enrollee check for backward compatibility.
     */
    private function flatEnrolleeCheck(User $actor, Course $course): bool
    {
        $enrollment = $this->enrollment($actor, $course);

        return $enrollment !== null && $enrollment->isActive();
    }

    /**
     * Fallback: flat enrollment-based instructor check for backward compatibility.
     */
    private function flatInstructorCheck(User $actor, Course $course): bool
    {
        return CourseEnrollment::query()
            ->where('course_id', $course->id)
            ->where('user_id', $actor->id)
            ->where('role', 'instructor')
            ->where('status', 'active')
            ->exists();
    }
}
