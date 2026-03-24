<?php

namespace App\Services;

use App\Settings\GeneralSettings;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use DateTimeInterface;

class DateFormatService
{
    private string $format;

    private string $timezone;

    public function __construct(GeneralSettings $settings)
    {
        $this->format = $settings->date_format ?? 'Y-m-d H:i:s';

        // Resolve the effective timezone: config('app.timezone') already reflects
        // the TZ env var (highest priority) or the user-defined setting applied
        // at boot via applyTimezoneFromSettings().
        $this->timezone = config('app.timezone', 'UTC');
    }

    /**
     * Return the configured date format string.
     */
    public function getFormat(): string
    {
        return $this->format;
    }

    /**
     * Return the configured timezone string.
     */
    public function getTimezone(): string
    {
        return $this->timezone;
    }

    /**
     * Format a date value using the configured format and timezone.
     * Dates are assumed to be stored in UTC (Laravel default). They are
     * parsed as UTC and then converted to the configured app timezone before
     * formatting, so changing the TZ setting updates all displayed dates.
     *
     * Accepts a Carbon instance, DateTimeInterface, or a date string.
     * Returns $fallback when the value is null/empty.
     */
    public function format(CarbonInterface|DateTimeInterface|string|null $date, string $fallback = 'Never'): string
    {
        if (! $date) {
            return $fallback;
        }

        if ($date instanceof CarbonInterface) {
            // Carbon instances from Eloquent casts are already UTC — just convert.
            return $date->copy()->setTimezone($this->timezone)->format($this->format);
        }

        // Plain strings / DateTimeInterface values — parse as UTC then convert.
        return Carbon::parse($date, 'UTC')->setTimezone($this->timezone)->format($this->format);
    }
}
