<?php

declare(strict_types=1);

namespace Tests;

use App\Models\Organisation;
use App\Models\User;
use App\Services\Cloudflare\ArrayCloudflareKvClient;
use App\Services\Cloudflare\CloudflareKvClientContract;
use App\Support\CurrentOrganisation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Base test case for tenant-aware feature tests.
 *
 * Seeds two organisations + two users + memberships:
 *   - $user belongs to $org (role: owner)
 *   - $otherUser belongs to $otherOrg (role: owner)
 *   - Neither is a superuser by default.
 *
 * Use $this->actingAsMember($user, $org) — NOT plain $this->actingAs($user).
 * actingAs() skips the auth + ResolveCurrentOrganisation middleware chain, so
 * the global scope would be unbound and queries would silently return ALL
 * tenants' data. actingAsMember binds BOTH the session AND the
 * CurrentOrganisation service to mimic what the middleware does in real HTTP
 * requests.
 */
abstract class TenantTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected User $otherUser;

    protected Organisation $org;

    protected Organisation $otherOrg;

    protected ArrayCloudflareKvClient $kv;

    protected function setUp(): void
    {
        parent::setUp();

        // Sanctum's stateful middleware only engages the session when the
        // request looks like it's coming from a configured SPA domain.
        $this->withHeaders([
            'Origin' => 'http://localhost:3001',
            'Referer' => 'http://localhost:3001/',
        ]);

        // Bind the in-memory CF KV fake so PHPUnit never makes real CF calls.
        // Tests can introspect $this->kv->getStore() to assert publish writes.
        $this->kv = new ArrayCloudflareKvClient;
        $this->app->instance(CloudflareKvClientContract::class, $this->kv);

        resolve(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::firstOrCreate(['name' => User::ROLE_SUPERUSER, 'guard_name' => 'web']);

        $this->org = Organisation::create([
            'name' => 'Effect Digital',
            'slug' => 'effect-digital',
        ]);

        $this->otherOrg = Organisation::create([
            'name' => 'Other Client',
            'slug' => 'other-client',
        ]);

        $this->user = User::create([
            'name' => 'Member User',
            'email' => 'member@example.test',
            'password' => Hash::make('password'),
        ]);
        $this->org->users()->attach($this->user, ['role' => Organisation::ROLE_OWNER]);

        $this->otherUser = User::create([
            'name' => 'Other User',
            'email' => 'other@example.test',
            'password' => Hash::make('password'),
        ]);
        $this->otherOrg->users()->attach($this->otherUser, ['role' => Organisation::ROLE_OWNER]);
    }

    protected function actingAsMember(User $user, Organisation $organisation): static
    {
        $this->actingAs($user);

        session(['current_organisation_id' => $organisation->getKey()]);
        resolve(CurrentOrganisation::class)->set($organisation);

        return $this;
    }

    protected function makeSuperuser(User $user): User
    {
        $user->assignRole(User::ROLE_SUPERUSER);
        $user->refresh();

        return $user;
    }
}
