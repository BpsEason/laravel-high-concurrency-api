<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Item;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;

class SyncItemStock extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:sync-stock {itemId?}'; // 添加 {itemId?} 可選參數

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Synchronize item stock between database and Redis. Specify itemId to sync a specific item, or leave empty to sync all.';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $itemId = $this->argument('itemId');

        if ($itemId) {
            $item = Item::find($itemId);
            if ($item) {
                $this->syncSingleItem($item);
            } else {
                $this->error("Item with ID {$itemId} not found.");
            }
        } else {
            $this->syncAllItems();
        }

        $this->info('Stock synchronization complete.');
    }

    protected function syncSingleItem(Item $item): void
    {
        $databaseStock = $item->stock;
        $redisKey = "item:{$item->id}:stock";

        try {
            Redis::set($redisKey, $databaseStock);
            $this->info("Synced item {$item->id}: DB stock {$databaseStock} -> Redis stock updated.");
            Log::info("Item stock synchronized (single): Item {$item->id}, DB: {$databaseStock}");
        } catch (\Exception $e) {
            $this->error("Failed to sync item {$item->id}: " . $e->getMessage());
            Log::error("Failed to sync item stock (single): Item {$item->id}, Error: {$e->getMessage()}");
        }
    }

    protected function syncAllItems(): void
    {
        $items = Item::all();
        foreach ($items as $item) {
            $this->syncSingleItem($item);
        }
    }
}