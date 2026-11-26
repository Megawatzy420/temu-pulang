<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class CreateUserCommand extends Command
{
    protected $signature = 'temupulang:create-user
                            {--role= : Role slug for the account (owner or cashier)}
                            {--name= : Full display name for the account}
                            {--email= : Unique email address for the account}';

    protected $description = 'Create an active Owner or Cashier user account with a hidden password prompt.';

    public function handle(): int
    {
        $nameOption = $this->option('name');
        $name = is_string($nameOption) && trim($nameOption) !== ''
            ? trim($nameOption)
            : trim((string) $this->ask('Full name'));

        if ($name === '') {
            $this->error('Name is required.');

            return self::FAILURE;
        }

        $emailOption = $this->option('email');
        $email = is_string($emailOption) && trim($emailOption) !== ''
            ? strtolower(trim($emailOption))
            : strtolower(trim((string) $this->ask('Email address')));

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->error('A valid email address is required.');

            return self::FAILURE;
        }

        if (User::withTrashed()->where('email', $email)->exists()) {
            $this->error("Email '{$email}' is already registered.");

            return self::FAILURE;
        }

        $roleOption = $this->option('role');
        $role = is_string($roleOption) && trim($roleOption) !== ''
            ? strtolower(trim($roleOption))
            : strtolower(trim((string) $this->ask('Role (owner or cashier)')));

        if (! in_array($role, ['owner', 'cashier'], true)) {
            $this->error("Invalid role '{$role}'. Allowed roles: owner, cashier.");

            return self::FAILURE;
        }

        $password = (string) $this->secret('Password (minimum 10 characters)');
        $confirmation = (string) $this->secret('Confirm password');

        if (mb_strlen($password) < 10) {
            $this->error('Password must be at least 10 characters.');

            return self::FAILURE;
        }

        if ($password !== $confirmation) {
            $this->error('Password confirmation does not match.');

            return self::FAILURE;
        }

        $roleModel = Role::firstOrCreate(
            ['slug' => $role],
            [
                'name' => $role === 'owner' ? 'Owner' : 'Cashier',
                'description' => $role === 'owner'
                    ? 'Shop owner with full administrative and financial authority'
                    : 'Front-of-house cashier: operates POS and views permitted inventory',
                'is_system' => true,
            ]
        );

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'is_active' => true,
        ]);

        $user->forceFill(['email_verified_at' => now()])->save();
        $user->assignRole($roleModel);

        $this->info("User '{$user->name}' ({$user->email}) created with role '{$role}' and active status.");

        return self::SUCCESS;
    }
}
