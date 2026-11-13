<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        if (! config('portal.demo') || ! app()->environment('local')) {
            throw new \RuntimeException('Demo seeding requires a local environment and PORTAL_DEMO=true.');
        }
        $this->call(DemoSeeder::class);
    }
}
