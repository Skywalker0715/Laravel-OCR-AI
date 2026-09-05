<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

/**
 * Menyediakan beberapa kategori default yang berlaku untuk semua user.
 *
 * user_id dibiarkan NULL sehingga kategori ini bersifat "default sistem":
 * setiap user bisa melihat & menggunakannya, tanpa harus membuat sendiri.
 * Seeder ini safe untuk dijalankan berulang (gunakan updateOrCreate).
 */
class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            [
                'name' => 'Makanan & Minuman',
                'icon' => 'o-shopping-cart',
                'color' => '#10B981',
            ],
            [
                'name' => 'Transportasi',
                'icon' => 'o-truck',
                'color' => '#3B82F6',
            ],
            [
                'name' => 'Belanja Rumah Tangga',
                'icon' => 'o-shopping-bag',
                'color' => '#F59E0B',
            ],
            [
                'name' => 'Kesehatan',
                'icon' => 'o-heart',
                'color' => '#EF4444',
            ],
            [
                'name' => 'Hiburan',
                'icon' => 'o-film',
                'color' => '#8B5CF6',
            ],
            [
                'name' => 'Lainnya',
                'icon' => 'o-ellipsis-horizontal',
                'color' => '#64748B',
            ],
        ];

        foreach ($categories as $category) {
            Category::updateOrCreate(
                ['name' => $category['name'], 'user_id' => null],
                $category,
            );
        }
    }
}
