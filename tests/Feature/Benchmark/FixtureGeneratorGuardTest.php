<?php

namespace Tests\Feature\Benchmark;

use App\Models\Assignment;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\LearningModule;
use App\Models\Material;
use App\Models\Question;
use App\Models\Quiz;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Benchmark\Concerns\BenchmarkFixtureSetup;
use Tests\TestCase;

class FixtureGeneratorGuardTest extends TestCase
{
    use RefreshDatabase;
    use BenchmarkFixtureSetup;

    private array $fixtures = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBenchmarkFixtures();
        $this->fixtures = $this->parseFixturePools($this->fixturesContent);
    }

    protected function tearDown(): void
    {
        $this->tearDownBenchmarkFixtures();
        $this->fixtures = [];
        parent::tearDown();
    }

    #[Test]
    public function fixture_generation_succeeds_and_includes_all_pools(): void
    {
        $this->assertNotEmpty($this->fixturesContent, 'Generated fixtures content is empty');

        $expectedPools = [
            'GROUP_RESTRICTED_MODULE_TARGETS',
            'PREREQUISITE_LOCKED_TARGETS',
            'PREREQUISITE_UNLOCK_TARGETS',
            'MIN_GRADE_LOCKED_TARGETS',
            'HIDDEN_MODULE_TARGETS',
            'LOCKED_GRADE_TARGETS',
            'QUIZ_OVERRIDE_TARGETS',
            'ASSIGNMENT_OVERRIDE_TARGETS',
            'SUSPENDED_ACCESS_TARGETS',
            'NON_ENROLLED_ACCESS_TARGETS',
            'QUIZ_DETAIL_ATTEMPT_TARGETS',
            'GROUPING_RESTRICTED_MODULE_TARGETS',
            'COURSE_COMPLETION_CHECK_TARGETS',
        ];

        foreach ($expectedPools as $pool) {
            $this->assertStringContainsString("const {$pool} =", $this->fixturesContent);
        }
    }

    #[Test]
    public function generated_required_pools_are_non_empty(): void
    {
        $requiredPools = [
            'ENROLLED_PAIRS',
            'INSTRUCTOR_COURSE_PAIRS',
            'READABLE_MATERIAL_TARGETS',
            'READABLE_QUIZ_TARGETS',
            'READABLE_ASSIGNMENT_TARGETS',
            'WRITABLE_MATERIAL_DOWNLOAD_TARGETS',
            'WRITABLE_ASSIGNMENT_SUBMISSION_TARGETS',
            'WRITABLE_QUIZ_ATTEMPT_TARGETS',
            'GRADING_TARGETS',
            'GRADE_UPDATE_TARGETS',
            'UNAUTHORIZED_GRADE_UPDATE_TARGETS',
        ];

        foreach ($requiredPools as $pool) {
            $this->assertArrayHasKey($pool, $this->fixtures, "{$pool} not found in generated fixtures");
            $this->assertNotEmpty($this->fixtures[$pool], "{$pool} is empty");
        }
    }

    #[Test]
    public function grade_update_targets_include_max_score(): void
    {
        $targets = $this->fixtures['GRADE_UPDATE_TARGETS'] ?? [];
        $this->assertNotEmpty($targets);

        foreach ($targets as $i => $target) {
            $this->assertArrayHasKey(
                'maxScore',
                $target,
                "GRADE_UPDATE_TARGETS[{$i}] missing maxScore. Keys: ".implode(', ', array_keys($target))
            );
            $this->assertIsNumeric(
                $target['maxScore'],
                "GRADE_UPDATE_TARGETS[{$i}] maxScore is not numeric, got: ".gettype($target['maxScore'])
            );
            $this->assertGreaterThanOrEqual(
                0,
                $target['maxScore'],
                "GRADE_UPDATE_TARGETS[{$i}] maxScore is negative: {$target['maxScore']}"
            );
        }
    }

    #[Test]
    public function writable_quiz_attempt_targets_include_answers(): void
    {
        $targets = $this->fixtures['WRITABLE_QUIZ_ATTEMPT_TARGETS'] ?? [];
        $this->assertNotEmpty($targets);

        $validOptions = ['A', 'B', 'C', 'D'];

        foreach ($targets as $i => $target) {
            $this->assertArrayHasKey(
                'answers',
                $target,
                "WRITABLE_QUIZ_ATTEMPT_TARGETS[{$i}] missing answers. Keys: ".implode(', ', array_keys($target))
            );
            $this->assertNotEmpty(
                $target['answers'],
                "WRITABLE_QUIZ_ATTEMPT_TARGETS[{$i}] answers is empty"
            );
            $this->assertIsArray(
                $target['answers'],
                "WRITABLE_QUIZ_ATTEMPT_TARGETS[{$i}] answers is not an array"
            );

            foreach ($target['answers'] as $questionId => $answerValue) {
                $this->assertIsInt(
                    $questionId,
                    "WRITABLE_QUIZ_ATTEMPT_TARGETS[{$i}] answers key should be int (question ID), got ".gettype($questionId)
                );
                $this->assertContains(
                    $answerValue,
                    $validOptions,
                    "WRITABLE_QUIZ_ATTEMPT_TARGETS[{$i}] answers[{$questionId}] value '{$answerValue}' is not a valid option"
                );
            }
        }
    }

    #[Test]
    public function unauthorized_grade_update_targets_include_max_score_and_expected_status(): void
    {
        $targets = $this->fixtures['UNAUTHORIZED_GRADE_UPDATE_TARGETS'] ?? [];
        $this->assertNotEmpty($targets);

        foreach ($targets as $i => $target) {
            $this->assertArrayHasKey(
                'maxScore',
                $target,
                "UNAUTHORIZED_GRADE_UPDATE_TARGETS[{$i}] missing maxScore"
            );
            $this->assertIsNumeric($target['maxScore']);
            $this->assertArrayHasKey(
                'expectedStatus',
                $target,
                "UNAUTHORIZED_GRADE_UPDATE_TARGETS[{$i}] missing expectedStatus"
            );
            $this->assertEquals(
                403,
                $target['expectedStatus'],
                "UNAUTHORIZED_GRADE_UPDATE_TARGETS[{$i}] expectedStatus should be 403, got {$target['expectedStatus']}"
            );
        }
    }

    #[Test]
    public function writable_quiz_target_answers_fallback_for_invalid_correct_answer(): void
    {
        if (User::count() === 0) {
            $this->seed(\Database\Seeders\DatabaseSeeder::class);
        }

        $instructor = User::factory()->create(['role' => 'instructor']);
        $student = User::factory()->create(['role' => 'student']);

        $course = Course::factory()->create([
            'instructor_id' => $instructor->id,
            'is_active' => true,
        ]);

        CourseEnrollment::create([
            'user_id' => $student->id,
            'course_id' => $course->id,
            'role' => 'student',
            'status' => 'active',
            'started_at' => now(),
        ]);

        // Activity pools required by the fixture generator (need at least 1 of each per course)
        Material::factory()->create(['course_id' => $course->id, 'is_active' => true]);
        Assignment::factory()->create(['course_id' => $course->id, 'is_active' => true]);

        $quiz = Quiz::factory()->create([
            'course_id' => $course->id,
            'is_active' => true,
            'available_from' => null,
            'available_until' => null,
            'max_attempts' => 5,
        ]);

        // Questions with various edge-case correct_answer values.
        // Note: DB column is NOT NULL, so we test empty string, invalid option, and valid option.
        Question::create(['quiz_id' => $quiz->id, 'question_text' => 'Empty answer?', 'options' => ['A' => 'A', 'B' => 'B', 'C' => 'C', 'D' => 'D'], 'correct_answer' => '', 'points' => 1]);
        Question::create(['quiz_id' => $quiz->id, 'question_text' => 'Out-of-range answer?', 'options' => ['A' => 'A', 'B' => 'B', 'C' => 'C', 'D' => 'D'], 'correct_answer' => 'E', 'points' => 1]);
        Question::create(['quiz_id' => $quiz->id, 'question_text' => 'Valid correct answer?', 'options' => ['A' => 'A', 'B' => 'B', 'C' => 'C', 'D' => 'D'], 'correct_answer' => 'B', 'points' => 1]);
        Question::create(['quiz_id' => $quiz->id, 'question_text' => 'Also valid?', 'options' => ['A' => 'A', 'B' => 'B', 'C' => 'C', 'D' => 'D'], 'correct_answer' => 'C', 'points' => 1]);

        // Regenerate fixtures to a temp path
        $outputPath = tempnam(sys_get_temp_dir(), 'k6-fixtures-invalid-');
        $exitCode = Artisan::call('benchmark:generate-k6-fixtures', ['--output' => $outputPath]);

        if ($exitCode !== 0) {
            $error = Artisan::output();
            @unlink($outputPath);
            $this->fail('benchmark:generate-k6-fixtures exited with code '.$exitCode.' and output: '.substr($error, 0, 500));
        }

        $content = file_get_contents($outputPath);
        @unlink($outputPath);

        $targets = $this->parseFixturePools($content)['WRITABLE_QUIZ_ATTEMPT_TARGETS'] ?? [];
        $this->assertNotEmpty($targets, 'WRITABLE_QUIZ_ATTEMPT_TARGETS not found');

        // Find our custom quiz target
        $ourTarget = null;
        foreach ($targets as $target) {
            if ($target['activityId'] === $quiz->id) {
                $ourTarget = $target;
                break;
            }
        }

        $this->assertNotNull($ourTarget, 'Target for custom quiz not found in generated fixtures');
        $this->assertArrayHasKey('answers', $ourTarget);
        $this->assertCount(4, $ourTarget['answers']);

        $validOptions = ['A', 'B', 'C', 'D'];

        foreach ($ourTarget['answers'] as $questionId => $value) {
            $this->assertContains(
                $value,
                $validOptions,
                "Answer value '{$value}' for question {$questionId} is not a valid option"
            );
            $this->assertNotSame('E', $value, "Question {$questionId} answer should not be 'E' (invalid)");
        }
    }
}
