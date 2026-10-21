<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * One local staff login per role, so every dashboard can be tried. Only runs in the local and testing environments.
 *
 * Passwords are not kept in code: each run gives every user a fresh random password (or DEMO_STAFF_PASSWORD for all,
 * if you set it) and writes the logins to storage/app/private/demo-staff-credentials.txt, which git ignores.
 */
class DemoRoleUsersSeeder extends Seeder
{
    public const FILE = 'demo-staff-credentials.txt';

    public function run(): void
    {
        // Local machines only. A shared, guessable password must never reach a staging or live server.
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->error('Demo staff users are only created in the local and testing environments.');

            return;
        }

        $this->call(RolesAndPermissionsSeeder::class);
        $shared = env('DEMO_STAFF_PASSWORD');
        $lines = ['Local demo staff logins (these exist only on this machine). Sign in at /staff/login.', ''];

        foreach (RolesAndPermissionsSeeder::ROLES as $slug => $definition) {
            $email = "{$slug}@example.test";
            $password = is_string($shared) && $shared !== '' ? $shared : Str::password(16, symbols: false);
            $user = User::updateOrCreate(['email' => $email], ['name' => 'Demo '.$definition['name'], 'password' => Hash::make($password)]);
            $user->forceFill(['is_active' => true])->save();
            $user->roles()->sync([Role::where('slug', $slug)->value('id')]);
            $lines[] = sprintf('%-22s %-34s %s', $definition['name'], $email, $password);
        }

        // The older all-roles demo login follows the shared password too, so "one password for all staff" is true.
        if (is_string($shared) && $shared !== '') {
            User::where('email', DemoStaffSeeder::EMAIL)->first()?->forceFill(['password' => Hash::make($shared)])->save();
            $lines[] = sprintf('%-22s %-34s %s', 'All roles (older demo)', DemoStaffSeeder::EMAIL, $shared);
        }

        Storage::disk('local')->put(self::FILE, implode("\n", $lines)."\n");
        $this->command?->info('Staff logins written to '.storage_path('app/private/'.self::FILE));
    }
}
