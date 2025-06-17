<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Support\Facades\Log; // 用於日誌記錄

class StockInsufficientException extends Exception
{
    protected int $currentStock;

    public function __construct(string $message = "庫存不足。", int $currentStock = 0, int $code = 0, Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->currentStock = $currentStock;
    }

    public function getCurrentStock(): int
    {
        return $this->currentStock;
    }

    /**
     * 將異常報告到日誌。
     *
     * @return void
     */
    public function report(): void
    {
        Log::warning('Stock Insufficient Exception: ' . $this->getMessage(), [
            'current_stock' => $this->currentStock,
            'file' => $this->getFile(),
            'line' => $this->getLine(),
        ]);
    }
}