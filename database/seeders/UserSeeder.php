<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        User::firstOrCreate(
            ['email' => 'seller@example.com'],
            [
                'name'              => 'Seller',
                'password'          => Hash::make('pass'),
                'email_verified_at' => now(),
                'balance'           => 1000000.00,
            ]
        );

        User::firstOrCreate(
            ['email' => 'buyer@example.com'],
            [
                'name'              => 'Buyer',
                'password'          => Hash::make('public'),
                'email_verified_at' => now(),
                'balance'           => 600000.00,
            ]
        );

        $this->command->info('Admin and public user seeded.');
        
    }
}
