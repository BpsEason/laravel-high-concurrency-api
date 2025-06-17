<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Throwable;
use Illuminate\Http\JsonResponse;
use Illuminate\Auth\AuthenticationException; // 引入 AuthenticationException
use Illuminate\Validation\ValidationException; // 引入 ValidationException
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException; // 引入 404 異常

class Handler extends ExceptionHandler
{
    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });
    }

    /**
     * Render an exception into an HTTP response.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Throwable  $exception
     * @return \Symfony\Component\HttpFoundation\Response
     *
     * @throws \Throwable
     */
    public function render($request, Throwable $exception)
    {
        // 處理自定義業務異常
        if ($exception instanceof StockInsufficientException) {
            return response()->json([
                'message' => $exception->getMessage(),
                'error_code' => 'INSUFFICIENT_STOCK',
                'current_stock' => $exception->getCurrentStock()
            ], 400); // Bad Request
        }

        if ($exception instanceof RedisOperationException) {
            // 由於 Redis 操作失敗通常是併發或系統問題，返回 503 Service Unavailable
            return response()->json([
                'message' => $exception->getMessage(),
                'error_code' => 'SYSTEM_BUSY'
            ], 503);
        }

        // 處理 JWT 認證失敗
        if ($exception instanceof AuthenticationException) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // 處理驗證失敗
        if ($exception instanceof ValidationException) {
            return response()->json([
                'message' => '提供的資料無效。',
                'errors' => $exception->errors()
            ], 422);
        }

        // 處理 404 Not Found
        if ($exception instanceof NotFoundHttpException) {
            return response()->json(['message' => '資源未找到。'], 404);
        }

        // 對於其他所有異常，返回通用錯誤訊息（避免暴露敏感信息）
        if ($request->expectsJson()) {
            return response()->json([
                'message' => '伺服器內部錯誤，請稍後再試。',
                // 在開發環境可以顯示更詳細的錯誤，生產環境則關閉 app.debug
                // 'exception' => config('app.debug') ? $exception->getMessage() : null,
                // 'trace' => config('app.debug') ? $exception->getTrace() : null,
            ], 500);
        }

        return parent::render($request, $exception);
    }
}