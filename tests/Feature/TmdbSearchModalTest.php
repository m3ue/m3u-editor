<?php

use App\Livewire\TmdbSearch;
use App\Models\User;
use Livewire\Livewire;

it('renders the modal cancel action as a Filament button', function () {
    $this->actingAs(User::factory()->create());

    $html = Livewire::test(TmdbSearch::class)->html();

    expect($html)->toMatch('/<button(?=[^>]*x-on:click="\$wire\.closeModal\(\)")(?=[^>]*\bfi-btn\b)[^>]*>/');
});
