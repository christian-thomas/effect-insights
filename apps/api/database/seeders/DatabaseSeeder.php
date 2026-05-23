<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Organisation;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        resolve(PermissionRegistrar::class)->forgetCachedPermissions();

        Role::firstOrCreate(['name' => User::ROLE_SUPERUSER, 'guard_name' => 'web']);

        $effect = Organisation::firstOrCreate(
            ['slug' => 'effect-digital'],
            ['name' => 'Effect Digital'],
        );

        $christian = User::firstOrCreate(
            ['email' => 'me@christianthomas.co.uk'],
            [
                'name' => 'Christian Thomas',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ],
        );

        $christian->assignRole(User::ROLE_SUPERUSER);

        $effect->users()->syncWithoutDetaching([
            $christian->getKey() => ['role' => Organisation::ROLE_OWNER],
        ]);
    }
}
