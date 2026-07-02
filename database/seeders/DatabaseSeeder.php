<?php

namespace Database\Seeders;

use App\Models\Assignment;
use App\Models\Context;
use App\Models\Course;
use App\Models\Grade;
use App\Models\Quiz;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    private const BATCH_SIZE = 500;

    private const INSTRUCTOR_COUNT = 40;

    private const STUDENT_COUNT = 1960;

    private const MIN_STUDENTS_PER_COURSE = 30;

    private const MAX_STUDENTS_PER_COURSE = 60;

    private const MATERIALS_PER_COURSE = 12;

    private const QUIZZES_PER_COURSE = 4;

    private const ASSIGNMENTS_PER_COURSE = 12;

    private const MIN_QUESTIONS_PER_QUIZ = 25;

    private const MAX_QUESTIONS_PER_QUIZ = 50;

    private const QUIZ_ATTEMPTS_PER_STUDENT = 3;

    private array $roleIdMap = [];

    public function run(): void
    {
        ini_set('memory_limit', '512M');

        DB::disableQueryLog();
        $this->command->info('--- LMS Benchmark Seed (deterministic, bulk) ---');

        // ─── 1. Base tables ───────────────────────────────────
        $this->seedRoles();
        $systemContextId = $this->seedSystemContext();
        $this->seedRoleAssignments($systemContextId);

        // ─── 2. Users ─────────────────────────────────────────
        $this->command->info('Creating 2,000 users (40 instructors + 1,960 students)...');
        $instructorIds = $this->bulkInsertUsers('instructor', self::INSTRUCTOR_COUNT);
        $studentIds = $this->bulkInsertUsers('student', self::STUDENT_COUNT);
        $allUserIds = array_merge($instructorIds, $studentIds);
        $this->command->info('  -> '.count($allUserIds).' users created');

        // ─── 3. Course Categories ──────────────────────────────
        $categoryIds = $this->seedCourseCategories();
        $this->command->info('  -> '.count($categoryIds).' categories created');

        // ─── 4. Courses (50 total: 10 detailed + 40 generated) ─
        $this->command->info('Creating 50 courses...');
        $courseDefs = $this->generateCourseDefs($instructorIds, $categoryIds);
        $courseIds = [];
        $courseCategoryMap = [];

        // --- 4a. Detailed courses (indices 0-9) ---
        $detailedDefs = $this->detailedCourseDefs($instructorIds, $categoryIds);

        // --- 4b. Generated courses (indices 10-49) ---
        $generatedDefs = $this->generatedCourseDefs($instructorIds, $categoryIds, 40);

        $allCourseDefs = $this->withBenchmarkActivityTargets(array_merge($detailedDefs, $generatedDefs));

        foreach ($allCourseDefs as $ci => $def) {
            $courseIds[] = DB::table('courses')->insertGetId([
                'name' => $def['name'],
                'description' => $def['description'],
                'instructor_id' => $def['instructor_id'],
                'is_active' => $def['is_active'],
                'course_category_id' => $def['course_category_id'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $courseCategoryMap[$courseIds[$ci]] = $def['course_category_id'];

            // Course context
            $courseCtxId = DB::table('contexts')->insertGetId([
                'contextlevel' => Context::LEVEL_COURSE,
                'instance_id' => $courseIds[$ci],
                'path' => '/'.$systemContextId.'/'.$courseIds[$ci],
                'depth' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Instructor role assignment at course context
            DB::table('role_assignments')->insert([
                'role_id' => $this->roleIdMap['instructor'],
                'context_id' => $courseCtxId,
                'user_id' => $def['instructor_id'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Course enrolment method
            DB::table('course_enrolment_methods')->insert([
                'course_id' => $courseIds[$ci],
                'method' => 'manual',
                'status' => 'active',
                'default_role' => 'student',
                'starts_at' => now()->subYears(1),
                'ends_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $this->command->info('  -> '.count($courseIds).' courses created');

        // ─── 5. Sections + Modules + Activities ────────────────
        $this->command->info('Creating sections, modules, and activities...');
        $sectionIds = [];
        $moduleIds = []; // [courseIdx => [sectionTitle => [module_id, type, title, visible, completion_enabled]]]
        $activityIds = []; // [courseIdx => {quiz_ids: [...], assignment_ids: [...], material_ids: [...]}]
        $createdQuizIds = [];
        $createdAssignmentIds = [];
        $createdMaterialIds = [];

        foreach ($allCourseDefs as $ci => $def) {
            $courseId = $courseIds[$ci];
            $moduleIds[$ci] = [];
            $sectionNames = $def['sections'];
            $sectionNameToId = [];

            foreach ($sectionNames as $si => $sectionTitle) {
                $secId = DB::table('course_sections')->insertGetId([
                    'course_id' => $courseId,
                    'title' => $sectionTitle,
                    'summary' => 'Summary for '.$sectionTitle.'.',
                    'sort_order' => $si,
                    'visible' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $sectionIds[] = $secId;
                $sectionNameToId[$sectionTitle] = $secId;
            }

            // Activities per section
            $sortOrder = 0;
            foreach ($def['activities'] as $sectionTitle => $acts) {
                $secId = $sectionNameToId[$sectionTitle] ?? null;
                if (! $secId) {
                    continue;
                }

                foreach ($acts as $act) {
                    $type = $act['type'];
                    $aTitle = $act['title'];
                    $visible = $act['visible'] ?? true;
                    $completionEnabled = $act['completion_enabled'] ?? false;

                    $activityId = $this->insertActivity($type, $aTitle, $courseId, $visible, $act);

                    if ($type === 'quiz') {
                        $createdQuizIds[$ci][] = $activityId;
                    } elseif ($type === 'assignment') {
                        $createdAssignmentIds[$ci][] = $activityId;
                    } elseif ($type === 'material') {
                        $createdMaterialIds[$ci][] = $activityId;
                    }

                    // Learning Module
                    $moduleId = DB::table('learning_modules')->insertGetId([
                        'course_id' => $courseId,
                        'course_section_id' => $secId,
                        'module_type' => $type,
                        'module_id' => $activityId,
                        'visible' => $visible,
                        'available_from' => $act['available_from'] ?? null,
                        'available_until' => $act['available_until'] ?? null,
                        'sort_order' => $sortOrder++,
                        'completion_enabled' => $completionEnabled,
                        'completion_rule' => $completionEnabled ? ($type === 'quiz' ? 'finish' : ($type === 'assignment' ? 'submit' : 'view')) : null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $moduleIds[$ci][] = [
                        'id' => $moduleId,
                        'type' => $type,
                        'title' => $aTitle,
                        'visible' => $visible,
                        'completion_enabled' => $completionEnabled,
                    ];

                    // Module context
                    $courseCtxRow = DB::table('contexts')
                        ->where('contextlevel', Context::LEVEL_COURSE)
                        ->where('instance_id', $courseId)
                        ->first();
                    if ($courseCtxRow) {
                        DB::table('contexts')->insert([
                            'contextlevel' => Context::LEVEL_MODULE,
                            'instance_id' => $moduleId,
                            'path' => $courseCtxRow->path.'/'.$moduleId,
                            'depth' => $courseCtxRow->depth + 1,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }
            }
        }
        $this->command->info('  -> '.count($sectionIds).' sections, activities created');

        // ─── 6. Enrollments ────────────────────────────────────
        $this->command->info('Creating enrollments (30-60 students per course)...');
        $enrollmentCount = 0;
        $enrolledUserIdsByCourse = [];

        mt_srand(42);
        foreach ($courseIds as $ci => $courseId) {
            $count = mt_rand(self::MIN_STUDENTS_PER_COURSE, self::MAX_STUDENTS_PER_COURSE);
            $pool = $studentIds;
            shuffle($pool);
            $selected = array_slice($pool, 0, min($count, count($pool)));
            $enrolledUserIdsByCourse[$courseId] = $selected;

            $batch = [];
            foreach ($selected as $uid) {
                $batch[] = [
                    'user_id' => $uid,
                    'course_id' => $courseId,
                    'role' => 'student',
                    'status' => 'active',
                    'enrolled_at' => now()->subDays(mt_rand(30, 90)),
                    'starts_at' => now()->subDays(mt_rand(30, 90)),
                    'ends_at' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                if (count($batch) >= self::BATCH_SIZE) {
                    DB::table('course_enrollments')->insert($batch);
                    $enrollmentCount += count($batch);
                    $batch = [];
                }
            }
            if (! empty($batch)) {
                DB::table('course_enrollments')->insert($batch);
                $enrollmentCount += count($batch);
            }

            // Role assignment for student enrollments
            $raBatch = [];
            foreach ($selected as $uid) {
                $courseCtxRow = DB::table('contexts')
                    ->where('contextlevel', Context::LEVEL_COURSE)
                    ->where('instance_id', $courseId)
                    ->first();
                if ($courseCtxRow) {
                    $raBatch[] = [
                        'role_id' => $this->roleIdMap['student'],
                        'context_id' => $courseCtxRow->id,
                        'user_id' => $uid,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
                if (count($raBatch) >= self::BATCH_SIZE) {
                    DB::table('role_assignments')->insert($raBatch);
                    $raBatch = [];
                }
            }
            if (! empty($raBatch)) {
                DB::table('role_assignments')->insert($raBatch);
            }

            // Instructor enrollment
            $def = $allCourseDefs[$ci];
            DB::table('course_enrollments')->insert([
                'user_id' => $def['instructor_id'],
                'course_id' => $courseId,
                'role' => 'instructor',
                'status' => 'active',
                'enrolled_at' => now()->subDays(60),
                'starts_at' => now()->subDays(60),
                'ends_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $enrollmentCount++;
        }
        $this->command->info('  -> '.$enrollmentCount.' enrollments created');

        // ─── 7. Grade Items ────────────────────────────────────
        $this->command->info('Creating grade items...');
        $gradeItemIdsByCourse = []; // [courseId => [type_itemId => gradeItemId]]
        foreach ($courseIds as $ci => $courseId) {
            $gradeItemIdsByCourse[$courseId] = [];
            $quizIds = $createdQuizIds[$ci] ?? [];
            foreach ($quizIds as $qid) {
                $giId = DB::table('grade_items')->insertGetId([
                    'course_id' => $courseId,
                    'item_type' => 'quiz',
                    'item_id' => $qid,
                    'name' => $this->getQuizTitle($qid).' - Grade',
                    'max_score' => 100,
                    'pass_score' => 60,
                    'weight' => round(mt_rand(50, 200) / 100, 2),
                    'hidden' => false,
                    'locked' => false,
                    'source' => 'quiz',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $gradeItemIdsByCourse[$courseId]['quiz_'.$qid] = $giId;
            }
            $assignIds = $createdAssignmentIds[$ci] ?? [];
            foreach ($assignIds as $aid) {
                $maxScore = $this->getAssignmentMaxScore($aid) ?: 100;
                $giId = DB::table('grade_items')->insertGetId([
                    'course_id' => $courseId,
                    'item_type' => 'assignment',
                    'item_id' => $aid,
                    'name' => $this->getAssignmentTitle($aid).' - Grade',
                    'max_score' => $maxScore,
                    'pass_score' => $maxScore * 0.6,
                    'weight' => round(mt_rand(50, 200) / 100, 2),
                    'hidden' => false,
                    'locked' => false,
                    'source' => 'assignment',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $gradeItemIdsByCourse[$courseId]['assignment_'.$aid] = $giId;
            }
        }
        $this->command->info('  -> grade items created');

        // ─── 8. Availability Rules + Completion + Course Completion Criteria ──
        $this->command->info('Creating availability rules and completion records...');
        $this->createAvailabilityRules($moduleIds, $courseIds, $allCourseDefs);
        $this->createCourseCompletionCriteriaAll($courseIds, $allCourseDefs, $moduleIds, $gradeItemIdsByCourse);

        // Module completions for some enrolled users
        mt_srand(123);
        $compCount = 0;
        foreach ($courseIds as $ci => $courseId) {
            $enrolled = $enrolledUserIdsByCourse[$courseId] ?? [];
            if (empty($enrolled)) {
                continue;
            }
            $sample = array_slice($enrolled, 0, min(5, count($enrolled)));

            foreach ($moduleIds[$ci] ?? [] as $mod) {
                if (! $mod['completion_enabled']) {
                    continue;
                }
                foreach ($sample as $uid) {
                    DB::table('module_completions')->insert([
                        'learning_module_id' => $mod['id'],
                        'user_id' => $uid,
                        'state' => 'complete',
                        'completed_at' => now()->subDays(mt_rand(1, 20)),
                        'source' => match ($mod['type']) {
                            'material' => 'view',
                            'quiz' => 'quiz_attempt',
                            'assignment' => 'assignment_submission',
                            default => 'view',
                        },
                        'override_by' => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $compCount++;
                }
            }

            // Pre-seed course completions
            $this->preSeedCourseCompletionsBulk($courseId, $gradeItemIdsByCourse[$courseId] ?? []);
        }
        $this->command->info('  -> '.$compCount.' module completions created');

        // ─── 9. Groups ─────────────────────────────────────────
        $this->command->info('Creating groups...');
        $groupNames = ['Group A', 'Group B', 'Alpha', 'Beta', 'Gamma', 'Team 1', 'Workshop 1', 'Workshop 2',
            'Lab A', 'Lab B', 'Lab C', 'Study Group', 'Project Team', 'Section 1', 'Section 2', 'Cohort 1'];
        $groupCount = 0;
        $groupMemberCount = 0;

        foreach ($courseIds as $ci => $courseId) {
            $ng = mt_rand(1, 3);
            $gIds = [];
            mt_srand($ci * 7 + 42);
            for ($gi = 0; $gi < $ng; $gi++) {
                $gName = ($ci < 10 && isset($groupNames[$gi])) ? $groupNames[$gi] : 'Group '.($gi + 1);
                $gId = DB::table('course_groups')->insertGetId([
                    'course_id' => $courseId,
                    'name' => $gName,
                    'sort_order' => $gi,
                    'active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $gIds[] = $gId;
                $groupCount++;
            }

            // Group members — split enrolled students across groups
            $enrolled = $enrolledUserIdsByCourse[$courseId] ?? [];
            if (! empty($gIds) && ! empty($enrolled)) {
                $chunks = array_chunk($enrolled, (int) ceil(count($enrolled) / count($gIds)));
                foreach ($gIds as $gi => $gId) {
                    $chunk = $chunks[$gi] ?? [];
                    $gmBatch = [];
                    foreach ($chunk as $uid) {
                        $gmBatch[] = [
                            'course_group_id' => $gId,
                            'user_id' => $uid,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];
                    }
                    if (! empty($gmBatch)) {
                        DB::table('course_group_members')->insert($gmBatch);
                        $groupMemberCount += count($gmBatch);
                    }
                }
            }

            // Course groupings for some courses
            if (! empty($gIds) && $ci % 3 === 0) {
                $groupingId = DB::table('course_groupings')->insertGetId([
                    'course_id' => $courseId,
                    'name' => 'Cohort '.($ci),
                    'sort_order' => 0,
                    'active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                foreach ($gIds as $gId) {
                    DB::table('course_grouping_groups')->insert([
                        'course_grouping_id' => $groupingId,
                        'course_group_id' => $gId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }
        $this->command->info('  -> '.$groupCount.' groups, '.$groupMemberCount.' members');

        // ─── 10. Quiz Attempts ─────────────────────────────────
        $this->command->info('Creating quiz attempts (up to 3 per student per quiz)...');
        $attemptCount = 0;
        $quizIdToCourse = [];
        foreach ($courseIds as $ci => $courseId) {
            foreach ($createdQuizIds[$ci] ?? [] as $qid) {
                $quizIdToCourse[$qid] = $courseId;
            }
        }

        mt_srand(456);
        foreach ($createdQuizIds as $ci => $qids) {
            $courseId = $courseIds[$ci];
            $enrolled = $enrolledUserIdsByCourse[$courseId] ?? [];
            if (empty($enrolled)) {
                continue;
            }
            $sample = $enrolled;

            foreach ($qids as $qid) {
                $batch = [];
                foreach ($sample as $uid) {
                    for ($attemptNumber = 1; $attemptNumber <= self::QUIZ_ATTEMPTS_PER_STUDENT; $attemptNumber++) {
                        $score = mt_rand(40, 100);
                        $batch[] = [
                            'quiz_id' => $qid,
                            'user_id' => $uid,
                            'answers' => '[]',
                            'score' => $score,
                            'status' => 'finished',
                            'attempt_number' => $attemptNumber,
                            'started_at' => now()->subDays(mt_rand(1, 30)),
                            'completed_at' => now()->subDays(mt_rand(0, 5)),
                            'submitted_at' => now()->subDays(mt_rand(0, 5)),
                            'expires_at' => null,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];
                        if (count($batch) >= self::BATCH_SIZE) {
                            DB::table('quiz_attempts')->insert($batch);
                            $attemptCount += count($batch);
                            $batch = [];
                        }
                    }
                }
                if (! empty($batch)) {
                    DB::table('quiz_attempts')->insert($batch);
                    $attemptCount += count($batch);
                }
            }
        }
        $this->command->info('  -> '.$attemptCount.' quiz attempts');

        // ─── 11. Submissions ───────────────────────────────────
        $this->command->info('Creating submissions (1 per enrolled student per assignment)...');
        $submissionCount = 0;

        mt_srand(789);
        foreach ($createdAssignmentIds as $ci => $aids) {
            $courseId = $courseIds[$ci];
            $enrolled = $enrolledUserIdsByCourse[$courseId] ?? [];
            if (empty($enrolled)) {
                continue;
            }
            $sample = $enrolled;

            foreach ($aids as $aid) {
                $batch = [];
                foreach ($sample as $uid) {
                    $score = mt_rand(40, 100);
                    $batch[] = [
                        'assignment_id' => $aid,
                        'user_id' => $uid,
                        'file_path' => 'submissions/assignment-'.$aid.'-user-'.$uid.'.pdf',
                        'score' => $score,
                        'status' => 'graded',
                        'attempt_number' => 1,
                        'is_latest' => true,
                        'submitted_at' => now()->subDays(mt_rand(1, 15)),
                        'graded_at' => now()->subDays(mt_rand(0, 5)),
                        'late' => false,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                    if (count($batch) >= self::BATCH_SIZE) {
                        DB::table('submissions')->insert($batch);
                        $submissionCount += count($batch);
                        $batch = [];
                    }
                }
                if (! empty($batch)) {
                    DB::table('submissions')->insert($batch);
                    $submissionCount += count($batch);
                }
            }
        }
        $this->command->info('  -> '.$submissionCount.' submissions');

        // ─── 12. Grades from Quiz Attempts (~25k) + Submissions (~12.5k) ─────
        $this->command->info('Creating grades from quiz attempts...');
        $gradeCount = 0;

        // Grades from quiz attempts
        DB::table('quiz_attempts')
            ->whereNotNull('score')
            ->where('status', 'finished')
            ->orderBy('id')
            ->chunk(500, function ($attempts) use ($gradeItemIdsByCourse, $quizIdToCourse, &$gradeCount) {
                $batch = [];
                foreach ($attempts as $attempt) {
                    $courseId = $quizIdToCourse[$attempt->quiz_id] ?? null;
                    if (! $courseId) {
                        continue;
                    }

                    $giId = $gradeItemIdsByCourse[$courseId]['quiz_'.$attempt->quiz_id] ?? null;
                    if (! $giId) {
                        continue;
                    }

                    $batch[] = [
                        'user_id' => $attempt->user_id,
                        'course_id' => $courseId,
                        'grade_item_id' => $giId,
                        'gradeable_type' => 'quiz_attempt',
                        'gradeable_id' => $attempt->id,
                        'score' => $attempt->score,
                        'max_score' => 100,
                        'percentage' => $attempt->score,
                        'status' => 'final',
                        'source' => 'quiz',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                    if (count($batch) >= self::BATCH_SIZE) {
                        DB::table('grades')->insert($batch);
                        $gradeCount += count($batch);
                        $batch = [];
                    }
                }
                if (! empty($batch)) {
                    DB::table('grades')->insert($batch);
                    $gradeCount += count($batch);
                }
            });
        $this->command->info('  -> '.$gradeCount.' grades from quiz attempts');

        // Grades from submissions
        $this->command->info('Creating grades from submissions...');
        DB::table('submissions')
            ->whereNotNull('score')
            ->where('status', 'graded')
            ->orderBy('id')
            ->chunk(500, function ($submissions) use ($gradeItemIdsByCourse, &$gradeCount) {
                $batch = [];
                foreach ($submissions as $sub) {
                    $courseId = DB::table('assignments')->where('id', $sub->assignment_id)->value('course_id');
                    if (! $courseId) {
                        continue;
                    }

                    $giId = $gradeItemIdsByCourse[$courseId]['assignment_'.$sub->assignment_id] ?? null;
                    if (! $giId) {
                        continue;
                    }

                    $maxScore = DB::table('assignments')->where('id', $sub->assignment_id)->value('max_score') ?: 100;

                    $batch[] = [
                        'user_id' => $sub->user_id,
                        'course_id' => $courseId,
                        'grade_item_id' => $giId,
                        'gradeable_type' => 'submission',
                        'gradeable_id' => $sub->id,
                        'score' => $sub->score,
                        'max_score' => $maxScore,
                        'percentage' => ($sub->score / $maxScore) * 100,
                        'status' => 'final',
                        'source' => 'assignment',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                    if (count($batch) >= self::BATCH_SIZE) {
                        DB::table('grades')->insert($batch);
                        $gradeCount += count($batch);
                        $batch = [];
                    }
                }
                if (! empty($batch)) {
                    DB::table('grades')->insert($batch);
                    $gradeCount += count($batch);
                }
            });
        $this->command->info('  -> grades total now: '.$gradeCount);

        // ─── 13. Post-seed data (overrides, quiz detail rows, etc.) ──────────
        $this->command->info('Creating overrides, quiz details, file records...');
        $this->seedPostData($courseIds, $allCourseDefs, $gradeItemIdsByCourse);

        // ─── 14. Summary ──────────────────────────────────────
        $this->printSummary();
    }

    // ═══════════════════════════════════════════════════════════════
    //  ROLES & CONTEXTS
    // ═══════════════════════════════════════════════════════════════

    private function seedRoles(): void
    {
        $roleDefs = ['manager', 'instructor', 'student'];
        foreach ($roleDefs as $shortname) {
            $existing = DB::table('roles')->where('shortname', $shortname)->first();
            if ($existing) {
                $this->roleIdMap[$shortname] = $existing->id;
            } else {
                $id = DB::table('roles')->insertGetId([
                    'shortname' => $shortname,
                    'name' => ucfirst($shortname),
                    'archetype' => $shortname,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $this->roleIdMap[$shortname] = $id;
            }
        }
    }

    private function seedSystemContext(): int
    {
        $existing = DB::table('contexts')
            ->where('contextlevel', Context::LEVEL_SYSTEM)
            ->where('instance_id', 0)
            ->first();
        if ($existing) {
            return $existing->id;
        }

        return DB::table('contexts')->insertGetId([
            'contextlevel' => Context::LEVEL_SYSTEM,
            'instance_id' => 0,
            'path' => '/1',
            'depth' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedRoleAssignments(int $systemContextId): void
    {
        // First instructor gets manager role at system context
        // (we'll assign later after users are created, using the first instructor ID)
        // This is done inside seedPostData
        $this->systemContextId = $systemContextId;
    }

    // ═══════════════════════════════════════════════════════════════
    //  USERS (bulk insert, no Eloquent)
    // ═══════════════════════════════════════════════════════════════

    private function bulkInsertUsers(string $role, int $count): array
    {
        $ids = [];
        $hashCache = [];
        $batch = [];

        for ($i = 1; $i <= $count; $i++) {
            $hashIdx = ($i - 1) % 50;
            if (! isset($hashCache[$hashIdx])) {
                $hashCache[$hashIdx] = bcrypt('password');
            }

            $displayName = $role === 'instructor'
                ? 'Instructor '.$i
                : 'Student '.$i;

            $batch[] = [
                'name' => $displayName,
                'email' => strtolower(str_replace(' ', '.', $displayName)).'@lms.test',
                'password' => $hashCache[$hashIdx],
                'role' => $role,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            if (count($batch) >= self::BATCH_SIZE) {
                $ids = array_merge($ids, $this->insertAndGetIds('users', $batch));
                $batch = [];
            }
        }

        if (! empty($batch)) {
            $ids = array_merge($ids, $this->insertAndGetIds('users', $batch));
        }

        return $ids;
    }

    private function insertAndGetIds(string $table, array $rows): array
    {
        if (empty($rows)) {
            return [];
        }
        DB::table($table)->insert($rows);

        // Get the last inserted IDs
        $firstId = DB::getPdo()->lastInsertId();

        return range($firstId, $firstId + count($rows) - 1);
    }

    // ═══════════════════════════════════════════════════════════════
    //  COURSE CATEGORIES
    // ═══════════════════════════════════════════════════════════════

    private function seedCourseCategories(): array
    {
        $catDefs = [
            ['name' => 'Technology', 'children' => [
                ['name' => 'Computer Science', 'children' => [
                    ['name' => 'Programming'],
                    ['name' => 'Web Development'],
                ]],
                ['name' => 'Data Science'],
                ['name' => 'Cybersecurity'],
            ]],
            ['name' => 'Design', 'children' => [
                ['name' => 'UI/UX Design'],
            ]],
            ['name' => 'Engineering', 'children' => [
                ['name' => 'DevOps'],
                ['name' => 'Cloud Computing'],
            ]],
            ['name' => 'Artificial Intelligence', 'visible' => false],
        ];

        $ids = [];
        $walk = function (array $defs, ?int $parentId, int $depth, string $pathPrefix) use (&$walk, &$ids): void {
            foreach ($defs as $i => $def) {
                $name = $def['name'];
                $visible = $def['visible'] ?? true;
                $path = $parentId !== null ? ($pathPrefix ? $pathPrefix.'/'.$parentId : (string) $parentId) : null;

                $id = DB::table('course_categories')->insertGetId([
                    'parent_id' => $parentId,
                    'name' => $name,
                    'description' => $name.' category',
                    'sort_order' => $i,
                    'visible' => $visible,
                    'depth' => $depth,
                    'path' => $path,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $ids[$name] = $id;

                if (! empty($def['children'])) {
                    $walk($def['children'], $id, $depth + 1, $path ? $path.'/'.$id : (string) $id);
                }
            }
        };
        $walk($catDefs, null, 0, '');

        return $ids;
    }

    // ═══════════════════════════════════════════════════════════════
    //  COURSE DEFINITIONS
    // ═══════════════════════════════════════════════════════════════

    private function detailedCourseDefs(array $instructorIds, array $categoryIds): array
    {
        $catIndex = function (string $name) use ($categoryIds) {
            return $categoryIds[$name] ?? null;
        };

        return [
            ['name' => 'Web Development Fundamentals',
                'description' => 'Course description for Web Development Fundamentals.',
                'instructor_id' => $instructorIds[0], 'is_active' => true,
                'course_category_id' => $catIndex('Web Development'),
                'sections' => ['Introduction', 'Core Concepts', 'Final Project'],
                'activities' => [
                    'Introduction' => [
                        ['type' => 'material', 'title' => 'Course Overview', 'visible' => true, 'completion_enabled' => true],
                        ['type' => 'material', 'title' => 'Development Environment Setup', 'visible' => true, 'completion_enabled' => true],
                    ],
                    'Core Concepts' => [
                        ['type' => 'material', 'title' => 'HTML Fundamentals', 'visible' => true, 'completion_enabled' => false],
                        ['type' => 'material', 'title' => 'CSS Styling Guide', 'visible' => true, 'completion_enabled' => true],
                        ['type' => 'assignment', 'title' => 'Build a Personal Page', 'visible' => true, 'completion_enabled' => true],
                    ],
                    'Final Project' => [
                        ['type' => 'quiz', 'title' => 'HTML & CSS Basics Quiz', 'visible' => true, 'completion_enabled' => true],
                        ['type' => 'assignment', 'title' => 'Final Project: Portfolio Site', 'visible' => true, 'completion_enabled' => true],
                    ],
                ]],
            ['name' => 'Data Science with Python',
                'description' => 'Course description for Data Science with Python.',
                'instructor_id' => $instructorIds[0], 'is_active' => true,
                'course_category_id' => $catIndex('Data Science'),
                'sections' => ['Setup', 'Data Wrangling', 'Visualization', 'Statistics', 'Final Project'],
                'activities' => [
                    'Setup' => [
                        ['type' => 'material', 'title' => 'Python Environment Setup', 'visible' => true, 'completion_enabled' => true],
                        ['type' => 'material', 'title' => 'Jupyter Notebooks Guide', 'visible' => true, 'completion_enabled' => false],
                    ],
                    'Data Wrangling' => [
                        ['type' => 'material', 'title' => 'Pandas Fundamentals', 'visible' => true, 'completion_enabled' => true],
                        ['type' => 'assignment', 'title' => 'Data Cleaning Exercise', 'visible' => true, 'completion_enabled' => true],
                    ],
                    'Visualization' => [
                        ['type' => 'material', 'title' => 'Matplotlib & Seaborn', 'visible' => false, 'completion_enabled' => false],
                        ['type' => 'quiz', 'title' => 'Data Wrangling Quiz', 'visible' => true, 'completion_enabled' => true],
                    ],
                    'Statistics' => [
                        ['type' => 'material', 'title' => 'Descriptive Statistics', 'visible' => true, 'completion_enabled' => false],
                        ['type' => 'quiz', 'title' => 'Statistics Fundamentals Quiz', 'visible' => true, 'completion_enabled' => true],
                    ],
                    'Final Project' => [
                        ['type' => 'assignment', 'title' => 'Data Analysis Report', 'visible' => true, 'completion_enabled' => true],
                    ],
                ]],
            ['name' => 'Mobile App Development',
                'description' => 'Course description for Mobile App Development.',
                'instructor_id' => $instructorIds[1], 'is_active' => true,
                'course_category_id' => $catIndex('Programming'),
                'sections' => ['Getting Started', 'UI Components', 'Navigation', 'Publishing'],
                'activities' => [
                    'Getting Started' => [
                        ['type' => 'material', 'title' => 'Environment Setup', 'visible' => true, 'completion_enabled' => true],
                    ],
                    'UI Components' => [
                        ['type' => 'material', 'title' => 'Layouts & Views', 'visible' => true, 'completion_enabled' => true],
                        ['type' => 'assignment', 'title' => 'Build a Login Screen', 'visible' => true, 'completion_enabled' => true],
                        ['type' => 'quiz', 'title' => 'UI Components Quiz', 'visible' => true, 'completion_enabled' => true],
                    ],
                    'Navigation' => [
                        ['type' => 'material', 'title' => 'Navigation Patterns', 'visible' => true, 'completion_enabled' => false],
                        ['type' => 'assignment', 'title' => 'Multi-Screen App', 'visible' => false, 'completion_enabled' => false],
                    ],
                    'Publishing' => [
                        ['type' => 'material', 'title' => 'App Store Deployment', 'visible' => true, 'completion_enabled' => false],
                    ],
                ]],
            ['name' => 'Database Design & SQL',
                'description' => 'Course description for Database Design & SQL.',
                'instructor_id' => $instructorIds[1], 'is_active' => true,
                'course_category_id' => $catIndex('Computer Science'),
                'sections' => ['ER Modeling', 'Normalization', 'SELECT Queries', 'Joins', 'Indexes', 'Transactions'],
                'activities' => [
                    'ER Modeling' => [
                        ['type' => 'material', 'title' => 'Entity-Relationship Diagrams', 'visible' => true, 'completion_enabled' => true],
                        ['type' => 'assignment', 'title' => 'Design an ER Diagram', 'visible' => true, 'completion_enabled' => true],
                    ],
                    'Normalization' => [
                        ['type' => 'material', 'title' => 'Normal Forms (1NF-3NF)', 'visible' => true, 'completion_enabled' => true],
                        ['type' => 'quiz', 'title' => 'Normalization Quiz', 'visible' => true, 'completion_enabled' => true],
                    ],
                    'SELECT Queries' => [
                        ['type' => 'material', 'title' => 'Basic SELECT & WHERE', 'visible' => true, 'completion_enabled' => false],
                        ['type' => 'assignment', 'title' => 'SQL Query Exercises', 'visible' => true, 'completion_enabled' => true],
                    ],
                    'Joins' => [
                        ['type' => 'material', 'title' => 'INNER, LEFT, RIGHT, FULL Joins', 'visible' => true, 'completion_enabled' => false],
                        ['type' => 'quiz', 'title' => 'Joins & Relationships Quiz', 'visible' => true, 'completion_enabled' => true],
                    ],
                    'Indexes' => [
                        ['type' => 'material', 'title' => 'Indexing Strategies', 'visible' => true, 'completion_enabled' => false],
                    ],
                    'Transactions' => [
                        ['type' => 'material', 'title' => 'ACID & Transactions', 'visible' => true, 'completion_enabled' => false],
                    ],
                ]],
            ['name' => 'Machine Learning Foundations',
                'description' => 'Course description for Machine Learning Foundations.',
                'instructor_id' => $instructorIds[2], 'is_active' => true,
                'course_category_id' => $catIndex('Data Science'),
                'sections' => ['Intro', 'Regression', 'Classification', 'Clustering', 'Neural Networks', 'Evaluation', 'Capstone'],
                'activities' => [
                    'Intro' => [
                        ['type' => 'material', 'title' => 'What is ML?', 'visible' => true, 'completion_enabled' => true],
                        ['type' => 'quiz', 'title' => 'ML Concepts Quiz', 'visible' => true, 'completion_enabled' => true],
                    ],
                    'Regression' => [
                        ['type' => 'material', 'title' => 'Linear Regression', 'visible' => true, 'completion_enabled' => true],
                        ['type' => 'assignment', 'title' => 'Regression Analysis', 'visible' => true, 'completion_enabled' => true],
                    ],
                    'Classification' => [
                        ['type' => 'material', 'title' => 'Logistic Regression & Decision Trees', 'visible' => true, 'completion_enabled' => false],
                        ['type' => 'assignment', 'title' => 'Classification Task', 'visible' => true, 'completion_enabled' => true],
                    ],
                    'Clustering' => [
                        ['type' => 'material', 'title' => 'K-Means & Hierarchical Clustering', 'visible' => true, 'completion_enabled' => false],
                        ['type' => 'quiz', 'title' => 'Clustering Quiz', 'visible' => true, 'completion_enabled' => true],
                    ],
                    'Neural Networks' => [
                        ['type' => 'material', 'title' => 'Neural Network Basics', 'visible' => false, 'completion_enabled' => false],
                    ],
                    'Evaluation' => [
                        ['type' => 'material', 'title' => 'Model Evaluation Metrics', 'visible' => true, 'completion_enabled' => false],
                    ],
                    'Capstone' => [
                        ['type' => 'assignment', 'title' => 'ML Capstone Project', 'visible' => true, 'completion_enabled' => true],
                    ],
                ]],
            ['name' => 'Cloud Architecture',
                'description' => 'Course description for Cloud Architecture.',
                'instructor_id' => $instructorIds[2], 'is_active' => false,
                'course_category_id' => $catIndex('Cloud Computing'),
                'sections' => ['Overview', 'AWS Basics', 'Case Study'],
                'activities' => [
                    'Overview' => [['type' => 'material', 'title' => 'Cloud Computing Overview', 'visible' => true, 'completion_enabled' => false]],
                    'AWS Basics' => [['type' => 'material', 'title' => 'AWS Core Services', 'visible' => true, 'completion_enabled' => false]],
                    'Case Study' => [['type' => 'material', 'title' => 'Architecture Case Study', 'visible' => true, 'completion_enabled' => false]],
                ]],
            ['name' => 'Cybersecurity Essentials',
                'description' => 'Course description for Cybersecurity Essentials.',
                'instructor_id' => $instructorIds[3], 'is_active' => true,
                'course_category_id' => $catIndex('Cybersecurity'),
                'sections' => ['Threat Model', 'Network Security', 'Cryptography', 'Incident Response'],
                'activities' => [
                    'Threat Model' => [
                        ['type' => 'material', 'title' => 'Threat Modeling Overview', 'visible' => true, 'completion_enabled' => true],
                        ['type' => 'assignment', 'title' => 'Threat Model Document', 'visible' => true, 'completion_enabled' => true],
                    ],
                    'Network Security' => [
                        ['type' => 'material', 'title' => 'Firewalls & IDS', 'visible' => true, 'completion_enabled' => false],
                        ['type' => 'quiz', 'title' => 'Network Security Quiz', 'visible' => true, 'completion_enabled' => true],
                    ],
                    'Cryptography' => [
                        ['type' => 'material', 'title' => 'Symmetric & Asymmetric Crypto', 'visible' => true, 'completion_enabled' => false],
                        ['type' => 'assignment', 'title' => 'Implement a Caesar Cipher', 'visible' => false, 'completion_enabled' => false],
                    ],
                    'Incident Response' => [
                        ['type' => 'material', 'title' => 'Incident Response Plan', 'visible' => true, 'completion_enabled' => false],
                    ],
                ]],
            ['name' => 'UI/UX Design Principles',
                'description' => 'Course description for UI/UX Design Principles.',
                'instructor_id' => $instructorIds[3], 'is_active' => true,
                'course_category_id' => $catIndex('UI/UX Design'),
                'sections' => ['Design Thinking', 'Wireframing', 'Prototyping', 'User Testing', 'Portfolio'],
                'activities' => [
                    'Design Thinking' => [['type' => 'material', 'title' => 'Design Thinking Process', 'visible' => true, 'completion_enabled' => true]],
                    'Wireframing' => [
                        ['type' => 'material', 'title' => 'Wireframing Tools & Techniques', 'visible' => true, 'completion_enabled' => true],
                        ['type' => 'assignment', 'title' => 'Create Wireframes', 'visible' => true, 'completion_enabled' => true],
                    ],
                    'Prototyping' => [
                        ['type' => 'material', 'title' => 'Interactive Prototyping', 'visible' => true, 'completion_enabled' => false],
                        ['type' => 'quiz', 'title' => 'UX Principles Quiz', 'visible' => true, 'completion_enabled' => true],
                    ],
                    'User Testing' => [
                        ['type' => 'material', 'title' => 'Conducting User Tests', 'visible' => true, 'completion_enabled' => false],
                        ['type' => 'assignment', 'title' => 'Usability Test Report', 'visible' => false, 'completion_enabled' => false],
                    ],
                    'Portfolio' => [['type' => 'material', 'title' => 'Building Your Portfolio', 'visible' => true, 'completion_enabled' => false]],
                ]],
            ['name' => 'DevOps & CI/CD',
                'description' => 'Course description for DevOps & CI/CD.',
                'instructor_id' => $instructorIds[4], 'is_active' => true,
                'course_category_id' => $catIndex('DevOps'),
                'sections' => ['CI Fundamentals', 'Pipeline Setup', 'Monitoring'],
                'activities' => [
                    'CI Fundamentals' => [['type' => 'material', 'title' => 'Continuous Integration Concepts', 'visible' => true, 'completion_enabled' => true]],
                    'Pipeline Setup' => [
                        ['type' => 'material', 'title' => 'Building a CI Pipeline', 'visible' => true, 'completion_enabled' => true],
                        ['type' => 'assignment', 'title' => 'GitHub Actions Pipeline', 'visible' => true, 'completion_enabled' => true],
                        ['type' => 'quiz', 'title' => 'CI/CD Concepts Quiz', 'visible' => true, 'completion_enabled' => true],
                    ],
                    'Monitoring' => [['type' => 'material', 'title' => 'Application Monitoring', 'visible' => true, 'completion_enabled' => false]],
                ]],
            ['name' => 'Artificial Intelligence Intro',
                'description' => 'Course description for Artificial Intelligence Intro.',
                'instructor_id' => $instructorIds[4], 'is_active' => false,
                'course_category_id' => $catIndex('Artificial Intelligence'),
                'sections' => ['History', 'Tools', 'Ethics'],
                'activities' => [
                    'History' => [['type' => 'material', 'title' => 'History of AI', 'visible' => true, 'completion_enabled' => false]],
                    'Tools' => [['type' => 'material', 'title' => 'AI Tools & Libraries', 'visible' => true, 'completion_enabled' => false]],
                    'Ethics' => [['type' => 'material', 'title' => 'AI Ethics', 'visible' => true, 'completion_enabled' => false]],
                ]],
        ];
    }

    private function generatedCourseDefs(array $instructorIds, array $categoryIds, int $count): array
    {
        $topics = [
            'Software Engineering', 'Algorithms', 'Operating Systems', 'Networking',
            'Compiler Design', 'Computer Graphics', 'Parallel Computing', 'Distributed Systems',
            'Embedded Systems', 'Blockchain', 'IoT Fundamentals', 'Quantum Computing',
            'Robotics', 'Natural Language Processing', 'Computer Vision', 'Reinforcement Learning',
            'Time Series Analysis', 'Bayesian Statistics', 'Optimization Methods', 'Numerical Computing',
            'Agile Methodologies', 'Software Testing', 'Code Quality', 'API Design',
            'Microservices', 'Event-Driven Architecture', 'System Design', 'Performance Engineering',
            'Database Administration', 'Data Warehousing', 'Big Data Analytics', 'Data Engineering',
            'Web Security', 'Penetration Testing', 'Digital Forensics', 'Privacy Engineering',
            'Game Development', 'AR/VR Development', 'Cross-Platform Dev', 'Desktop Applications',
        ];
        $sectionPool = ['Fundamentals', 'Core Topics', 'Advanced Topics', 'Hands-On Lab', 'Final Project',
            'Theory', 'Practice', 'Case Studies', 'Review', 'Assessment'];
        $defs = [];
        mt_srand(999);
        for ($i = 0; $i < $count; $i++) {
            $topic = $topics[$i % count($topics)];
            $instrId = $instructorIds[($i + 5) % count($instructorIds)];
            $isActive = $i % 5 !== 0; // every 5th course is inactive
            $catId = $categoryIds[array_rand($categoryIds)];

            $sectionNames = [];
            for ($s = 0; $s < count($sectionPool); $s++) {
                $sectionNames[] = ($s < count($sectionPool)) ? $sectionPool[$s] : 'Module '.($s + 1);
            }

            $activities = [];
            foreach ($sectionNames as $sName) {
                $activities[$sName] = [];
            }

            $activityPlan = [
                'material' => self::MATERIALS_PER_COURSE,
                'quiz' => self::QUIZZES_PER_COURSE,
                'assignment' => self::ASSIGNMENTS_PER_COURSE,
            ];
            $activityIndex = 0;

            foreach ($activityPlan as $type => $typeCount) {
                for ($n = 1; $n <= $typeCount; $n++) {
                    $sectionName = $sectionNames[$activityIndex % count($sectionNames)];
                    $activities[$sectionName][] = [
                        'type' => $type,
                        'title' => $topic.' - '.ucfirst($type).' '.$n,
                        'visible' => true,
                        'completion_enabled' => $type !== 'material' || $n <= 3,
                    ];
                    $activityIndex++;
                }
            }

            $defs[] = [
                'name' => $topic,
                'description' => 'Course description for '.$topic.'.',
                'instructor_id' => $instrId,
                'is_active' => $isActive,
                'course_category_id' => $catId,
                'sections' => $sectionNames,
                'activities' => $activities,
            ];
        }

        return $defs;
    }

    private function withBenchmarkActivityTargets(array $courseDefs): array
    {
        foreach ($courseDefs as &$courseDef) {
            $byType = ['material' => [], 'quiz' => [], 'assignment' => []];

            foreach ($courseDef['activities'] as $activities) {
                foreach ($activities as $activity) {
                    $byType[$activity['type']][] = $activity;
                }
            }

            $targets = [
                'material' => self::MATERIALS_PER_COURSE,
                'quiz' => self::QUIZZES_PER_COURSE,
                'assignment' => self::ASSIGNMENTS_PER_COURSE,
            ];

            $normalized = [];
            foreach ($targets as $type => $target) {
                $activities = array_slice($byType[$type], 0, $target);
                for ($sequence = count($activities) + 1; $sequence <= $target; $sequence++) {
                    $activities[] = $this->generatedActivity($courseDef['name'], $type, $sequence);
                }
                array_push($normalized, ...$activities);
            }

            $sections = array_values($courseDef['sections']);
            $courseDef['activities'] = array_fill_keys($sections, []);

            foreach ($normalized as $index => $activity) {
                $courseDef['activities'][$sections[$index % count($sections)]][] = $activity;
            }
        }
        unset($courseDef);

        return $courseDefs;
    }

    private function generatedActivity(string $courseName, string $type, int $sequence): array
    {
        return [
            'type' => $type,
            'title' => $courseName.' - '.ucfirst($type).' '.$sequence,
            'visible' => true,
            'completion_enabled' => $type !== 'material' || $sequence <= 3,
        ];
    }

    private function generateCourseDefs(array $instructorIds, array $categoryIds): array
    {
        // Deprecated — kept for interface compat; actual defs come from detailedCourseDefs() + generatedCourseDefs()
        return [];
    }

    // ═══════════════════════════════════════════════════════════════
    //  ACTIVITY INSERTION
    // ═══════════════════════════════════════════════════════════════

    private function insertActivity(string $type, string $title, int $courseId, bool $visible, array $spec): int
    {
        if ($type === 'material') {
            return DB::table('materials')->insertGetId([
                'course_id' => $courseId,
                'title' => $title,
                'file_path' => 'materials/'.strtolower(str_replace(' ', '-', $title)).'.pdf',
                'file_size' => mt_rand(200000, 5000000),
                'type' => 'pdf',
                'mime_type' => 'application/pdf',
                'revision' => 1,
                'checksum' => sha1($title),
                'is_active' => $visible,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } elseif ($type === 'quiz') {
            $qid = DB::table('quizzes')->insertGetId([
                'course_id' => $courseId,
                'title' => $title,
                'description' => 'Quiz: '.$title,
                'time_limit' => 30,
                'passing_score' => 60,
                'is_active' => $visible,
                'available_from' => $spec['available_from'] ?? null,
                'available_until' => $spec['available_until'] ?? null,
                'max_attempts' => self::QUIZ_ATTEMPTS_PER_STUDENT + 1,
                'grading_method' => 'highest',
                'grace_period' => 0,
                'overdue_handling' => 'auto_submit',
                'delay_between_attempts' => 0,
                'review_visibility' => 'after_submission',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $questionCount = mt_rand(self::MIN_QUESTIONS_PER_QUIZ, self::MAX_QUESTIONS_PER_QUIZ);

            for ($qi = 1; $qi <= $questionCount; $qi++) {
                $questionId = DB::table('questions')->insertGetId([
                    'quiz_id' => $qid,
                    'question_text' => 'Question '.$qi.': Sample question for '.$title.'?',
                    'options' => json_encode(['A' => 'Option A', 'B' => 'Option B', 'C' => 'Option C', 'D' => 'Option D']),
                    'correct_answer' => 'A',
                    'points' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('quiz_question_slots')->insert([
                    'quiz_id' => $qid,
                    'question_id' => $questionId,
                    'slot' => $qi,
                    'page' => 1,
                    'max_points' => 1,
                    'require_previous' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return $qid;
        } elseif ($type === 'assignment') {
            return DB::table('assignments')->insertGetId([
                'course_id' => $courseId,
                'title' => $title,
                'description' => 'Assignment: '.$title,
                'due_date' => now()->addDays(30),
                'max_score' => 100,
                'is_active' => true,
                'available_from' => now()->subDays(30),
                'cutoff_date' => now()->addDays(35),
                'max_attempts' => 2,
                'allow_late_submission' => true,
                'submission_type' => 'file',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        throw new \InvalidArgumentException("Unknown activity type: $type");
    }

    // ═══════════════════════════════════════════════════════════════
    //  AVAILABILITY RULES & COMPLETIONS
    // ═══════════════════════════════════════════════════════════════

    private function createAvailabilityRules(array $moduleIds, array $courseIds, array $courseDefs): void
    {
        // Only apply detailed rules for first 10 courses
        foreach ($moduleIds as $ci => $mods) {
            if ($ci >= 10) {
                continue;
            }
            $courseId = $courseIds[$ci] ?? null;
            if (! $courseId) {
                continue;
            }

            // Build a title → module_id map for this course
            $titleToModId = [];
            foreach ($mods as $m) {
                $titleToModId[$m['title']] = $m['id'];
            }

            // Course 0: Development Environment Setup requires Course Overview
            if ($ci === 0) {
                $target = $titleToModId['Development Environment Setup'] ?? null;
                $required = $titleToModId['Course Overview'] ?? null;
                if ($target && $required) {
                    DB::table('module_availability_rules')->insert([
                        'learning_module_id' => $target,
                        'rule_type' => 'completion',
                        'required_module_id' => $required,
                        'operator' => '==',
                        'value' => 'complete',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            // Course 1: Data Wrangling Quiz requires Data Cleaning Exercise
            if ($ci === 1) {
                $target = $titleToModId['Data Wrangling Quiz'] ?? null;
                $required = $titleToModId['Data Cleaning Exercise'] ?? null;
                if ($target && $required) {
                    DB::table('module_availability_rules')->insert([
                        'learning_module_id' => $target,
                        'rule_type' => 'completion',
                        'required_module_id' => $required,
                        'operator' => '==',
                        'value' => 'complete',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            // Course 3: SQL Query Exercises requires Normalization Quiz
            if ($ci === 3) {
                $target = $titleToModId['SQL Query Exercises'] ?? null;
                $required = $titleToModId['Normalization Quiz'] ?? null;
                if ($target && $required) {
                    DB::table('module_availability_rules')->insert([
                        'learning_module_id' => $target,
                        'rule_type' => 'completion',
                        'required_module_id' => $required,
                        'operator' => '==',
                        'value' => 'complete',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    private function createCourseCompletionCriteriaAll(array $courseIds, array $courseDefs, array $moduleIds, array $gradeItemIdsByCourse): void
    {
        // Set criteria for first 5 active courses
        $targetCourses = [0, 1, 3, 7, 8];
        foreach ($targetCourses as $ci) {
            $courseId = $courseIds[$ci] ?? null;
            if (! $courseId) {
                continue;
            }

            $mods = $moduleIds[$ci] ?? [];

            // Pick first completion-enabled module for module criterion
            $compMod = null;
            foreach ($mods as $m) {
                if ($m['completion_enabled']) {
                    $compMod = $m;
                    break;
                }
            }
            if ($compMod) {
                DB::table('course_completion_criteria')->insert([
                    'course_id' => $courseId,
                    'criteriatype' => 'module',
                    'module_instance_id' => $compMod['id'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // Pick first quiz grade item for grade criterion
            $quizGi = null;
            foreach ($gradeItemIdsByCourse[$courseId] ?? [] as $key => $giId) {
                if (str_starts_with($key, 'quiz_')) {
                    $quizGi = $giId;
                    break;
                }
            }
            if ($quizGi) {
                DB::table('course_completion_criteria')->insert([
                    'course_id' => $courseId,
                    'criteriatype' => 'grade',
                    'grade_item_id' => $quizGi,
                    'pass_threshold' => 60,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    private function preSeedCourseCompletionsBulk(int $courseId, array $gradeItemMap): void
    {
        $criteria = DB::table('course_completion_criteria')
            ->where('course_id', $courseId)
            ->get();

        if ($criteria->isEmpty()) {
            return;
        }

        $activeStudentIds = DB::table('course_enrollments')
            ->where('course_id', $courseId)
            ->where('role', 'student')
            ->where('status', 'active')
            ->pluck('user_id');

        foreach ($activeStudentIds as $userId) {
            $allMet = true;

            foreach ($criteria as $criterion) {
                $met = false;

                if ($criterion->criteriatype === 'module' && $criterion->module_instance_id) {
                    $met = DB::table('module_completions')
                        ->where('learning_module_id', $criterion->module_instance_id)
                        ->where('user_id', $userId)
                        ->where('state', 'complete')
                        ->exists();
                } elseif ($criterion->criteriatype === 'grade' && $criterion->grade_item_id) {
                    $grade = DB::table('grades')
                        ->where('grade_item_id', $criterion->grade_item_id)
                        ->where('user_id', $userId)
                        ->first();
                    $threshold = $criterion->pass_threshold ?? 0;
                    $met = $grade && $grade->score !== null && (float) $grade->score >= (float) $threshold;
                }

                if ($met) {
                    DB::table('course_completion_criterion_completions')->insert([
                        'course_completion_criterion_id' => $criterion->id,
                        'user_id' => $userId,
                        'completed' => true,
                        'completed_at' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                } else {
                    $allMet = false;
                }
            }

            DB::table('course_completions')->insert([
                'course_id' => $courseId,
                'user_id' => $userId,
                'timeenrolled' => now()->subDays(60),
                'timestarted' => $allMet ? now()->subDays(30) : null,
                'timecompleted' => $allMet ? now()->subDays(1) : null,
                'reaggregate' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    // ═══════════════════════════════════════════════════════════════
    //  POST-SEED DATA
    // ═══════════════════════════════════════════════════════════════

    private function seedPostData(array $courseIds, array $courseDefs, array $gradeItemIdsByCourse): void
    {
        // Manager role assignment for first instructor
        $firstInstructorId = $courseDefs[0]['instructor_id'] ?? null;
        if ($firstInstructorId && isset($this->systemContextId)) {
            DB::table('role_assignments')->insert([
                'role_id' => $this->roleIdMap['manager'],
                'context_id' => $this->systemContextId,
                'user_id' => $firstInstructorId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Lock one quiz grade item
        $firstQuizGi = DB::table('grade_items')->where('item_type', 'quiz')->first();
        if ($firstQuizGi) {
            DB::table('grade_items')->where('id', $firstQuizGi->id)->update(['locked' => true]);
        }

        // Quiz override for first student on first quiz
        $firstQuiz = DB::table('quizzes')->where('is_active', true)->first();
        $sampleStudent = DB::table('users')->where('role', 'student')->first();
        if ($firstQuiz && $sampleStudent) {
            DB::table('quiz_overrides')->insert([
                'quiz_id' => $firstQuiz->id,
                'user_id' => $sampleStudent->id,
                'max_attempts' => self::QUIZ_ATTEMPTS_PER_STUDENT + 2,
                'time_limit' => 60,
                'grace_period' => 5,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Assignment override for first student
        $firstAssignment = DB::table('assignments')->where('is_active', true)->first();
        if ($firstAssignment && $sampleStudent) {
            DB::table('assignment_overrides')->insert([
                'assignment_id' => $firstAssignment->id,
                'user_id' => $sampleStudent->id,
                'due_date' => now()->addDays(35),
                'cutoff_date' => now()->addDays(40),
                'max_attempts' => 5,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Quiz attempt detail rows (for all finished attempts without details)
        DB::table('quiz_attempts')
            ->where('status', 'finished')
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('quiz_attempt_questions')
                    ->whereColumn('quiz_attempt_questions.quiz_attempt_id', 'quiz_attempts.id');
            })
            ->orderBy('id')
            ->chunk(200, function ($attempts) {
                $qBatch = [];
                $sBatch = [];
                foreach ($attempts as $attempt) {
                    $slots = DB::table('quiz_question_slots')
                        ->where('quiz_id', $attempt->quiz_id)
                        ->get();
                    foreach ($slots as $slot) {
                        $qBatch[] = [
                            'quiz_attempt_id' => $attempt->id,
                            'quiz_question_slot_id' => $slot->id,
                            'question_id' => $slot->question_id,
                            'slot' => $slot->slot,
                            'max_points' => $slot->max_points,
                            'score' => null,
                            'state' => 'not_answered',
                            'created_at' => now(),
                            'updated_at' => now(),
                        ];
                        if (count($qBatch) >= self::BATCH_SIZE) {
                            DB::table('quiz_attempt_questions')->insert($qBatch);
                            $qBatch = [];
                        }
                    }
                }
                if (! empty($qBatch)) {
                    DB::table('quiz_attempt_questions')->insert($qBatch);
                }

                // Steps for the questions we just created
                $questionIds = DB::table('quiz_attempt_questions')
                    ->whereIn('quiz_attempt_id', $attempts->pluck('id'))
                    ->pluck('id');
                foreach ($questionIds as $qid) {
                    $sBatch[] = [
                        'quiz_attempt_question_id' => $qid,
                        'sequence_number' => 0,
                        'state' => 'not_answered',
                        'user_id' => $attempts->first()->user_id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                    if (count($sBatch) >= self::BATCH_SIZE) {
                        DB::table('quiz_attempt_steps')->insert($sBatch);
                        $sBatch = [];
                    }
                }
                if (! empty($sBatch)) {
                    DB::table('quiz_attempt_steps')->insert($sBatch);
                }
            });

        // Quiz aggregate grades
        DB::table('quiz_attempts')
            ->where('status', 'finished')
            ->whereNotNull('score')
            ->orderBy('quiz_id')->orderBy('user_id')
            ->chunk(200, function ($attempts) {
                $grouped = [];
                foreach ($attempts as $a) {
                    $key = $a->quiz_id.'-'.$a->user_id;
                    $grouped[$key][] = $a;
                }
                foreach ($grouped as $key => $userAttempts) {
                    $first = $userAttempts[0];
                    $maxScore = 100; // quizzes have max_score 100
                    $scores = array_column($userAttempts, 'score');
                    $grade = max($scores); // highest grading method
                    $percentage = $maxScore > 0 ? ($grade / $maxScore) * 100 : 0;

                    DB::table('quiz_grades')->updateOrInsert(
                        ['quiz_id' => $first->quiz_id, 'user_id' => $first->user_id],
                        [
                            'grade' => $grade,
                            'max_score' => $maxScore,
                            'percentage' => $percentage,
                            'grading_method' => 'highest',
                            'attempt_count' => count($userAttempts),
                            'last_attempt_id' => $userAttempts[count($userAttempts) - 1]->id,
                            'graded_at' => now(),
                            'updated_at' => now(),
                            'created_at' => now(),
                        ]
                    );
                }
            });

        // Marker allocations (first 3 assignments)
        $assignments = DB::table('assignments')
            ->join('courses', 'assignments.course_id', '=', 'courses.id')
            ->select('assignments.*', 'courses.instructor_id as course_instructor_id')
            ->where('assignments.is_active', true)
            ->limit(3)
            ->get();
        $instructorIds = DB::table('users')->where('role', 'instructor')->pluck('id')->toArray();
        foreach ($assignments as $assignment) {
            DB::table('assignments')
                ->where('id', $assignment->id)
                ->update(['marking_allocation_enabled' => true, 'marker_count' => 2, 'multi_mark_method' => 'average']);

            $submissions = DB::table('submissions')
                ->where('assignment_id', $assignment->id)
                ->limit(3)
                ->get();

            $otherInstructors = array_values(array_filter($instructorIds, fn ($iid) => $iid !== $assignment->course_instructor_id));
            $markers = array_slice($otherInstructors, 0, 2);

            foreach ($submissions as $sub) {
                foreach ($markers as $markerId) {
                    DB::table('assignment_allocated_markers')->insert([
                        'assignment_id' => $assignment->id,
                        'submission_id' => $sub->id,
                        'student_id' => $sub->user_id,
                        'marker_id' => $markerId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }

        // File records for materials
        DB::table('materials')->orderBy('id')->chunk(200, function ($materials) {
            $batch = [];
            foreach ($materials as $m) {
                $instructorId = DB::table('courses')->where('id', $m->course_id)->value('instructor_id') ?: 1;
                $batch[] = [
                    'owner_type' => 'material',
                    'owner_id' => $m->id,
                    'uploader_id' => $instructorId,
                    'component' => 'material',
                    'file_path' => $m->file_path,
                    'mime_type' => $m->mime_type,
                    'file_size' => $m->file_size,
                    'checksum' => sha1($m->file_path),
                    'revision' => $m->revision,
                    'visible' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                if (count($batch) >= self::BATCH_SIZE) {
                    DB::table('file_records')->insert($batch);
                    $batch = [];
                }
            }
            if (! empty($batch)) {
                DB::table('file_records')->insert($batch);
            }
        });

        // File records for submissions
        DB::table('submissions')->orderBy('id')->chunk(200, function ($submissions) {
            $batch = [];
            foreach ($submissions as $s) {
                $batch[] = [
                    'owner_type' => 'submission',
                    'owner_id' => $s->id,
                    'uploader_id' => $s->user_id,
                    'component' => 'assignment_submission',
                    'file_path' => $s->file_path,
                    'mime_type' => 'application/pdf',
                    'file_size' => rand(100000, 5000000),
                    'checksum' => sha1($s->file_path),
                    'revision' => 1,
                    'visible' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                if (count($batch) >= self::BATCH_SIZE) {
                    DB::table('file_records')->insert($batch);
                    $batch = [];
                }
            }
            if (! empty($batch)) {
                DB::table('file_records')->insert($batch);
            }
        });
    }

    // ═══════════════════════════════════════════════════════════════
    //  HELPERS
    // ═══════════════════════════════════════════════════════════════

    private function getQuizTitle(int $quizId): string
    {
        return DB::table('quizzes')->where('id', $quizId)->value('title') ?? 'Quiz';
    }

    private function getAssignmentTitle(int $assignmentId): string
    {
        return DB::table('assignments')->where('id', $assignmentId)->value('title') ?? 'Assignment';
    }

    private function getAssignmentMaxScore(int $assignmentId): int
    {
        return (int) (DB::table('assignments')->where('id', $assignmentId)->value('max_score') ?? 100);
    }

    // ═══════════════════════════════════════════════════════════════
    //  SUMMARY
    // ═══════════════════════════════════════════════════════════════

    private function printSummary(): void
    {
        $this->command->info('');
        $this->command->info('Seeding complete!');
        $this->command->info('- Users: '.DB::table('users')->count().' (40 instructors, 1960 students)');
        $this->command->info('- Courses: '.DB::table('courses')->count());
        $this->command->info('- Sections: '.DB::table('course_sections')->count());
        $this->command->info('- Enrollments: '.DB::table('course_enrollments')->count());
        $this->command->info('- Materials: '.DB::table('materials')->count());
        $this->command->info('- Quizzes: '.DB::table('quizzes')->count());
        $this->command->info('- Assignments: '.DB::table('assignments')->count());
        $this->command->info('- Quiz Attempts: '.DB::table('quiz_attempts')->count());
        $this->command->info('- Submissions: '.DB::table('submissions')->count());
        $this->command->info('- Grade Items: '.DB::table('grade_items')->count());
        $this->command->info('- Grades: '.DB::table('grades')->count());
        $this->command->info('- Course Categories: '.DB::table('course_categories')->count());
        $this->command->info('- Groups: '.DB::table('course_groups')->count());
        $this->command->info('- Group Members: '.DB::table('course_group_members')->count());
        $this->command->info('- File Records: '.DB::table('file_records')->count());
    }
}
