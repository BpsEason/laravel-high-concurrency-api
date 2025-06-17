<?php

namespace App\Jobs;

use App\Models\Item;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log; // 用於日誌記錄

class UpdateItemStock implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected int $itemId;
    protected int $quantity;

    /**
     * Create a new job instance.
     */
    public function __construct(int $itemId, int $quantity)
    {
        $this->itemId = $itemId;
        $this->quantity = $quantity;
        $this->onQueue('stock_updates'); // 可以指定佇列名稱，例如 'stock_updates'
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            $item = Item::find($this->itemId);
            if ($item) {
                // 確保庫存不會變成負數（雖然Redis層已經檢查過，但DB層再做一次防護）
                if ($item->stock >= $this->quantity) {
                    $item->decrement('stock', $this->quantity);
                    Log::info("Job executed: Successfully decremented database stock for item {$this->itemId} by {$this->quantity}. New stock: {$item->fresh()->stock}");
                } else {
                    Log::warning("Job failed: Database stock for item {$this->itemId} is already too low ({$item->stock}) to decrement by {$this->quantity}. Stock might be inconsistent.");
                    // 在這裡可以觸發告警或額外的數據校準邏輯
                }
            } else {
                Log::error("Job failed: Item {$this->itemId} not found in database for stock update.");
            }
        } catch (\Exception $e) {
            Log::error("Job failed: Error updating database stock for item {$this->itemId} by {$this->quantity}: " . $e->getMessage(), ['exception' => $e]);
            // 將 Job 標記為失敗，它會被重新嘗試（根據設定的重試次數）
            $this->fail($e);
        }
    }
}