<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Item;
use App\Models\User;
use App\Jobs\UpdateItemStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;

class ItemPurchaseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake(); // 假冒佇列以測試 Job 分派

        // 清理所有 item:* 相關的 Redis 鍵
        $keys = Redis::keys('item:*');
        if (!empty($keys)) {
            Redis::del(array_map(fn($key) => str_replace('database_', '', $key), $keys));
        }
    }

    public function test_purchase_item_successfully(): void
    {
        $user = User::factory()->create();
        $token = JWTAuth::fromUser($user);
        $item = Item::factory()->create(['stock' => 10]);

        // 預先設定 Redis 庫存
        Redis::set("item:{$item->id}:stock", $item->stock);

        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->postJson("/api/v1/items/{$item->id}/purchase", ['quantity' => 3]);

        $response->assertStatus(200)
                 ->assertJson(['message' => 'Purchase successful!']);

        // 驗證 UpdateItemStock Job 是否分派
        Queue::assertPushed(UpdateItemStock::class, function ($job) use ($item) {
            return $job->itemId === $item->id && $job->quantity === 3;
        });
    }

    public function test_purchase_fails_with_insufficient_stock(): void
    {
        $user = User::factory()->create();
        $token = JWTAuth::fromUser($user);
        $item = Item::factory()->create(['stock' => 2]);

        // 預先設定 Redis 庫存
        Redis::set("item:{$item->id}:stock", $item->stock);

        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->postJson("/api/v1/items/{$item->id}/purchase", ['quantity' => 5]);

        $response->assertStatus(400)
                 ->assertJsonStructure(['message', 'error_code', 'current_stock'])
                 ->assertJson(['error_code' => 'INSUFFICIENT_STOCK', 'current_stock' => 2]);

        $this->assertEquals(2, $item->fresh()->stock);
        Queue::assertNotPushed(UpdateItemStock::class);
    }

    public function test_purchase_fails_with_invalid_quantity(): void
    {
        $user = User::factory()->create();
        $token = JWTAuth::fromUser($user);
        $item = Item::factory()->create(['stock' => 10]);

        // 測試數量為 0
        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->postJson("/api/v1/items/{$item->id}/purchase", ['quantity' => 0]);

        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['quantity']);

        // 測試非數字數量
        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->postJson("/api/v1/items/{$item->id}/purchase", ['quantity' => 'abc']);

        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['quantity']);

        // 測試超過最大限制
        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->postJson("/api/v1/items/{$item->id}/purchase", ['quantity' => 2000]);

        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['quantity']);

        Queue::assertNotPushed(UpdateItemStock::class);
    }

    public function test_purchase_fails_if_item_not_found_or_soft_deleted(): void
    {
        $user = User::factory()->create();
        $token = JWTAuth::fromUser($user);

        // 測試不存在的商品
        $nonExistentItemId = 999;
        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->postJson("/api/v1/items/{$nonExistentItemId}/purchase", ['quantity' => 1]);

        $response->assertStatus(422)
                 ->assertJson(['message' => "商品 {$nonExistentItemId} 不存在或已被刪除。"]);

        // 測試軟刪除的商品
        $item = Item::factory()->create(['stock' => 10]);
        $item->delete();
        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->postJson("/api/v1/items/{$item->id}/purchase", ['quantity' => 1]);

        $response->assertStatus(422)
                 ->assertJson(['message' => "商品 {$item->id} 不存在或已被刪除。"]);

        Queue::assertNotPushed(UpdateItemStock::class);
    }

    public function test_purchase_fails_if_unauthenticated(): void
    {
        $item = Item::factory()->create(['stock' => 10]);

        $response = $this->postJson("/api/v1/items/{$item->id}/purchase", ['quantity' => 1]);

        $response->assertStatus(401)
                 ->assertJson(['message' => 'Unauthenticated.']);

        Queue::assertNotPushed(UpdateItemStock::class);
    }
}