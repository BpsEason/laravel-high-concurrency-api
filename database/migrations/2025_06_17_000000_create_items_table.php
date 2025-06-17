<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 運行資料庫遷移。
     */
    public function up(): void
    {
        Schema::create('items', function (Blueprint $table) {
            $table->id(); // 主鍵 ID
            $table->string('name'); // 商品名稱
            $table->unsignedInteger('stock'); // 商品庫存 (非負整數)
            $table->timestamps(); // created_at 和 updated_at 時間戳
            $table->softDeletes(); // 軟刪除欄位 deleted_at
        });
    }

    /**
     * 回滾資料庫遷移。
     */
    public function down(): void
    {
        Schema::dropIfExists('items');
    }
};