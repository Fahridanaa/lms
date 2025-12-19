<?php

namespace Tests\Unit\Services\Cache;

use App\Services\Cache\NoCacheStrategy;
use Tests\TestCase;

/**
 * Unit tests for No-Cache (Baseline) Strategy
 *
 * Validates implementation follows the pattern:
 * - READ: Always execute callback (no cache lookup)
 * - WRITE: Execute persist callback only (no cache operations)
 *
 * Purpose: Provides baseline performance metrics without caching
 */
class NoCacheStrategyTest extends TestCase
{
    protected NoCacheStrategy $strategy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->strategy = new NoCacheStrategy();
    }

    /**
     * Test: get() ALWAYS executes callback (never uses cache)
     */
    public function test_get_always_executes_callback(): void
    {
        $callCount = 0;

        $callback = function () use (&$callCount) {
            $callCount++;
            return ['data' => 'from callback'];
        };

        // Call multiple times
        $result1 = $this->strategy->get('key1', $callback);
        $result2 = $this->strategy->get('key1', $callback); // Same key
        $result3 = $this->strategy->get('key2', $callback); // Different key

        // Callback should execute EVERY time (no caching)
        $this->assertEquals(3, $callCount, 'Callback should execute every time');
        $this->assertEquals(['data' => 'from callback'], $result1);
        $this->assertEquals(['data' => 'from callback'], $result2);
        $this->assertEquals(['data' => 'from callback'], $result3);
    }

    /**
     * Test: get() returns fresh data every call
     */
    public function test_get_returns_fresh_data_on_every_call(): void
    {
        $counter = 0;

        $callback = function () use (&$counter) {
            return ['count' => ++$counter];
        };

        $result1 = $this->strategy->get('test', $callback);
        $result2 = $this->strategy->get('test', $callback);
        $result3 = $this->strategy->get('test', $callback);

        // Each call should return fresh, incremented data
        $this->assertEquals(['count' => 1], $result1);
        $this->assertEquals(['count' => 2], $result2);
        $this->assertEquals(['count' => 3], $result3);
    }

    /**
     * Test: put() executes persist callback if provided
     */
    public function test_put_executes_persist_callback(): void
    {
        $persistCalled = false;
        $persistedValue = null;

        $persist = function ($value) use (&$persistCalled, &$persistedValue) {
            $persistCalled = true;
            $persistedValue = $value;
        };

        $value = ['data' => 'to persist'];
        $result = $this->strategy->put('key', $value, $persist);

        $this->assertTrue($result);
        $this->assertTrue($persistCalled, 'Persist callback should be executed');
        $this->assertEquals($value, $persistedValue);
    }

    /**
     * Test: put() succeeds without persist callback
     */
    public function test_put_succeeds_without_persist_callback(): void
    {
        $result = $this->strategy->put('key', ['data'], null);

        $this->assertTrue($result);
    }

    /**
     * Test: forget() always returns true (no cache to clear)
     */
    public function test_forget_always_returns_true(): void
    {
        $result1 = $this->strategy->forget('key1');
        $result2 = $this->strategy->forget('key2');
        $result3 = $this->strategy->forget('nonexistent');

        $this->assertTrue($result1);
        $this->assertTrue($result2);
        $this->assertTrue($result3);
    }

    /**
     * Test: remember() always executes callback (same as get)
     */
    public function test_remember_always_executes_callback(): void
    {
        $callCount = 0;

        $callback = function () use (&$callCount) {
            return ['call' => ++$callCount];
        };

        $result1 = $this->strategy->remember('key', $callback);
        $result2 = $this->strategy->remember('key', $callback);

        // Should execute every time
        $this->assertEquals(['call' => 1], $result1);
        $this->assertEquals(['call' => 2], $result2);
    }

    /**
     * Test: tags() returns self for chaining (but doesn't use tags)
     */
    public function test_tags_returns_self_for_chaining(): void
    {
        $result = $this->strategy->tags(['users', 'posts']);

        $this->assertInstanceOf(NoCacheStrategy::class, $result);
        $this->assertSame($this->strategy, $result);
    }

    /**
     * Test: tags() does not affect behavior
     */
    public function test_tags_does_not_affect_behavior(): void
    {
        $callCount = 0;

        $callback = function () use (&$callCount) {
            return ['call' => ++$callCount];
        };

        // With tags
        $result1 = $this->strategy->tags(['users'])->get('key', $callback);
        $result2 = $this->strategy->tags(['posts'])->get('key', $callback);

        // Should still execute every time (tags ignored)
        $this->assertEquals(['call' => 1], $result1);
        $this->assertEquals(['call' => 2], $result2);
    }

    /**
     * Test: flushTags() always returns true (no cache to flush)
     */
    public function test_flush_tags_always_returns_true(): void
    {
        $result1 = $this->strategy->flushTags(['users']);
        $result2 = $this->strategy->flushTags(['posts', 'comments']);
        $result3 = $this->strategy->flushTags([]);

        $this->assertTrue($result1);
        $this->assertTrue($result2);
        $this->assertTrue($result3);
    }

    /**
     * Test: No-Cache strategy is truly stateless
     */
    public function test_no_cache_strategy_is_stateless(): void
    {
        $callback1 = fn() => ['value' => 1];
        $callback2 = fn() => ['value' => 2];

        // Call with same key multiple times with different callbacks
        $result1 = $this->strategy->get('same-key', $callback1);
        $result2 = $this->strategy->get('same-key', $callback2);
        $result3 = $this->strategy->get('same-key', $callback1);

        // Each should return different values (no state/cache)
        $this->assertEquals(['value' => 1], $result1);
        $this->assertEquals(['value' => 2], $result2);
        $this->assertEquals(['value' => 1], $result3);
    }

    /**
     * Test: Baseline comparison - verify no caching mechanism
     */
    public function test_baseline_no_caching_mechanism_active(): void
    {
        $executionCount = 0;

        $callback = function () use (&$executionCount) {
            $executionCount++;
            return ['data'];
        };

        // Execute 5 times
        for ($i = 0; $i < 5; $i++) {
            $this->strategy->get('key', $callback);
        }

        // All 5 executions should have occurred (no caching)
        $this->assertEquals(5, $executionCount, 'Callback should execute 5 times');
    }

    /**
     * Test: Performance characteristic - always hits data source
     */
    public function test_always_hits_data_source(): void
    {
        $dataSourceHits = 0;

        $dataSource = function () use (&$dataSourceHits) {
            $dataSourceHits++;
            return ['data' => 'from source'];
        };

        // Make 10 requests to same key
        for ($i = 0; $i < 10; $i++) {
            $this->strategy->get('same-key', $dataSource);
        }

        // Should hit data source all 10 times (100% miss rate)
        $this->assertEquals(10, $dataSourceHits, 'Should hit data source every time');
    }
}
