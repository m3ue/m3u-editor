<?php

use App\Rules\ValidRegexPattern;
use Illuminate\Support\Facades\Validator;

it('passes for a valid regex pattern', function (): void {
    $validator = Validator::make(
        ['pattern' => '^(?!VOD).*$'],
        ['pattern' => [new ValidRegexPattern]],
    );

    expect($validator->passes())->toBeTrue();
});

it('fails for a pattern with a dangling quantifier', function (): void {
    $validator = Validator::make(
        ['pattern' => '^(?!*Kids).*$'],
        ['pattern' => [new ValidRegexPattern]],
    );

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->first('pattern'))->toContain('not a valid regular expression');
});

it('ignores empty values', function (): void {
    $validator = Validator::make(
        ['pattern' => ''],
        ['pattern' => [new ValidRegexPattern]],
    );

    expect($validator->passes())->toBeTrue();
});

it('fails when the invalid pattern is inside a tags array, as Filament TagsInput submits it', function (): void {
    $validator = Validator::make(
        ['patterns' => ['^(?!VOD).*$', '^(?!*Kids).*$', '^(?!SRS).*$']],
        ['patterns' => [new ValidRegexPattern]],
    );

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->first('patterns'))->toContain('^(?!*Kids).*$');
});

it('passes when every pattern in a tags array compiles', function (): void {
    $validator = Validator::make(
        ['patterns' => ['^(?!VOD).*$', '^(?!Kids).*$', '^(?!SRS).*$']],
        ['patterns' => [new ValidRegexPattern]],
    );

    expect($validator->passes())->toBeTrue();
});
