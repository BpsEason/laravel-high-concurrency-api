<?php

namespace App\Jobs;

use App\Models\Item;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class UpdateItemStock implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected int $itemId;
    protected int $quantity;

    public $tries = 3; // 最多重試 3 次
    public $backoff = 5; // 每次重試間隔 5 秒

    /**
     * Create a new job instance.
     */
    public function __construct(int $itemId, int $quantity)
    {
        $this->itemId = $itemId;
        $this->quantity = $quantity;
        $this->onQueue('stock_updates');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            $item = Item::find($this->itemId);
            if ($item) {
                if ($item->stock >= $this->quantity) {
                    $originalStock = $item->stock;
                    $item->decrement('stock', $this->quantity);
                    Log::info("Stock updated for item {$this->itemId}: decreased by {$this->quantity}, original stock: {$originalStock}, new stock: {$item->fresh()->stock}");
                } else {
                    Log::warning("Insufficient stock for item {$this->itemId}: current {$item->stock}, requested {$this->quantity}");
                }
            } else {
                Log::error("Item {$this->itemId} not found for stock update.");
            }
        } catch (\Exception $e) {
            Log::error("Failed to update stock for item {$this->itemId}: {$e->getMessage()}");
            $this->fail($e);
        }
    }
}