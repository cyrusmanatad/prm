import { expect, it } from 'vitest';
import { dateTime, localToIso, notificationStates, query } from '../../resources/js/features/admin/format';

it('formats timestamps with an explicit zone and never invents a missing one', () => {
    const text = dateTime('2026-11-16T09:30:00+00:00');
    expect(text).toMatch(/16 Nov 2026/);
    expect(text).toMatch(/[A-Z]{2,5}|GMT|UTC/);
    expect(dateTime(null)).toBe('Not recorded');
    expect(dateTime('not a date', 'Unknown')).toBe('Unknown');
});

it('converts local date-time input to an ISO instant and leaves invalid input for server validation', () => {
    expect(localToIso('2026-11-16T09:30')).toBe(new Date('2026-11-16T09:30').toISOString());
    expect(localToIso('')).toBe('');
    expect(localToIso('nonsense')).toBe('nonsense');
});

it('builds list URLs that omit empty filters and the first page', () => {
    expect(query({ q: '', state: 'uncertain', page: 1 })).toBe('?state=uncertain');
    expect(query({ q: 'Cedar Studio', page: 3 })).toBe('?q=Cedar+Studio&page=3');
    expect(query({})).toBe('');
});

it('labels every stored outbox state without claiming recipient delivery', () => {
    expect(Object.keys(notificationStates)).toEqual([
        'pending',
        'sending',
        'sent',
        'captured',
        'cancelled',
        'uncertain',
    ]);
    expect(notificationStates.sent!.label).toBe('Accepted by transport');
    expect(Object.values(notificationStates).some((state) => /delivered/i.test(state.label))).toBe(false);
});
