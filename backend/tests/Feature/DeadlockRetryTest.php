<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Every DB::transaction() retries a deadlock victim (App\Support\DeadlockRetryingMySqlConnection). */
class DeadlockRetryTest extends TestCase
{
    private function deadlock(): QueryException
    {
        return new QueryException('update x', [], new \PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction'));
    }

    public function test_a_deadlock_is_retried_and_the_second_attempt_commits(): void
    {
        $calls = 0;
        $result = DB::transaction(function () use (&$calls) {
            $calls++;
            if ($calls < 3) {
                throw $this->deadlock();
            }

            return 'ok';
        });

        $this->assertSame('ok', $result);
        $this->assertSame(3, $calls);
    }

    public function test_it_gives_up_after_three_attempts_and_rethrows(): void
    {
        $calls = 0;
        $this->expectException(QueryException::class);
        try {
            DB::transaction(function () use (&$calls) {
                $calls++;
                throw $this->deadlock();
            });
        } finally {
            $this->assertSame(3, $calls);
            $this->assertSame(0, DB::transactionLevel(), 'the failed attempts must all be rolled back');
        }
    }

    public function test_a_non_deadlock_error_is_not_retried(): void
    {
        $calls = 0;
        try {
            DB::transaction(function () use (&$calls) {
                $calls++;
                throw new \RuntimeException('posting refused');
            });
        } catch (\RuntimeException $e) {
        }
        $this->assertSame(1, $calls);
    }
}
