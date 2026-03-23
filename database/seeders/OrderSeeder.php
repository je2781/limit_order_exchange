<?php

namespace Database\Seeders;

use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Seeder;

class OrderSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::first();

        if (!$user) {
            $this->command->warn('No users found. Run UserSeeder first.');
            return;
        }

        Order::insert([
            [
                'user_id'    => $user->id,
                'symbol'     => 'BTC',
                'side'       => 'buy',
                'price'      => 95000.00,
                'amount'     => 0.01,
                'status'     => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'user_id'    => $user->id,
                'symbol'     => 'BTC',
                'side'       => 'sell',
                'price'      => 96000.00,
                'amount'     => 0.01,
                'status'     => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->command->info('2 orders seeded.');
    }
}