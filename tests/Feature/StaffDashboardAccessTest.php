<?php

use App\Models\User;
use Database\Seeders\DemoRoleUsersSeeder;
use Database\Seeders\DemoStaffSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;

/**
 * What each role can actually open in the admin. Every role must have working screens (never an empty dashboard),
 * and nothing outside it. Paths are the API calls the admin screens make.
 */
const ROLE_ACCESS = [
    'super-admin' => ['staff', 'settings', 'orders/summary', 'customers', 'products', 'reports/sales', 'discounts', 'reviews', 'newsletter', 'pages', 'inventory/low-stock', 'delivery-zones'],
    'store-manager' => ['settings', 'orders/summary', 'customers', 'products', 'reports/sales', 'discounts', 'reviews', 'newsletter', 'pages', 'inventory/low-stock', 'delivery-zones'],
    'product-manager' => ['products', 'reviews', 'inventory/low-stock'],
    'content-editor' => ['pages'],
    'order-manager' => ['orders/summary', 'delivery-zones'],
    'customer-support' => ['orders/summary', 'customers', 'reviews'],
    'marketing-manager' => ['reports/sales', 'discounts', 'newsletter', 'pages', 'reviews'],
];

const ALL_AREAS = ['staff', 'settings', 'orders/summary', 'customers', 'products', 'reports/sales', 'discounts', 'reviews', 'newsletter', 'pages', 'inventory/low-stock', 'delivery-zones'];

it('gives each role exactly its own admin areas', function (string $role) {
    $user = orderStaff($role);

    foreach (ALL_AREAS as $area) {
        $res = asStaff($user)->getJson("/api/v1/admin/{$area}");
        in_array($area, ROLE_ACCESS[$role], true)
            ? expect($res->status())->toBe(200, "{$role} should open {$area}")
            : expect($res->status())->toBe(403, "{$role} should not open {$area}");
    }
})->with(array_keys(ROLE_ACCESS));

it('covers every seeded role in the access map', function () {
    expect(array_keys(ROLE_ACCESS))->toEqualCanonicalizing(array_keys(RolesAndPermissionsSeeder::ROLES));
});

it('seeds one working staff login per role and writes them to a private file', function () {
    Storage::fake('local');
    $this->seed(DemoRoleUsersSeeder::class);

    $file = Storage::disk('local')->get(DemoRoleUsersSeeder::FILE);
    foreach (RolesAndPermissionsSeeder::ROLES as $slug => $definition) {
        $user = User::with('roles')->firstWhere('email', "{$slug}@example.test");
        expect($user->roles->pluck('slug')->all())->toBe([$slug])->and($user->is_active)->toBeTrue()->and($user->isStaff())->toBeTrue();

        preg_match('/'.preg_quote("{$slug}@example.test", '/').'\s+(\S+)/', $file, $m);
        guest()->postJson('/api/v1/auth/staff/login', ['email' => "{$slug}@example.test", 'password' => $m[1]])->assertOk()->assertJsonPath('data.user.roles.0', $slug);
    }
});

it('gives every demo staff login, including the older all-roles one, the shared password when one is set', function () {
    Storage::fake('local');
    putenv('DEMO_STAFF_PASSWORD=shared-demo-pass');
    $_ENV['DEMO_STAFF_PASSWORD'] = $_SERVER['DEMO_STAFF_PASSWORD'] = 'shared-demo-pass';
    $this->seed(DemoStaffSeeder::class);

    try {
        $this->seed(DemoRoleUsersSeeder::class);
        foreach (array_keys(RolesAndPermissionsSeeder::ROLES) as $slug) {
            guest()->postJson('/api/v1/auth/staff/login', ['email' => "{$slug}@example.test", 'password' => 'shared-demo-pass'])->assertOk();
        }
        guest()->postJson('/api/v1/auth/staff/login', ['email' => DemoStaffSeeder::EMAIL, 'password' => 'shared-demo-pass'])->assertOk();
    } finally {
        putenv('DEMO_STAFF_PASSWORD');
        unset($_ENV['DEMO_STAFF_PASSWORD'], $_SERVER['DEMO_STAFF_PASSWORD']);
    }
});

it('refuses to create demo staff outside local and testing', function () {
    app()->detectEnvironment(fn () => 'staging');
    Storage::fake('local');
    $this->seed(DemoRoleUsersSeeder::class);

    expect(User::where('email', 'like', '%@example.test')->count())->toBe(0);
});
