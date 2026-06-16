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
     * Determine the availability state label for a non-available module.
     */
    protected function resolveState(string $reason): string
    {
        return match ($reason) {
            'hidden' => 'hidden',
            'not_yet_available' => 'not_yet_available',
            'no_longer_available' => 'expired',
            default => 'locked',
        };
    }

    /**
     * Get structured availability metadata for a user on a learning module.
     *
     * Returns a rich shape with state, reasons, and failed-rule details,
     * designed for course structure responses.
     *
     * @return array{available: bool, reason: ?string, availability: array{state: string, primary_reason: ?string, visible_to_user: bool, failed_rules: array}}
     */
    public function structuredAvailabilityFor(User $actor, LearningModule $module, bool $isInstructor = false): array
    {
        // Instructors bypass availability rules unless we specifically want
        // to show the availability metadata even for them (for instructor UIs).
        if ($isInstructor) {
            return [
                'available' => true,
                'reason' => null,
                'availability' => [
                    'state' => 'available',
                    'primary_reason' => null,
                    'visible_to_user' => true,
                    'failed_rules' => [],
                    'instructor_bypass' => true,
                ],
            ];
        }

        // Get base availability (flat result)
        $flat = $this->availabilityForInternal($actor, $module, true);
        $available = $flat['available'];
        $reason = $flat['reason'];
        $failedRules = $flat['_failed_rules'] ?? [];

        $state = $available ? 'available' : $this->resolveState($reason ?? 'restricted');

        // visible_to_user: hidden modules are invisible, everything else is visible
        $visibleToUser = $reason !== 'hidden';

        return [
            'available' => $available,
            'reason' => $reason,
            'availability' => [
                'state' => $state,
                'primary_reason' => $reason,
                'visible_to_user' => $visibleToUser,
                'failed_rules' => $failedRules,
            ],
        ];
    }

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
        return $this->availabilityForInternal($actor, $module, false);
    }

    /**
     * Internal availability check.
     * When $collectFailedRules is true, returns additional _failed_rules metadata
     * with structured details about each failing rule.
     *
     * @return array{available: bool, reason: ?string, _failed_rules?: array}
     */
    protected function availabilityForInternal(User $actor, LearningModule $module, bool $collectFailedRules = false): array
    {
        // 1. Check basic visibility (visible flag)
        if (! $module->visible) {
            $result = ['available' => false, 'reason' => 'hidden'];
            if ($collectFailedRules) {
                $result['_failed_rules'] = [];
            }

            return $result;
        }

        // 2. Check date window (available_from, available_until on the module itself)
        $now = now();
        $failedRules = $collectFailedRules ? [] : null;

        if ($module->available_from && $module->available_from->gt($now)) {
            $result = ['available' => false, 'reason' => 'not_yet_available'];
            if ($collectFailedRules) {
                $result['_failed_rules'] = [
                    [
                        'type' => 'date',
                        'operator' => 'after',
                        'threshold' => $module->available_from->toIso8601String(),
                    ],
                ];
            }

            return $result;
        }
        if ($module->available_until && $module->available_until->lt($now)) {
            $result = ['available' => false, 'reason' => 'no_longer_available'];
            if ($collectFailedRules) {
                $result['_failed_rules'] = [
                    [
                        'type' => 'date',
                        'operator' => 'before',
                        'threshold' => $module->available_until->toIso8601String(),
                    ],
                ];
            }

            return $result;
        }

        // 3. Load and check availability rules with grouped condition evaluation
        $rules = $module->availabilityRules;

        if ($rules->isEmpty()) {
            $result = ['available' => true, 'reason' => null];
            if ($collectFailedRules) {
                $result['_failed_rules'] = [];
            }

            return $result;
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
                $result = ['available' => true, 'reason' => null];
                if ($collectFailedRules) {
                    $result['_failed_rules'] = [];
                }

                return $result;
            }
        }

        // No group passed — collect failed rules or find first failure
        if ($collectFailedRules) {
            $allFailed = [];
            foreach ($groups as $groupRules) {
                foreach ($groupRules as $rule) {
                    if (! $this->checkRule($actor, $module, $rule)) {
                        $allFailed[] = $this->buildFailedRuleDetail($actor, $module, $rule);
                    }
                }
            }

            return [
                'available' => false,
                'reason' => $allFailed[0]['reason'] ?? 'restricted',
                '_failed_rules' => $allFailed,
            ];
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
     * Build structured failed-rule detail for a single rule.
     */
    protected function buildFailedRuleDetail(User $actor, LearningModule $module, ModuleAvailabilityRule $rule): array
    {
        $detail = [
            'type' => $rule->rule_type,
            'reason' => $this->reasonFor($rule),
        ];

        if ($rule->operator) {
            $detail['operator'] = $rule->operator;
        }

        if ($rule->value !== null) {
            $detail['threshold'] = $rule->rule_type === 'date'
                ? $rule->value
                : (float) $rule->value;
        }

        if ($rule->rule_type === 'completion' && $rule->required_module_id) {
            $detail['required_module_id'] = $rule->required_module_id;
        }

        if ($rule->rule_type === 'min_grade' && $rule->grade_item_id) {
            $detail['grade_item_id'] = $rule->grade_item_id;
        }

        if ($rule->rule_type === 'group' && $rule->course_group_id) {
            $detail['course_group_id'] = $rule->course_group_id;
        }

        if ($rule->rule_type === 'group' && $rule->course_grouping_id) {
            $detail['course_grouping_id'] = $rule->course_grouping_id;
        }

        return $detail;
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
