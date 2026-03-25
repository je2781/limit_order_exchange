<?php

namespace Database\Seeders;

use App\Models\Asset;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class AssetSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $seller = User::firstWhere('email', 'seller@example.com');

         if (!$seller) {
            $this->command->warn('Seller user not found. Run UserSeeder first.');
            return;
        }   


        Asset::insert([
            [
                'user_id'       => $seller->id,
                'symbol'        => 'BTC',
                'amount'        => 1.50000000,
                'locked_amount' => 0.00000000,
                'created_at'    => now(),
                'updated_at'    => now(),
            ],
            [
                'user_id'       => $seller->id,
                'symbol'        => 'ETH',
                'amount'        => 10.00000000,
                'locked_amount' => 0.00000000,
                'created_at'    => now(),
                'updated_at'    => now(),
            ],
            [
                'user_id'       => $seller->id,
                'symbol'        => 'SOL',
                'amount'        => 50.00000000,
                'locked_amount' => 0.00000000,
                'created_at'    => now(),
                'updated_at'    => now(),
            ],
            [
                'user_id'       => $seller->id,
                'symbol'        => 'XRP',
                'amount'        => 300.00000000,
                'locked_amount' => 0.00000000,
                'created_at'    => now(),
                'updated_at'    => now(),
            ],
        ]);

        $this->command->info('3 assets seeded.');
    }
}
