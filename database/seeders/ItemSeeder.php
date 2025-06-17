<?php

namespace Database\Seeders;

use App\Models\Item;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

class ItemSeeder extends Seeder
{
    public function run(): void
    {
        try {
            Item::factory()->create([
                'name' => 'Fashion Gadget',
                'stock' => 100,
            ]);

            Item::factory()->create([
                'name' => 'Super Device',
                'stock' => 50,
            ]);

            User::factory()->create([
                'name' => 'Test User',
                'email' => 'test@example.com',
                'password' => bcrypt(env('TEST_USER_PASSWORD', 'password')),
            ]);
        } catch (\Exception $e) {
            Log::error("ItemSeeder failed: {$e->getMessage()}");
            throw $e; // 重新拋出以便 CI/CD 檢測失敗
        }
    }
}