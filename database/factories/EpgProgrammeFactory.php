<?php

namespace Database\Factories;

use App\Models\Epg;
use App\Models\EpgProgramme;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EpgProgramme>
 */
class EpgProgrammeFactory extends Factory
{
    protected $model = EpgProgramme::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = fake()->dateTimeBetween('now', '+7 days');
        $end = (clone $start)->modify('+1 hour');

        return [
            'epg_id' => Epg::factory(),
            'epg_channel_id' => 'channel.'.fake()->numerify('###'),
            'title' => fake()->words(3, true),
            'subtitle' => fake()->optional()->sentence(4),
            'description' => fake()->optional()->paragraph(),
            'category' => fake()->optional()->randomElement(['Drama', 'Comedy', 'News', 'Sports', 'Documentary']),
            'start_time' => $start,
            'end_time' => $end,
            'episode_num' => null,
            'season' => fake()->optional()->numberBetween(1, 20),
            'episode' => fake()->optional()->numberBetween(1, 24),
            'is_new' => false,
            'icon' => null,
            'rating' => fake()->optional()->randomElement(['TV-G', 'TV-PG', 'TV-14', 'TV-MA']),
        ];
    }

    public function isNew(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_new' => true,
        ]);
    }

    public function withEpisode(int $season, int $episode): static
    {
        return $this->state(fn (array $attributes) => [
            'season' => $season,
            'episode' => $episode,
            'episode_num' => sprintf('S%02dE%02d', $season, $episode),
        ]);
    }

    public function upcoming(int $minutesFromNow = 15): static
    {
        return $this->state(function (array $attributes) use ($minutesFromNow) {
            $start = now()->addMinutes($minutesFromNow);
            $end = (clone $start)->addHour();

            return [
                'start_time' => $start,
                'end_time' => $end,
            ];
        });
    }
}
