<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Adds the Bundle module permissions without touching existing ones.
 *
 *   php artisan db:seed --class=BundlePermissionSeeder
 *
 * Note: PermissionSeeder truncates all permissions, so re-run this seeder after it.
 */
class BundlePermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (['view bundle', 'manage bundle'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        Role::where('name', 'super-admin')->first()?->givePermissionTo(['view bundle', 'manage bundle']);
        Role::where('name', 'staff')->first()?->givePermissionTo('view bundle');

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->command?->info('Bundle permissions seeded.');
    }
}
