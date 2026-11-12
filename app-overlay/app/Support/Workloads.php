<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * Workloads that hit MySQL and Redis. Each one takes ~300ms on the server side,
 * so sequential vs. parallel execution is clearly visible.
 * Static methods only: closures sent to task workers must stay serializable.
 */
class Workloads
{
    public const WAIT_SECONDS = 0.3;

    public static function db(int $i): array
    {
        $t = microtime(true);

        DB::select('SELECT SLEEP(?) AS slept', [self::WAIT_SECONDS]);
        DB::table('items')->insert([
            'name' => "item-{$i}",
            'value' => random_int(1, 1000),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $count = DB::table('items')->count();

        return ['type' => 'db', 'i' => $i, 'rows' => $count, 'pid' => getmypid(), 'ms' => self::ms($t)];
    }

    public static function redis(int $i): array
    {
        $t = microtime(true);

        $counter = Redis::incr('demo:counter');
        Redis::set("demo:item:{$i}", json_encode(['at' => microtime(true)]), 'EX', 300);
        // blocks on the Redis side for WAIT_SECONDS (list is always empty)
        Redis::command('blpop', ['demo:never', self::WAIT_SECONDS]);

        return ['type' => 'redis', 'i' => $i, 'counter' => $counter, 'pid' => getmypid(), 'ms' => self::ms($t)];
    }

    private static function ms(float $since): float
    {
        return round((microtime(true) - $since) * 1000, 1);
    }
}
