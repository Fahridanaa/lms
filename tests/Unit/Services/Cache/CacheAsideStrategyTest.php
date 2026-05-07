<?php

namespace Tests\Unit\Services\Cache;

use App\Services\Cache\CacheAsideStrategy;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Unit tests for Cache-Aside (Lazy Loading) Strategy
 *
 * Validates implementation follows the pattern:
 * - READ: Check cache → if miss, query DB → store in cache → return
 * - WRITE: Update DB → invalidate cache (or update cache)
 */
class CacheAsideStrategyTest extends TestCase
{
    protected CacheAsideStrategy $strategy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->strategy = new CacheAsideStrategy();
    }

    /**
     * Test: Cache HIT scenario
     * Should return cached value without executing callback
     */
    public function test_get_returns_cached_value_on_cache_hit(): void
    {
        $key = 'test:key';
        $cachedValue = ['id' => 1, 'name' => 'Test'];

        Cache::shouldReceive('get')
            ->once()
            ->with('lms:test:key')
            ->andReturn($cachedValue);

        $callbackExecuted = false;
        $callback = function () use (&$callbackExecuted) {
            $callbackExecuted = true;
            return ['should' => 'not execute'];
        };

        $result = $this->strategy->get($key, $callback);

        $this->assertEquals($cachedValue, $result);
        $this->assertFalse($callbackExecuted, 'Callback should NOT execute on cache hit');
    }

    /**
     * Test: Cache MISS scenario
     * Should execute callback, store result in cache, and return value
     */
    public function test_get_executes_callback_and_stores_on_cache_miss(): void
    {
        $key = 'test:key';
        $dbValue = ['id' => 2, 'name' => 'From DB'];

        // Expect cache miss (returns null)
        Cache::shouldReceive('get')
            ->once()
            ->with('lms:test:key')
            ->andReturn(null);

        // Expect cache put after callback execution
        Cache::shouldReceive('put')
            ->once()
            ->with('lms:test:key', $dbValue, 3600)
            ->andReturn(true);

        $callbackExecuted = false;
        $callback = function () use (&$callbackExecuted, $dbValue) {
            $callbackExecuted = true;
            return $dbValue;
        };

        $result = $this->strategy->get($key, $callback);

        $this->assertEquals($dbValue, $result);
        $this->assertTrue($callbackExecuted, 'Callback SHOULD execute on cache miss');
    }

    /**
     * Test: put() stores value in cache
     */
    public function test_put_stores_value_in_cache(): void
    {
        $key = 'test:key';
        $value = ['data' => 'value'];

        Cache::shouldReceive('put')
            ->once()
            ->with('lms:test:key', $value, 3600)
            ->andReturn(true);

        $result = $this->strategy->put($key, $value);

        $this->assertTrue($result);
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
     * Test: remember() works like get()
     */
    public function test_remember_works_like_get(): void
    {
        $key = 'test:key';
        $value = ['id' => 3];

        Cache::shouldReceive('get')
            ->once()
            ->with('lms:test:key')
            ->andReturn($value);

        $result = $this->strategy->remember($key, fn() => ['fallback']);

        $this->assertEquals($value, $result);
    }

    /**
     * Test: Cache tags support
     */
    public function test_get_with_tags_uses_tagged_cache(): void
    {
        $key = 'test:key';
        $tags = ['users', 'profiles'];
        $value = ['tagged' => 'data'];

        $taggedCache = \Mockery::mock();
        $taggedCache->shouldReceive('get')
            ->once()
            ->with('lms:test:key')
            ->andReturn($value);

        Cache::shouldReceive('tags')
            ->once()
            ->with($tags)
            ->andReturn($taggedCache);

        $result = $this->strategy->tags($tags)->get($key, fn() => ['fallback']);

        $this->assertEquals($value, $result);
    }

    /**
     * Test: put() with tags
     */
    public function test_put_with_tags_uses_tagged_cache(): void
    {
        $key = 'test:key';
        $tags = ['users'];
        $value = ['data'];

        $taggedCache = \Mockery::mock();
        $taggedCache->shouldReceive('put')
            ->once()
            ->with('lms:test:key', $value, 3600)
            ->andReturn(true);

        Cache::shouldReceive('tags')
            ->once()
            ->with($tags)
            ->andReturn($taggedCache);

        $result = $this->strategy->tags($tags)->put($key, $value);

        $this->assertTrue($result);
    }

    /**
     * Test: flushTags() invalidates all tagged entries
     */
    public function test_flush_tags_invalidates_tagged_entries(): void
    {
        $tags = ['users', 'posts'];

        $taggedCache = \Mockery::mock();
        $taggedCache->shouldReceive('flush')
            ->once()
            ->andReturn(true);

        Cache::shouldReceive('tags')
            ->once()
            ->with($tags)
            ->andReturn($taggedCache);

        $result = $this->strategy->flushTags($tags);

        $this->assertTrue($result);
    }

    /**
     * Test: Key prefixing works correctly
     */
    public function test_uses_configured_prefix(): void
    {
        config(['caching-strategy.prefix' => 'custom']);
        $strategy = new CacheAsideStrategy();

        Cache::shouldReceive('get')
            ->once()
            ->with('custom:test')
            ->andReturn(['prefixed']);

        $result = $strategy->get('test', fn() => ['fallback']);

        $this->assertEquals(['prefixed'], $result);
    }

    /**
     * Test: TTL configuration is respected
     */
    public function test_uses_configured_ttl(): void
    {
        config(['caching-strategy.ttl' => 7200]);
        $strategy = new CacheAsideStrategy();

        Cache::shouldReceive('get')
            ->once()
            ->andReturn(null);

        Cache::shouldReceive('put')
            ->once()
            ->with('lms:test', ['data'], 7200)
            ->andReturn(true);

        $strategy->get('test', fn() => ['data']);
    }

    /**
     * Test: Cache-Aside now executes persist callback
     * Application manages DB writes via the persist callback
     */
    public function test_put_executes_persist_callback(): void
    {
        $key = 'test:key';
        $value = ['data'];

        $persistCalled = false;
        $persistedValue = null;
        $persist = function ($val) use (&$persistCalled, &$persistedValue) {
            $persistCalled = true;
            $persistedValue = $val;
        };

        Cache::shouldReceive('put')
            ->once()
            ->with('lms:test:key', $value, 3600)
            ->andReturn(true);

        $this->strategy->put($key, $value, $persist);

        // Cache-Aside now DOES execute persist callback
        $this->assertTrue($persistCalled, 'Cache-Aside should execute persist callback');
        $this->assertEquals($value, $persistedValue);
    }

    /**
     * Test: Tags are reset after get() operation
     * Ensures tags don't leak between different cache operations
     */
    public function test_tags_are_reset_after_get(): void
    {
        $key1 = 'users:1';
        $tags = ['users', 'profiles'];
        $value = ['tagged' => 'data'];

        // First call with tags: expect tagged cache access
        $taggedCache = \Mockery::mock();
        $taggedCache->shouldReceive('get')
            ->once()
            ->with('lms:users:1')
            ->andReturn($value);

        Cache::shouldReceive('tags')
            ->once()
            ->with($tags)
            ->andReturn($taggedCache);

        $this->strategy->tags($tags)->get($key1, fn() => ['fallback']);

        // Second call WITHOUT tags: expect direct cache access (no tags)
        $key2 = 'quiz:1';
        Cache::shouldReceive('get')
            ->once()
            ->with('lms:quiz:1')
            ->andReturn('quiz-data');

        // Should NOT call Cache::tags() because tags were reset
        $result = $this->strategy->get($key2, fn() => ['fallback']);

        $this->assertEquals('quiz-data', $result);
    }

    /**
     * Test: Tags are reset after put() operation
     */
    public function test_tags_are_reset_after_put(): void
    {
        $tags = ['users'];
        $value = ['data'];

        // First call with tags: expect tagged cache put
        $taggedCache = \Mockery::mock();
        $taggedCache->shouldReceive('put')
            ->once()
            ->with('lms:users:1', $value, 3600)
            ->andReturn(true);

        Cache::shouldReceive('tags')
            ->once()
            ->with($tags)
            ->andReturn($taggedCache);

        $this->strategy->tags($tags)->put('users:1', $value);

        // Second call WITHOUT tags: expect direct cache put (no tags)
        Cache::shouldReceive('put')
            ->once()
            ->with('lms:quiz:1', $value, 3600)
            ->andReturn(true);

        // Should NOT call Cache::tags() because tags were reset
        $result = $this->strategy->put('quiz:1', $value);

        $this->assertTrue($result);
    }

    /**
     * Test: Tags are reset after forget() operation
     */
    public function test_tags_are_reset_after_forget(): void
    {
        $tags = ['users'];

        // First call with tags: expect tagged cache forget
        $taggedCache = \Mockery::mock();
        $taggedCache->shouldReceive('forget')
            ->once()
            ->with('lms:users:1')
            ->andReturn(true);

        Cache::shouldReceive('tags')
            ->once()
            ->with($tags)
            ->andReturn($taggedCache);

        $this->strategy->tags($tags)->forget('users:1');

        // Second call WITHOUT tags: expect direct cache forget (no tags)
        Cache::shouldReceive('forget')
            ->once()
            ->with('lms:quiz:1')
            ->andReturn(true);

        // Should NOT call Cache::tags() because tags were reset
        $result = $this->strategy->forget('quiz:1');

        $this->assertTrue($result);
    }

    /**
     * Test: Multiple chained get() calls don't interfere with each other's tags
     *
     * When get() has a cache miss, it calls put() internally BEFORE tags are reset.
     * The put() uses the same tags that were set for the get().
     * After the entire get() operation completes, tags are reset.
     */
    public function test_consecutive_tagged_operations_work_independently(): void
    {
        // First: tagged get (cache miss, then put with same tags)
        $taggedCache1 = \Mockery::mock();
        $taggedCache1->shouldReceive('get')
            ->once()
            ->with('lms:users:1')
            ->andReturn(null);

        Cache::shouldReceive('tags')
            ->once()
            ->with(['users'])
            ->andReturn($taggedCache1);

        // After miss, put() is called from within get(), still using ['users'] tags
        $taggedCache1b = \Mockery::mock();
        $taggedCache1b->shouldReceive('put')
            ->once()
            ->with('lms:users:1', ['new'], 3600)
            ->andReturn(true);

        Cache::shouldReceive('tags')
            ->once()
            ->with(['users'])
            ->andReturn($taggedCache1b);

        $this->strategy->tags(['users'])->get('users:1', fn() => ['new']);

        // Second: tagged get with DIFFERENT tags (cache hit)
        $taggedCache2 = \Mockery::mock();
        $taggedCache2->shouldReceive('get')
            ->once()
            ->with('lms:quiz:1')
            ->andReturn('quiz-data');

        Cache::shouldReceive('tags')
            ->once()
            ->with(['quizzes'])
            ->andReturn($taggedCache2);

        $result = $this->strategy->tags(['quizzes'])->get('quiz:1', fn() => ['fallback']);

        $this->assertEquals('quiz-data', $result);
    }
}
