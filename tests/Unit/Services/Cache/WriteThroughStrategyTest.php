<?php

namespace Tests\Unit\Services\Cache;

use App\Services\Cache\WriteThroughStrategy;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Unit tests for Write-Through Caching Strategy
 *
 * Validates implementation follows the pattern:
 * - READ: Check cache → if miss, query DB → store in cache
 * - WRITE: Write to database AND cache simultaneously (synchronous)
 *
 * Key difference from Cache-Aside and Read-Through:
 * - put() writes to BOTH cache AND database at the same time
 * - Cache is always in sync with database after writes
 */
class WriteThroughStrategyTest extends TestCase
{
    protected WriteThroughStrategy $strategy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->strategy = new WriteThroughStrategy();
    }

    /**
     * Test: get() with cache HIT returns cached value
     */
    public function test_get_returns_cached_value_on_cache_hit(): void
    {
        $key = 'test:key';
        $cachedValue = ['id' => 1, 'name' => 'Cached'];

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
     * Test: get() with cache MISS fetches from DB and stores in cache
     */
    public function test_get_executes_callback_and_stores_on_cache_miss(): void
    {
        $key = 'test:key';
        $dbValue = ['id' => 2, 'from' => 'database'];

        // Cache miss
        Cache::shouldReceive('get')
            ->once()
            ->with('lms:test:key')
            ->andReturn(null);

        // Should store fetched value in cache
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
     * Test: put() writes to BOTH database AND cache (Write-Through pattern)
     * This is the key characteristic!
     */
    public function test_put_writes_to_database_and_cache_synchronously(): void
    {
        $key = 'test:key';
        $value = ['data' => 'new value'];

        $persistCalled = false;
        $persistedValue = null;

        $persist = function ($val) use (&$persistCalled, &$persistedValue) {
            $persistCalled = true;
            $persistedValue = $val;
        };

        // Should write to cache after DB write
        Cache::shouldReceive('put')
            ->once()
            ->with('lms:test:key', $value, 3600)
            ->andReturn(true);

        $result = $this->strategy->put($key, $value, $persist);

        $this->assertTrue($result);
        $this->assertTrue($persistCalled, 'Persist callback MUST be executed');
        $this->assertEquals($value, $persistedValue);
    }

    /**
     * Test: put() writes to cache even without persist callback
     */
    public function test_put_writes_to_cache_without_persist_callback(): void
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
     * Test: put() returns false on exception
     */
    public function test_put_returns_false_on_exception(): void
    {
        $key = 'test:key';
        $value = ['data'];

        $persist = function () {
            throw new \Exception('Database error');
        };

        $result = $this->strategy->put($key, $value, $persist);

        // Should return false when persist callback throws exception
        $this->assertFalse($result);
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
    public function test_remember_delegates_to_get(): void
    {
        $key = 'test:key';
        $value = ['data'];

        Cache::shouldReceive('get')
            ->once()
            ->with('lms:test:key')
            ->andReturn($value);

        $result = $this->strategy->remember($key, fn() => ['fallback']);

        $this->assertEquals($value, $result);
    }

    /**
     * Test: get() with tags uses tagged cache
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
     * Test: put() with tags writes to tagged cache
     */
    public function test_put_with_tags_writes_to_tagged_cache(): void
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
     * Test: flushTags() handles exceptions gracefully
     */
    public function test_flush_tags_returns_false_on_exception(): void
    {
        $tags = ['users'];

        $taggedCache = \Mockery::mock();
        $taggedCache->shouldReceive('flush')
            ->once()
            ->andThrow(new \Exception('Flush error'));

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
        $strategy = new WriteThroughStrategy();

        Cache::shouldReceive('get')
            ->once()
            ->with('custom:test')
            ->andReturn(['data']);

        $result = $strategy->get('test', fn() => ['fallback']);

        $this->assertEquals(['data'], $result);
    }

    /**
     * Test: TTL configuration is respected
     */
    public function test_uses_configured_ttl(): void
    {
        config(['caching-strategy.ttl' => 7200]);
        $strategy = new WriteThroughStrategy();

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
     * Test: Write-Through guarantees cache-database consistency
     * Both operations happen together
     */
    public function test_write_through_ensures_cache_db_consistency(): void
    {
        $key = 'user:1';
        $newData = ['id' => 1, 'name' => 'Updated'];

        $dbWritten = false;
        $cacheWritten = false;

        $persist = function ($data) use (&$dbWritten, $newData) {
            $dbWritten = true;
            $this->assertEquals($newData, $data);
        };

        Cache::shouldReceive('put')
            ->once()
            ->andReturnUsing(function () use (&$cacheWritten) {
                $cacheWritten = true;
                return true;
            });

        $result = $this->strategy->put($key, $newData, $persist);

        $this->assertTrue($result);
        $this->assertTrue($dbWritten, 'Database write must occur');
        $this->assertTrue($cacheWritten, 'Cache write must occur');
    }

    /**
     * Test: Write order - DB first, then cache
     */
    public function test_write_through_writes_database_before_cache(): void
    {
        $key = 'test:key';
        $value = ['data'];
        $operations = [];

        $persist = function () use (&$operations) {
            $operations[] = 'db_write';
        };

        Cache::shouldReceive('put')
            ->once()
            ->andReturnUsing(function () use (&$operations) {
                $operations[] = 'cache_write';
                return true;
            });

        $this->strategy->put($key, $value, $persist);

        // Verify DB write happened before cache write
        $this->assertEquals(['db_write', 'cache_write'], $operations);
    }
}
