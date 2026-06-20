<?php

namespace Tests\Unit\Services\Cache;

use App\Repositories\AssignmentRepository;
use App\Repositories\GradeRepository;
use App\Repositories\MaterialRepository;
use App\Services\Cache\Loaders\CourseCacheLoader;
use Tests\TestCase;

class CourseCacheLoaderTest extends TestCase
{
    protected CourseCacheLoader $loader;
    protected $mockMaterialRepo;
    protected $mockAssignmentRepo;
    protected $mockGradeRepo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockMaterialRepo = \Mockery::mock(MaterialRepository::class);
        $this->mockAssignmentRepo = \Mockery::mock(AssignmentRepository::class);
        $this->mockGradeRepo = \Mockery::mock(GradeRepository::class);

        $this->loader = new CourseCacheLoader(
            $this->mockMaterialRepo,
            $this->mockAssignmentRepo,
            $this->mockGradeRepo
        );
    }

    public function test_supports_course_prefix(): void
    {
        $this->assertTrue($this->loader->supports('course:1'));
        $this->assertTrue($this->loader->supports('course:1:materials'));
        $this->assertTrue($this->loader->supports('course:1:assignments'));
        $this->assertTrue($this->loader->supports('course:1:gradebook'));
        $this->assertTrue($this->loader->supports('course:1:statistics'));
        $this->assertTrue($this->loader->supports('course:1:top-performers:10'));
        $this->assertTrue($this->loader->supports('course:1:user:2:grades'));
    }

    public function test_does_not_support_other_prefixes(): void
    {
        $this->assertFalse($this->loader->supports('quiz:1'));
        $this->assertFalse($this->loader->supports('material:1'));
        $this->assertFalse($this->loader->supports('user:1'));
        $this->assertFalse($this->loader->supports('attempt:1'));
    }

    public function test_does_not_claim_course_structure_keys(): void
    {
        $this->assertFalse($this->loader->supports('course:1:structure:2'));
    }

    public function test_load_materials_delegates_to_repository(): void
    {
        $this->mockMaterialRepo->shouldReceive('getByCourse')
            ->once()
            ->with(5)
            ->andReturn(new \Illuminate\Database\Eloquent\Collection(['material1', 'material2']));

        $result = $this->loader->load('course:5:materials');

        $this->assertEquals(new \Illuminate\Database\Eloquent\Collection(['material1', 'material2']), $result);
    }

    public function test_load_assignments_delegates_to_repository(): void
    {
        $this->mockAssignmentRepo->shouldReceive('getByCourse')
            ->once()
            ->with(3)
            ->andReturn(new \Illuminate\Database\Eloquent\Collection(['assignment1']));

        $result = $this->loader->load('course:3:assignments');

        $this->assertEquals(new \Illuminate\Database\Eloquent\Collection(['assignment1']), $result);
    }

    public function test_load_statistics_delegates_to_repository(): void
    {
        $this->mockGradeRepo->shouldReceive('getCourseStatistics')
            ->once()
            ->with(7)
            ->andReturn(['total_grades' => 10, 'average_percentage' => 75]);

        $result = $this->loader->load('course:7:statistics');

        $this->assertEquals(['total_grades' => 10, 'average_percentage' => 75], $result);
    }
}
