<?php

namespace Tests\Unit\Services\Cache;

use App\Repositories\GradeRepository;
use App\Repositories\QuizAttemptRepository;
use App\Services\Cache\Loaders\UserCacheLoader;
use Tests\TestCase;

class UserCacheLoaderTest extends TestCase
{
    protected UserCacheLoader $loader;
    protected $mockAttemptRepo;
    protected $mockGradeRepo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockAttemptRepo = \Mockery::mock(QuizAttemptRepository::class);
        $this->mockGradeRepo = \Mockery::mock(GradeRepository::class);

        $this->loader = new UserCacheLoader(
            $this->mockAttemptRepo,
            $this->mockGradeRepo
        );
    }

    public function test_supports_user_prefix(): void
    {
        $this->assertTrue($this->loader->supports('user:1'));
        $this->assertTrue($this->loader->supports('user:1:all-attempts'));
        $this->assertTrue($this->loader->supports('user:1:quiz:2:attempts'));
        $this->assertTrue($this->loader->supports('user:1:grades:all'));
        $this->assertTrue($this->loader->supports('user:1:performance:summary'));
    }

    public function test_does_not_support_other_prefixes(): void
    {
        $this->assertFalse($this->loader->supports('quiz:1'));
        $this->assertFalse($this->loader->supports('course:1'));
        $this->assertFalse($this->loader->supports('assignment:1'));
    }

    public function test_load_all_attempts_delegates_to_repository(): void
    {
        $this->mockAttemptRepo->shouldReceive('getUserAttempts')
            ->once()
            ->with(42)
            ->andReturn(new \Illuminate\Database\Eloquent\Collection(['attempt1', 'attempt2']));

        $result = $this->loader->load('user:42:all-attempts');

        $this->assertEquals(new \Illuminate\Database\Eloquent\Collection(['attempt1', 'attempt2']), $result);
    }

    public function test_load_quiz_attempts_delegates_to_repository(): void
    {
        $this->mockAttemptRepo->shouldReceive('getUserAttempts')
            ->once()
            ->with(42, 5)
            ->andReturn(new \Illuminate\Database\Eloquent\Collection(['attempt1']));

        $result = $this->loader->load('user:42:quiz:5:attempts');

        $this->assertEquals(new \Illuminate\Database\Eloquent\Collection(['attempt1']), $result);
    }

    public function test_load_grades_all_delegates_to_repository(): void
    {
        $this->mockGradeRepo->shouldReceive('getUserGrades')
            ->once()
            ->with(10)
            ->andReturn(new \Illuminate\Database\Eloquent\Collection(['grade1']));

        $result = $this->loader->load('user:10:grades:all');

        $this->assertEquals(new \Illuminate\Database\Eloquent\Collection(['grade1']), $result);
    }
}
