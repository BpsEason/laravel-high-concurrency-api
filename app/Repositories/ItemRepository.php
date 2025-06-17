<?php

namespace App\Repositories;

use App\Models\Item;
use App\Repositories\Contracts\ItemRepositoryInterface;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;

class ItemRepository implements ItemRepositoryInterface
{
    public function getRedisStock(int $itemId): ?int
    {
        $stock = Redis::get("item:{$itemId}:stock");
        if ($stock === null) {
            return null;
        }
        return (int)$stock;
    }

    public function setRedisStock(int $itemId, int $stock): bool
    {
        try {
            Redis::set("item:{$itemId}:stock", $stock);
            return true;
        } catch (\Exception $e) {
            Log::error(__('item.set_redis_stock_failed', ['item_id' => $itemId, 'error' => $e->getMessage()]));
            return false;
        }
    }

    public function watchStock(int $itemId): void
    {
        Redis::watch("item:{$itemId}:stock");
    }

    public function unwatchStock(int $itemId): void
    {
        Redis::unwatch();
    }

    public function decrementRedisStockAtomically(int $itemId, int $quantity)
    {
        try {
            Redis::multi();
            Redis::decrby("item:{$itemId}:stock", $quantity);
            $result = Redis::exec();
            return $result;
        } catch (\Exception $e) {
            Log::error(__('item.redis_transaction_failed', ['item_id' => $itemId, 'error' => $e->getMessage()]));
            return false;
        }
    }

    public function decrementDatabaseStock(int $itemId, int $quantity): bool
    {
        try {
            $item = Item::find($itemId);
            if ($item) {
                $item->decrement('stock', $quantity);
                return true;
            }
            Log::warning(__('item.database_stock_not_found', ['item_id' => $itemId]));
            return false;
        } catch (\Exception $e) {
            Log::error(__('item.decrement_database_stock_failed', ['item_id' => $itemId, 'error' => $e->getMessage()]));
            return false;
        }
    }

    public function acquireLock(int $itemId, int $ttl = 5): bool
    {
        try {
            return (bool) Redis::set("item:{$itemId}:lock", 1, 'EX', $ttl, 'NX');
        } catch (\Exception $e) {
            Log::error(__('item.acquire_lock_failed', ['item_id' => $itemId, 'error' => $e->getMessage()]));
            return false;
        }
    }

    public function releaseLock(int $itemId): void
    {
        try {
            Redis::del("item:{$itemId}:lock");
        } catch (\Exception $e) {
            Log::error(__('item.release_lock_failed', ['item_id' => $itemId, 'error' => $e->getMessage()]));
            // TODO: 觸發告警（例如透過監控系統或通知）
        }
    }
}