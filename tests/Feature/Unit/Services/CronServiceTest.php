<?php

use App\Services\CronService;

describe('CronService', function () {
    describe('isValid', function () {
        it('accepts valid expressions', function (string $expression) {
            expect(CronService::isValid($expression))->toBeTrue();
        })->with([
            '* * * * *',
            '0 */6 * * *',
            '0 0 * * 1',
            '0 3 * * *',
            '*/5 * * * *',
            '0 0 1 * *',
            '0 0 1 1 *',
        ]);

        it('rejects invalid expressions', function (string $expression) {
            expect(CronService::isValid($expression))->toBeFalse();
        })->with([
            '',
            'not a cron',
            '60 * * * *',
            '* * * * * *',
            '[unclosed',
        ]);
    });

    describe('describe', function () {
        it('returns every minute for * * * * *', function () {
            expect(CronService::describe('* * * * *'))->toBe('Every minute');
        });

        it('returns every N minutes for */N pattern', function () {
            expect(CronService::describe('*/5 * * * *'))->toBe('Every 5 minutes');
            expect(CronService::describe('*/15 * * * *'))->toBe('Every 15 minutes');
        });

        it('returns hourly for 0 * * * *', function () {
            expect(CronService::describe('0 * * * *'))->toBe('Hourly');
        });

        it('returns every N hours for */N hour pattern', function () {
            expect(CronService::describe('0 */6 * * *'))->toBe('Every 6 hours');
            expect(CronService::describe('30 */2 * * *'))->toBe('Every 2 hours at minute 30');
        });

        it('returns daily at time for numeric hour and minute', function () {
            expect(CronService::describe('0 3 * * *'))->toBe('Daily at 03:00');
            expect(CronService::describe('30 14 * * *'))->toBe('Daily at 14:30');
        });

        it('returns weekly with day name', function () {
            expect(CronService::describe('0 0 * * 1'))->toBe('Weekly on Monday at 00:00');
            expect(CronService::describe('0 0 * * 0'))->toBe('Weekly on Sunday at 00:00');
        });

        it('returns monthly with day', function () {
            expect(CronService::describe('0 0 1 * *'))->toBe('Monthly on day 1 at 00:00');
        });

        it('returns yearly with month and day', function () {
            expect(CronService::describe('0 0 1 1 *'))->toBe('Yearly on January 1 at 00:00');
        });

        it('returns invalid message for bad expression', function () {
            expect(CronService::describe('not valid'))->toBe('Invalid expression');
        });

        it('falls back to raw expression for complex patterns', function () {
            $expression = '0,30 9-17 * * 1-5';
            expect(CronService::describe($expression))->toBe($expression);
        });
    });

    describe('nextRuns', function () {
        it('returns 5 run dates by default', function () {
            $runs = CronService::nextRuns('0 */6 * * *');
            expect($runs)->toHaveCount(5);
        });

        it('returns requested count', function () {
            $runs = CronService::nextRuns('* * * * *', 3);
            expect($runs)->toHaveCount(3);
        });

        it('returns empty array for invalid expression', function () {
            expect(CronService::nextRuns('invalid'))->toBe([]);
        });

        it('returns formatted date strings', function () {
            $runs = CronService::nextRuns('0 0 * * *', 1);
            expect($runs[0])->toMatch('/^\w{3}, \w{3} \d{1,2} \d{4} at \d{2}:\d{2}$/');
        });
    });

    describe('presets', function () {
        it('returns an array of presets', function () {
            $presets = CronService::presets();
            expect($presets)->toBeArray()->not->toBeEmpty();
        });

        it('includes common presets', function () {
            $presets = CronService::presets();
            expect($presets)->toHaveKey('* * * * *')
                ->toHaveKey('0 * * * *')
                ->toHaveKey('0 0 * * *');
        });

        it('has valid cron expressions as keys', function () {
            foreach (array_keys(CronService::presets()) as $expression) {
                expect(CronService::isValid($expression))->toBeTrue("'$expression' should be valid");
            }
        });
    });

    describe('renderPreview', function () {
        it('renders valid expression with description and runs', function () {
            $html = CronService::renderPreview('0 * * * *');
            expect($html->toHtml())
                ->toContain('Hourly')
                ->toContain('Next 5 occurrences');
        });

        it('renders error for invalid expression', function () {
            $html = CronService::renderPreview('bad expression');
            expect($html->toHtml())
                ->toContain('Invalid expression');
        });
    });
});
