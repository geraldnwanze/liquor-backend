<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/** Local/testing staff login only (DatabaseSeeder skips this in production). Email and password are in this file. */
class DemoStaffSeeder extends Seeder
{
    public const EMAIL = 'orders.staff@example.test';

    public const PASSWORD = 'Local-Staff-Pass-2026!';

    public function run(): void
    {
        $refunds = Role::updateOrCreate(['slug' => 'demo-refund-approver'], ['name' => 'Demo Refund Approver']);
        $refunds->permissions()->sync([Permission::firstOrCreate(['slug' => 'orders.refund'])->id]);

        $user = User::updateOrCreate(['email' => self::EMAIL], ['name' => 'Demo Order Staff', 'password' => Hash::make(self::PASSWORD)]);
        $user->roles()->sync(Role::whereIn('slug', ['order-manager', 'product-manager', 'marketing-manager', 'customer-support', 'store-manager', 'super-admin', 'demo-refund-approver'])->pluck('id'));
    }
}
