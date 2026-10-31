<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

class LoginAccountLockoutTest extends TestCase
{
    use DatabaseTransactions;

    public function test_failures_from_rotating_ips_still_lock_the_account(): void
    {
        $admin = User::whereHas('role', fn ($q) => $q->where('slug', 'admin'))->first() ?? $this->markTestSkipped('no admin');
        $email = 'lock' . Str::random(6) . '@example.test';
        $user = User::forceCreate([
            'name' => 'Lock User', 'email' => $email, 'password' => Hash::make('correct-horse-42'),
            'role_id' => $admin->role_id, 'branch_id' => $admin->branch_id, 'status' => 'Active',
        ]);
        RateLimiter::clear('login-account|' . strtolower($email));

        for ($i = 1; $i <= 10; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => "10.0.0.{$i}"])
                ->postJson('/api/login', ['email' => $email, 'password' => 'wrong-password'])
                ->assertStatus(422);
        }

        // 11th attempt from a brand-new IP, with the CORRECT password, is still refused.
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.1.1'])
            ->postJson('/api/login', ['email' => $email, 'password' => 'correct-horse-42'])
            ->assertStatus(429);

        RateLimiter::clear('login-account|' . strtolower($email));
        $this->assertNotNull($user->id);
    }
}
