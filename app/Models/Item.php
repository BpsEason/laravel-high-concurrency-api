<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Item extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['name', 'stock'];

    /**
     * 確保庫存值不為負數。
     *
     * @param int $value
     * @return void
     */
    public function setStockAttribute(int $value): void
    {
        $this->attributes['stock'] = max(0, $value);
    }
}