<?php

namespace Tests\Unit\Services\Cache;

use App\Models\Course;
use App\Models\CourseCompletionCriterion;
use App\Models\CourseCompletionCriterionCompletion;
use App\Models\User;
use App\Services\CourseCompletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CourseCompletionCacheTest extends TestCase
{
    use RefreshDatabase;

    private CourseCompletionService $service;

    private Course $course;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new CourseCompletionService;

        $this->user = User::factory()->create(['role' => 'student']);
        $this->course = Course::factory()->create([
            'instructor_id' => User::factory()->create(['role' => 'instructor'])->id,
            'is_active' => true,
        ]);

        // Create a criterion
        CourseCompletionCriterion::query()->create([
            'course_id' => $this->course->id,
            'criteriatype' => 'module',
            'module_instance_id' => null,
        ]);
    }

    public function test_progress_is_cached_after_first_read(): void
    {
        // First call
        $this->service->getUserProgress($this->course->id, $this->user->id);

        // Enable query log for second call
        DB::enableQueryLog();
        $progress = $this->service->getUserProgress($this->course->id, $this->user->id);
        DB::disableQueryLog();

        $queries = DB::getQueryLog();
        $completionQueries = array_filter($queries, function ($q) {
            return str_contains($q['query'], 'course_completion_criteria')
                || str_contains($q['query'], 'course_completion_criterion_completions')
                || str_contains($q['query'], 'course_completions');
        });

        $this->assertCount(0, $completionQueries,
            'Second progress read should not query completion tables (cached)'
        );
        $this->assertSame(1, $progress['criteria_total']);
    }

    public function test_completion_invalidates_progress_cache(): void
    {
        // Warm cache
        $this->service->getUserProgress($this->course->id, $this->user->id);

        // Mark criterion completion — should invalidate cache
        $criterion = CourseCompletionCriterion::first();
        CourseCompletionCriterionCompletion::query()->create([
            'course_completion_criterion_id' => $criterion->id,
            'user_id' => $this->user->id,
            'completed' => true,
            'completed_at' => now(),
        ]);

        // Mark course complete directly (simulates cascade)
        $this->service->markCourseComplete($this->course->id, $this->user->id);

        // Read should now reflect the change (fresh from DB, cache invalidated)
        $progress = $this->service->getUserProgress($this->course->id, $this->user->id);

        $this->assertSame(1, $progress['criteria_met']);
    }
}
