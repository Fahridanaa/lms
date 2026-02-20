<?php

namespace Tests\Unit\Services\Cache;

use App\Contracts\CacheStoreInterface;
use App\Services\Cache\WriteThroughStrategy;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Unit tests for Write-Through Caching Strategy
 *
 * Validates implementation follows the pattern:
 * - READ: Check cache → if miss, store.load() fetches from DB → store in cache
 * - WRITE: store.store() to database AND cache simultaneously
 * - NO CALLBACK FALLBACK: Throws exception if no store registered
 */
class WriteThroughStrategyTest extends TestCase
{
    protected WriteThroughStrategy $strategy;
    protected $mockStore;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockStore = \Mockery::mock(CacheStoreInterface::class);
        $this->mockStore->shouldReceive('supports')->andReturn(true)->byDefault();

        $this->strategy = new WriteThroughStrategy([$this->mockStore]);
    }

    /**
     * Test: get() throws exception when no store is registered
     */
    public function test_get_throws_exception_when_no_store_registered(): void
    {
        $strategy = new WriteThroughStrategy([]); // No stores

        Cache::shouldReceive('get')->andReturn(null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No store registered for cache key: test:key');

        $strategy->get('test:key');
    }

    /**
     * Test: put() throws exception when no store is registered
     */
    public function test_put_throws_exception_when_no_store_registered(): void
    {
        $strategy = new WriteThroughStrategy([]); // No stores

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No store registered for cache key: test:key');

        $strategy->put('test:key', ['data']);
    }

    /**
     * Test: forget() throws exception when no store is registered
     */
    public function test_forget_throws_exception_when_no_store_registered(): void
    {
        $strategy = new WriteThroughStrategy([]); // No stores

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No store registered for cache key: test:key');

        $strategy->forget('test:key');
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

        $this->mockStore->shouldNotReceive('load');

        $result = $this->strategy->get($key);

        $this->assertEquals($cachedValue, $result);
    }

    /**
     * Test: get() with cache MISS uses store.load()
     */
    public function test_get_uses_store_load_on_cache_miss(): void
    {
        $key = 'test:key';
        $dbValue = ['id' => 2, 'from' => 'database'];

        Cache::shouldReceive('get')
            ->once()
            ->with('lms:test:key')
            ->andReturn(null);

        $this->mockStore->shouldReceive('load')
            ->once()
            ->with($key)
            ->andReturn($dbValue);

        Cache::shouldReceive('put')
            ->once()
            ->with('lms:test:key', $dbValue, 3600);

        $result = $this->strategy->get($key);

        $this->assertEquals($dbValue, $result);
    }

    /**
     * Test: put() writes to BOTH database (via store) AND cache
     */
    public function test_put_writes_to_database_and_cache_synchronously(): void
    {
        $key = 'test:key';
        $value = ['data' => 'new value'];

        $this->mockStore->shouldReceive('store')
            ->once()
            ->with($key, $value);

        Cache::shouldReceive('put')
            ->once()
            ->with('lms:test:key', $value, 3600);

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

        $this->mockStore->shouldReceive('store')
            ->once()
            ->andThrow(new \Exception('Database error'));

        $result = $this->strategy->put($key, $value);

        $this->assertFalse($result);
    }

    /**
     * Test: forget() calls store.erase() and removes from cache
     */
    public function test_forget_calls_store_erase_and_removes_from_cache(): void
    {
        $key = 'test:key';

        $this->mockStore->shouldReceive('erase')
            ->once()
            ->with($key);

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

        Cache::shouldReceive('get')
            ->once()
            ->with('lms:test:key')
            ->andReturn($value);

        $result = $this->strategy->remember($key);

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

        $result = $this->strategy->tags($tags)->get($key);

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

        $this->mockStore->shouldReceive('store')->once();

        $taggedCache = \Mockery::mock();
        $taggedCache->shouldReceive('put')
            ->once()
            ->with('lms:test:key', $value, 3600);

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
        $taggedCache->shouldReceive('flush')->once()->andReturn();

        Cache::shouldReceive('tags')->once()->with($tags)->andReturn($taggedCache);

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
        $taggedCache->shouldReceive('flush')->once()->andThrow(new \Exception('Flush error'));

        Cache::shouldReceive('tags')->once()->with($tags)->andReturn($taggedCache);

        $result = $this->strategy->flushTags($tags);

        $this->assertFalse($result);
    }

    /**
     * Test: Key prefixing works correctly
     */
    public function test_uses_configured_prefix(): void
    {
        config(['caching-strategy.prefix' => 'custom']);

        $store = \Mockery::mock(CacheStoreInterface::class);
        $store->shouldReceive('supports')->andReturn(true);

        $strategy = new WriteThroughStrategy([$store]);

        Cache::shouldReceive('get')
            ->once()
            ->with('custom:test')
            ->andReturn(['data']);

        $result = $strategy->get('test');

        $this->assertEquals(['data'], $result);
    }

    /**
     * Test: TTL configuration is respected
     */
    public function test_uses_configured_ttl(): void
    {
        config(['caching-strategy.ttl' => 7200]);

        $store = \Mockery::mock(CacheStoreInterface::class);
        $store->shouldReceive('supports')->andReturn(true);
        $store->shouldReceive('load')->andReturn(['data']);

        $strategy = new WriteThroughStrategy([$store]);

        Cache::shouldReceive('get')->once()->andReturn(null);
        Cache::shouldReceive('put')
            ->once()
            ->with('lms:test', ['data'], 7200);

        $strategy->get('test');
    }

    /**
     * Test: Write-Through guarantees cache-database consistency
     */
    public function test_write_through_ensures_cache_db_consistency(): void
    {
        $key = 'user:1';
        $newData = ['id' => 1, 'name' => 'Updated'];
        $operations = [];

        $this->mockStore->shouldReceive('store')
            ->once()
            ->andReturnUsing(function () use (&$operations) {
                $operations[] = 'db_write';
            });

        Cache::shouldReceive('put')
            ->once()
            ->andReturnUsing(function () use (&$operations) {
                $operations[] = 'cache_write';
                return true;
            });

        $result = $this->strategy->put($key, $newData);

        $this->assertTrue($result);
        $this->assertEquals(['db_write', 'cache_write'], $operations);
    }

    /**
     * Test: Multiple stores - correct one is selected based on supports()
     */
    public function test_multiple_stores_correct_one_selected(): void
    {
        $quizKey = 'quiz:1';
        $userKey = 'user:2';

        $quizStore = \Mockery::mock(CacheStoreInterface::class);
        $quizStore->shouldReceive('supports')->with($quizKey)->andReturn(true);
        $quizStore->shouldReceive('supports')->with($userKey)->andReturn(false);
        $quizStore->shouldReceive('store')->with($quizKey, ['type' => 'quiz']);

        $userStore = \Mockery::mock(CacheStoreInterface::class);
        $userStore->shouldReceive('supports')->with($userKey)->andReturn(true);
        $userStore->shouldReceive('store')->with($userKey, ['type' => 'user']);

        Cache::shouldReceive('put')->twice();

        $strategy = new WriteThroughStrategy([$quizStore, $userStore]);

        $strategy->put($quizKey, ['type' => 'quiz']);
        $strategy->put($userKey, ['type' => 'user']);
    }

    /**
     * Test: addStore() registers additional store
     */
    public function test_add_store_registers_additional_store(): void
    {
        $key = 'new:123';
        $value = ['new' => 'data'];

        $newStore = \Mockery::mock(CacheStoreInterface::class);
        $newStore->shouldReceive('supports')->with($key)->andReturn(true);
        $newStore->shouldReceive('store')->with($key, $value);

        $this->mockStore->shouldReceive('supports')->with($key)->andReturn(false);

        Cache::shouldReceive('put')->once();

        $this->strategy->addStore($newStore);
        $result = $this->strategy->put($key, $value);

        $this->assertTrue($result);
    }

    /**
     * Test: Callback is IGNORED (not used)
     */
    public function test_callback_is_ignored_when_store_exists(): void
    {
        $key = 'test:key';
        $storeValue = ['from' => 'store'];

        $this->mockStore->shouldReceive('store')->with($key, $storeValue);

        Cache::shouldReceive('put')->once();

        $callbackExecuted = false;
        $callback = function () use (&$callbackExecuted) {
            $callbackExecuted = true;
        };

        // Callback is passed but should be ignored
        $result = $this->strategy->put($key, $storeValue, $callback);

        $this->assertTrue($result);
        $this->assertFalse($callbackExecuted);
    }
}
