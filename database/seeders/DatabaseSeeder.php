<?php

namespace Database\Seeders;

use App\Models\Assignment;
use App\Models\AssignmentAllocatedMarker;
use App\Models\Context;
use App\Models\Course;
use App\Models\CourseCategory;
use App\Models\CourseCompletion;
use App\Models\CourseCompletionCriterion;
use App\Models\CourseCompletionCriterionCompletion;
use App\Models\CourseEnrollment;
use App\Models\CourseGroup;
use App\Models\CourseGrouping;
use App\Models\CourseGroupingGroup;
use App\Models\CourseGroupMember;
use App\Models\CourseSection;
use App\Models\FileRecord;
use App\Models\Grade;
use App\Models\GradeItem;
use App\Models\LearningModule;
use App\Models\Material;
use App\Models\ModuleAvailabilityRule;
use App\Models\ModuleCompletion;
use App\Models\Question;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\QuizAttemptQuestion;
use App\Models\QuizAttemptStep;
use App\Models\QuizAttemptStepData;
use App\Models\QuizGrade;
use App\Models\QuizQuestionSlot;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Deterministic seed data for the LMS benchmark.
     *
     * All data is derived from fixed arrays — no faker randomness
     * so that every seed produces identical results.
     */
    public function run(): void
    {
        ini_set('memory_limit', '512M');

        $this->command->info('--- LMS Benchmark Seed (deterministic) ---');

        // ─── 1. Users ───────────────────────────────────────────────────────────
        $this->command->info('Creating users...');

        $instructors = collect();
        foreach (['Alice', 'Bob', 'Carol', 'Dave', 'Eve'] as $i => $name) {
            $instructors->push(User::create([
                'name' => "Instructor {$name}",
                'email' => 'instructor'.strtolower($name).'@lms.test',
                'password' => bcrypt('password'),
                'role' => 'instructor',
            ]));
        }

        $students = collect();
        for ($i = 1; $i <= 50; $i++) {
            $students->push(User::create([
                'name' => "Student {$i}",
                'email' => "student{$i}@lms.test",
                'password' => bcrypt('password'),
                'role' => 'student',
            ]));
        }

        $studentIds = $students->pluck('id');

        // ─── 1b. Course Categories (Plan 005) ──────────────────────────────────
        $this->command->info('Creating course categories...');

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

        $allCategoryIds = [];
        $allCategoryNames = [];
        $createCategories = function (array $defs, ?int $parentId, int $depth, string $pathPrefix) use (&$createCategories, &$allCategoryIds, &$allCategoryNames): void {
            foreach ($defs as $i => $def) {
                $name = $def['name'];
                $visible = $def['visible'] ?? true;
                $path = $parentId !== null ? ($pathPrefix ? $pathPrefix.'/'.$parentId : (string) $parentId) : null;
                $cat = CourseCategory::create([
                    'parent_id' => $parentId,
                    'name' => $name,
                    'description' => $name.' category',
                    'sort_order' => $i,
                    'visible' => $visible,
                    'depth' => $depth,
                    'path' => $path,
                ]);
                $allCategoryIds[] = $cat->id;
                $allCategoryNames[$name] = $cat->id;
                if (! empty($def['children'])) {
                    $createCategories($def['children'], $cat->id, $depth + 1, $path ? $path.'/'.$cat->id : (string) $cat->id);
                }
            }
        };
        $createCategories($catDefs, null, 0, '');

        $this->command->info('  -> '.count($allCategoryIds).' categories created');

        // ─── 2. Courses ─────────────────────────────────────────────────────────
        $this->command->info('Creating courses...');

        $courseDefs = [
            ['title' => 'Web Development Fundamentals',    'instructor_idx' => 0, 'sections' => 3, 'active' => true, 'category' => 'Web Development'],
            ['title' => 'Data Science with Python',         'instructor_idx' => 0, 'sections' => 5, 'active' => true, 'category' => 'Data Science'],
            ['title' => 'Mobile App Development',           'instructor_idx' => 1, 'sections' => 4, 'active' => true, 'category' => 'Programming'],
            ['title' => 'Database Design & SQL',            'instructor_idx' => 1, 'sections' => 6, 'active' => true, 'category' => 'Computer Science'],
            ['title' => 'Machine Learning Foundations',     'instructor_idx' => 2, 'sections' => 7, 'active' => true, 'category' => 'Data Science'],
            ['title' => 'Cloud Architecture',               'instructor_idx' => 2, 'sections' => 3, 'active' => false, 'category' => 'Cloud Computing'],
            ['title' => 'Cybersecurity Essentials',         'instructor_idx' => 3, 'sections' => 4, 'active' => true, 'category' => 'Cybersecurity'],
            ['title' => 'UI/UX Design Principles',          'instructor_idx' => 3, 'sections' => 5, 'active' => true, 'category' => 'UI/UX Design'],
            ['title' => 'DevOps & CI/CD',                   'instructor_idx' => 4, 'sections' => 3, 'active' => true, 'category' => 'DevOps'],
            ['title' => 'Artificial Intelligence Intro',    'instructor_idx' => 4, 'sections' => 3, 'active' => false, 'category' => 'Artificial Intelligence'],
        ];

        $sectionNames = [
            ['Introduction', 'Core Concepts', 'Final Project'],
            ['Setup', 'Data Wrangling', 'Visualization', 'Statistics', 'Final Project'],
            ['Getting Started', 'UI Components', 'Navigation', 'Publishing'],
            ['ER Modeling', 'Normalization', 'SELECT Queries', 'Joins', 'Indexes', 'Transactions'],
            ['Intro', 'Regression', 'Classification', 'Clustering', 'Neural Networks', 'Evaluation', 'Capstone'],
            ['Overview', 'AWS Basics', 'Case Study'],
            ['Threat Model', 'Network Security', 'Cryptography', 'Incident Response'],
            ['Design Thinking', 'Wireframing', 'Prototyping', 'User Testing', 'Portfolio'],
            ['CI Fundamentals', 'Pipeline Setup', 'Monitoring'],
            ['History', 'Tools', 'Ethics'],
        ];

        $enrollmentMap = [
            0 => [1, 10],
            1 => [5, 19],
            2 => [10, 29],
            3 => [15, 26],
            4 => [20, 44],
            5 => [1, 30],
            6 => [5, 22],
            7 => [10, 31],
            8 => [1, 14],
            9 => [20, 29],
        ];

        $suspendEnrollments = [
            0 => [5],
            5 => [2, 15],
            9 => [22, 25],
        ];

        $expireEnrollments = [
            5 => [8],
        ];

        $courses = collect();

        foreach ($courseDefs as $ci => $def) {
            $catId = isset($def['category']) ? ($allCategoryNames[$def['category']] ?? null) : null;
            $course = Course::create([
                'name' => $def['title'],
                'description' => 'Course description for '.$def['title'].'.',
                'instructor_id' => $instructors[$def['instructor_idx']]->id,
                'is_active' => $def['active'],
                'course_category_id' => $catId,
            ]);
            $courses->push($course);

            // Create course context
            $courseContext = Context::create([
                'contextlevel' => Context::LEVEL_COURSE,
                'instance_id' => $course->id,
                'path' => '/1/'.$course->id,
                'depth' => 1,
            ]);

            // Assign instructor role at course context
            $instructorRole = Role::where('shortname', 'instructor')->first();
            if ($instructorRole) {
                RoleAssignment::firstOrCreate([
                    'role_id' => $instructorRole->id,
                    'context_id' => $courseContext->id,
                    'user_id' => $instructors[$def['instructor_idx']]->id,
                ]);
            }

            // Assign manager role at system context (first instructor)
            if ($ci === 0) {
                $systemContext = Context::firstOrCreate(
                    ['contextlevel' => Context::LEVEL_SYSTEM, 'instance_id' => 0],
                    ['path' => '/1', 'depth' => 0]
                );
                $managerRole = Role::where('shortname', 'manager')->first();
                if ($managerRole) {
                    RoleAssignment::firstOrCreate([
                        'role_id' => $managerRole->id,
                        'context_id' => $systemContext->id,
                        'user_id' => $instructors[$def['instructor_idx']]->id,
                    ]);
                }
            }

            // Sections
            $sections = collect();
            $names = $sectionNames[$ci];
            for ($si = 0; $si < $def['sections']; $si++) {
                $sectionTitle = $names[$si] ?? 'Section '.($si + 1);
                $sections->push(CourseSection::create([
                    'course_id' => $course->id,
                    'title' => $sectionTitle,
                    'summary' => 'Summary for '.$sectionTitle.'.',
                    'sort_order' => $si,
                    'visible' => true,
                ]));
            }
            // Make last section of inactive courses hidden
            if (! $def['active']) {
                $sections->last()->update(['visible' => false]);
            }

            // Enroll students
            [$start, $end] = $enrollmentMap[$ci];
            $suspended = $suspendEnrollments[$ci] ?? [];
            $expired = $expireEnrollments[$ci] ?? [];

            for ($sid = $start; $sid <= $end; $sid++) {
                $student = $students->where('id', $studentIds[$sid - 1])->first();
                if (! $student) {
                    continue;
                }
                $status = 'active';
                $endsAt = null;
                if (in_array($sid, $suspended)) {
                    $status = 'suspended';
                } elseif (in_array($sid, $expired)) {
                    $status = 'active';
                    $endsAt = now()->subDay();
                }
                $enrollment = CourseEnrollment::create([
                    'user_id' => $student->id,
                    'course_id' => $course->id,
                    'role' => 'student',
                    'status' => $status,
                    'enrolled_at' => now()->subDays(60),
                    'starts_at' => now()->subDays(60),
                    'ends_at' => $endsAt,
                ]);

                // Create role_assignment for student at course context
                if ($enrollment->role === 'student' && $status === 'active') {
                    $studentRole = Role::where('shortname', 'student')->first();
                    if ($studentRole) {
                        RoleAssignment::firstOrCreate([
                            'role_id' => $studentRole->id,
                            'context_id' => $courseContext->id,
                            'user_id' => $student->id,
                        ]);
                    }
                }
            }

            // Enroll instructor as instructor-role enrollment too
            CourseEnrollment::create([
                'user_id' => $instructors[$def['instructor_idx']]->id,
                'course_id' => $course->id,
                'role' => 'instructor',
                'status' => 'active',
                'enrolled_at' => now()->subDays(60),
                'starts_at' => now()->subDays(60),
                'ends_at' => null,
            ]);

            // ─── Groups ─────────────────────────────────────────────────────────
            $groupDefsForCourse = [
                0 => ['Group A', 'Group B'],
                1 => ['Alpha', 'Beta', 'Gamma'],
                2 => ['Team 1'],
                3 => ['Workshop 1', 'Workshop 2'],
                4 => ['Lab A', 'Lab B', 'Lab C'],
                5 => [],
                6 => ['Study Group', 'Project Team'],
                7 => ['Section 1', 'Section 2'],
                8 => ['Cohort 1'],
                9 => [],
            ];

            $createdGroups = collect();
            foreach (($groupDefsForCourse[$ci] ?? []) as $gi => $gname) {
                $group = CourseGroup::create([
                    'course_id' => $course->id,
                    'name' => $gname,
                    'sort_order' => $gi,
                    'active' => true,
                ]);
                $createdGroups->push($group);
            }

            // Group members — split enrolled students across groups
            if ($createdGroups->isNotEmpty()) {
                $enrolledStudentIds = CourseEnrollment::where('course_id', $course->id)
                    ->where('role', 'student')
                    ->where('status', 'active')
                    ->pluck('user_id');
                $split = $enrolledStudentIds->chunk((int) ceil($enrolledStudentIds->count() / $createdGroups->count()));
                foreach ($createdGroups as $gi => $group) {
                    $chunk = $split->get($gi);
                    if ($chunk) {
                        foreach ($chunk as $uid) {
                            CourseGroupMember::create([
                                'course_group_id' => $group->id,
                                'user_id' => $uid,
                            ]);
                        }
                    }
                }
            }

            // ─── Course Groupings (Plan 005) ────────────────────────────────────
            // Create groupings for courses that have groups
            if ($createdGroups->isNotEmpty() && in_array($ci, [1, 4, 6, 7], true)) {
                $grouping = CourseGrouping::create([
                    'course_id' => $course->id,
                    'name' => 'Lab Cohorts',
                    'sort_order' => 0,
                    'active' => true,
                ]);
                // Add all course groups to this grouping
                foreach ($createdGroups as $cg) {
                    CourseGroupingGroup::create([
                        'course_grouping_id' => $grouping->id,
                        'course_group_id' => $cg->id,
                    ]);
                }
                $this->command->info('  -> Created grouping with '.$createdGroups->count().' groups for course '.$course->id);
            }

            // ─── Modules per Section ────────────────────────────────────────────
            $sort = 0;
            $activitySpecs = $this->getActivitySpecsForCourse($ci);

            foreach ($sections as $section) {
                $specs = $activitySpecs[$section->title] ?? [];
                foreach ($specs as $spec) {
                    $spec['course_id'] = $course->id;
                    $spec['course_section_id'] = $section->id;
                    $spec['sort_order'] = $sort++;
                    $this->createActivity($spec, $course, $section, $ci, $createdGroups);
                }
            }

            // ─── Grade Items for quizzes and assignments ────────────────────────
            $quizzes = Quiz::where('course_id', $course->id)->get();
            foreach ($quizzes as $quiz) {
                GradeItem::create([
                    'course_id' => $course->id,
                    'item_type' => 'quiz',
                    'item_id' => $quiz->id,
                    'name' => $quiz->title.' - Grade',
                    'max_score' => 100,
                    'pass_score' => 60,
                    'weight' => round(mt_rand(50, 200) / 100, 2),
                    'hidden' => false,
                    'locked' => false,
                    'source' => 'quiz',
                ]);
            }

            $assignments = Assignment::where('course_id', $course->id)->get();
            foreach ($assignments as $assignment) {
                GradeItem::create([
                    'course_id' => $course->id,
                    'item_type' => 'assignment',
                    'item_id' => $assignment->id,
                    'name' => $assignment->title.' - Grade',
                    'max_score' => $assignment->max_score,
                    'pass_score' => $assignment->max_score * 0.6,
                    'weight' => round(mt_rand(50, 200) / 100, 2),
                    'hidden' => false,
                    'locked' => false,
                    'source' => 'assignment',
                ]);
            }

            // ─── Second pass: min-grade availability rules (requires grade items to exist) ─
            $this->createMinGradeRules($course, $ci);

            // ─── Course-level completion criteria ────────────────────────────────
            $this->createCourseCompletionCriteria($course, $ci);

            // ─── Pre-seed course completions from existing data ──────────────────
            $this->preSeedCourseCompletions($course);

            unset($sections, $quizzes, $assignments, $createdGroups);
        }

        unset($courses);

        // ─── Benchmark-required seed additions ──────────────────────────────────
        $this->command->info('Adding benchmark-specific seed data...');

        // Lock one grade item for locked-grade benchmark targets
        $firstQuizGradeItem = GradeItem::where('item_type', 'quiz')->first();
        if ($firstQuizGradeItem) {
            $firstQuizGradeItem->update(['locked' => true]);
            $this->command->info('  -> Locked grade item '.$firstQuizGradeItem->id);
        }

        // Create one quiz override for a student on the first quiz
        $firstQuiz = Quiz::where('is_active', true)->first();
        $sampleStudent = User::where('role', 'student')->first();
        if ($firstQuiz && $sampleStudent) {
            \App\Models\QuizOverride::create([
                'quiz_id' => $firstQuiz->id,
                'user_id' => $sampleStudent->id,
                'max_attempts' => 5,
                'time_limit' => 60,
                'grace_period' => 5,
            ]);
            $this->command->info('  -> Created quiz override for user '.$sampleStudent->id.' on quiz '.$firstQuiz->id);
        }

        // Create one assignment override for a student on the first assignment
        $firstAssignment = Assignment::where('is_active', true)->first();
        if ($firstAssignment && $sampleStudent) {
            \App\Models\AssignmentOverride::create([
                'assignment_id' => $firstAssignment->id,
                'user_id' => $sampleStudent->id,
                'due_date' => now()->addDays(35),
                'cutoff_date' => now()->addDays(40),
                'max_attempts' => 5,
            ]);
            $this->command->info('  -> Created assignment override for user '.$sampleStudent->id.' on assignment '.$firstAssignment->id);
        }

        // ─── Quiz Attempts ──────────────────────────────────────────────────────
        $this->command->info('Creating quiz attempts...');
        $attemptCount = 0;
        Quiz::with('course.enrollments')->chunk(50, function ($quizzes) use (&$attemptCount) {
            foreach ($quizzes as $quiz) {
                $enrolled = $quiz->course->enrollments
                    ->where('role', 'student')
                    ->where('status', 'active')
                    ->pluck('user_id');
                if ($enrolled->isEmpty()) {
                    continue;
                }
                $sample = $enrolled->take(5);
                foreach ($sample as $uid) {
                    try {
                        QuizAttempt::create([
                            'quiz_id' => $quiz->id,
                            'user_id' => $uid,
                            'answers' => [],
                            'score' => rand(40, 100),
                            'status' => 'finished',
                            'attempt_number' => 1,
                            'started_at' => now()->subDays(rand(1, 30)),
                            'completed_at' => now()->subDays(rand(0, 5)),
                            'submitted_at' => now()->subDays(rand(0, 5)),
                            'expires_at' => null,
                        ]);
                        $attemptCount++;
                    } catch (\Illuminate\Database\QueryException $e) {
                        // Unique constraint — skip duplicates
                    }
                }
            }
        });
        $this->command->info('  -> '.$attemptCount.' quiz attempts');

        // ─── Assignment Submissions ─────────────────────────────────────────────
        $this->command->info('Creating assignment submissions...');
        $submissionCount = 0;
        Assignment::with('course.enrollments')->chunk(50, function ($assignments) use (&$submissionCount) {
            foreach ($assignments as $assignment) {
                $enrolled = $assignment->course->enrollments
                    ->where('role', 'student')
                    ->where('status', 'active')
                    ->pluck('user_id');
                if ($enrolled->isEmpty()) {
                    continue;
                }
                $sample = $enrolled->take(3);
                foreach ($sample as $uid) {
                    try {
                        Submission::create([
                            'assignment_id' => $assignment->id,
                            'user_id' => $uid,
                            'file_path' => 'submissions/assignment-'.$assignment->id.'-user-'.$uid.'.pdf',
                            'score' => rand(40, 100),
                            'status' => 'graded',
                            'attempt_number' => 1,
                            'is_latest' => true,
                            'submitted_at' => now()->subDays(rand(1, 15)),
                            'graded_at' => now()->subDays(rand(0, 5)),
                            'late' => false,
                        ]);
                        $submissionCount++;
                    } catch (\Illuminate\Database\QueryException $e) {
                        // Unique constraint — skip duplicates
                    }
                }
            }
        });
        $this->command->info('  -> '.$submissionCount.' submissions');

        // ─── Grades from Quiz Attempts ──────────────────────────────────────────
        $this->command->info('Creating grades from quiz attempts...');
        $gradeCount = 0;
        QuizAttempt::whereNotNull('score')
            ->where('status', 'finished')
            ->chunk(100, function ($attempts) use (&$gradeCount) {
                foreach ($attempts as $attempt) {
                    $gradeItem = GradeItem::where('course_id', $attempt->quiz->course_id)
                        ->where('item_type', 'quiz')
                        ->where('item_id', $attempt->quiz_id)
                        ->first();
                    if (! $gradeItem) {
                        continue;
                    }
                    try {
                        Grade::create([
                            'user_id' => $attempt->user_id,
                            'course_id' => $attempt->quiz->course_id,
                            'grade_item_id' => $gradeItem->id,
                            'gradeable_type' => 'quiz_attempt',
                            'gradeable_id' => $attempt->id,
                            'score' => $attempt->score,
                            'max_score' => 100,
                            'percentage' => $attempt->score,
                            'status' => 'final',
                            'source' => 'quiz',
                        ]);
                        $gradeCount++;
                    } catch (\Illuminate\Database\QueryException $e) {
                        // Unique constraint skip
                    }
                }
            });
        $this->command->info('  -> '.$gradeCount.' grades from quiz attempts');

        // ─── Grades from Submissions ────────────────────────────────────────────
        $this->command->info('Creating grades from submissions...');
        Submission::whereNotNull('score')
            ->where('status', 'graded')
            ->chunk(100, function ($submissions) use (&$gradeCount) {
                foreach ($submissions as $submission) {
                    $gradeItem = GradeItem::where('course_id', $submission->assignment->course_id)
                        ->where('item_type', 'assignment')
                        ->where('item_id', $submission->assignment_id)
                        ->first();
                    if (! $gradeItem) {
                        continue;
                    }
                    $maxScore = $submission->assignment->max_score ?: 100;
                    try {
                        Grade::create([
                            'user_id' => $submission->user_id,
                            'course_id' => $submission->assignment->course_id,
                            'grade_item_id' => $gradeItem->id,
                            'gradeable_type' => 'submission',
                            'gradeable_id' => $submission->id,
                            'score' => $submission->score,
                            'max_score' => $maxScore,
                            'percentage' => ($submission->score / $maxScore) * 100,
                            'status' => 'final',
                            'source' => 'assignment',
                        ]);
                        $gradeCount++;
                    } catch (\Illuminate\Database\QueryException $e) {
                        // Unique constraint skip
                    }
                }
            });
        $this->command->info('  -> grades total now: '.$gradeCount);

        // ─── Backfill Quiz Attempt Detail Rows (Plan 001) ───────────────────────
        $this->command->info('Backfilling quiz attempt detail rows...');
        $detailCount = 0;
        QuizAttempt::where('status', 'finished')
            ->whereDoesntHave('attemptQuestions')
            ->chunk(50, function ($attempts) use (&$detailCount) {
                foreach ($attempts as $attempt) {
                    $quiz = $attempt->quiz;
                    if (! $quiz) {
                        continue;
                    }

                    $quiz->load(['questionSlots.question']);
                    foreach ($quiz->questionSlots as $slot) {
                        $attemptQuestion = QuizAttemptQuestion::create([
                            'quiz_attempt_id' => $attempt->id,
                            'quiz_question_slot_id' => $slot->id,
                            'question_id' => $slot->question_id,
                            'slot' => $slot->slot,
                            'max_points' => $slot->max_points ?? (float) $slot->question?->points ?? 0,
                            'score' => null,
                            'state' => 'not_answered',
                        ]);

                        QuizAttemptStep::create([
                            'quiz_attempt_question_id' => $attemptQuestion->id,
                            'sequence_number' => 0,
                            'state' => 'not_answered',
                            'user_id' => $attempt->user_id,
                        ]);

                        $detailCount++;
                    }
                }
            });
        $this->command->info('  -> '.$detailCount.' attempt-question rows created');

        // ─── Backfill Quiz Aggregate Grades (Plan 002) ─────────────────────────
        $this->command->info('Backfilling quiz aggregate grades...');
        $aggCount = 0;
        QuizAttempt::where('status', 'finished')
            ->whereNotNull('score')
            ->chunk(100, function ($attempts) use (&$aggCount) {
                // Group by (quiz_id, user_id) and compute aggregate
                $grouped = $attempts->groupBy(fn ($a) => $a->quiz_id.'-'.$a->user_id);
                foreach ($grouped as $key => $userAttempts) {
                    $first = $userAttempts->first();
                    $quizId = $first->quiz_id;
                    $userId = $first->user_id;
                    $quiz = $first->quiz;
                    if (! $quiz) {
                        continue;
                    }

                    $maxScore = (float) $quiz->questions->sum('points');
                    $gradingMethod = $quiz->grading_method ?? 'highest';

                    $grade = match ($gradingMethod) {
                        'highest' => $userAttempts->max('score'),
                        'latest' => $userAttempts->sortByDesc('completed_at')->first()->score,
                        'average' => $userAttempts->avg('score'),
                        'first' => $userAttempts->sortBy('completed_at')->first()->score,
                        default => $userAttempts->max('score'),
                    };
                    $percentage = $maxScore > 0 ? $grade : 0;

                    QuizGrade::updateOrCreate(
                        ['quiz_id' => $quizId, 'user_id' => $userId],
                        [
                            'grade' => $grade,
                            'max_score' => $maxScore,
                            'percentage' => $percentage,
                            'grading_method' => $gradingMethod,
                            'attempt_count' => $userAttempts->count(),
                            'last_attempt_id' => $userAttempts->sortByDesc('id')->first()->id,
                            'graded_at' => now(),
                        ]
                    );
                    $aggCount++;
                }
            });
        $this->command->info('  -> '.$aggCount.' quiz aggregate grades created/updated');

        // ─── Backfill Assignment Marker Allocation (Plan 004) ───────────────────
        $this->command->info('Backfilling assignment marker allocations...');
        $markerAllocCount = 0;
        $assignments = Assignment::where('is_active', true)->get();
        // Pick first 3 assignments for marker allocation
        foreach ($assignments->take(3) as $assignment) {
            $assignment->update([
                'marking_allocation_enabled' => true,
                'marker_count' => 2,
                'multi_mark_method' => 'average',
            ]);

            // Find submissions for this assignment
            $submissions = Submission::where('assignment_id', $assignment->id)->take(3)->get();
            // Find instructors other than the course owner
            $otherInstructors = User::where('role', 'instructor')
                ->where('id', '!=', $assignment->course->instructor_id)
                ->take(2)
                ->get();

            foreach ($submissions as $submission) {
                foreach ($otherInstructors as $marker) {
                    AssignmentAllocatedMarker::firstOrCreate([
                        'assignment_id' => $assignment->id,
                        'submission_id' => $submission->id,
                        'student_id' => $submission->user_id,
                        'marker_id' => $marker->id,
                    ]);
                    $markerAllocCount++;
                }
            }
        }
        $this->command->info('  -> '.$markerAllocCount.' marker allocations created');

        // ─── File Records for Materials ─────────────────────────────────────────
        $this->command->info('Creating file records for materials...');
        Material::chunk(50, function ($materials) {
            foreach ($materials as $material) {
                FileRecord::create([
                    'owner_type' => 'material',
                    'owner_id' => $material->id,
                    'uploader_id' => $material->course->instructor_id,
                    'component' => 'material',
                    'file_path' => $material->file_path,
                    'mime_type' => $material->mime_type,
                    'file_size' => $material->file_size,
                    'checksum' => sha1($material->file_path),
                    'revision' => $material->revision,
                    'visible' => true,
                ]);
            }
        });

        // ─── File Records for Submissions ───────────────────────────────────────
        $this->command->info('Creating file records for submissions...');
        Submission::chunk(50, function ($submissions) {
            foreach ($submissions as $submission) {
                FileRecord::create([
                    'owner_type' => 'submission',
                    'owner_id' => $submission->id,
                    'uploader_id' => $submission->user_id,
                    'component' => 'assignment_submission',
                    'file_path' => $submission->file_path,
                    'mime_type' => 'application/pdf',
                    'file_size' => rand(100000, 5000000),
                    'checksum' => sha1($submission->file_path),
                    'revision' => 1,
                    'visible' => true,
                ]);
            }
        });

        // ─── Summary ────────────────────────────────────────────────────────────
        $this->command->info('');
        $this->command->info('Seeding complete!');
        $this->command->info('- Users: '.User::count().' (5 instructors, 50 students)');
        $this->command->info('- Courses: '.Course::count().' (2 inactive)');
        $this->command->info('- Sections: '.CourseSection::count());
        $this->command->info('- Enrollments: '.CourseEnrollment::count().' (includes suspended & expired)');
        $this->command->info('- Groups: '.CourseGroup::count());
        $this->command->info('- Group Members: '.CourseGroupMember::count());
        $this->command->info('- Materials: '.Material::count());
        $this->command->info('- Quizzes: '.Quiz::count());
        $this->command->info('- Assignments: '.Assignment::count());
        $this->command->info('- Learning Modules: '.LearningModule::count());
        $this->command->info('- Availability Rules: '.ModuleAvailabilityRule::count());
        $this->command->info('- Module Completions: '.ModuleCompletion::count());
        $this->command->info('- Course Completion Criteria: '.CourseCompletionCriterion::count());
        $this->command->info('- Course Completions: '.CourseCompletion::count());
        $this->command->info('- Quiz Attempts: '.QuizAttempt::count());
        $this->command->info('- Submissions: '.Submission::count());
        $this->command->info('- Grade Items: '.GradeItem::count());
        $this->command->info('- Grades: '.Grade::count());
        $this->command->info('- Course Categories: '.CourseCategory::count());
        $this->command->info('- Course Groupings: '.CourseGrouping::count());
        $this->command->info('- Grouping Groups: '.CourseGroupingGroup::count());
        $this->command->info('- Quiz Attempt Questions: '.QuizAttemptQuestion::count());
        $this->command->info('- Quiz Attempt Steps: '.QuizAttemptStep::count());
        $this->command->info('- Quiz Attempt Step Data: '.QuizAttemptStepData::count());
        $this->command->info('- Quiz Aggregate Grades: '.QuizGrade::count());
        $this->command->info('- Marker Allocations: '.AssignmentAllocatedMarker::count());
        $this->command->info('- File Records: '.FileRecord::count());
    }

    /**
     * Return activity specs keyed by section title for a given course index.
     */
    private function getActivitySpecsForCourse(int $courseIdx): array
    {
        $all = [
            0 => [ // Web Development Fundamentals
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
            ],
            1 => [ // Data Science with Python
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
            ],
            2 => [ // Mobile App Development
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
            ],
            3 => [ // Database Design & SQL
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
            ],
            4 => [ // Machine Learning Foundations
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
            ],
            5 => [ // Cloud Architecture (inactive)
                'Overview' => [
                    ['type' => 'material', 'title' => 'Cloud Computing Overview', 'visible' => true, 'completion_enabled' => false],
                ],
                'AWS Basics' => [
                    ['type' => 'material', 'title' => 'AWS Core Services', 'visible' => true, 'completion_enabled' => false],
                ],
                'Case Study' => [
                    ['type' => 'material', 'title' => 'Architecture Case Study', 'visible' => true, 'completion_enabled' => false],
                ],
            ],
            6 => [ // Cybersecurity Essentials
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
            ],
            7 => [ // UI/UX Design Principles
                'Design Thinking' => [
                    ['type' => 'material', 'title' => 'Design Thinking Process', 'visible' => true, 'completion_enabled' => true],
                ],
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
                'Portfolio' => [
                    ['type' => 'material', 'title' => 'Building Your Portfolio', 'visible' => true, 'completion_enabled' => false],
                ],
            ],
            8 => [ // DevOps & CI/CD
                'CI Fundamentals' => [
                    ['type' => 'material', 'title' => 'Continuous Integration Concepts', 'visible' => true, 'completion_enabled' => true],
                ],
                'Pipeline Setup' => [
                    ['type' => 'material', 'title' => 'Building a CI Pipeline', 'visible' => true, 'completion_enabled' => true],
                    ['type' => 'assignment', 'title' => 'GitHub Actions Pipeline', 'visible' => true, 'completion_enabled' => true],
                    ['type' => 'quiz', 'title' => 'CI/CD Concepts Quiz', 'visible' => true, 'completion_enabled' => true],
                ],
                'Monitoring' => [
                    ['type' => 'material', 'title' => 'Application Monitoring', 'visible' => true, 'completion_enabled' => false],
                ],
            ],
            9 => [ // Artificial Intelligence Intro (inactive)
                'History' => [
                    ['type' => 'material', 'title' => 'History of AI', 'visible' => true, 'completion_enabled' => false],
                ],
                'Tools' => [
                    ['type' => 'material', 'title' => 'AI Tools & Libraries', 'visible' => true, 'completion_enabled' => false],
                ],
                'Ethics' => [
                    ['type' => 'material', 'title' => 'AI Ethics', 'visible' => true, 'completion_enabled' => false],
                ],
            ],
        ];

        return $all[$courseIdx] ?? [];
    }

    /**
     * Create a single activity (material/quiz/assignment) and its LearningModule wrapper.
     */
    private function createActivity(array $spec, Course $course, CourseSection $section, int $courseIdx, $createdGroups): void
    {
        $type = $spec['type'];
        $title = $spec['title'];
        $visible = $spec['visible'] ?? true;
        $completionEnabled = $spec['completion_enabled'] ?? false;
        $availableFrom = $spec['available_from'] ?? null;
        $availableUntil = $spec['available_until'] ?? null;

        $activity = null;

        if ($type === 'material') {
            $activity = Material::create([
                'course_id' => $course->id,
                'title' => $title,
                'file_path' => 'materials/'.strtolower(str_replace(' ', '-', $title)).'.pdf',
                'file_size' => rand(200000, 5000000),
                'type' => 'pdf',
                'mime_type' => 'application/pdf',
                'revision' => 1,
                'checksum' => sha1($title),
                'is_active' => $visible,
            ]);
        } elseif ($type === 'quiz') {
            $activity = Quiz::create([
                'course_id' => $course->id,
                'title' => $title,
                'description' => 'Quiz: '.$title,
                'time_limit' => 30,
                'passing_score' => 60,
                'is_active' => $visible,
                'available_from' => $availableFrom,
                'available_until' => $availableUntil,
                'max_attempts' => 2,
                'grading_method' => 'highest',
                'grace_period' => 0,
                'overdue_handling' => 'auto_submit',
                'delay_between_attempts' => 0,
                'review_visibility' => 'after_submission',
            ]);

            // Create questions for this quiz
            for ($qi = 1; $qi <= 5; $qi++) {
                $question = Question::create([
                    'quiz_id' => $activity->id,
                    'question_text' => 'Question '.$qi.': Sample question for '.$title.'?',
                    'options' => ['A' => 'Option A', 'B' => 'Option B', 'C' => 'Option C', 'D' => 'Option D'],
                    'correct_answer' => 'A',
                    'points' => 1,
                ]);

                // Create quiz_question_slot
                QuizQuestionSlot::create([
                    'quiz_id' => $activity->id,
                    'question_id' => $question->id,
                    'slot' => $qi,
                    'page' => 1,
                    'max_points' => $question->points,
                    'require_previous' => false,
                ]);
            }
        } elseif ($type === 'assignment') {
            $activity = Assignment::create([
                'course_id' => $course->id,
                'title' => $title,
                'description' => 'Assignment: '.$title,
                'due_date' => now()->addDays(30),
                'max_score' => 100,
                'is_active' => true,
                'available_from' => now()->subDays(30),
                'cutoff_date' => now()->addDays(35),
                'max_attempts' => 1,
                'allow_late_submission' => true,
                'submission_type' => 'file',
            ]);
        }

        if (! $activity) {
            return;
        }

        // Create the LearningModule wrapper
        $module = LearningModule::create([
            'course_id' => $course->id,
            'course_section_id' => $section->id,
            'module_type' => $type,
            'module_id' => $activity->id,
            'visible' => $visible,
            'available_from' => $availableFrom,
            'available_until' => $availableUntil,
            'sort_order' => $spec['sort_order'] ?? 0,
            'completion_enabled' => $completionEnabled,
            'completion_rule' => $completionEnabled ? ($type === 'quiz' ? 'finish' : ($type === 'assignment' ? 'submit' : 'view')) : null,
        ]);

        // Create module context
        $courseCtx = Context::where('contextlevel', Context::LEVEL_COURSE)
            ->where('instance_id', $course->id)
            ->first();
        if ($courseCtx) {
            Context::create([
                'contextlevel' => Context::LEVEL_MODULE,
                'instance_id' => $module->id,
                'path' => $courseCtx->path.'/'.$module->id,
                'depth' => $courseCtx->depth + 1,
            ]);
        }

        // Availability rules for certain modules

        // Course 0: Setup Guide requires completing Course Overview
        if ($courseIdx === 0 && $title === 'Development Environment Setup') {
            $prereq = LearningModule::where('course_id', $course->id)
                ->where('module_type', 'material')
                ->whereHas('material', function ($q) {
                    $q->where('title', 'Course Overview');
                })
                ->first();
            if ($prereq) {
                ModuleAvailabilityRule::create([
                    'learning_module_id' => $module->id,
                    'rule_type' => 'completion',
                    'required_module_id' => $prereq->id,
                    'operator' => '==',
                    'value' => 'complete',
                ]);
            }
        }

        // Course 1: Data Wrangling Quiz requires completing Data Cleaning Exercise
        if ($courseIdx === 1 && $title === 'Data Wrangling Quiz') {
            $prereq = LearningModule::where('course_id', $course->id)
                ->where('module_type', 'assignment')
                ->whereHas('assignment', function ($q) {
                    $q->where('title', 'Data Cleaning Exercise');
                })
                ->first();
            if ($prereq) {
                ModuleAvailabilityRule::create([
                    'learning_module_id' => $module->id,
                    'rule_type' => 'completion',
                    'required_module_id' => $prereq->id,
                    'operator' => '==',
                    'value' => 'complete',
                ]);
            }
        }

        // Course 3: SQL Query Exercises requires completing Normalization Quiz
        if ($courseIdx === 3 && $title === 'SQL Query Exercises') {
            $prereq = LearningModule::where('course_id', $course->id)
                ->where('module_type', 'quiz')
                ->whereHas('quiz', function ($q) {
                    $q->where('title', 'Normalization Quiz');
                })
                ->first();
            if ($prereq) {
                ModuleAvailabilityRule::create([
                    'learning_module_id' => $module->id,
                    'rule_type' => 'completion',
                    'required_module_id' => $prereq->id,
                    'operator' => '==',
                    'value' => 'complete',
                ]);
            }
        }

        // Course 6: Threat Model Document has group rule
        if ($courseIdx === 6 && $title === 'Threat Model Document') {
            $firstGroup = $createdGroups->first();
            if ($firstGroup) {
                ModuleAvailabilityRule::create([
                    'learning_module_id' => $module->id,
                    'rule_type' => 'group',
                    'course_group_id' => $firstGroup->id,
                    'operator' => '==',
                    'value' => 'member',
                ]);
            }
        }

        // Course 7: Create Wireframes has date availability window
        if ($courseIdx === 7 && $title === 'Create Wireframes') {
            $module->update([
                'available_from' => now()->subDays(15),
                'available_until' => now()->addDays(45),
            ]);
        }

        // Course 4: Nested availability rule on Classification Task
        // (completion of Regression Analysis) OR (completion of Clustering Quiz)
        // Group 1: completion of Regression Analysis (exists when Classification Task is created)
        if ($courseIdx === 4 && $title === 'Classification Task') {
            $regressionModule = LearningModule::where('course_id', $course->id)
                ->where('module_type', 'assignment')
                ->whereHas('assignment', fn ($q) => $q->where('title', 'Regression Analysis'))
                ->first();

            if ($regressionModule) {
                ModuleAvailabilityRule::create([
                    'learning_module_id' => $module->id,
                    'rule_type' => 'completion',
                    'required_module_id' => $regressionModule->id,
                    'operator' => '==',
                    'condition_group' => 1,
                ]);
            }
        }

        // Course 4: Add the Clustering Quiz branch to the nested availability rule
        // (created when Clustering Quiz is processed, since Classification Task already exists)
        if ($courseIdx === 4 && $title === 'Clustering Quiz') {
            $classModule = LearningModule::where('course_id', $course->id)
                ->where('module_type', 'assignment')
                ->whereHas('assignment', fn ($q) => $q->where('title', 'Classification Task'))
                ->first();

            if ($classModule) {
                ModuleAvailabilityRule::create([
                    'learning_module_id' => $classModule->id,
                    'rule_type' => 'completion',
                    'required_module_id' => $module->id,
                    'operator' => '==',
                    'condition_group' => 2,
                ]);

                $this->command->info('  -> Added Clustering Quiz branch to nested availability rule for Classification Task');
            }
        }

        // Create completion records for completion-enabled modules for some students
        if ($completionEnabled) {
            $enrolledIds = CourseEnrollment::where('course_id', $course->id)
                ->where('role', 'student')
                ->where('status', 'active')
                ->pluck('user_id')
                ->take(3);

            foreach ($enrolledIds as $uid) {
                $source = match ($type) {
                    'material' => 'view',
                    'quiz' => 'quiz_attempt',
                    'assignment' => 'assignment_submission',
                    default => 'view',
                };
                ModuleCompletion::create([
                    'learning_module_id' => $module->id,
                    'user_id' => $uid,
                    'state' => 'complete',
                    'completed_at' => now()->subDays(rand(1, 20)),
                    'source' => $source,
                    'override_by' => null,
                ]);
            }
        }
    }

    /**
     * Second pass: create min-grade availability rules that depend on grade items.
     * These cannot be created during createActivity() because grade items are
     * created after all activities.
     */
    private function createMinGradeRules(Course $course, int $courseIdx): void
    {
        if ($courseIdx !== 4) {
            return;
        }

        // Course 4: Regression Analysis requires min grade on ML Concepts Quiz
        $module = LearningModule::query()
            ->where('course_id', $course->id)
            ->where('module_type', 'assignment')
            ->whereHas('assignment', function ($q) {
                $q->where('title', 'Regression Analysis');
            })
            ->first();

        if (! $module) {
            return;
        }

        $gradeItem = GradeItem::where('course_id', $course->id)
            ->where('item_type', 'quiz')
            ->get()
            ->first(function ($gi) {
                $q = Quiz::find($gi->item_id);

                return $q && $q->title === 'ML Concepts Quiz';
            });

        if (! $gradeItem) {
            return;
        }

        ModuleAvailabilityRule::create([
            'learning_module_id' => $module->id,
            'rule_type' => 'min_grade',
            'grade_item_id' => $gradeItem->id,
            'operator' => '>=',
            'value' => '60',
        ]);

        $this->command->info('  -> Created min-grade rule for Regression Analysis (course '.$courseIdx.')');
    }

    /**
     * Create course-level completion criteria for specific courses.
     */
    private function createCourseCompletionCriteria(Course $course, int $courseIdx): void
    {
        switch ($courseIdx) {
            case 0: // Web Dev
                $this->addModuleCriteriaForCourse($course, ['Course Overview', 'HTML & CSS Basics']);
                $this->addGradeCriteriaForQuiz($course, 'HTML & CSS Basics Quiz', 60);
                break;
            case 1: // Data Science
                $this->addModuleCriteriaForCourse($course, ['Python Basics', 'Data Cleaning Exercise', 'Data Wrangling Quiz']);
                break;
            case 3: // Database
                $this->addModuleCriteriaForCourse($course, ['Entity-Relationship Diagrams']);
                $this->addGradeCriteriaForQuiz($course, 'Normalization Quiz', 60);
                break;
            case 7: // UI/UX
                $this->addModuleCriteriaForCourse($course, ['Design Thinking Process']);
                $this->addGradeCriteriaForQuiz($course, 'UX Principles Quiz', 60);
                break;
            case 8: // DevOps
                $this->addModuleCriteriaForCourse($course, ['Continuous Integration Concepts']);
                $this->addGradeCriteriaForQuiz($course, 'CI/CD Concepts Quiz', 60);
                break;
        }
    }

    private function addModuleCriteriaForCourse(Course $course, array $titles): void
    {
        $modules = LearningModule::where('course_id', $course->id)
            ->where(function ($q) use ($titles) {
                $q->whereHas('material', fn ($q) => $q->whereIn('title', $titles))
                    ->orWhereHas('quiz', fn ($q) => $q->whereIn('title', $titles))
                    ->orWhereHas('assignment', fn ($q) => $q->whereIn('title', $titles));
            })
            ->get();

        foreach ($modules as $module) {
            CourseCompletionCriterion::firstOrCreate([
                'course_id' => $course->id,
                'criteriatype' => 'module',
                'module_instance_id' => $module->id,
            ]);
        }
    }

    private function addGradeCriteriaForQuiz(Course $course, string $quizTitle, float $passThreshold): void
    {
        $quiz = Quiz::where('course_id', $course->id)->where('title', $quizTitle)->first();
        if (! $quiz) {
            return;
        }

        $gradeItem = GradeItem::where('course_id', $course->id)
            ->where('item_type', 'quiz')
            ->where('item_id', $quiz->id)
            ->first();

        if (! $gradeItem) {
            return;
        }

        CourseCompletionCriterion::firstOrCreate([
            'course_id' => $course->id,
            'criteriatype' => 'grade',
            'grade_item_id' => $gradeItem->id,
            'pass_threshold' => $passThreshold,
        ]);
    }

    /**
     * Pre-seed course completions from existing module completions and grades.
     *
     * For each active student in the course, checks each criterion against existing
     * completion and grade data. Creates criterion_completion records for matched
     * criteria, and marks the course complete if all criteria are met.
     */
    private function preSeedCourseCompletions(Course $course): void
    {
        $criteria = CourseCompletionCriterion::query()
            ->where('course_id', $course->id)
            ->get();

        if ($criteria->isEmpty()) {
            return;
        }

        $activeStudentIds = CourseEnrollment::query()
            ->where('course_id', $course->id)
            ->where('role', 'student')
            ->where('status', 'active')
            ->pluck('user_id');

        foreach ($activeStudentIds as $userId) {
            $allMet = true;

            foreach ($criteria as $criterion) {
                $met = match ($criterion->criteriatype) {
                    'module' => $this->checkModuleCriterionMet($criterion, $userId),
                    'grade' => $this->checkGradeCriterionMet($criterion, $userId),
                    default => false,
                };

                if ($met) {
                    CourseCompletionCriterionCompletion::query()->firstOrCreate(
                        [
                            'course_completion_criterion_id' => $criterion->id,
                            'user_id' => $userId,
                        ],
                        [
                            'completed' => true,
                            'completed_at' => now(),
                        ]
                    );
                } else {
                    $allMet = false;
                }
            }

            if ($allMet) {
                CourseCompletion::query()->firstOrCreate(
                    [
                        'course_id' => $course->id,
                        'user_id' => $userId,
                    ],
                    [
                        'timeenrolled' => now()->subDays(60),
                        'timestarted' => now()->subDays(30),
                        'timecompleted' => now()->subDays(1),
                        'reaggregate' => false,
                    ]
                );
            } else {
                // Ensure at least an incomplete record exists
                CourseCompletion::query()->firstOrCreate(
                    [
                        'course_id' => $course->id,
                        'user_id' => $userId,
                    ],
                    [
                        'timeenrolled' => now()->subDays(60),
                        'timestarted' => null,
                        'timecompleted' => null,
                        'reaggregate' => false,
                    ]
                );
            }
        }
    }

    /**
     * Check if a module-type criterion is met for a user.
     */
    private function checkModuleCriterionMet(CourseCompletionCriterion $criterion, int $userId): bool
    {
        if ($criterion->module_instance_id === null) {
            return false;
        }

        return ModuleCompletion::query()
            ->where('learning_module_id', $criterion->module_instance_id)
            ->where('user_id', $userId)
            ->where('state', 'complete')
            ->exists();
    }

    /**
     * Check if a grade-type criterion is met for a user.
     */
    private function checkGradeCriterionMet(CourseCompletionCriterion $criterion, int $userId): bool
    {
        if ($criterion->grade_item_id === null) {
            return false;
        }

        $grade = Grade::query()
            ->where('grade_item_id', $criterion->grade_item_id)
            ->where('user_id', $userId)
            ->first();

        if ($grade === null || $grade->score === null) {
            return false;
        }

        $threshold = $criterion->pass_threshold ?? 0;

        return (float) $grade->score >= (float) $threshold;
    }
}
