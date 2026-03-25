<?php

namespace Database\Seeders;

use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Seeder;

class OrderSeeder extends Seeder
{
    public function run(): void
    {
          $buyer = User::firstWhere('email', 'buyer@example.com');

         if (!$buyer) {
            $this->command->warn('Buyer user not found. Run UserSeeder first.');
            return;
        }

        Order::insert([
            [
                'user_id'    => $buyer->id,
                'symbol'     => 'BTC',
                'side'       => 'buy',
                'price'      => 95000.00,
                'amount'     => 0.01,
                'status'     => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        ]);

        $this->command->info('1 order seeded.');
    }
}