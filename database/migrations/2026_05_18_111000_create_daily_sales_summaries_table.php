<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_sales_summaries', function (Blueprint $table) {
            $table->id();
            $table->date('sales_date')->unique();
            $table->unsignedInteger('orders_count')->default(0);
            $table->unsignedInteger('items_sold')->default(0);
            $table->decimal('gross_sales', 12, 2)->default(0);
            $table->unsignedInteger('chunks_processed')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_sales_summaries');
    }
};
