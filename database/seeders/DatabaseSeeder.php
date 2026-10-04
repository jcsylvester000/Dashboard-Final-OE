<?php

namespace Database\Seeders;

use App\Domain\Identity\AccessLinkService;
use App\Models\User;
use App\Models\UserAccessLink;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            DepartmentSeeder::class,
            WorkflowTemplateSeeder::class,
        ]);

        $this->seedFirstSuperAdmin();
    }

    /**
     * Creates the first Super Admin once and prints a one-time setup link.
     * The app sends no email, so the link is shown here only.
     */
    private function seedFirstSuperAdmin(): void
    {
        if (User::role(User::SUPER_ADMIN)->exists()) {
            return;
        }

        $email = Str::lower((string) config('app.seed_admin.email'));

        $admin = User::firstOrNew(['email' => $email]);
        $admin->fill([
            'name' => (string) config('app.seed_admin.name'),
            'password' => bin2hex(random_bytes(32)),
        ]);
        $admin->forceFill(['email_verified_at' => now(), 'is_active' => true])->save();
        $admin->syncRoles([User::SUPER_ADMIN]);

        ['url' => $url] = app(AccessLinkService::class)->issue($admin, UserAccessLink::PURPOSE_SETUP);

        $this->command->newLine();
        $this->command->info('Super Admin created: '.$email);
        $this->command->warn('Open this one-time link to set the password (valid 72 hours):');
        $this->command->line($url);
        $this->command->newLine();
    }
}
