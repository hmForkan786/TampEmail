<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\AdPlacement;
use Illuminate\Database\Seeder;

final class AdPlacementSeeder extends Seeder
{
    public function run(): void
    {
        $placements = [
            ['key' => 'homepage_top', 'name' => 'Homepage Top', 'display_order' => 10],
            ['key' => 'homepage_bottom', 'name' => 'Homepage Bottom', 'display_order' => 20],
            ['key' => 'inbox_list', 'name' => 'Inbox List', 'display_order' => 30],
            ['key' => 'email_detail', 'name' => 'Email Detail', 'display_order' => 40],
            ['key' => 'sidebar', 'name' => 'Sidebar', 'display_order' => 50],
            ['key' => 'dashboard', 'name' => 'Dashboard', 'display_order' => 60],
            ['key' => 'pricing_page', 'name' => 'Pricing Page', 'display_order' => 70],
            ['key' => 'login', 'name' => 'Login', 'display_order' => 80],
            ['key' => 'register', 'name' => 'Register', 'display_order' => 90],
            ['key' => 'blog', 'name' => 'Blog', 'display_order' => 100],
            ['key' => 'footer', 'name' => 'Footer', 'display_order' => 110],
        ];

        foreach ($placements as $placement) {
            AdPlacement::query()->updateOrCreate(
                ['key' => $placement['key']],
                [
                    'name' => $placement['name'],
                    'description' => null,
                    'is_active' => true,
                    'display_order' => $placement['display_order'],
                ],
            );
        }
    }
}
