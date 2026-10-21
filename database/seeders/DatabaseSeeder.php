<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);

        if (! app()->isProduction()) {
            $this->call(DemoCatalogSeeder::class);
            $this->call(DemoDeliverySeeder::class);
            $this->call(DemoStaffSeeder::class);
            $this->call(DemoRoleUsersSeeder::class);
            $this->call(DemoDiscountSeeder::class);
            $this->call(DemoCustomerSeeder::class);
        }
    }
}
