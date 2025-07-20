<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;

use App\Models\User;
use Arden28\Guardian\Models\TwoFactorSetting;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        Role::create(['name' => 'admin', 'guard_name' => 'api']);
        Role::create(['name' => 'user', 'guard_name' => 'api']);
        Permission::create(['name' => 'manage_roles', 'guard_name' => 'api']);
        Permission::create(['name' => 'manage_permissions', 'guard_name' => 'api']);
        Permission::create(['name' => 'impersonate', 'guard_name' => 'api']);

        // Create User
        $user = User::create([
            'name' => "Brian Mwangi",
            'email' => "brianmwangi@gmail.com",
            'password' => Hash::make("Brian@2004"),
            'is_active' => true,
        ]);

        // Two Factor Authentification
        TwoFactorSetting::create([
            'user_id' => $user->id,
            'phone_number' => $user->phone_number ?? null,
        ]);

        // Assign default role
        $user->assignRole(config('guardian.roles.default_role', 'user'));

        // \App\Models\User::factory(10)->create();

        // \App\Models\User::factory()->create([
        //     'name' => 'Test User',
        //     'email' => 'test@example.com',
        // ]);
    }
}
