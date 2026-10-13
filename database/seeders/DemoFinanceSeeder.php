<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Adds a Finance demo user (demo.finance.1@overeasy.local / "password") so billing can be QA'd
 * with the demo data. Safe to run more than once.
 *
 *   php artisan db:seed --class=DemoFinanceSeeder
 */
class DemoFinanceSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('DemoFinanceSeeder must never run in production.');
        }

        $this->call(RolesAndPermissionsSeeder::class);

        $user = User::query()->where('email', 'demo.finance.1@overeasy.local')->first()
            ?? User::factory()->create(['name' => 'Grace Lim', 'title' => 'Finance & Billing', 'email' => 'demo.finance.1@overeasy.local']);

        $user->syncRoles(['finance']);
    }
}
