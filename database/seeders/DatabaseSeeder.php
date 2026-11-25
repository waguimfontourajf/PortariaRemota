<?php

namespace Database\Seeders;

use App\Models\Condominio;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(PerfisSeeder::class);

        $this->criarSuperAdmin();

        if (app()->environment('local') && Condominio::query()->doesntExist()) {
            Condominio::create([
                'nome' => 'Condomínio Demonstração',
                'cidade' => 'São Paulo',
                'uf' => 'SP',
            ]);
        }
    }

    private function criarSuperAdmin(): void
    {
        $email = config('portaria.admin.email');
        $senha = config('portaria.admin.senha');

        if (blank($email) || blank($senha)) {
            $this->command?->warn('PORTARIA_ADMIN_EMAIL/PORTARIA_ADMIN_SENHA não definidos no .env: super admin não criado. Use "php artisan portaria:criar-admin".');

            return;
        }

        User::query()->firstOrCreate(
            ['email' => $email],
            [
                'name' => config('portaria.admin.nome'),
                'password' => $senha,
                'super_admin' => true,
                'email_verified_at' => now(),
            ],
        );
    }
}
