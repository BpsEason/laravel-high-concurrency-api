<?php

namespace App\Http\Controllers;

use App\Http\Requests\PurchaseItemRequest;
use App\Models\Item;
use App\Services\ItemService;
use Illuminate\Http\JsonResponse;
use App\Exceptions\StockInsufficientException;
use App\Exceptions\RedisOperationException;
use Illuminate\Support\Facades\Log; // 用於日誌記錄

class ItemController extends Controller
{
    protected ItemService $itemService;

    public function __construct(ItemService $itemService)
    {
        $this->itemService = $itemService;
        $this->middleware('auth:api', ['except' => ['index', 'show']]);
        // 對於高併發搶購介面，通常會加上限流，例如：
        // $this->middleware('throttle:60,1')->only('purchase'); // 每分鐘 60 次請求
    }

    /**
     * Display a listing of the resource.
     */
    public function index(): JsonResponse
    {
        // 對於頻繁讀取且不常變動的資料，可以考慮快取
        // return \Illuminate\Support\Facades\Cache::remember('all_items', now()->addMinutes(10), function () {
        //     return Item::all();
        // });
        return response()->json(Item::all());
    }

    /**
     * Display the specified resource.
     */
    public function show(Item $item): JsonResponse
    {
        return response()->json($item);
    }

    /**
     * Handle item purchase.
     *
     * @param  \App\Http\Requests\PurchaseItemRequest  $request
     * @param  \App\Models\Item  $item
     * @return \Illuminate\Http\JsonResponse
     */
    public function purchase(PurchaseItemRequest $request, Item $item): JsonResponse
    {
        $quantity = $request->validated()['quantity'];

        try {
            $this->itemService->purchaseItem($item->id, $quantity);
            return response()->json(['message' => 'Purchase successful!'], 200); // 成功狀態碼 200 OK
        } catch (StockInsufficientException $e) {
            // 庫存不足的業務錯誤
            Log::warning("Purchase failed for item {$item->id}: " . $e->getMessage(), ['item_id' => $item->id, 'requested_quantity' => $quantity]);
            return response()->json([
                'message' => $e->getMessage(),
                'error_code' => 'INSUFFICIENT_STOCK',
                'current_stock' => $e->getCurrentStock() // 返回當前庫存，方便前端展示
            ], 400); // 400 Bad Request 或 409 Conflict
        } catch (RedisOperationException $e) {
            // Redis 操作失敗，通常是併發衝突或系統忙碌
            Log::error("Redis operation failed during purchase for item {$item->id}: " . $e->getMessage(), ['item_id' => $item->id, 'requested_quantity' => $quantity]);
            return response()->json([
                'message' => '系統繁忙，請稍後再試。',
                'error_code' => 'SYSTEM_BUSY'
            ], 503); // 503 Service Unavailable
        } catch (\Exception $e) {
            // 其他未知錯誤
            Log::error("An unexpected error occurred during purchase for item {$item->id}: " . $e->getMessage(), ['item_id' => $item->id, 'exception' => $e]);
            return response()->json([
                'message' => '購買失敗，發生未知錯誤。',
                'error_code' => 'UNKNOWN_ERROR'
            ], 500); // 500 Internal Server Error
        }
    }
}