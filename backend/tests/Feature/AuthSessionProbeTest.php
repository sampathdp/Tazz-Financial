<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuthSessionProbeTest extends TestCase
{
    use DatabaseTransactions;

    private function makeUser(): User
    {
        $admin = User::whereHas('role', fn ($q) => $q->where('slug', 'admin'))->first() ?? $this->markTestSkipped('no admin');

        return User::forceCreate([
            'name' => 'Sess User', 'email' => 'sess' . Str::random(6) . '@example.test', 'password' => Hash::make('secret1'),
            'role_id' => $admin->role_id, 'branch_id' => $admin->branch_id, 'status' => 'Active',
        ]);
    }

    public function test_a_deactivated_user_loses_access_immediately(): void
    {
        $user = $this->makeUser();
        $token = $user->createToken('t')->plainTextToken;
        $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/me')->assertStatus(200);

        $admin = User::whereHas('role', fn ($q) => $q->where('slug', 'admin'))->first();
        $this->actingAs($admin, 'sanctum')->putJson("/api/users/{$user->id}", ['status' => 'Inactive'])->assertStatus(200);
        $this->app['auth']->forgetGuards();
        $res = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/me');
        $this->assertContains($res->status(), [401, 403], 'a disabled account keeps working until its token expires (12h)');
    }

    public function test_changing_a_password_revokes_old_tokens(): void
    {
        $user = $this->makeUser();
        $token = $user->createToken('t')->plainTextToken;
        $admin = User::whereHas('role', fn ($q) => $q->where('slug', 'admin'))->first();
        $this->actingAs($admin, 'sanctum')->putJson("/api/users/{$user->id}", ['password' => 'a-long-secret-42'])->assertStatus(200);
        $this->app['auth']->forgetGuards();
        $res = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/me');
        $this->assertSame(401, $res->status(), 'old token still valid after a password reset');
    }
}
