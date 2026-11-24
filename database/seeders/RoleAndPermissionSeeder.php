<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleAndPermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $permissions = [
            // POS operations
            [
                'slug' => 'pos.access',
                'name' => 'Access POS Terminal',
                'group' => 'pos',
                'description' => 'Can access the point of sale checkout interface',
            ],
            [
                'slug' => 'pos.orders.create',
                'name' => 'Create POS Orders',
                'group' => 'pos',
                'description' => 'Can create, modify, and submit customer orders',
            ],
            [
                'slug' => 'pos.orders.view',
                'name' => 'View Shift Orders',
                'group' => 'pos',
                'description' => 'Can view orders placed during active shift',
            ],

            // Product Catalog
            [
                'slug' => 'products.view',
                'name' => 'View Products',
                'group' => 'products',
                'description' => 'Can browse product catalog, categories, and modifiers',
            ],
            [
                'slug' => 'products.manage',
                'name' => 'Manage Products',
                'group' => 'products',
                'description' => 'Can create, edit, price, and delete products, variants, and modifiers',
            ],

            // Inventory
            [
                'slug' => 'inventory.view',
                'name' => 'View Inventory',
                'group' => 'inventory',
                'description' => 'Can view stock levels and ingredient availability',
            ],
            [
                'slug' => 'inventory.manage',
                'name' => 'Manage Inventory',
                'group' => 'inventory',
                'description' => 'Can adjust stock levels, perform audits, and manage suppliers',
            ],

            // Financial & Reports
            [
                'slug' => 'reports.view',
                'name' => 'View Standard Reports',
                'group' => 'reports',
                'description' => 'Can view shift summary and basic daily sales count',
            ],
            [
                'slug' => 'reports.sensitive.view',
                'name' => 'View Sensitive Financial Reports',
                'group' => 'reports',
                'description' => 'Can view profit margins, revenue totals, discounts, and audit logs',
            ],

            // Refunds
            [
                'slug' => 'refunds.process',
                'name' => 'Process Refunds',
                'group' => 'refunds',
                'description' => 'Can authorize order cancellations and payment refunds',
            ],

            // System Settings
            [
                'slug' => 'settings.manage',
                'name' => 'Manage Settings',
                'group' => 'settings',
                'description' => 'Can configure shop profile, taxes, printers, and system parameters',
            ],
        ];

        $permissionModels = [];
        foreach ($permissions as $permissionData) {
            $permissionModels[$permissionData['slug']] = Permission::updateOrCreate(
                ['slug' => $permissionData['slug']],
                $permissionData
            );
        }

        // 1. Owner Role (Full unrestricted access)
        $ownerRole = Role::updateOrCreate(
            ['slug' => 'owner'],
            [
                'name' => 'Owner',
                'description' => 'Shop owner with full administrative and financial authority',
                'is_system' => true,
            ]
        );
        $ownerRole->permissions()->sync(array_map(fn ($p) => $p->id, $permissionModels));

        // 2. Cashier Role (Restricted access: POS & permitted inventory viewing only)
        $cashierRole = Role::updateOrCreate(
            ['slug' => 'cashier'],
            [
                'name' => 'Cashier',
                'description' => 'Front-of-house cashier: operates POS and views permitted inventory',
                'is_system' => true,
            ]
        );

        $cashierPermissions = [
            $permissionModels['pos.access']->id,
            $permissionModels['pos.orders.create']->id,
            $permissionModels['pos.orders.view']->id,
            $permissionModels['products.view']->id,
            $permissionModels['inventory.view']->id,
            $permissionModels['reports.view']->id,
        ];
        $cashierRole->permissions()->sync($cashierPermissions);
    }
}
