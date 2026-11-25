<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

class CriarAdminCommand extends Command
{
    protected $signature = 'portaria:criar-admin
        {--nome= : Nome do administrador}
        {--email= : E-mail de login}
        {--senha= : Senha (se omitida, será perguntada)}';

    protected $description = 'Cria (ou promove) um usuário super admin';

    public function handle(): int
    {
        $nome = $this->option('nome') ?: text('Nome', default: 'Administrador', required: true);
        $email = $this->option('email') ?: text('E-mail', required: true);
        $senha = $this->option('senha') ?: password('Senha (mínimo 8 caracteres)', required: true);

        $validacao = Validator::make(
            ['email' => $email, 'senha' => $senha],
            ['email' => ['required', 'email'], 'senha' => ['required', Password::min(8)]],
        );

        if ($validacao->fails()) {
            foreach ($validacao->errors()->all() as $erro) {
                $this->error($erro);
            }

            return self::FAILURE;
        }

        $usuario = User::query()->firstOrNew(['email' => $email]);
        $novo = ! $usuario->exists;

        $usuario->fill(['name' => $nome, 'password' => $senha]);
        $usuario->forceFill([
            'super_admin' => true,
            'ativo' => true,
            'email_verified_at' => $usuario->email_verified_at ?? now(),
        ])->save();

        $this->info($novo
            ? "Super admin {$email} criado."
            : "Usuário {$email} atualizado e promovido a super admin.");

        $this->line('No primeiro acesso será pedido para ativar a verificação em duas etapas (2FA).');

        return self::SUCCESS;
    }
}
