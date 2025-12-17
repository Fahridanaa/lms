<?php

namespace App\Providers;

use App\Repositories\AssignmentRepository;
use App\Repositories\GradeRepository;
use App\Repositories\MaterialRepository;
use App\Repositories\QuizAttemptRepository;
use App\Repositories\QuizRepository;
use App\Repositories\SubmissionRepository;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(QuizRepository::class, function ($app) {
            return new QuizRepository($app->make(\App\Models\Quiz::class));
        });
        $this->app->singleton(QuizAttemptRepository::class, function ($app) {
            return new QuizAttemptRepository($app->make(\App\Models\QuizAttempt::class));
        });
        $this->app->singleton(GradeRepository::class, function ($app) {
            return new GradeRepository($app->make(\App\Models\Grade::class));
        });
        $this->app->singleton(AssignmentRepository::class, function ($app) {
            return new AssignmentRepository($app->make(\App\Models\Assignment::class));
        });
        $this->app->singleton(SubmissionRepository::class, function ($app) {
            return new SubmissionRepository($app->make(\App\Models\Submission::class));
        });
        $this->app->singleton(MaterialRepository::class, function ($app) {
            return new MaterialRepository($app->make(\App\Models\Material::class));
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
