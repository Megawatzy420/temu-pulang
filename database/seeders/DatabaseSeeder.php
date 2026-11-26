<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        if (app()->environment(['local', 'testing'])) {
            $this->call([
                RoleAndPermissionSeeder::class,
                UserSeeder::class,
                ShopSettingSeeder::class,
                ProductCategorySeeder::class,
                ProductSeeder::class,
                ModifierSeeder::class,
            ]);

            return;
        }

        $this->call([
            RoleAndPermissionSeeder::class,
            ShopSettingSeeder::class,
        ]);
    }
}
