<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Money-posting routes demand an Idempotency-Key when REQUIRE_IDEMPOTENCY_KEY is on (security audit Phase 5.2). */
class IdempotencyRequiredTest extends TestCase
{
    use DatabaseTransactions;

    private const ROUTES = ['/api/fixed-deposits', '/api/fd-loans', '/api/savings-interest-runs', '/api/branch-funding', '/api/pawnings'];

    private function admin(): void
    {
        $roleId = DB::table('roles')->where('slug', 'admin')->where('is_system', 1)->value('id') ?? DB::table('roles')->insertGetId([
            'name' => 'Administrator', 'slug' => 'admin', 'is_system' => 1, 'can_view_all_branches' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs(User::forceCreate([
            'name' => 'Idem', 'email' => 'idem' . Str::random(6) . '@example.test', 'password' => Hash::make('x'),
            'role_id' => $roleId, 'branch_id' => null, 'status' => 'Active',
        ])->fresh(), 'sanctum');
    }

    public function test_write_without_key_is_refused_when_enforced(): void
    {
        config(['app.require_idempotency_key' => true]);
        $this->admin();

        foreach (self::ROUTES as $uri) {
            $this->postJson($uri, [])->assertStatus(428);
        }
    }

    public function test_write_with_key_reaches_the_handler(): void
    {
        config(['app.require_idempotency_key' => true]);
        $this->admin();

        foreach (self::ROUTES as $uri) {
            $status = $this->postJson($uri, [], ['Idempotency-Key' => (string) Str::uuid()])->getStatusCode();
            $this->assertNotSame(428, $status, "{$uri} still demanded a key");
        }
    }

    public function test_off_by_default_so_existing_clients_keep_working(): void
    {
        $this->admin();

        foreach (self::ROUTES as $uri) {
            $this->assertNotSame(428, $this->postJson($uri, [])->getStatusCode());
        }
    }
}
