<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Services\ItemService;
use App\Models\Item;
use App\Repositories\Contracts\ItemRepositoryInterface; // 引入介面
use App\Jobs\UpdateItemStock; // 引入 Job
use App\Exceptions\StockInsufficientException; // 引入異常
use App\Exceptions\RedisOperationException; // 引入異常
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue; // 引入 Queue 門面用於測試異步 Job
use Mockery; // 引入 Mockery

class ItemServiceTest extends TestCase
{
    use RefreshDatabase;

    protected ItemService $itemService;
    protected $itemRepositoryMock; // Mock Repository

    protected function setUp(): void
    {
        parent::setUp();

        // 使用 Mockery 創建 ItemRepositoryInterface 的 Mock 對象
        $this->itemRepositoryMock = Mockery::mock(ItemRepositoryInterface::class);

        // 將 Mock 對象綁定到 Laravel 的服務容器中
        // 這樣在 ItemService 創建時，會自動注入這個 Mock 對象
        $this->app->instance(ItemRepositoryInterface::class, $this->itemRepositoryMock);

        $this->itemService = new ItemService($this->itemRepositoryMock);

        // 假冒佇列，這樣異步 Job 不會真的被執行，方便測試
        Queue::fake();
    }

    protected function tearDown(): void
    {
        Mockery::close(); // 清理 Mockery
        parent::tearDown();
    }

    public function test_item_can_be_purchased_when_stock_available(): void
    {
        $item = Item::factory()->create(['stock' => 10]);
        $quantity = 3;

        // 設定 Mock ItemRepositoryInterface 的行為
        $this->itemRepositoryMock->shouldReceive('acquireLock')->once()->with($item->id, 5)->andReturn(true);
        $this->itemRepositoryMock->shouldReceive('watchStock')->once()->with($item->id);
        $this->itemRepositoryMock->shouldReceive('getRedisStock')->once()->with($item->id)->andReturn($item->stock);
        $this->itemRepositoryMock->shouldReceive('decrementRedisStockAtomically')->once()->with($item->id, $quantity)->andReturn([true]); // 模擬 Redis 事務成功
        $this->itemRepositoryMock->shouldReceive('releaseLock')->once()->with($item->id);

        $result = $this->itemService->purchaseItem($item->id, $quantity);

        $this->assertTrue($result);

        // 斷言 UpdateItemStock Job 是否被正確調度
        Queue::assertPushed(UpdateItemStock::class, function ($job) use ($item, $quantity) {
            return $job->itemId === $item->id && $job->quantity === $quantity;
        });

        // 由於資料庫更新是異步的，這裡不直接斷言資料庫庫存，因為 Job 還沒執行
        $this->assertEquals(10, $item->fresh()->stock); // 資料庫庫存此時應仍為原始值
    }

    public function test_item_purchase_fails_when_stock_insufficient(): void
    {
        $item = Item::factory()->create(['stock' => 5]);
        $quantity = 10;

        // 設定 Mock ItemRepositoryInterface 的行為
        $this->itemRepositoryMock->shouldReceive('acquireLock')->once()->with($item->id, 5)->andReturn(true);
        $this->itemRepositoryMock->shouldReceive('watchStock')->once()->with($item->id);
        $this->itemRepositoryMock->shouldReceive('getRedisStock')->once()->with($item->id)->andReturn($item->stock); // 返回當前庫存 5
        $this->itemRepositoryMock->shouldReceive('unwatchStock')->once()->with($item->id); // 庫存不足會取消監聽
        $this->itemRepositoryMock->shouldReceive('releaseLock')->once()->with($item->id);

        // 期望拋出 StockInsufficientException 異常
        $this->expectException(StockInsufficientException::class);
        $this->expectExceptionMessage("商品 {$item->id} 庫存不足。");

        $this->itemService->purchaseItem($item->id, $quantity);

        // 確保沒有 Job 被調度
        Queue::assertNotPushed(UpdateItemStock::class);
        $this->assertEquals(5, $item->fresh()->stock); // 資料庫庫存應該沒有變動
    }

    public function test_item_purchase_fails_when_redis_transaction_fails_due_to_watch_change(): void
    {
        $item = Item::factory()->create(['stock' => 10]);
        $quantity = 3;

        // 設定 Mock ItemRepositoryInterface 的行為
        $this->itemRepositoryMock->shouldReceive('acquireLock')->once()->with($item->id, 5)->andReturn(true);
        $this->itemRepositoryMock->shouldReceive('watchStock')->once()->with($item->id);
        $this->itemRepositoryMock->shouldReceive('getRedisStock')->once()->with($item->id)->andReturn($item->stock);
        // 模擬 Redis 事務失敗（例如因為 WATCH 的鍵在 EXEC 前被修改）
        $this->itemRepositoryMock->shouldReceive('decrementRedisStockAtomically')->once()->with($item->id, $quantity)->andReturn(null); // Redis::exec() 返回 null 表示事務被取消
        $this->itemRepositoryMock->shouldReceive('releaseLock')->once()->with($item->id);

        // 期望拋出 RedisOperationException 異常
        $this->expectException(RedisOperationException::class);
        $this->expectExceptionMessage("購買失敗，商品 {$item->id} 庫存發生併發衝突。請重試。");

        $this->itemService->purchaseItem($item->id, $quantity);

        // 確保沒有 Job 被調度
        Queue::assertNotPushed(UpdateItemStock::class);
        $this->assertEquals(10, $item->fresh()->stock); // 資料庫庫存應該沒有變動
    }

    public function test_item_purchase_fails_when_lock_cannot_be_acquired(): void
    {
        $item = Item::factory()->create(['stock' => 10]);
        $quantity = 3;

        // 模擬多次嘗試後仍無法獲取鎖
        $this->itemRepositoryMock->shouldReceive('acquireLock')->times(6)->with($item->id, 5)->andReturn(false); // 5 次重試 + 1 次最初嘗試 = 6 次
        // 注意：這裡如果ItemService中重試次數是5，則會是6次調用acquireLock（第一次嘗試+5次重試）

        $this->itemRepositoryMock->shouldReceive('releaseLock')->never(); // 由於沒有獲取到鎖，所以不會有釋放鎖的調用
        $this->itemRepositoryMock->shouldReceive('watchStock')->never(); // 由於沒有獲取到鎖，所以不會有監聽庫存的調用
        $this->itemRepositoryMock->shouldReceive('getRedisStock')->never();
        $this->itemRepositoryMock->shouldReceive('decrementRedisStockAtomically')->never();


        // 期望拋出 RedisOperationException 異常
        $this->expectException(RedisOperationException::class);
        $this->expectExceptionMessage("未能獲取商品 {$item->id} 的操作鎖，系統繁忙。請稍後再試。");

        $this->itemService->purchaseItem($item->id, $quantity);

        // 確保沒有 Job 被調度
        Queue::assertNotPushed(UpdateItemStock::class);
        $this->assertEquals(10, $item->fresh()->stock); // 資料庫庫存應該沒有變動
    }
}