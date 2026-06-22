<?php

namespace Tests\Unit;

use Tests\TestCase;

class RedisClusterConfigTest extends TestCase
{
    public function test_cluster_hosts_accept_comma_separated_env_value(): void
    {
        $this->withEnv([
            'REDIS_CLUSTER_MODE' => 'true',
            'REDIS_CLUSTER_HOSTS' => 'redis-c1, redis-c2, redis-c3',
            'REDIS_CLUSTER_HOST_1' => null,
            'REDIS_CLUSTER_HOST_2' => null,
            'REDIS_CLUSTER_HOST_3' => null,
        ], function (): void {
            $config = require base_path('config/database.php');

            $this->assertSame(
                ['redis-c1', 'redis-c2', 'redis-c3'],
                array_column($config['redis']['clusters']['default'], 'host'),
            );

            $this->assertSame(
                ['redis-c1', 'redis-c2', 'redis-c3'],
                array_column($config['redis']['clusters']['cache'], 'host'),
            );
        });
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function withEnv(array $values, callable $callback): void
    {
        $original = [];

        foreach ($values as $key => $value) {
            $current = getenv($key);
            $original[$key] = $current === false ? null : $current;

            if ($value === null) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);

                continue;
            }

            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }

        try {
            $callback();
        } finally {
            foreach ($original as $key => $value) {
                if ($value === null) {
                    putenv($key);
                    unset($_ENV[$key], $_SERVER[$key]);

                    continue;
                }

                putenv("{$key}={$value}");
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        }
    }
}
