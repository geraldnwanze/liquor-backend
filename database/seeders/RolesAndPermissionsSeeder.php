<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RolesAndPermissionsSeeder extends Seeder
{
    /** @var array<string, array{name: string, permissions: array<int, string>}> */
    public const ROLES = [
        // Unlimited. Holds every permission through "*", and is the only role that can manage staff.
        'super-admin' => ['name' => 'Super Administrator', 'permissions' => ['*']],
        // Owns the store: everything about running it, but not who works here (staff.manage stays with the super admin).
        'store-manager' => ['name' => 'Store Manager', 'permissions' => [
            'catalog.manage', 'catalog.view-cost', 'inventory.manage', 'orders.manage', 'orders.refund',
            'customers.manage', 'promotions.manage', 'content.manage', 'reviews.moderate', 'shipping.manage', 'reports.view', 'settings.manage',
        ]],
        'product-manager' => ['name' => 'Product Manager', 'permissions' => ['catalog.manage', 'inventory.manage', 'reviews.moderate']],
        'content-editor' => ['name' => 'Content Editor', 'permissions' => ['content.manage']],
        'order-manager' => ['name' => 'Order Manager', 'permissions' => ['orders.manage', 'shipping.manage']],
        'customer-support' => ['name' => 'Customer Support', 'permissions' => ['orders.view', 'customers.manage', 'reviews.moderate']],
        'marketing-manager' => ['name' => 'Marketing Manager', 'permissions' => ['promotions.manage', 'content.manage', 'reports.view', 'reviews.moderate']],
    ];

    public function run(): void
    {
        $all = collect(self::ROLES)->pluck('permissions')->flatten()->reject(fn ($p) => $p === '*')->unique();
        $permissions = $all->mapWithKeys(fn (string $slug) => [
            $slug => Permission::firstOrCreate(['slug' => $slug]),
        ]);

        foreach (self::ROLES as $slug => $definition) {
            $role = Role::updateOrCreate(['slug' => $slug], ['name' => $definition['name']]);
            $role->permissions()->sync(
                $definition['permissions'] === ['*'] ? [] : $permissions->only($definition['permissions'])->pluck('id')
            );
        }
    }
}
