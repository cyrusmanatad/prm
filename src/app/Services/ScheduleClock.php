<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use DateTimeZone;

/** A weekly wall-clock occurrence has one UTC identity, including DST folds. */
class ScheduleClock
{
    public function nextWeekly(string $timezone, int $weekday, string $time, CarbonImmutable $after): CarbonImmutable
    {
        $zone = new DateTimeZone($timezone);
        $date = $after->setTimezone($zone)->startOfDay()->format('Y-m-d');
        for ($day = 0; $day < 15; $day++) {
            $wall = CarbonImmutable::parse($date.' '.$time.':00', 'UTC')->addDays($day);
            if ($wall->isoWeekday() !== $weekday) {
                continue;
            }
            $offsets = array_unique(array_column($zone->getTransitions($wall->timestamp - 172800, $wall->timestamp + 172800), 'offset'));
            $candidates = [];
            foreach ($offsets as $offset) {
                $instant = $wall->subSeconds($offset);
                if ($instant->setTimezone($zone)->format('Y-m-d H:i:s') === $wall->format('Y-m-d H:i:s')) {
                    $candidates[] = $instant->timestamp;
                }
            }
            // Skip nonexistent spring times; choose the first instant of a repeated hour.
            if ($candidates && min($candidates) > $after->timestamp) {
                return CarbonImmutable::createFromTimestampUTC(min($candidates));
            }
        }
        throw new \LogicException('No weekly occurrence found.');
    }
}
