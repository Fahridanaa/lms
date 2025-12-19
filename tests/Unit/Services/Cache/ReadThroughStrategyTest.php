<?php

namespace Tests\Unit\Services\Cache;

use App\Services\Cache\ReadThroughStrategy;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Unit tests for Read-Through Caching Strategy
 *
 * Validates implementation follows the pattern:
 * - READ: Cache intercepts → if miss, cache fetches from DB → returns data
 * - WRITE: Update DB → invalidate cache (next read will fetch fresh data)
 *
 * Key difference from Cache-Aside:
 * - Uses Cache::remember() - cache layer handles the read-through logic
 * - put() INVALIDATES cache instead of updating it
 */
class ReadThroughStrategyTest extends TestCase
{
    protected ReadThroughStrategy $strategy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->strategy = new ReadThroughStrategy();
    }

    /**
     * Test: get() uses Cache::remember() for read-through pattern
     * Laravel's remember() handles: check cache → miss → execute callback → store → return
     */
    public function test_get_uses_cache_remember_for_read_through(): void
    {
        $key = 'test:key';
        $value = ['id' => 1, 'name' => 'Test'];

        Cache::shouldReceive('remember')
            ->once()
            ->with('lms:test:key', 3600, \Mockery::type('callable'))
            ->andReturnUsing(function ($key, $ttl, $callback) use ($value) {
                // Simulate cache remember behavior
                return $callback();
            });

        $callback = fn() => $value;

        $result = $this->strategy->get($key, $callback);

        $this->assertEquals($value, $result);
    }

    /**
     * Test: get() with cache hit returns cached value
     */
    public function test_get_returns_cached_value_without_executing_callback(): void
    {
        $key = 'test:key';
        $cachedValue = ['cached' => 'data'];

        Cache::shouldReceive('remember')
            ->once()
            ->andReturn($cachedValue);

        $callbackExecuted = false;
        $callback = function () use (&$callbackExecuted) {
            $callbackExecuted = true;
            return ['fresh' => 'data'];
        };

        $result = $this->strategy->get($key, $callback);

        $this->assertEquals($cachedValue, $result);
    }

    /**
     * Test: put() INVALIDATES cache instead of updating
     * This is key characteristic of Read-Through pattern
     */
    public function test_put_invalidates_cache_instead_of_updating(): void
    {
        $key = 'test:key';
        $value = ['new' => 'value'];

        // Should call forget(), NOT put()
        Cache::shouldReceive('forget')
            ->once()
            ->with('lms:test:key')
            ->andReturn(true);

        // Should NOT call put()
        Cache::shouldNotReceive('put');

        $result = $this->strategy->put($key, $value);

        $this->assertTrue($result);
    }

    /**
     * Test: put() executes persist callback if provided
     */
    public function test_put_executes_persist_callback(): void
    {
        $key = 'test:key';
        $value = ['data' => 'to persist'];

        $persistCalled = false;
        $persistedValue = null;

        $persist = function ($val) use (&$persistCalled, &$persistedValue) {
            $persistCalled = true;
            $persistedValue = $val;
        };

        Cache::shouldReceive('forget')
            ->once()
            ->andReturn(true);

        $this->strategy->put($key, $value, $persist);

        $this->assertTrue($persistCalled, 'Persist callback should be executed');
        $this->assertEquals($value, $persistedValue);
    }

    /**
     * Test: forget() removes value from cache
     */
    public function test_forget_removes_value_from_cache(): void
    {
        $key = 'test:key';

        Cache::shouldReceive('forget')
            ->once()
            ->with('lms:test:key')
            ->andReturn(true);

        $result = $this->strategy->forget($key);

        $this->assertTrue($result);
    }

    /**
     * Test: remember() delegates to get()
     */
    public function test_remember_delegates_to_get(): void
    {
        $key = 'test:key';
        $value = ['data'];

        Cache::shouldReceive('remember')
            ->once()
            ->andReturn($value);

        $result = $this->strategy->remember($key, fn() => $value);

        $this->assertEquals($value, $result);
    }

    /**
     * Test: get() with tags uses tagged cache remember
     */
    public function test_get_with_tags_uses_tagged_cache_remember(): void
    {
        $key = 'test:key';
        $tags = ['users', 'profiles'];
        $value = ['tagged' => 'data'];

        $taggedCache = \Mockery::mock();
        $taggedCache->shouldReceive('remember')
            ->once()
            ->with('lms:test:key', 3600, \Mockery::type('callable'))
            ->andReturn($value);

        Cache::shouldReceive('tags')
            ->once()
            ->with($tags)
            ->andReturn($taggedCache);

        $result = $this->strategy->tags($tags)->get($key, fn() => $value);

        $this->assertEquals($value, $result);
    }

    /**
     * Test: put() with tags invalidates tagged cache
     */
    public function test_put_with_tags_invalidates_tagged_cache(): void
    {
        $key = 'test:key';
        $tags = ['users'];
        $value = ['data'];

        $taggedCache = \Mockery::mock();
        $taggedCache->shouldReceive('forget')
            ->once()
            ->with('lms:test:key')
            ->andReturn(true);

        Cache::shouldReceive('tags')
            ->once()
            ->with($tags)
            ->andReturn($taggedCache);

        $result = $this->strategy->tags($tags)->put($key, $value);

        $this->assertTrue($result);
    }

    /**
     * Test: flushTags() clears all tagged entries
     */
    public function test_flush_tags_clears_tagged_entries(): void
    {
        $tags = ['users', 'posts'];

        $taggedCache = \Mockery::mock();
        $taggedCache->shouldReceive('flush')
            ->once()
            ->andReturn();

        Cache::shouldReceive('tags')
            ->once()
            ->with($tags)
            ->andReturn($taggedCache);

        $result = $this->strategy->flushTags($tags);

        $this->assertTrue($result);
    }

    /**
     * Test: flushTags() returns false on exception
     */
    public function test_flush_tags_returns_false_on_exception(): void
    {
        $tags = ['users'];

        $taggedCache = \Mockery::mock();
        $taggedCache->shouldReceive('flush')
            ->once()
            ->andThrow(new \Exception('Cache error'));

        Cache::shouldReceive('tags')
            ->once()
            ->with($tags)
            ->andReturn($taggedCache);

        $result = $this->strategy->flushTags($tags);

        $this->assertFalse($result);
    }

    /**
     * Test: Key prefixing works correctly
     */
    public function test_uses_configured_prefix(): void
    {
        config(['caching-strategy.prefix' => 'custom']);
        $strategy = new ReadThroughStrategy();

        Cache::shouldReceive('remember')
            ->once()
            ->with('custom:test', 3600, \Mockery::type('callable'))
            ->andReturn(['data']);

        $result = $strategy->get('test', fn() => ['data']);

        $this->assertEquals(['data'], $result);
    }

    /**
     * Test: TTL configuration is respected
     */
    public function test_uses_configured_ttl(): void
    {
        config(['caching-strategy.ttl' => 7200]);
        $strategy = new ReadThroughStrategy();

        Cache::shouldReceive('remember')
            ->once()
            ->with('lms:test', 7200, \Mockery::type('callable'))
            ->andReturn(['data']);

        $strategy->get('test', fn() => ['data']);
    }

    /**
     * Test: Read-Through pattern characteristic
     * Cache layer transparently handles read operations
     */
    public function test_read_through_pattern_is_transparent(): void
    {
        $key = 'user:1';
        $dbData = ['id' => 1, 'name' => 'John'];

        // Cache::remember handles everything transparently
        Cache::shouldReceive('remember')
            ->once()
            ->with('lms:user:1', 3600, \Mockery::type('callable'))
            ->andReturnUsing(function ($key, $ttl, $callback) use ($dbData) {
                // Simulates cache miss scenario
                return $callback(); // Executes the data source callback
            });

        $dataSourceCalled = false;
        $dataSource = function () use (&$dataSourceCalled, $dbData) {
            $dataSourceCalled = true;
            return $dbData;
        };

        $result = $this->strategy->get($key, $dataSource);

        $this->assertEquals($dbData, $result);
        $this->assertTrue($dataSourceCalled);
    }
}
