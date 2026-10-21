<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/** Local/testing customer login only (DatabaseSeeder skips this in production). Email and password are in this file. */
class DemoCustomerSeeder extends Seeder
{
    public const EMAIL = 'demo.customer@example.test';

    public const PASSWORD = 'Local-Customer-Pass-2026!';

    public function run(): void
    {
        User::updateOrCreate(['email' => self::EMAIL], ['name' => 'Demo Customer', 'password' => Hash::make(self::PASSWORD)]);
    }
}
