<?php

use App\Services\AedAiRegexSuggestionService;

it('builds a complete prompt for the selected output language', function () {
    $service = new AedAiRegexSuggestionService;

    $prompt = $service->buildSystemPrompt('German');

    expect($prompt)
        ->toContain('German')
        ->toContain('team_delimiter')
        ->toContain('output_timezone')
        ->toContain('description_format')
        ->toContain('pre_event_format')
        ->toContain('post_event_format')
        ->toContain('no_event_format')
        ->toContain('Keep variable names exactly unchanged');
});

it('parses fenced ai json and fills missing optional fields', function () {
    $service = new AedAiRegexSuggestionService;

    $result = $service->parseResponse(<<<'JSON'
```json
{"title_regex":"^EVENT\\s*\\d+:\\s*(.+?)\\s*\\(","time_regex":"(\\d{1,2}:\\d{2})","time_format":"H:i","description_format":"{title} am {date} um {time}","pre_event_format":"Live in {time_until}: {title}","post_event_format":"Sendeschluss","category":"Sport"}
```
JSON);

    expect($result['title_regex'])->toBe('^EVENT\\s*\\d+:\\s*(.+?)\\s*\\(')
        ->and($result['time_regex'])->toBe('(\\d{1,2}:\\d{2})')
        ->and($result['description_format'])->toBe('{title} am {date} um {time}')
        ->and($result['post_event_format'])->toBe('Sendeschluss')
        ->and($result['category'])->toBe('Sport')
        ->and($result['source_timezone'])->toBe('UTC')
        ->and($result['output_timezone'])->toBe('UTC')
        ->and($result['event_duration_minutes'])->toBe(180)
        ->and($result['no_event_format'])->toBe('{channel}');
});

it('prefers structured titles in the user prompt', function () {
    $service = new AedAiRegexSuggestionService;

    $prompt = $service->buildUserPrompt([
        'NO EVENT STREAMING',
        'EVENT 01: Team A vs Team B (7.09 20:15 CET)',
    ], 'German');

    expect($prompt)
        ->toContain('Target output language: German')
        ->toContain('EVENT 01: Team A vs Team B')
        ->not->toContain('NO EVENT STREAMING');
});
