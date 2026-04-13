<?php

namespace Database\Factories;

use App\Models\StreamProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class StreamProfileFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = StreamProfile::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->name(),
            'description' => fake()->text(),
            'args' => '-i {input_url} -c:v libx264 -preset faster -crf 23 -f mpegts {output_args|pipe:1}',
            'backend' => 'ffmpeg',
            'format' => 'ts',
        ];
    }

    /**
     * A Streamlink resolver profile.
     */
    public function streamlink(string $quality = 'best', string $format = 'ts'): static
    {
        return $this->state(fn (array $attributes) => [
            'backend' => 'streamlink',
            'args' => $quality,
            'format' => $format,
        ]);
    }

    /**
     * A yt-dlp resolver profile.
     */
    public function ytdlp(string $formatSelector = 'bestvideo+bestaudio/best', string $format = 'ts'): static
    {
        return $this->state(fn (array $attributes) => [
            'backend' => 'ytdlp',
            'args' => $formatSelector,
            'format' => $format,
        ]);
    }
}
