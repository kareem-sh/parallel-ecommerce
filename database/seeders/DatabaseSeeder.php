<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // ── Auth ─────────────────────────────────────────────
        User::updateOrCreate(
            ['email' => 'test@example.com'],
            ['name' => 'Test User', 'password' => bcrypt('password')]
        );

        // ── Domain data (order matters — FK dependencies) ────
        $this->call([
            ProductSeeder::class,
            OrderSeeder::class,
            DailySalesSummarySeeder::class,
        ]);
    }
}
