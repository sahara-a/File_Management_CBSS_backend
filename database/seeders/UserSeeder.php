<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'usertwo@test.com'], // unique key
            [
                'name' => 'Admin',
                'password' => Hash::make('Password@123'),
                'is_active' => true,
            ]
        );
    }
}
