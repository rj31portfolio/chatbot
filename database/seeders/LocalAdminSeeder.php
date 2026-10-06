<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class LocalAdminSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new \RuntimeException('Local admin seeding is only available in the local environment.');
        }
        $email = 'admin@example.test';
        if (User::where('email', $email)->exists()) {
            return;
        }
        $password = Str::random(24).'A1';
        $user = User::create(['name' => 'Local Platform Admin', 'email' => $email, 'password' => $password]);
        $user->forceFill(['is_super_admin' => true, 'email_verified_at' => now()])->save();
        file_put_contents(storage_path('app/admin-credentials.txt'), "Local admin login\nEmail: {$email}\nPassword: {$password}\n");
        $this->command?->info('Local admin created. Credentials: storage/app/admin-credentials.txt');
    }
}
