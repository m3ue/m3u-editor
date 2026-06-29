<?php

use App\Models\AedProfile;
use App\Services\AedEvent;
use App\Services\AedExtractorService;
use Carbon\Carbon;

beforeEach(function () {
    Carbon::setTestNow(Carbon::create(2026, 6, 29, 10, 0, 0, 'UTC'));
});

afterEach(function () {
    Carbon::setTestNow();
});

function makeProfile(array $attrs = []): AedProfile
{
    $profile = new AedProfile;
    $profile->title_regex = $attrs['title_regex'] ?? null;
    $profile->time_regex = $attrs['time_regex'] ?? null;
    $profile->time_format = $attrs['time_format'] ?? null;
    $profile->source_timezone = $attrs['source_timezone'] ?? 'UTC';
    $profile->date_regex = $attrs['date_regex'] ?? null;
    $profile->date_format = $attrs['date_format'] ?? null;
    $profile->team_delimiter = $attrs['team_delimiter'] ?? null;
    $profile->logo_url = $attrs['logo_url'] ?? null;
    $profile->output_timezone = $attrs['output_timezone'] ?? 'UTC';
    $profile->event_duration_minutes = $attrs['event_duration_minutes'] ?? 180;
    $profile->title_format = $attrs['title_format'] ?? '{title}';
    $profile->description_format = $attrs['description_format'] ?? null;
    $profile->no_event_format = $attrs['no_event_format'] ?? '{channel}';
    $profile->category = $attrs['category'] ?? null;

    return $profile;
}

test('extracts title using title_regex capture group', function () {
    $profile = makeProfile([
        'title_regex' => '^(.*?)\s*\[DAZN\]',
    ]);
    $service = new AedExtractorService;
    $result = $service->extract($profile, 'Tommy Fury vs. Eddie Hall [DAZN] (06.13 13:00 ET)');

    expect($result)->toBeInstanceOf(AedEvent::class)
        ->and($result->title)->toBe('Tommy Fury vs. Eddie Hall');
});

test('returns null when title_regex does not match', function () {
    $profile = makeProfile([
        'title_regex' => '^\[ESPN\](.*)',
    ]);
    $service = new AedExtractorService;
    $result = $service->extract($profile, 'Tommy Fury vs. Eddie Hall [DAZN] (06.13 13:00 ET)');

    expect($result)->toBeNull();
});

test('uses full channel title when title_regex is empty', function () {
    $profile = makeProfile();
    $service = new AedExtractorService;
    $result = $service->extract($profile, 'My Channel');

    expect($result)->not->toBeNull()
        ->and($result->title)->toBe('My Channel');
});

test('extracts start time with time_regex and time_format', function () {
    $profile = makeProfile([
        'title_regex' => '^(.*?)\s*\[DAZN\]',
        'time_regex' => '\((\d{2}\.\d{2})\s+(\d{1,2}:\d{2}\s*[AP]M)\s+ET',
        'time_format' => 'm.d g:i A',
        'source_timezone' => 'America/New_York',
        'output_timezone' => 'UTC',
    ]);

    // The combined capture won't work with two groups easily; test simpler case
    $profile->time_regex = '(\d{1,2}:\d{2}\s*[AP]M)\s+ET';
    $profile->date_regex = '\((\d{2}\.\d{2})';
    $profile->date_format = 'm.d';
    $profile->time_format = 'g:i A';

    $service = new AedExtractorService;
    $result = $service->extract($profile, 'Fury vs Hall [DAZN] (06.29 2:00 PM ET)');

    expect($result)->not->toBeNull()
        ->and($result->hasTime())->toBeTrue()
        ->and($result->start)->not->toBeNull()
        ->and($result->start->timezone->getName())->toBe('UTC')
        // 2:00 PM ET = 18:00 UTC
        ->and($result->start->hour)->toBe(18);
});

test('hasTime returns false when time extraction fails', function () {
    $profile = makeProfile([
        'title_regex' => '^(.*?)\s*\[DAZN\]',
        'time_regex' => '\[NOTIME\](\d+)',
        'time_format' => 'H:i',
        'source_timezone' => 'UTC',
    ]);
    $service = new AedExtractorService;
    $result = $service->extract($profile, 'Some Event [DAZN]');

    expect($result)->not->toBeNull()
        ->and($result->hasTime())->toBeFalse();
});

test('end time is start time + event_duration_minutes', function () {
    $profile = makeProfile([
        'title_regex' => '^(.*?)\s*\[',
        'time_regex' => '(\d{1,2}:\d{2}\s*[AP]M)',
        'time_format' => 'g:i A',
        'source_timezone' => 'UTC',
        'output_timezone' => 'UTC',
        'event_duration_minutes' => 120,
    ]);
    $service = new AedExtractorService;
    $result = $service->extract($profile, 'My Event [Sport] 3:00 PM');

    expect($result?->hasTime())->toBeTrue()
        ->and($result->start->diffInMinutes($result->end))->toEqual(120);
});

test('formats title_format template with {title} variable', function () {
    $profile = makeProfile([
        'title_regex' => '^(.*?)\s*\[',
        'title_format' => 'LIVE: {title}',
    ]);
    $service = new AedExtractorService;
    $result = $service->extract($profile, 'Fury vs Hall [DAZN]');

    expect($result->title)->toBe('LIVE: Fury vs Hall');
});

test('fallback returns no_event_format with {channel} placeholder', function () {
    $profile = makeProfile([
        'no_event_format' => 'Off Air - {channel}',
    ]);
    $service = new AedExtractorService;
    $result = $service->fallback($profile, 'PPV Channel 1');

    expect($result)->toBeInstanceOf(AedEvent::class)
        ->and($result->title)->toBe('Off Air - PPV Channel 1')
        ->and($result->hasTime())->toBeFalse();
});

test('fallback uses channel title when no_event_format is null', function () {
    $profile = makeProfile(['no_event_format' => null]);
    $service = new AedExtractorService;
    $result = $service->fallback($profile, 'My Sports Channel');

    expect($result->title)->toBe('My Sports Channel');
});

test('handles invalid regex without crashing', function () {
    $profile = makeProfile([
        'title_regex' => '(unclosed bracket [invalid',
    ]);
    $service = new AedExtractorService;
    $result = $service->extract($profile, 'Any Channel Title');

    expect($result)->toBeNull();
});

test('converts source timezone to output timezone', function () {
    $profile = makeProfile([
        'time_regex' => '(\d{1,2}:\d{2}\s*[AP]M)',
        'time_format' => 'g:i A',
        'source_timezone' => 'America/Chicago',
        'output_timezone' => 'America/New_York',
    ]);
    $service = new AedExtractorService;
    $result = $service->extract($profile, 'Event at 1:00 PM');

    expect($result?->hasTime())->toBeTrue()
        // 1:00 PM CT = 2:00 PM ET
        ->and($result->start->hour)->toBe(14)
        ->and($result->start->timezone->getName())->toBe('America/New_York');
});
