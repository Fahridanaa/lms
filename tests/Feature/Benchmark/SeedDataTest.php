<?php

namespace Tests\Feature\Benchmark;

use App\Models\Assignment;
use App\Models\Course;
use App\Models\CourseEnrollment;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SeedDataTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    #[Test]
    public function seed_creates_users_courses_and_enrollments(): void
    {
        $this->assertEquals(5, User::where('role', 'instructor')->count());
        $this->assertEquals(50, User::where('role', 'student')->count());
        $this->assertCount(10, Course::all());

        $suspended = CourseEnrollment::where('status', 'suspended')->count();
        $expired = CourseEnrollment::whereNotNull('ends_at')
            ->where('ends_at', '<', now())->count();
        $this->assertGreaterThan(0, $suspended);
        $this->assertGreaterThan(0, $expired);
    }

    #[Test]
    public function seed_creates_groups_and_modules(): void
    {
        $this->assertGreaterThan(0, CourseGroup::count());
        $this->assertGreaterThan(0, CourseGroupMember::count());

        LearningModule::all()->each(function ($module) {
            $this->assertNotNull($module->course);
        });

        LearningModule::all()->each(function ($module) {
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

        Quiz::all()->each(function ($quiz) {
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
        ModuleAvailabilityRule::all()->each(function ($rule) {
            $this->assertNotNull($rule->learningModule);
        });

        ModuleCompletion::all()->each(function ($c) {
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
        QuizAttempt::all()->each(function ($attempt) {
            $this->assertNotNull(Quiz::find($attempt->quiz_id));
            $this->assertNotNull(User::find($attempt->user_id));
        });

        Submission::all()->each(function ($submission) {
            $this->assertNotNull(Assignment::find($submission->assignment_id));
            $this->assertNotNull(User::find($submission->user_id));
        });

        Question::all()->each(function ($question) {
            $this->assertNotNull(Quiz::find($question->quiz_id));
        });
        QuizQuestionSlot::all()->each(function ($slot) {
            $this->assertNotNull(Quiz::find($slot->quiz_id));
            $this->assertNotNull(Question::find($slot->question_id));
        });
    }

    #[Test]
    public function seed_grade_items_grades_and_file_records_are_valid(): void
    {
        GradeItem::all()->each(function ($item) {
            $this->assertNotNull($item->course);
        });

        Grade::all()->each(function ($grade) {
            $this->assertNotNull($grade->user);
            $this->assertNotNull($grade->course);
            $this->assertNotNull($grade->gradeItem);
        });

        FileRecord::all()->each(function ($file) {
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
