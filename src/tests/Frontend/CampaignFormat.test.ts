import { afterEach, expect, it, vi } from 'vitest';
import { calendarDate, daysUntil, dueHint } from '../../resources/js/features/campaigns/format';

afterEach(() => vi.useRealTimers());

it('shows a due date as the same calendar day in every time zone', () => {
    expect(calendarDate('2027-02-03')).toBe('3 Feb 2027');
    expect(calendarDate(null)).toBe('Not set');
    expect(calendarDate('not-a-date')).toBe('Not set');
});

it('counts whole days from the browser date and describes past due dates', () => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date(2026, 10, 28, 23, 30));
    expect(daysUntil('2026-12-15')).toBe(17);
    expect(dueHint('2026-12-15')).toBe('In 17 days');
    expect(dueHint('2026-11-29')).toBe('In 1 day');
    expect(dueHint('2026-11-28')).toBe('Due today');
    expect(dueHint('2026-11-27')).toBe('Past due');
    expect(dueHint(null)).toBe('');
});
