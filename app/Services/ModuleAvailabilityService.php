<?php

namespace App\Services;

use App\Models\CourseGroupMember;
use App\Models\Grade;
use App\Models\LearningModule;
use App\Models\ModuleAvailabilityRule;
use App\Models\ModuleCompletion;
use App\Models\User;
use Carbon\Carbon;

class ModuleAvailabilityService
{
    public function __construct(
        protected CourseAccessService $courseAccessService
    ) {}

    /**
     * Get availability state for a user on a learning module.
     *
     * Evaluates rules grouped by condition_group:
     * - Rules with the same condition_group are AND-ed together.
     * - Groups are OR-ed against each other.
     * - null condition_group means the rule stands alone (singleton group).
     *
     * @return array{available: bool, reason: ?string}
     */
    public function availabilityFor(User $actor, LearningModule $module): array
    {
        // 1. Check basic visibility (visible flag)
        if (! $module->visible) {
            return ['available' => false, 'reason' => 'hidden'];
        }

        // 2. Check date window (available_from, available_until on the module itself)
        $now = now();
        if ($module->available_from && $module->available_from->gt($now)) {
            return ['available' => false, 'reason' => 'not_yet_available'];
        }
        if ($module->available_until && $module->available_until->lt($now)) {
            return ['available' => false, 'reason' => 'no_longer_available'];
        }

        // 3. Load and check availability rules with grouped condition evaluation
        $rules = $module->availabilityRules;

        if ($rules->isEmpty()) {
            return ['available' => true, 'reason' => null];
        }

        // Group rules by condition_group
        // null groups are treated as singleton groups (each rule in its own group)
        $groups = $rules->groupBy(function ($rule) {
            return $rule->condition_group ?? 'singleton_'.$rule->id;
        });

        // OR between groups: at least one group must pass
        foreach ($groups as $groupRules) {
            $groupPassed = true;

            // AND within each group: ALL rules must pass
            foreach ($groupRules as $rule) {
                if (! $this->checkRule($actor, $module, $rule)) {
                    $groupPassed = false;
                    break;
                }
            }

            if ($groupPassed) {
                return ['available' => true, 'reason' => null];
            }
        }

        // No group passed — find reason from the first failing group
        foreach ($groups as $groupRules) {
            foreach ($groupRules as $rule) {
                if (! $this->checkRule($actor, $module, $rule)) {
                    return ['available' => false, 'reason' => $this->reasonFor($rule)];
                }
            }
        }

        return ['available' => false, 'reason' => 'restricted'];
    }

    /**
     * Check a single availability rule.
     */
    protected function checkRule(User $actor, LearningModule $module, ModuleAvailabilityRule $rule): bool
    {
        return match ($rule->rule_type) {
            'date' => $this->checkDateRule($rule),
            'completion' => $this->checkCompletionRule($actor, $rule),
            'min_grade' => $this->checkMinGradeRule($actor, $module, $rule),
            'group' => $this->checkGroupRule($actor, $rule),
            default => true,
        };
    }

    protected function checkDateRule(ModuleAvailabilityRule $rule): bool
    {
        // operator: before|after|>=|<=, value: ISO date string
        if (! $rule->value) {
            return true;
        }

        $date = Carbon::parse($rule->value);

        return match ($rule->operator) {
            'before' => now()->lt($date),
            'after' => now()->gt($date),
            '>=' => now()->gte($date),
            '<=' => now()->lte($date),
            default => true,
        };
    }

    protected function checkCompletionRule(User $actor, ModuleAvailabilityRule $rule): bool
    {
        // Check if actor has completed the required_module
        if (! $rule->required_module_id) {
            return true;
        }

        $completion = ModuleCompletion::query()
            ->where('learning_module_id', $rule->required_module_id)
            ->where('user_id', $actor->id)
            ->first();

        if (! $completion) {
            return false;
        }

        // operator: '==' means any completion; '>=' means passed or better
        return match ($rule->operator) {
            '==' => $completion->state !== 'incomplete',
            '>=' => in_array($completion->state, ['complete', 'complete_passed']),
            default => $completion->state !== 'incomplete',
        };
    }

    protected function checkMinGradeRule(User $actor, LearningModule $module, ModuleAvailabilityRule $rule): bool
    {
        if (! $rule->grade_item_id) {
            return true;
        }

        // Query the specific grade item's grade for this user
        $grade = Grade::query()
            ->where('user_id', $actor->id)
            ->where('grade_item_id', $rule->grade_item_id)
            ->where('status', 'final')
            ->first();

        if (! $grade) {
            return false;
        }

        $threshold = $rule->value ? (float) $rule->value : 0;

        return match ($rule->operator) {
            '>=' => ($grade->percentage ?? 0) >= $threshold,
            '<=' => ($grade->percentage ?? 0) <= $threshold,
            '>' => ($grade->percentage ?? 0) > $threshold,
            '<' => ($grade->percentage ?? 0) < $threshold,
            default => ($grade->percentage ?? 0) >= $threshold,
        };
    }

    protected function checkGroupRule(User $actor, ModuleAvailabilityRule $rule): bool
    {
        // Direct group membership check
        if ($rule->course_group_id) {
            return CourseGroupMember::query()
                ->where('course_group_id', $rule->course_group_id)
                ->where('user_id', $actor->id)
                ->exists();
        }

        // Grouping membership check: user must be in any active group inside the grouping
        if ($rule->course_grouping_id) {
            // Inactive groupings do not grant access
            $grouping = \App\Models\CourseGrouping::query()->find($rule->course_grouping_id);
            if (! $grouping || ! $grouping->active) {
                return false;
            }

            $groupIdsInGrouping = \App\Models\CourseGroupingGroup::query()
                ->where('course_grouping_id', $rule->course_grouping_id)
                ->pluck('course_group_id');

            if ($groupIdsInGrouping->isEmpty()) {
                return false;
            }

            return CourseGroupMember::query()
                ->whereIn('course_group_id', $groupIdsInGrouping)
                ->where('user_id', $actor->id)
                ->exists();
        }

        // No group or grouping constraint
        return true;
    }

    protected function reasonFor(ModuleAvailabilityRule $rule): string
    {
        return match ($rule->rule_type) {
            'date' => 'date_restriction',
            'completion' => 'prerequisite_not_met',
            'min_grade' => 'minimum_grade_not_met',
            'group' => 'group_restriction',
            default => 'restricted',
        };
    }
}
