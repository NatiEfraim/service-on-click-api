<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Laravel\Passport\ClientRepository;

class RunAllCommands extends Command
{
    protected $signature = 'run:all';

    protected $description = 'Run multiple Artisan commands in sequence';


    public function handle()
    {
        $this->call('optimize:clear');
        $this->call('migrate:fresh', ['--seed' => true]);

            $this->call('passport:client', [
                '--personal' => true,
                '--name' => config('auth.access_token_name'),
                '--provider' => 'users',
            ]);

        // if (app()->environment('production')) {
        //     $this->call('migrate', ['--force' => true]);
        //     if (!$this->hasPersonalAccessClient()) {
        //         $this->call('passport:client', [
        //             '--personal' => true,
        //             '--name' => config('auth.access_token_name'),
        //             '--provider' => 'users',
        //         ]);
        //     }
        // } else {
        //     $this->call('migrate:fresh', ['--seed' => true]);

        //     $this->call('passport:client', [
        //         '--personal' => true,
        //         '--name' => config('auth.access_token_name'),
        //         '--provider' => 'users',
        //     ]);
        // }

        $this->call('optimize:clear');
    }

    private function hasPersonalAccessClient(): bool
    {
        try {
            app(ClientRepository::class)->personalAccessClient('users');
            return true;
        } catch (\RuntimeException) {
            return false;
        }
    }
}
