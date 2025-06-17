<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Item;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Support\Facades\Redis; // 引入 Redis

class ItemPurchaseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // 確保 Redis 相關的鍵在每次測試前被清理
        // 清理所有 item:*:stock 和 item:*:lock 鍵
        $keys = Redis::keys('item:*:stock');
        $lockKeys = Redis::keys('item:*:lock');
        if (!empty($keys)) {
            Redis::del($keys);
        }
        if (!empty($lockKeys)) {
            Redis::del($lockKeys);
        }
    }

    public function test_purchase_item_successfully(): void
    {
        $user = User::factory()->create();
        $token = JWTAuth::fromUser($user);
        $item = Item::factory()->create(['stock' => 10]);

        // 預先設定 Redis 庫存，模擬初始化完成
        Redis::set("item:{$item->id}:stock", $item->stock);

        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->postJson("/api/v1/items/{$item->id}/purchase", ['quantity' => 3]);

        $response->assertStatus(200)
                 ->assertJson(['message' => 'Purchase successful!']);

        // 由於資料庫更新是異步的，這裡不能立即斷言資料庫的狀態
        // 您需要等待佇列處理完成，或在整合測試中檢查
        // $this->assertEquals(7, $item->fresh()->stock);
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

        $response->assertStatus(400) // 庫存不足返回 400
                 ->assertJsonStructure(['message', 'error_code', 'current_stock'])
                 ->assertJson(['error_code' => 'INSUFFICIENT_STOCK', 'current_stock' => 2]);
        $this->assertEquals(2, $item->fresh()->stock); // 資料庫庫存應該沒有變動
    }

    public function test_purchase_fails_with_invalid_quantity(): void
    {
        $user = User::factory()->create();
        $token = JWTAuth::fromUser($user);
        $item = Item::factory()->create(['stock' => 10]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->postJson("/api/v1/items/{$item->id}/purchase", ['quantity' => 0]); // 數量為 0

        $response->assertStatus(422) // 驗證失敗返回 422
                 ->assertJsonValidationErrors(['quantity']);

        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->postJson("/api/v1/items/{$item->id}/purchase", ['quantity' => 'abc']); // 數量為非數字

        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['quantity']);

        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->postJson("/api/v1/items/{$item->id}/purchase", ['quantity' => 2000]); // 超過最大限制

        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['quantity']);
    }

    public function test_purchase_fails_if_item_not_found(): void
    {
        $user = User::factory()->create();
        $token = JWTAuth::fromUser($user);
        $nonExistentItemId = 999;

        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->postJson("/api/v1/items/{$nonExistentItemId}/purchase", ['quantity' => 1]);

        $response->assertStatus(404) // 資源未找到返回 404
                 ->assertJson(['message' => '資源未找到。']);
    }

    public function test_purchase_fails_if_unauthenticated(): void
    {
        $item = Item::factory()->create(['stock' => 10]);

        $response = $this->postJson("/api/v1/items/{$item->id}/purchase", ['quantity' => 1]);

        $response->assertStatus(401) // 未認證返回 401
                 ->assertJson(['message' => 'Unauthenticated.']);
    }
}