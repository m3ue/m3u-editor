<?php

namespace Database\Factories;

use App\Models\CachedContentActivityLease;
use App\Models\MediaServerIntegration;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CachedContentActivityLease>
 */
class CachedContentActivityLeaseFactory extends Factory
{
    public function definition(): array
    {
        $user = User::factory();

        return [
            'user_id' => $user,
            'integration_id' => MediaServerIntegration::factory()->for($user),
            'mapping_uuid' => fake()->uuid(),
            'revision' => hash('sha256', fake()->uuid()),
            'canonical_id' => 'episode:source:'.fake()->uuid(),
            'variant_key' => 'unknown',
            'kind' => 'playback',
            'lease_id' => fake()->uuid(),
            'sequence' => 1,
            'expires_at' => now()->addSeconds(120),
            'last_activity_at' => now(),
        ];
    }
}
