<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class SetPasswordCommand extends Command
{
    protected $signature = 'temupulang:set-password
                            {email : Email address of the user account}';

    protected $description = 'Update a user account password hash via a hidden prompt.';

    public function handle(): int
    {
        $email = strtolower(trim((string) $this->argument('email')));
        $user = User::where('email', $email)->first();

        if (! $user) {
            $this->error("User not found for email: {$email}");

            return self::FAILURE;
        }

        $password = (string) $this->secret('New password (minimum 10 characters)');
        $confirmation = (string) $this->secret('Confirm new password');

        if (mb_strlen($password) < 10) {
            $this->error('Password must be at least 10 characters.');

            return self::FAILURE;
        }

        if ($password !== $confirmation) {
            $this->error('Password confirmation does not match.');

            return self::FAILURE;
        }

        $user->forceFill([
            'password' => Hash::make($password),
        ])->save();

        $this->info("Password updated for user '{$user->email}'.");

        return self::SUCCESS;
    }
}
