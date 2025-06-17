<?php

namespace App\Http\Controllers;

use App\Http\Requests\PurchaseItemRequest;
use App\Models\Item;
use App\Services\ItemService;
use Illuminate\Http\JsonResponse;
use App\Exceptions\StockInsufficientException;
use App\Exceptions\RedisOperationException;
use Illuminate\Support\Facades\Log;

class ItemController extends Controller
{
    protected ItemService $itemService;

    public function __construct(ItemService $itemService)
    {
        $this->itemService = $itemService;
        $this->middleware('auth:api', ['except' => ['index', 'show']]);
    }

    public function index(): JsonResponse
    {
        return response()->json(Item::all());
    }

    public function show(Item $item): JsonResponse
    {
        return response()->json($item);
    }

    public function purchase(PurchaseItemRequest $request, Item $item): JsonResponse
    {
        $quantity = $request->validated()['quantity'];

        try {
            $this->itemService->purchaseItem($item->id, $quantity);
            return response()->json(['message' => __('item.purchase_success')], 200);
        } catch (StockInsufficientException $e) {
            Log::warning("Purchase failed for item {$item->id}: " . $e->getMessage(), ['item_id' => $item->id, 'requested_quantity' => $quantity]);
            return response()->json([
                'message' => $e->getMessage(),
                'error_code' => 'INSUFFICIENT_STOCK',
                'current_stock' => $e->getCurrentStock()
            ], 400);
        } catch (RedisOperationException $e) {
            Log::error("Redis operation failed during purchase for item {$item->id}: " . $e->getMessage(), ['item_id' => $item->id, 'requested_quantity' => $quantity]);
            return response()->json([
                'message' => $e->getMessage(), // 保留原始訊息以區分“併發衝突”或“商品不存在”
                'error_code' => 'SYSTEM_BUSY'
            ], 422); // 改為 422，與 ItemPurchaseTest.php 對齊
        } catch (\Exception $e) {
            Log::error("Unexpected error during purchase for item {$item->id}: " . $e->getMessage(), ['item_id' => $item->id, 'exception' => $e]);
            return response()->json([
                'message' => __('item.purchase_failed'),
                'error_code' => 'UNKNOWN_ERROR'
            ], 500);
        }
    }
}