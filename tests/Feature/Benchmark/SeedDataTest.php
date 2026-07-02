<?php

namespace Tests\Feature\Benchmark;

use App\Models\Assignment;
use App\Models\Course;
use App\Models\CourseGroup;
use App\Models\CourseGroupMember;
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
use App\Models\QuizQuestionSlot;
use App\Models\Submission;
use App\Models\User;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Benchmark\Concerns\BenchmarkSeedSetup;
use Tests\TestCase;

class SeedDataTest extends TestCase
{
    use BenchmarkSeedSetup;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBenchmarkSeed(migrateFresh: true);
    }

    #[Test]
    public function seed_creates_users_courses_and_enrollments(): void
    {
        $this->assertEquals(40, User::where('role', 'instructor')->count());
        $this->assertEquals(1960, User::where('role', 'student')->count());
        $this->assertSame(50, Course::count());
    }

    #[Test]
    public function seed_creates_groups_and_modules(): void
    {
        $this->assertGreaterThan(0, CourseGroup::count());
        $this->assertGreaterThan(0, CourseGroupMember::count());

        LearningModule::query()->cursor()->each(function ($module) {
            $this->assertNotNull($module->course);
        });

        LearningModule::query()->cursor()->each(function ($module) {
            $activity = match ($module->module_type) {
                'material' => Material::find($module->module_id),
                'quiz' => Quiz::find($module->module_id),
                'assignment' => Assignment::find($module->module_id),
                default => null,
            };
            $this->assertNotNull($activity);
        });
    }

    #[Test]
    public function seed_module_type_coverage_is_complete(): void
    {
        $types = LearningModule::pluck('module_type')->unique()->values()->toArray();
        $this->assertContains('material', $types);
        $this->assertContains('quiz', $types);
        $this->assertContains('assignment', $types);

        $this->assertGreaterThan(0, LearningModule::where('visible', true)->count());
        $this->assertGreaterThan(0, LearningModule::where('visible', false)->count());

        $this->assertGreaterThan(0, LearningModule::where('completion_enabled', true)->count());
        $this->assertGreaterThan(0, LearningModule::where('completion_enabled', false)->count());

        Quiz::query()->cursor()->each(function ($quiz) {
            $this->assertGreaterThan(0, Question::where('quiz_id', $quiz->id)->count());
        });

        $supportedRules = ['view', 'submit', 'pass_grade', 'finish'];
        LearningModule::where('completion_enabled', true)->each(function ($module) use ($supportedRules) {
            $this->assertNotNull($module->completion_rule);
            $this->assertContains($module->completion_rule, $supportedRules);
        });
    }

    #[Test]
    public function seed_availability_and_completion_are_valid(): void
    {
        $this->assertGreaterThan(0, ModuleAvailabilityRule::count(), 'No availability rules found — empty seed');
        $this->assertGreaterThan(0, ModuleCompletion::count(), 'No module completions found — empty seed');

        ModuleAvailabilityRule::query()->cursor()->each(function ($rule) {
            $this->assertNotNull($rule->learningModule);
        });

        ModuleCompletion::query()->cursor()->each(function ($c) {
            $this->assertNotNull($c->learningModule);
            $this->assertNotNull($c->user);
        });

        ModuleAvailabilityRule::where('rule_type', 'min_grade')->each(function ($rule) {
            $this->assertNotNull($rule->grade_item_id);
            $this->assertNotNull(GradeItem::find($rule->grade_item_id));
        });
    }

    #[Test]
    public function seed_quiz_and_submission_data_are_valid(): void
    {
        $this->assertGreaterThan(0, QuizAttempt::count(), 'No quiz attempts found — empty seed');
        $this->assertGreaterThan(0, Submission::count(), 'No submissions found — empty seed');
        $this->assertGreaterThan(0, Question::count(), 'No questions found — empty seed');
        $this->assertGreaterThan(0, QuizQuestionSlot::count(), 'No quiz question slots found — empty seed');

        QuizAttempt::query()->cursor()->each(function ($attempt) {
            $this->assertNotNull(Quiz::find($attempt->quiz_id));
            $this->assertNotNull(User::find($attempt->user_id));
        });

        Submission::query()->cursor()->each(function ($submission) {
            $this->assertNotNull(Assignment::find($submission->assignment_id));
            $this->assertNotNull(User::find($submission->user_id));
        });

        Question::query()->cursor()->each(function ($question) {
            $this->assertNotNull(Quiz::find($question->quiz_id));
        });
        QuizQuestionSlot::query()->cursor()->each(function ($slot) {
            $this->assertNotNull(Quiz::find($slot->quiz_id));
            $this->assertNotNull(Question::find($slot->question_id));
        });
    }

    #[Test]
    public function seed_grade_items_grades_and_file_records_are_valid(): void
    {
        GradeItem::query()->cursor()->each(function ($item) {
            $this->assertNotNull($item->course);
        });

        Grade::query()->cursor()->each(function ($grade) {
            $this->assertNotNull($grade->user);
            $this->assertNotNull($grade->course);
            $this->assertNotNull($grade->gradeItem);
        });

        FileRecord::query()->cursor()->each(function ($file) {
            $this->assertNotNull($file->owner_id);
        });

        $quizGradeCount = Grade::where('gradeable_type', 'quiz_attempt')->count();
        $assignmentGradeCount = Grade::where('gradeable_type', 'submission')->count();
        $gradedAttempts = QuizAttempt::where('status', 'finished')
            ->whereNotNull('score')->count();
        $this->assertEquals($gradedAttempts, $quizGradeCount);
        $this->assertGreaterThanOrEqual(
            Submission::where('status', 'graded')->count(),
            $assignmentGradeCount
        );
    }
}
