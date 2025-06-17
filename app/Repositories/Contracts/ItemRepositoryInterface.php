<?php

namespace App\Repositories\Contracts;

interface ItemRepositoryInterface
{
    /**
     * 從 Redis 獲取商品庫存。
     *
     * @param int $itemId
     * @return int|null null 表示 Redis 中不存在此商品的庫存鍵
     */
    public function getRedisStock(int $itemId): ?int;

    /**
     * 設定 Redis 中的商品庫存。
     *
     * @param int $itemId
     * @param int $stock
     * @return bool
     */
    public function setRedisStock(int $itemId, int $stock): bool;

    /**
     * 開始監聽 Redis 上的商品庫存鍵，用於 WATCH/MULTI/EXEC 事務。
     *
     * @param int $itemId
     * @return void
     */
    public function watchStock(int $itemId): void;

    /**
     * 取消監聽 Redis 上的商品庫存鍵。
     *
     * @param int $itemId
     * @return void
     */
    public function unwatchStock(int $itemId): void;

    /**
     * 原子性地遞減 Redis 中的商品庫存。
     * 使用 Redis MULTI/EXEC 事務確保操作原子性。
     *
     * @param int $itemId
     * @param int $quantity
     * @return array|bool|null 返回 Redis EXEC 的結果，null 表示事務被取消 (WATCH 鍵被修改)，false 表示命令執行失敗
     */
    public function decrementRedisStockAtomically(int $itemId, int $quantity);

    /**
     * 遞減資料庫中商品的實際庫存。
     *
     * @param int $itemId
     * @param int $quantity
     * @return bool
     */
    public function decrementDatabaseStock(int $itemId, int $quantity): bool;

    /**
     * 獲取分布式鎖。
     *
     * @param int $itemId
     * @param int $ttl 鎖的過期時間 (秒)
     * @return bool
     */
    public function acquireLock(int $itemId, int $ttl = 5): bool;

    /**
     * 釋放分布式鎖。
     *
     * @param int $itemId
     * @return void
     */
    public function releaseLock(int $itemId): void;
}