<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Support\Facades\Log; // 用於日誌記錄

class RedisOperationException extends Exception
{
    /**
     * 將異常報告到日誌。
     *
     * @return void
     */
    public function report(): void
    {
        Log::error('Redis Operation Exception: ' . $this->getMessage(), [
            'file' => $this->getFile(),
            'line' => $this->getLine(),
        ]);
    }
}