<?php

namespace App\Jobs;

use App\Services\CourseCompletionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Evaluate all course completion criteria for a user in a course.
 *
 * Deferred to the queue so the synchronous write path (grade update,
 * quiz submission, module completion) returns quickly without waiting
 * for the full completion evaluation to run.
 */
class EvaluateCourseCompletion implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        protected int $courseId,
        protected int $userId,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(CourseCompletionService $completionService): void
    {
        $completionService->evaluateAll($this->courseId, $this->userId);
    }
}
