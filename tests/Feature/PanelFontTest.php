<?php

it('uses the bundled Plus Jakarta Sans font without a font CDN', function () {
    $this->get(route('filament.admin.auth.login'))
        ->assertOk()
        ->assertSee("--font-family: 'Plus Jakarta Sans'", false)
        ->assertDontSee('fonts.bunny.net', false)
        ->assertDontSee('fonts.googleapis.com', false);
});

it('sizes the expanded sidebar to fit its navigation labels', function () {
    $this->get(route('filament.admin.auth.login'))
        ->assertOk()
        ->assertSee('--sidebar-width: 16rem', false);
});
