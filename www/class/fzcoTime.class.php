<?php

declare(strict_types=1);

function fzcoDisplayTimezoneName(): string
{
    $timezone = trim((string) (getenv('FZOC_TIMEZONE') ?: 'Europe/Paris'));

    try {
        new DateTimeZone($timezone);
        return $timezone;
    } catch (Throwable $e) {
        return 'Europe/Paris';
    }
}

function fzcoDisplayTimezone(): DateTimeZone
{
    static $timezone = null;

    if ($timezone instanceof DateTimeZone) {
        return $timezone;
    }

    $timezone = new DateTimeZone(fzcoDisplayTimezoneName());

    return $timezone;
}

function fzcoUtcTimezone(): DateTimeZone
{
    static $timezone = null;

    if ($timezone instanceof DateTimeZone) {
        return $timezone;
    }

    $timezone = new DateTimeZone('UTC');

    return $timezone;
}

function fzcoUtcToDisplayTime(
    ?string $value,
    string $format = 'Y-m-d H:i:s'
): ?string {
    if ($value === null || trim($value) === '') {
        return $value;
    }

    try {
        $date = new DateTimeImmutable($value, fzcoUtcTimezone());

        return $date
            ->setTimezone(fzcoDisplayTimezone())
            ->format($format);
    } catch (Throwable $e) {
        return $value;
    }
}

function fzcoDisplayNowIso(): string
{
    return (new DateTimeImmutable('now', fzcoDisplayTimezone()))
        ->format(DATE_ATOM);
}

function fzcoLocalMonthStartUtc(): string
{
    $localNow = new DateTimeImmutable('now', fzcoDisplayTimezone());
    $localStart = $localNow->modify('first day of this month')->setTime(0, 0, 0);

    return $localStart
        ->setTimezone(fzcoUtcTimezone())
        ->format('Y-m-d H:i:s');
}
