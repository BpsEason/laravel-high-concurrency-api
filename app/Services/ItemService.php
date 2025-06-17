<?php

namespace App\Services;

use App\Jobs\UpdateItemStock; // 引入新創建的 Job
use App\Repositories\Contracts\ItemRepositoryInterface; // 引入介面
use App\Exceptions\StockInsufficientException; // 引入自定義異常
use App\Exceptions\RedisOperationException; // 引入自定義異常
use Illuminate\Support\Facades\Log; // 用於日誌記錄

class ItemService
{
    protected ItemRepositoryInterface $itemRepository;

    public function __construct(ItemRepositoryInterface $itemRepository)
    {
        $this->itemRepository = $itemRepository;
    }

    /**
     * 處理商品購買邏輯，包含 Redis 原子操作和異步資料庫更新。
     *
     * @param int $itemId
     * @param int $quantity
     * @return bool
     * @throws StockInsufficientException
     * @throws RedisOperationException
     */
    public function purchaseItem(int $itemId, int $quantity): bool
    {
        $lockKey = "item:{$itemId}:lock"; // 分布式鎖的 Key
        $lockAcquired = false;
        $maxRetries = 5; // 鎖獲取失敗最大重試次數
        $retryCount = 0;

        // 嘗試獲取分布式鎖，並帶有重試機制和隨機退避
        while ($retryCount < $maxRetries && !$lockAcquired) {
            if ($this->itemRepository->acquireLock($itemId, 5)) { // 鎖定 5 秒
                $lockAcquired = true;
            } else {
                $retryCount++;
                // 隨機等待一段時間，避免驚群效應 (10ms ~ 200ms)
                usleep(rand(10000, 200000));
                Log::warning("Failed to acquire lock for item {$itemId}, retrying... ({$retryCount}/{$maxRetries})");
            }
        }

        if (!$lockAcquired) {
            // 多次嘗試後仍未能獲取鎖，表示系統極度繁忙或有死鎖風險
            Log::error("Failed to acquire lock for item {$itemId} after {$maxRetries} retries.");
            throw new RedisOperationException("未能獲取商品 {$itemId} 的操作鎖，系統繁忙。請稍後再試。");
        }

        try {
            // 開始 Redis WATCH 監聽庫存，確保在讀取到執行期間庫存未被其他操作修改
            $this->itemRepository->watchStock($itemId);
            $currentStock = $this->itemRepository->getRedisStock($itemId);

            if ($currentStock === null) {
                // 如果 Redis 中沒有庫存數據，這通常表示數據初始化不完整或 Redis 快取失效
                // 在實際生產中，這裡應該有嚴格的快取初始化策略，例如：
                // 1. 商品上架時寫入 Redis
                // 2. 定時任務同步資料庫到 Redis
                // 3. 在這裡嘗試從資料庫讀取並初始化 Redis (但會增加延遲，盡量避免在熱路徑)
                // 這裡我們直接拋出異常，讓前端知道商品狀態異常或系統未準備好
                $this->itemRepository->unwatchStock($itemId); // 取消監聽
                Log::error("Redis stock data missing for item {$itemId}.");
                throw new RedisOperationException("商品 {$itemId} 庫存資料異常，請聯繫管理員。");
            }

            if ($currentStock < $quantity) {
                $this->itemRepository->unwatchStock($itemId); // 取消監聽
                Log::info("Purchase failed: Insufficient stock for item {$itemId}. Current: {$currentStock}, Requested: {$quantity}");
                throw new StockInsufficientException("商品 {$itemId} 庫存不足。", $currentStock);
            }

            // 執行 Redis 事務 (MULTI/EXEC) 原子性扣減庫存
            $transactionResult = $this->itemRepository->decrementRedisStockAtomically($itemId, $quantity);

            if ($transactionResult === false || $transactionResult === null) {
                // Redis exec 返回 false 表示 WATCH 的數據在 MULTI/EXEC 期間被修改，事務失敗
                // 返回 null 通常是 Redis 連接問題或命令執行異常
                Log::warning("Redis transaction failed or data changed during WATCH for item {$itemId}.");
                throw new RedisOperationException("購買失敗，商品 {$itemId} 庫存發生併發衝突。請重試。");
            }

            // Redis 庫存扣減成功後，異步更新資料庫
            UpdateItemStock::dispatch($itemId, $quantity);
            Log::info("Purchase successful for item {$itemId}. Redis stock updated, DB update dispatched to queue.");
            return true;

        } finally {
            // 不管成功或失敗，務必釋放鎖
            $this->itemRepository->releaseLock($itemId);
            Log::debug("Lock released for item {$itemId}.");
        }
    }
}