<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    // Intentionally does NOT use WithoutModelEvents: every UUID-keyed model
    // in this app (User, Feature, ...) relies on the `creating` model event
    // (see App\Models\Concerns\HasUuid) to assign its primary key, so
    // suppressing model events here would leave `id` unset and fail on
    // insert.

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        $this->call(FeatureSeeder::class);

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);
    }
}
