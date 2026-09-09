<?php

use App\Services\ChannelMatchResolver;

beforeEach(function () {
    $this->resolver = new ChannelMatchResolver;
});

/**
 * @param  array<string, mixed>  $attributes
 */
function row(int $id, array $attributes = []): object
{
    return (object) array_merge([
        'id' => $id,
        'name' => null,
        'name_custom' => null,
        'title' => null,
        'title_custom' => null,
        'stream_id' => null,
        'stream_id_custom' => null,
    ], $attributes);
}

it('normalizes case, surrounding and internal whitespace', function () {
    expect($this->resolver->normalize('  Zee   TV '))->toBe('zee tv')
        ->and($this->resolver->normalize('ZEE TV'))->toBe('zee tv')
        ->and($this->resolver->normalize(''))->toBeNull()
        ->and($this->resolver->normalize(null))->toBeNull();
});

it('matches on a unique shared tvg id', function () {
    $source = [row(1, ['stream_id' => '945', 'name' => 'Zee TV'])];
    $target = [row(10, ['stream_id' => '945', 'name' => 'Zee Television'])];

    $result = $this->resolver->resolve($source, $target);

    expect($result['matched'])->toHaveKey(1)
        ->and($result['matched'][1]['target_id'])->toBe(10)
        ->and($result['matched'][1]['pass'])->toBe(ChannelMatchResolver::PASS_TVG_ID);
});

it('falls through to name then title when the tvg id is empty', function () {
    $source = [row(1, ['stream_id' => '', 'name' => 'Zee TV', 'title' => 'Zee'])];
    $target = [row(10, ['stream_id' => null, 'name' => 'ZEE TV', 'title' => 'Something Else'])];

    $result = $this->resolver->resolve($source, $target);

    expect($result['matched'][1]['target_id'])->toBe(10)
        ->and($result['matched'][1]['pass'])->toBe(ChannelMatchResolver::PASS_NAME);
});

it('reports a source row as ambiguous when the target side has colliding keys', function () {
    $source = [row(1, ['name' => 'Sports'])];
    $target = [row(10, ['name' => 'Sports']), row(11, ['name' => 'SPORTS'])];

    $result = $this->resolver->resolve($source, $target, [ChannelMatchResolver::PASS_NAME]);

    expect($result['matched'])->toBeEmpty()
        ->and($result['ambiguous'])->toHaveKey(1)
        ->and($result['ambiguous'][1]['target_ids'])->toEqualCanonicalizing([10, 11]);
});

it('does not match when the source side has colliding keys', function () {
    $source = [row(1, ['name' => 'News']), row(2, ['name' => 'news'])];
    $target = [row(10, ['name' => 'News'])];

    $result = $this->resolver->resolve($source, $target, [ChannelMatchResolver::PASS_NAME]);

    expect($result['matched'])->toBeEmpty()
        ->and($result['unmatched_source'])->toEqualCanonicalizing([1, 2]);
});

it('never assigns two source rows to the same target row', function () {
    $source = [row(1, ['stream_id' => '945', 'name' => 'A']), row(2, ['name' => 'zee'])];
    $target = [row(10, ['stream_id' => '945', 'name' => 'Zee'])];

    $result = $this->resolver->resolve($source, $target);

    expect($result['matched'])->toHaveKey(1)
        ->and($result['matched'])->not->toHaveKey(2)
        ->and($result['unmatched_source'])->toContain(2);
});

it('lists unmatched target rows', function () {
    $source = [row(1, ['stream_id' => '945'])];
    $target = [row(10, ['stream_id' => '945']), row(11, ['stream_id' => '999'])];

    $result = $this->resolver->resolve($source, $target);

    expect($result['unmatched_target'])->toBe([11]);
});
