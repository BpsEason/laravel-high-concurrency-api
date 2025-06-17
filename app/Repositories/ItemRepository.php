<?php

namespace App\Repositories;

use App\Models\Item;
use App\Repositories\Contracts\ItemRepositoryInterface;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log; // 用於日誌記錄

class ItemRepository implements ItemRepositoryInterface
{
    /**
     * 從 Redis 獲取商品庫存。
     *
     * @param int $itemId
     * @return int|null null 表示 Redis 中不存在此商品的庫存鍵
     */
    public function getRedisStock(int $itemId): ?int
    {
        $stock = Redis::get("item:{$itemId}:stock");
        if ($stock === null) {
            return null; // 表示鍵不存在
        }
        return (int)$stock;
    }

    /**
     * 設定 Redis 中的商品庫存。
     *
     * @param int $itemId
     * @param int $stock
     * @return bool
     */
    public function setRedisStock(int $itemId, int $stock): bool
    {
        try {
            Redis::set("item:{$itemId}:stock", $stock);
            return true;
        } catch (\Exception $e) {
            Log::error("Failed to set Redis stock for item {$itemId}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 開始監聽 Redis 上的商品庫存鍵，用於 WATCH/MULTI/EXEC 事務。
     *
     * @param int $itemId
     * @return void
     */
    public function watchStock(int $itemId): void
    {
        Redis::watch("item:{$itemId}:stock");
    }

    /**
     * 取消監聽 Redis 上的商品庫存鍵。
     *
     * @param int $itemId
     * @return void
     */
    public function unwatchStock(int $itemId): void
    {
        Redis::unwatch(); // unwatch is a global command, affects all keys watched by current connection
    }

    /**
     * 原子性地遞減 Redis 中的商品庫存。
     *
     * @param int $itemId
     * @param int $quantity
     * @return array|bool|null 返回 Redis EXEC 的結果，null 表示事務被取消 (WATCH 鍵被修改)，false 表示命令執行失敗
     */
    public function decrementRedisStockAtomically(int $itemId, int $quantity)
    {
        try {
            Redis::multi();
            Redis::decrby("item:{$itemId}:stock", $quantity);
            $result = Redis::exec();
            return $result;
        } catch (\Exception $e) {
            Log::error("Redis transaction failed for item {$itemId}: " . $e->getMessage());
            return false; // 代表命令執行失敗
        }
    }

    /**
     * 遞減資料庫中商品的實際庫存。
     *
     * @param int $itemId
     * @param int $quantity
     * @return bool
     */
    public function decrementDatabaseStock(int $itemId, int $quantity): bool
    {
        try {
            $item = Item::find($itemId);
            if ($item) {
                $item->decrement('stock', $quantity);
                return true;
            }
            return false; // 商品不存在
        } catch (\Exception $e) {
            Log::error("Failed to decrement database stock for item {$itemId}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 獲取分布式鎖。
     *
     * @param int $itemId
     * @param int $ttl 鎖的過期時間 (秒)
     * @return bool
     */
    public function acquireLock(int $itemId, int $ttl = 5): bool
    {
        try {
            return (bool) Redis::set("item:{$itemId}:lock", 1, 'EX', $ttl, 'NX');
        } catch (\Exception $e) {
            Log::error("Failed to acquire lock for item {$itemId}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * 釋放分布式鎖。
     *
     * @param int $itemId
     * @return void
     */
    public function releaseLock(int $itemId): void
    {
        try {
            Redis::del("item:{$itemId}:lock");
        } catch (\Exception $e) {
            Log::error("Failed to release lock for item {$itemId}: " . $e->getMessage());
            // 這裡可以考慮告警，因為鎖未能釋放可能導致死鎖
        }
    }
}