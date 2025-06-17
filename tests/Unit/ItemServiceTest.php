<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Services\ItemService;
use App\Models\Item;
use App\Repositories\Contracts\ItemRepositoryInterface;
use App\Jobs\UpdateItemStock;
use App\Exceptions\StockInsufficientException;
use App\Exceptions\RedisOperationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;

class ItemServiceTest extends TestCase
{
    use RefreshDatabase;

    protected ItemService $itemService;
    protected $itemRepositoryMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->itemRepositoryMock = Mockery::mock(ItemRepositoryInterface::class);
        $this->app->instance(ItemRepositoryInterface::class, $this->itemRepositoryMock);
        $this->itemService = new ItemService($this->itemRepositoryMock);
        Queue::fake();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_item_can_be_purchased_when_stock_available(): void
    {
        $item = Item::factory()->create(['stock' => 10]);
        $quantity = 3;

        $this->itemRepositoryMock->shouldReceive('acquireLock')->once()->with($item->id, 5)->andReturn(true);
        $this->itemRepositoryMock->shouldReceive('watchStock')->once()->with($item->id);
        $this->itemRepositoryMock->shouldReceive('getRedisStock')->once()->with($item->id)->andReturn($item->stock);
        $this->itemRepositoryMock->shouldReceive('decrementRedisStockAtomically')->once()->with($item->id, $quantity)->andReturn([true]);
        $this->itemRepositoryMock->shouldReceive('releaseLock')->once()->with($item->id);

        $result = $this->itemService->purchaseItem($item->id, $quantity);

        $this->assertTrue($result);
        Queue::assertPushed(UpdateItemStock::class, function ($job) use ($item, $quantity) {
            return $job->itemId === $item->id && $job->quantity === $quantity;
        });
        $this->assertEquals(10, $item->fresh()->stock);
    }

    public function test_item_purchase_fails_when_stock_insufficient(): void
    {
        $item = Item::factory()->create(['stock' => 5]);
        $quantity = 10;

        $this->itemRepositoryMock->shouldReceive('acquireLock')->once()->with($item->id, 5)->andReturn(true);
        $this->itemRepositoryMock->shouldReceive('watchStock')->once()->with($item->id);
        $this->itemRepositoryMock->shouldReceive('getRedisStock')->once()->with($item->id)->andReturn($item->stock);
        $this->itemRepositoryMock->shouldReceive('unwatchStock')->once()->with($item->id);
        $this->itemRepositoryMock->shouldReceive('releaseLock')->once()->with($item->id);

        $this->expectException(StockInsufficientException::class);
        $this->expectExceptionMessage("商品 {$item->id} 庫存不足。");

        $this->itemService->purchaseItem($item->id, $quantity);

        Queue::assertNotPushed(UpdateItemStock::class);
        $this->assertEquals(5, $item->fresh()->stock);
    }

    public function test_item_purchase_fails_when_redis_transaction_fails_due_to_watch_change(): void
    {
        $item = Item::factory()->create(['stock' => 10]);
        $quantity = 3;

        $this->itemRepositoryMock->shouldReceive('acquireLock')->once()->with($item->id, 5)->andReturn(true);
        $this->itemRepositoryMock->shouldReceive('watchStock')->once()->with($item->id);
        $this->itemRepositoryMock->shouldReceive('getRedisStock')->once()->with($item->id)->andReturn($item->stock);
        $this->itemRepositoryMock->shouldReceive('decrementRedisStockAtomically')->once()->with($item->id, $quantity)->andReturn(null);
        $this->itemRepositoryMock->shouldReceive('releaseLock')->once()->with($item->id);

        $this->expectException(RedisOperationException::class);
        $this->expectExceptionMessage("購買失敗，商品 {$item->id} 庫存發生併發衝突。請重試。");

        $this->itemService->purchaseItem($item->id, $quantity);

        Queue::assertNotPushed(UpdateItemStock::class);
        $this->assertEquals(10, $item->fresh()->stock);
    }

    public function test_item_purchase_fails_when_lock_cannot_be_acquired(): void
    {
        $item = Item::factory()->create(['stock' => 10]);
        $quantity = 3;

        // 模擬 6 次嘗試（1 次初始 + 5 次重試，與 ItemService.php 的 maxRetries=5 一致）
        $this->itemRepositoryMock->shouldReceive('acquireLock')->times(6)->with($item->id, 5)->andReturn(false);

        $this->expectException(RedisOperationException::class);
        $this->expectExceptionMessage("未能獲取商品 {$item->id} 的操作鎖，系統繁忙。請稍後再試。");

        $this->itemService->purchaseItem($item->id, $quantity);

        Queue::assertNotPushed(UpdateItemStock::class);
        $this->assertEquals(10, $item->fresh()->stock);
    }

    public function test_item_purchase_fails_when_item_is_soft_deleted(): void
    {
        $item = Item::factory()->create(['stock' => 10]);
        $item->delete(); // 軟刪除
        $quantity = 3;

        $this->itemRepositoryMock->shouldReceive('acquireLock')->once()->with($item->id, 5)->andReturn(true);
        $this->itemRepositoryMock->shouldReceive('watchStock')->once()->with($item->id);
        $this->itemRepositoryMock->shouldReceive('getRedisStock')->once()->with($item->id)->andReturn(null);
        $this->itemRepositoryMock->shouldReceive('unwatchStock')->once()->with($item->id);
        $this->itemRepositoryMock->shouldReceive('releaseLock')->once()->with($item->id);

        $this->expectException(RedisOperationException::class);
        $this->expectExceptionMessage("商品 {$item->id} 不存在或已被刪除。");

        $this->itemService->purchaseItem($item->id, $quantity);

        Queue::assertNotPushed(UpdateItemStock::class);
    }
}