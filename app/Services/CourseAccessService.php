<?php

namespace App\Services;

use App\Models\Assignment;
use App\Models\Context;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\CourseGroupMember;
use App\Models\LearningModule;
use App\Models\Material;
use App\Models\Quiz;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Support\Collection;

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

        // Use capability check when context exists, fallback to role-based check
        $courseContext = $this->contextService->find(\App\Models\Context::LEVEL_COURSE, $course->id);
        if ($courseContext === null) {
            return $this->isActiveEnrollee($actor, $course)
                || $this->isInstructorForCourse($actor, $course);
        }

        return $this->authorizationService->userHasCapabilityAt($actor, 'course:view', $courseContext);
    }

    /**
     * Check if an actor can read (see) a specific learning module.
     */
    public function canReadModule(User $actor, LearningModule $module): bool
    {
        $course = $module->course;

        // Users with module:ignore-availability capability can see all modules
        $moduleContext = $this->contextService->find(\App\Models\Context::LEVEL_MODULE, $module->id);
        if ($moduleContext !== null) {
            if ($this->authorizationService->userHasCapabilityAt($actor, 'module:ignore-availability', $moduleContext)) {
                return true;
            }
        } else {
            // Fallback: module context may not exist (e.g., test factories)
            // Check capability via course context or old role-based check
            $courseContext = $this->contextService->find(\App\Models\Context::LEVEL_COURSE, $course->id);
            if ($courseContext !== null) {
                if ($this->authorizationService->userHasCapabilityAt($actor, 'module:ignore-availability', $courseContext)) {
                    return true;
                }
            } elseif ($this->isInstructorForCourse($actor, $course)) {
                return true;
            }
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

        $courseContext = $this->contextService->find(\App\Models\Context::LEVEL_COURSE, $course->id);
        if ($courseContext === null) {
            $enrollment = $this->enrollment($actor, $course);

            if ($enrollment === null || ! $enrollment->isActive()) {
                return false;
            }

            return $enrollment->role === 'student';
        }

        return $this->authorizationService->userHasCapabilityAt($actor, 'assignment:submit', $courseContext);
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

        $courseContext = $this->contextService->find(\App\Models\Context::LEVEL_COURSE, $course->id);
        if ($courseContext === null) {
            $enrollment = $this->enrollment($actor, $course);

            if ($enrollment === null || ! $enrollment->isActive()) {
                return false;
            }

            return $enrollment->role === 'student';
        }

        return $this->authorizationService->userHasCapabilityAt($actor, 'quiz:attempt', $courseContext);
    }

    /**
     * Check if an actor can read the gradebook for a course.
     */
    public function canReadGradebook(User $actor, Course $course): bool
    {
        // Check gradebook:view capability
        $courseContext = $this->contextService->find(\App\Models\Context::LEVEL_COURSE, $course->id);
        if ($courseContext === null) {
            if ($this->isInstructorForCourse($actor, $course)) {
                return true;
            }

            return false;
        }

        return $this->authorizationService->userHasCapabilityAt($actor, 'gradebook:view', $courseContext);
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

        $courseContext = $this->contextService->find(\App\Models\Context::LEVEL_COURSE, $course->id);
        if ($courseContext === null) {
            return $this->isInstructorForCourse($actor, $course);
        }

        return $this->authorizationService->userHasCapabilityAt($actor, 'assignment:grade', $courseContext);
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

        // Users with module:ignore-availability capability may inspect activities
        // even when student availability rules (prerequisite, min-grade, date windows)
        // would block them. Only enforce full availability for others.
        $bypassAvailable = false;
        $moduleContext = $this->contextService->find(\App\Models\Context::LEVEL_MODULE, $module->id);
        if ($moduleContext !== null) {
            $bypassAvailable = $this->authorizationService->userHasCapabilityAt($actor, 'module:ignore-availability', $moduleContext);
        } else {
            // Fallback for factory-created modules without context
            $courseContext = $this->contextService->find(\App\Models\Context::LEVEL_COURSE, $module->course->id);
            if ($courseContext !== null) {
                $bypassAvailable = $this->authorizationService->userHasCapabilityAt($actor, 'module:ignore-availability', $courseContext);
            } else {
                $bypassAvailable = $this->isInstructorForCourse($actor, $module->course);
            }
        }

        if ($bypassAvailable) {
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
     * Batch Helpers
     * ────────────────────────────────────────────── */

    /**
     * Compute readability for a collection of modules in bulk.
     *
     * Resolves module contexts, instructor bypass capability, visibility,
     * course readability, and group-rule membership in a minimal set of queries,
     * then returns a map of module_id => bool.
     *
     * Intended for use in course-structure and list endpoints where per-module
     * canReadModule() would cause N+1 context, capability, and group queries.
     *
     * @param Collection<int, LearningModule> $modules  Must have availabilityRules relation loaded.
     */
    public function readableModulesFor(User $actor, Course $course, Collection $modules): Collection
    {
        $moduleIds = $modules->pluck('id');

        // Short-circuit: empty collection
        if ($moduleIds->isEmpty()) {
            return collect();
        }

        // 1. Course readability (resolved once)
        $courseReadable = $this->canReadCourse($actor, $course);

        // 2. Batch-load module contexts (one query)
        $moduleContexts = Context::query()
            ->where('contextlevel', Context::LEVEL_MODULE)
            ->whereIn('instance_id', $moduleIds)
            ->get()
            ->keyBy('instance_id');

        // 3. Batch-load course context (one query)
        $courseContext = $this->contextService->find(Context::LEVEL_COURSE, $course->id);

        // 4. Determine if actor has module:ignore-availability bypass or is instructor.
        //    Instructors bypass all visibility checks. We batch-check capabilities
        //    and then apply instructor fallback for modules without contexts.
        $isInstructor = $this->isInstructorForCourse($actor, $course);
        $bypassModuleIds = $this->resolveBypassModules($actor, $modules, $moduleContexts, $courseContext, $isInstructor);

        // 5. Collect all group IDs referenced in group-type availability rules
        $allGroupRules = $modules->flatMap(fn ($m) => $m->availabilityRules->where('rule_type', 'group'));
        $groupIds = $allGroupRules->pluck('course_group_id')->unique()->filter();

        // 6. Batch-load group memberships for the actor (one query)
        $groupMembershipIds = $groupIds->isNotEmpty()
            ? CourseGroupMember::query()
                ->whereIn('course_group_id', $groupIds)
                ->where('user_id', $actor->id)
                ->pluck('course_group_id')
            : collect();

        // 7. Compute readability per module
        $readableMap = collect();

        foreach ($modules as $module) {
            // Bypass: instructor with ignore-availability capability
            if ($bypassModuleIds->contains($module->id)) {
                $readableMap->put($module->id, true);
                continue;
            }

            // Visibility check
            if (! $module->visible) {
                $readableMap->put($module->id, false);
                continue;
            }

            // Course readability
            if (! $courseReadable) {
                $readableMap->put($module->id, false);
                continue;
            }

            // Group-based availability rules with condition_group awareness
            $groupRules = $module->availabilityRules->where('rule_type', 'group');
            if ($groupRules->isNotEmpty()) {
                $groups = $groupRules->groupBy(fn ($rule) => $rule->condition_group ?? 'singleton_'.$rule->id);

                $anyGroupPassed = false;
                foreach ($groups as $conditionGroupRules) {
                    $groupPassed = true;
                    foreach ($conditionGroupRules as $rule) {
                        if (! $rule->course_group_id) {
                            continue;
                        }
                        if (! $groupMembershipIds->contains($rule->course_group_id)) {
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
                    $allRuleGroups = $module->availabilityRules
                        ->whereNotNull('condition_group')
                        ->pluck('condition_group')
                        ->unique();
                    $groupRuleGroups = $groupRules
                        ->whereNotNull('condition_group')
                        ->pluck('condition_group')
                        ->unique();
                    $groupsWithOnlyNonGroupRules = $allRuleGroups->diff($groupRuleGroups);
                    $hasSingletonNullRules = $groupRules->whereNull('condition_group')->isNotEmpty();
                    $nonGroupBranchAvailable = $groupsWithOnlyNonGroupRules->isNotEmpty();

                    if (! $nonGroupBranchAvailable) {
                        $readableMap->put($module->id, false);
                        continue;
                    }
                }
            }

            $readableMap->put($module->id, true);
        }

        return $readableMap;
    }

    /**
     * Resolve which module IDs the actor can bypass visibility for.
     *
     * Checks module:ignore-availability capability at module contexts or
     * the course context (instructor bypass). Also applies the fallback
     * from canReadModule: if both module and course context are missing,
     * instructors get bypass. Returns a collection of module IDs that
     * have the bypass.
     *
     * @param Collection<int, LearningModule> $modules
     * @param Collection<int, Context> $moduleContexts  keyed by instance_id
     */
    private function resolveBypassModules(
        User $actor,
        Collection $modules,
        Collection $moduleContexts,
        ?Context $courseContext,
        bool $isInstructor,
    ): Collection {
        // Collect all context IDs we need to check
        $allContextIds = $moduleContexts->pluck('id');
        if ($courseContext !== null) {
            $allContextIds = $allContextIds->push($courseContext->id);
        }

        if ($allContextIds->isEmpty()) {
            // Fallback: if no contexts exist, instructors bypass all
            return $isInstructor ? $modules->pluck('id') : collect();
        }

        // Find the 'module:ignore-availability' capability ID
        $capId = \App\Models\Capability::query()
            ->where('shortname', 'module:ignore-availability')
            ->value('id');

        if ($capId === null) {
            return $isInstructor ? $modules->pluck('id') : collect();
        }

        // Find role IDs that grant this capability
        $roleIds = \App\Models\RoleCapability::query()
            ->where('capability_id', $capId)
            ->pluck('role_id');

        if ($roleIds->isEmpty()) {
            return $isInstructor ? $modules->pluck('id') : collect();
        }

        // Batch-check: does the actor have any of these roles at any relevant context?
        $assignedContextIds = \App\Models\RoleAssignment::query()
            ->whereIn('role_id', $roleIds)
            ->whereIn('context_id', $allContextIds)
            ->where('user_id', $actor->id)
            ->pluck('context_id');

        if ($assignedContextIds->isEmpty()) {
            // Fallback: instructors bypass modules without valid contexts
            if ($isInstructor) {
                // Modules without a module context get bypass via instructor fallback
                $modulesWithContext = $moduleContexts->keys();
                return $modules->pluck('id')->diff($modulesWithContext);
            }
            return collect();
        }

        // If course context has the bypass, all modules get it
        if ($courseContext !== null && $assignedContextIds->contains($courseContext->id)) {
            return $modules->pluck('id');
        }

        // Otherwise, find which specific module contexts have the bypass
        $bypassModuleInstanceIds = $moduleContexts
            ->filter(fn ($ctx) => $assignedContextIds->contains($ctx->id))
            ->keys();

        // Instructors also bypass modules without a module context
        if ($isInstructor) {
            $modulesWithoutContext = $modules
                ->filter(fn ($m) => ! $moduleContexts->has($m->id))
                ->pluck('id');
            $bypassModuleInstanceIds = $bypassModuleInstanceIds->merge($modulesWithoutContext);
        }

        return $bypassModuleInstanceIds;
    }

    /* ──────────────────────────────────────────────
     * Helpers
     * ────────────────────────────────────────────── */

    /**
     * Check if there is at least one active enrolment method for the course.
     * This replicates Moodle's check that a plugin-based enrolment method
     * must be active for user enrolments to be valid.
     */
    public function hasActiveEnrolmentMethod(Course $course): bool
    {
        return \App\Models\CourseEnrolmentMethod::query()
            ->where('course_id', $course->id)
            ->where('status', 'active')
            ->where(function ($q) {
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('ends_at')->orWhere('ends_at', '>=', now());
            })
            ->exists();
    }

    /**
     * Whether the actor has an active student enrolment in the course.
     * Uses context-based authorization: checks if the user has the 'student'
     * role at the course context. Also checks that the course has at least
     * one active enrolment method (Plan 05).
     */
    public function isActiveEnrollee(User $actor, Course $course): bool
    {
        // First check the course has an active enrolment method
        if (! $this->hasActiveEnrolmentMethod($course)) {
            return false;
        }

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
