<?php

namespace Tests\Unit\Services;

use App\Models\Context;
use App\Services\ContextService;
use Tests\TestCase;

class ContextServiceTest extends TestCase
{
    private ContextService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ContextService;
    }

    public function test_resolve_or_create_creates_system_context(): void
    {
        $context = $this->service->resolveOrCreate(Context::LEVEL_SYSTEM, 0);

        $this->assertSame(Context::LEVEL_SYSTEM, $context->contextlevel);
        $this->assertSame(0, (int) $context->instance_id);
        $this->assertSame('/1', $context->path);
        $this->assertSame(0, $context->depth);
        $this->assertTrue($context->exists);
    }

    public function test_resolve_or_create_returns_existing_context(): void
    {
        $first = $this->service->resolveOrCreate(Context::LEVEL_COURSE, 1, 1);
        $second = $this->service->resolveOrCreate(Context::LEVEL_COURSE, 1, 1);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Context::where('contextlevel', Context::LEVEL_COURSE)
            ->where('instance_id', 1)->count());
    }

    public function test_find_returns_null_for_nonexistent_context(): void
    {
        $this->assertNull($this->service->find(Context::LEVEL_COURSE, 99999));
    }

    public function test_build_path_for_system(): void
    {
        $path = $this->service->buildPath(Context::LEVEL_SYSTEM, 0);

        $this->assertSame('/1', $path);
    }

    public function test_build_path_for_course_with_parent(): void
    {
        $systemContext = $this->service->resolveOrCreate(Context::LEVEL_SYSTEM, 0);

        $path = $this->service->buildPath(Context::LEVEL_COURSE, 5, $systemContext->id);

        $this->assertSame('/1/5', $path);
    }

    public function test_build_path_for_module_with_course_context_parent(): void
    {
        $systemContext = $this->service->resolveOrCreate(Context::LEVEL_SYSTEM, 0);
        $courseContext = $this->service->resolveOrCreate(Context::LEVEL_COURSE, 3, $systemContext->id);

        $path = $this->service->buildPath(Context::LEVEL_MODULE, 42, $courseContext->id);

        $this->assertSame('/1/3/42', $path);
    }

    public function test_calculate_depth(): void
    {
        $this->assertSame(0, $this->service->calculateDepth('/1'));
        $this->assertSame(1, $this->service->calculateDepth('/1/5'));
        $this->assertSame(2, $this->service->calculateDepth('/1/5/23'));
    }

    public function test_ancestors_returns_correct_chain(): void
    {
        $system = $this->service->resolveOrCreate(Context::LEVEL_SYSTEM, 0);
        $course = $this->service->resolveOrCreate(Context::LEVEL_COURSE, 10, $system->id);
        $module = $this->service->resolveOrCreate(Context::LEVEL_MODULE, 100, $course->id);

        $module->refresh();
        $ancestors = $this->service->ancestors($module);

        $this->assertCount(2, $ancestors);
        // First ancestor should be root/system
        $this->assertSame(Context::LEVEL_SYSTEM, $ancestors[0]->contextlevel);
        $this->assertSame(0, (int) $ancestors[0]->instance_id);
        // Second ancestor should be course
        $this->assertSame(Context::LEVEL_COURSE, $ancestors[1]->contextlevel);
        $this->assertSame(10, (int) $ancestors[1]->instance_id);
    }

    public function test_ancestors_returns_empty_for_root_context(): void
    {
        $system = $this->service->resolveOrCreate(Context::LEVEL_SYSTEM, 0);

        $this->assertCount(0, $this->service->ancestors($system));
    }
}
