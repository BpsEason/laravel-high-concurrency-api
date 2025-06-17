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
            $table->integer('stock'); // 商品庫存 (整數)
            $table->timestamps(); // created_at 和 updated_at 時間戳
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