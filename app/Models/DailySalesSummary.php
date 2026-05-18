<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DailySalesSummary extends Model
{
    protected $fillable = [
        'sales_date',
        'orders_count',
        'items_sold',
        'gross_sales',
        'chunks_processed',
    ];

    protected function casts(): array
    {
        return [
            'sales_date' => 'date',
            'orders_count' => 'integer',
            'items_sold' => 'integer',
            'gross_sales' => 'decimal:2',
            'chunks_processed' => 'integer',
        ];
    }
}
