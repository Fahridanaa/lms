<?php

namespace Tests\Unit\Services\Cache;

use App\Contracts\CacheLoaderInterface;
use App\Services\Cache\ReadThroughStrategy;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Unit tests for Read-Through Caching Strategy
 *
 * Validates implementation follows the pattern:
 * - READ: Cache intercepts → if miss, loader fetches from DB → returns data
 * - WRITE: Update DB → invalidate cache (next read will fetch fresh data)
 * - NO CALLBACK FALLBACK: Throws exception if no loader registered
 */
class ReadThroughStrategyTest extends TestCase
{
    protected ReadThroughStrategy $strategy;
    protected $mockLoader;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockLoader = \Mockery::mock(CacheLoaderInterface::class);
        $this->mockLoader->shouldReceive('supports')->andReturn(true)->byDefault();

        $this->strategy = new ReadThroughStrategy([$this->mockLoader]);
    }

    /**
     * Test: get() throws exception when no loader is registered
     */
    public function test_get_throws_exception_when_no_loader_registered(): void
    {
        $strategy = new ReadThroughStrategy([]); // No loaders

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No loader registered for cache key: test:key');

        $strategy->get('test:key');
    }

    /**
     * Test: get() uses loader on cache miss
     */
    public function test_get_uses_loader_on_cache_miss(): void
    {
        $key = 'test:key';
        $value = ['id' => 1, 'name' => 'Test'];

        $this->mockLoader->shouldReceive('load')
            ->once()
            ->with($key)
            ->andReturn($value);

        Cache::shouldReceive('remember')
            ->once()
            ->with('lms:test:key', 3600, \Mockery::type('callable'))
            ->andReturnUsing(fn($k, $t, $cb) => $cb());

        $result = $this->strategy->get($key);

        $this->assertEquals($value, $result);
    }

    /**
     * Test: get() returns cached value (loader not called)
     */
    public function test_get_returns_cached_value_without_calling_loader(): void
    {
        $key = 'test:key';
        $cachedValue = ['cached' => 'data'];

        $this->mockLoader->shouldNotReceive('load');

        Cache::shouldReceive('remember')
            ->once()
            ->andReturn($cachedValue);

        $result = $this->strategy->get($key);

        $this->assertEquals($cachedValue, $result);
    }

    /**
     * Test: put() INVALIDATES cache instead of updating
     */
    public function test_put_invalidates_cache_instead_of_updating(): void
    {
        $key = 'test:key';
        $value = ['new' => 'value'];

        Cache::shouldReceive('forget')
            ->once()
            ->with('lms:test:key')
            ->andReturn(true);

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

        Cache::shouldReceive('forget')->once()->andReturn(true);

        $this->strategy->put($key, $value, $persist);

        $this->assertTrue($persistCalled);
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

        $this->mockLoader->shouldReceive('load')->andReturn($value);

        Cache::shouldReceive('remember')->once()->andReturn($value);

        $result = $this->strategy->remember($key);

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

        $this->mockLoader->shouldReceive('load')->andReturn($value);

        $taggedCache = \Mockery::mock();
        $taggedCache->shouldReceive('remember')
            ->once()
            ->with('lms:test:key', 3600, \Mockery::type('callable'))
            ->andReturn($value);

        Cache::shouldReceive('tags')
            ->once()
            ->with($tags)
            ->andReturn($taggedCache);

        $result = $this->strategy->tags($tags)->get($key);

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
        $taggedCache->shouldReceive('flush')->once()->andReturn();

        Cache::shouldReceive('tags')->once()->with($tags)->andReturn($taggedCache);

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
        $taggedCache->shouldReceive('flush')->once()->andThrow(new \Exception('Cache error'));

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

        $loader = \Mockery::mock(CacheLoaderInterface::class);
        $loader->shouldReceive('supports')->andReturn(true);
        $loader->shouldReceive('load')->andReturn(['data']);

        $strategy = new ReadThroughStrategy([$loader]);

        Cache::shouldReceive('remember')
            ->once()
            ->with('custom:test', 3600, \Mockery::type('callable'))
            ->andReturnUsing(fn($k, $t, $cb) => $cb());

        $result = $strategy->get('test');

        $this->assertEquals(['data'], $result);
    }

    /**
     * Test: TTL configuration is respected
     */
    public function test_uses_configured_ttl(): void
    {
        config(['caching-strategy.ttl' => 7200]);

        $loader = \Mockery::mock(CacheLoaderInterface::class);
        $loader->shouldReceive('supports')->andReturn(true);
        $loader->shouldReceive('load')->andReturn(['data']);

        $strategy = new ReadThroughStrategy([$loader]);

        Cache::shouldReceive('remember')
            ->once()
            ->with('lms:test', 7200, \Mockery::type('callable'))
            ->andReturnUsing(fn($k, $t, $cb) => $cb());

        $strategy->get('test');
    }

    /**
     * Test: Multiple loaders - correct one is selected based on supports()
     */
    public function test_multiple_loaders_correct_one_selected(): void
    {
        $quizKey = 'quiz:1';
        $userKey = 'user:2';

        $quizLoader = \Mockery::mock(CacheLoaderInterface::class);
        $quizLoader->shouldReceive('supports')->with($quizKey)->andReturn(true);
        $quizLoader->shouldReceive('supports')->with($userKey)->andReturn(false);
        $quizLoader->shouldReceive('load')->with($quizKey)->andReturn(['type' => 'quiz']);

        $userLoader = \Mockery::mock(CacheLoaderInterface::class);
        $userLoader->shouldReceive('supports')->with($userKey)->andReturn(true);
        $userLoader->shouldReceive('load')->with($userKey)->andReturn(['type' => 'user']);

        Cache::shouldReceive('remember')
            ->twice()
            ->andReturnUsing(fn($k, $t, $cb) => $cb());

        $strategy = new ReadThroughStrategy([$quizLoader, $userLoader]);

        $quizResult = $strategy->get($quizKey);
        $userResult = $strategy->get($userKey);

        $this->assertEquals(['type' => 'quiz'], $quizResult);
        $this->assertEquals(['type' => 'user'], $userResult);
    }

    /**
     * Test: addLoader() registers additional loader
     */
    public function test_add_loader_registers_additional_loader(): void
    {
        $key = 'new:123';
        $value = ['new' => 'data'];

        $newLoader = \Mockery::mock(CacheLoaderInterface::class);
        $newLoader->shouldReceive('supports')->with($key)->andReturn(true);
        $newLoader->shouldReceive('load')->with($key)->andReturn($value);

        $this->mockLoader->shouldReceive('supports')->with($key)->andReturn(false);

        Cache::shouldReceive('remember')
            ->once()
            ->andReturnUsing(fn($k, $t, $cb) => $cb());

        $this->strategy->addLoader($newLoader);
        $result = $this->strategy->get($key);

        $this->assertEquals($value, $result);
    }

    /**
     * Test: Callback is IGNORED (not used as fallback)
     */
    public function test_callback_is_ignored_when_loader_exists(): void
    {
        $key = 'test:key';
        $loaderValue = ['from' => 'loader'];

        $this->mockLoader->shouldReceive('load')->with($key)->andReturn($loaderValue);

        Cache::shouldReceive('remember')
            ->once()
            ->andReturnUsing(fn($k, $t, $cb) => $cb());

        $callbackExecuted = false;
        $callback = function () use (&$callbackExecuted) {
            $callbackExecuted = true;
            return ['from' => 'callback'];
        };

        // Callback is passed but should be ignored
        $result = $this->strategy->get($key, $callback);

        $this->assertEquals($loaderValue, $result);
        $this->assertFalse($callbackExecuted);
    }
}
