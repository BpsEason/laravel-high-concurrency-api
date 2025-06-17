<?php

namespace App\Services;

use App\Jobs\UpdateItemStock;
use App\Repositories\Contracts\ItemRepositoryInterface;
use App\Exceptions\StockInsufficientException;
use App\Exceptions\RedisOperationException;
use App\Models\Item;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Config;

class ItemService
{
    protected ItemRepositoryInterface $itemRepository;

    public function __construct(ItemRepositoryInterface $itemRepository)
    {
        $this->itemRepository = $itemRepository;
    }

    public function purchaseItem(int $itemId, int $quantity): bool
    {
        $lockKey = "item:{$itemId}:lock";
        $lockAcquired = false;
        $maxRetries = Config::get('app.lock_max_retries', 5);
        $retryCount = 0;
        $userId = auth()->id() ?? 'guest';

        while ($retryCount < $maxRetries && !$lockAcquired) {
            if ($this->itemRepository->acquireLock($itemId, 5)) {
                $lockAcquired = true;
            } else {
                $retryCount++;
                if (!RateLimiter::tooManyAttempts("log:lock-failure:{$itemId}", 10, 60)) {
                    Log::warning("Failed to acquire lock for item {$itemId}", [
                        'retry' => $retryCount,
                        'max_retries' => $maxRetries,
                        'user_id' => $userId,
                    ]);
                    RateLimiter::hit("log:lock-failure:{$itemId}");
                }
                usleep(rand(
                    Config::get('app.lock_retry_delay_min', 10000),
                    Config::get('app.lock_retry_delay_max', 200000)
                ));
            }
        }

        if (!$lockAcquired) {
            Log::error("Failed to acquire lock for item {$itemId} after {$maxRetries} retries", ['user_id' => $userId]);
            throw new RedisOperationException(__('item.lock_failed', ['item_id' => $itemId]));
        }

        try {
            try {
                \Redis::ping();
            } catch (\Exception $e) {
                Log::emergency("Redis connection failed", ['error' => $e->getMessage(), 'user_id' => $userId]);
                throw new RedisOperationException(__('item.redis_connection_failed'));
            }

            $this->itemRepository->watchStock($itemId);
            $currentStock = $this->itemRepository->getRedisStock($itemId);

            if ($currentStock === null) {
                $item = Item::whereNull('deleted_at')->find($itemId);
                if (!$item) {
                    $this->itemRepository->unwatchStock($itemId);
                    Log::error("Item {$itemId} not found or soft-deleted", ['user_id' => $userId]);
                    throw new RedisOperationException(__('item.not_found_or_deleted', ['item_id' => $itemId]));
                }
                $this->itemRepository->setRedisStock($itemId, $item->stock);
                $currentStock = $item->stock;
            }

            if ($currentStock < $quantity) {
                $this->itemRepository->unwatchStock($itemId);
                Log::info("Purchase failed: Insufficient stock for item {$itemId}", [
                    'current_stock' => $currentStock,
                    'requested_quantity' => $quantity,
                    'user_id' => $userId,
                ]);
                throw new StockInsufficientException(__('item.insufficient_stock', ['item_id' => $itemId]), $currentStock);
            }

            $transactionResult = $this->itemRepository->decrementRedisStockAtomically($itemId, $quantity);

            if ($transactionResult === false || $transactionResult === null) {
                Log::warning("Redis transaction failed for item {$itemId}", ['user_id' => $userId]);
                throw new RedisOperationException(__('item.transaction_failed', ['item_id' => $itemId]));
            }

            UpdateItemStock::dispatch($itemId, $quantity);
            Log::info("Purchase successful for item {$itemId}", [
                'quantity' => $quantity,
                'user_id' => $userId,
            ]);
            return true;

        } finally {
            $this->itemRepository->releaseLock($itemId);
            Log::debug("Lock released for item {$itemId}", ['user_id' => $userId]);
        }
    }
}