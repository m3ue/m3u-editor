<?php

namespace App\Plugins\Support;

use App\Models\Plugin;
use App\Models\User;
use Illuminate\Support\Arr;

class PluginSelectOptionsContext
{
    /**
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $field
     */
    public function __construct(
        public readonly Plugin $plugin,
        public readonly ?User $user,
        public readonly array $settings,
        public readonly array $state,
        public readonly array $field,
    ) {}

    public function value(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $this->state)) {
            return $this->state[$key];
        }

        return Arr::get($this->state, $key, Arr::get($this->settings, $key, $default));
    }
}
