<?php

namespace App\Providers;

use App\Models\Assignment;
use App\Models\Grade;
use App\Models\Material;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\Submission;
use App\Repositories\AssignmentRepository;
use App\Repositories\GradeRepository;
use App\Repositories\MaterialRepository;
use App\Repositories\QuizAttemptRepository;
use App\Repositories\QuizRepository;
use App\Repositories\SubmissionRepository;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(QuizRepository::class, function ($app) {
            return new QuizRepository($app->make(Quiz::class));
        });
        $this->app->singleton(QuizAttemptRepository::class, function ($app) {
            return new QuizAttemptRepository($app->make(QuizAttempt::class));
        });
        $this->app->singleton(GradeRepository::class, function ($app) {
            return new GradeRepository($app->make(Grade::class));
        });
        $this->app->singleton(AssignmentRepository::class, function ($app) {
            return new AssignmentRepository($app->make(Assignment::class));
        });
        $this->app->singleton(SubmissionRepository::class, function ($app) {
            return new SubmissionRepository($app->make(Submission::class));
        });
        $this->app->singleton(MaterialRepository::class, function ($app) {
            return new MaterialRepository($app->make(Material::class));
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Relation::enforceMorphMap([
            'quiz_attempt' => QuizAttempt::class,
            'submission' => Submission::class,
        ]);
    }
}
