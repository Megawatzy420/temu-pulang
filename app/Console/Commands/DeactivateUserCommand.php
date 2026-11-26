<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Console\Command;

class DeactivateUserCommand extends Command
{
    protected $signature = 'temupulang:deactivate-user
                            {email : Email address of the user account}
                            {--reactivate : Reactivate the user account instead of deactivating it}';

    protected $description = 'Deactivate or reactivate a user account while protecting the last active Owner.';

    public function handle(): int
    {
        $email = strtolower(trim((string) $this->argument('email')));
        $user = User::with('roles')->where('email', $email)->first();

        if (! $user) {
            $this->error("User not found for email: {$email}");

            return self::FAILURE;
        }

        $reactivate = (bool) $this->option('reactivate');

        if ($reactivate) {
            $user->update(['is_active' => true]);

            ActivityLog::record(
                action: 'user.reactivated',
                description: "User account '{$user->email}' reactivated via CLI.",
                subject: $user,
                properties: [
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'is_active' => true,
                ],
                module: 'auth'
            );

            $this->info("User '{$user->email}' has been reactivated.");

            return self::SUCCESS;
        }

        if ($user->isOwner() && $user->is_active) {
            $otherActiveOwnersCount = User::query()
                ->where('is_active', true)
                ->where('id', '!=', $user->id)
                ->whereHas('roles', fn ($q) => $q->where('slug', 'owner'))
                ->count();

            if ($otherActiveOwnersCount === 0) {
                $this->error('Cannot deactivate the last active owner account.');

                return self::FAILURE;
            }
        }

        $user->update(['is_active' => false]);

        ActivityLog::record(
            action: 'user.deactivated',
            description: "User account '{$user->email}' deactivated via CLI.",
            subject: $user,
            properties: [
                'user_id' => $user->id,
                'email' => $user->email,
                'is_active' => false,
            ],
            module: 'auth'
        );

        $this->info("User '{$user->email}' has been deactivated.");

        return self::SUCCESS;
    }
}
