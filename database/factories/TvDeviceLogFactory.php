<?php

namespace Database\Factories;

use App\Models\TvDevice;
use App\Models\TvDeviceLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TvDeviceLog>
 */
class TvDeviceLogFactory extends Factory
{
    public function definition(): array
    {
        $content = "M3U TV 1.1.4\n---\n[12:00:00.000] [INFO] ".fake()->sentence();

        return [
            'tv_device_id' => TvDevice::factory(),
            'app_version' => '1.1.4',
            'size_bytes' => strlen($content),
            'content' => $content,
        ];
    }
}
