<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class EnsureSuperAdminCommand extends Command
{
    protected $signature = 'users:ensure-super-admin
                            {email : E-mail do usuário que deve acessar /admin}
                            {--create : Criar o usuário se não existir}
                            {--name= : Nome ao criar o usuário}
                            {--password= : Senha ao criar o usuário (padrão: password)}';

    protected $description = 'Garante is_super_admin e is_active para acesso ao painel /admin';

    public function handle(): int
    {
        $email = strtolower(trim((string) $this->argument('email')));

        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            if (! $this->option('create')) {
                $this->error("Usuário não encontrado: {$email}");
                $this->line('Use --create para criar o usuário com acesso de superadmin.');

                return self::FAILURE;
            }

            $password = (string) ($this->option('password') ?: 'password');
            $name = (string) ($this->option('name') ?: strstr($email, '@', true) ?: $email);

            $user = User::query()->create([
                'name' => $name,
                'email' => $email,
                'password' => $password,
                'is_super_admin' => true,
                'is_active' => true,
            ]);

            $this->info("Usuário criado como superadmin: {$user->email}");

            return self::SUCCESS;
        }

        $user->forceFill([
            'is_super_admin' => true,
            'is_active' => true,
        ])->save();

        $this->info("Superadmin restaurado: {$user->email} (id={$user->getKey()})");

        return self::SUCCESS;
    }
}
