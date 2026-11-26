<?php

namespace Database\Seeders;

use App\Models\ActivityLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        $ownerRole = Role::where('slug', 'owner')->first();
        $cashierRole = Role::where('slug', 'cashier')->first();

        // 1. Development Owner Account
        $ownerEmail = env('SEED_OWNER_EMAIL', 'owner@temupulang.local');
        $ownerPassword = env('SEED_OWNER_PASSWORD', 'TemuPulang@2026!');
        $ownerName = env('SEED_OWNER_NAME', 'Temu Pulang Owner');

        $owner = User::updateOrCreate(
            ['email' => $ownerEmail],
            [
                'name' => $ownerName,
                'phone' => '081200000001',
                'password' => Hash::make($ownerPassword),
                'email_verified_at' => now(),
                'is_active' => true,
            ]
        );

        if ($ownerRole) {
            $owner->roles()->syncWithoutDetaching([$ownerRole->id]);
        }

        // 2. Development Cashier Account
        $cashierEmail = env('SEED_CASHIER_EMAIL', 'cashier@temupulang.local');
        $cashierPassword = env('SEED_CASHIER_PASSWORD', 'Cashier@2026!');
        $cashierName = env('SEED_CASHIER_NAME', 'Kasir Shift 1');

        $cashier = User::updateOrCreate(
            ['email' => $cashierEmail],
            [
                'name' => $cashierName,
                'phone' => '081200000002',
                'password' => Hash::make($cashierPassword),
                'email_verified_at' => now(),
                'is_active' => true,
            ]
        );

        if ($cashierRole) {
            $cashier->roles()->syncWithoutDetaching([$cashierRole->id]);
        }

        // Audit log initial system seeding
        ActivityLog::firstOrCreate(
            [
                'action' => 'system.initialized',
                'description' => 'System access control and initial accounts configured.',
            ],
            [
                'user_id' => $owner->id,
                'properties' => ['seeded_roles' => ['owner', 'cashier']],
            ]
        );
    }
}
