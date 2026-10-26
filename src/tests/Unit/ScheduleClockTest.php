<?php

namespace Tests\Unit;

use App\Services\ScheduleClock;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class ScheduleClockTest extends TestCase
{
    public function test_weekly_clock_preserves_local_time_across_dst_and_fractional_offsets(): void
    {
        $clock = new ScheduleClock;
        foreach ([
            ['America/New_York', 1, '09:00', '2026-03-02T15:00:00Z', '2026-03-09T13:00:00Z'],
            ['America/New_York', 1, '09:00', '2026-10-26T14:00:00Z', '2026-11-02T14:00:00Z'],
            ['Australia/Sydney', 1, '09:00', '2026-10-01T00:00:00Z', '2026-10-04T22:00:00Z'],
            ['Asia/Kathmandu', 1, '09:00', '2026-10-25T00:00:00Z', '2026-10-26T03:15:00Z'],
            ['UTC', 1, '09:00', '2026-10-26T09:00:00Z', '2026-11-02T09:00:00Z'],
            // US and half-hour Australian spring gaps skip that weekly occurrence.
            ['America/New_York', 7, '02:30', '2026-03-02T00:00:00Z', '2026-03-15T06:30:00Z'],
            ['Australia/Lord_Howe', 7, '02:15', '2026-10-01T00:00:00Z', '2026-10-10T15:15:00Z'],
            // Fall fold picks the earlier instant and never schedules its second copy.
            ['America/New_York', 7, '01:30', '2026-10-26T00:00:00Z', '2026-11-01T05:30:00Z'],
            ['America/New_York', 7, '01:30', '2026-11-01T05:45:00Z', '2026-11-08T06:30:00Z'],
        ] as [$zone, $day, $time, $after, $expected]) {
            $this->assertSame($expected, $clock->nextWeekly($zone, $day, $time, CarbonImmutable::parse($after))->format('Y-m-d\TH:i:s\Z'), $zone.' '.$after);
        }
    }
}
