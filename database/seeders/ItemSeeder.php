<?php

namespace Database\Seeders;

use App\Models\Item;
use App\Models\User;
use Illuminate\Database\Seeder;

class ItemSeeder extends Seeder
{
    public function run(): void
    {
        Item::factory()->create([
            'name' => 'Fashion Gadget',
            'stock' => 100,
        ]);

        Item::factory()->create([
            'name' => 'Super Device',
            'stock' => 50,
        ]);

        // 可以這樣調用 UserFactory，如果 UserSeeder 是獨立的，就在 UserSeeder 中創建
        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
        ]);
    }
}