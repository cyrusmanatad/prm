import { describe, expect, it } from 'vitest';
import { activityGroup, byDay, changeText, dayLabel } from '../../resources/js/features/pipeline/activity';

const label = (value: string) =>
    ({ pitched: 'Pitched', negotiating: 'Negotiating', 'repeat-client': 'Repeat Client' })[value] ?? value;
const item = (kind: string, body: string, occurred_at = '2026-11-21T04:00:00Z') => ({
    id: kind,
    kind,
    body,
    occurred_at,
});

describe('lead activity presentation', () => {
    it('separates logged conversations from automatic changes', () => {
        expect(['note', 'call', 'email', 'chat', 'reply'].map(activityGroup)).toEqual(Array(5).fill('conversation'));
        expect(['created', 'updated', 'stage', 'snoozed', 'other'].map(activityGroup)).toEqual(Array(5).fill('change'));
    });

    it('rewords stored change sentences with stage names and local times', () => {
        expect(changeText(item('stage', 'Stage changed from pitched to repeat-client.'), label)).toEqual({
            lead: 'Stage',
            value: 'Pitched → Repeat Client',
        });
        const snoozed = changeText(item('snoozed', 'Next action moved to 2026-11-28T01:00:00+00:00.'), label);
        expect(snoozed.lead).toBe('Next action set to');
        expect(snoozed.value).toBe(
            new Date('2026-11-28T01:00:00Z').toLocaleString('en-US', {
                weekday: 'short',
                month: 'short',
                day: 'numeric',
                hour: 'numeric',
                minute: '2-digit',
            }),
        );
        expect(snoozed.value).not.toContain('T01:00');
        expect(changeText(item('created', 'Lead captured.'), label)).toEqual({ lead: 'Lead captured' });
        expect(changeText(item('updated', 'Lead details updated.'), label)).toEqual({ lead: 'Details updated' });
        // Anything unexpected is shown as stored rather than guessed.
        expect(changeText(item('stage', 'Stage moved by import.'), label)).toEqual({ lead: 'Stage moved by import.' });
        expect(changeText(item('snoozed', 'Next action moved to soon.'), label)).toEqual({
            lead: 'Next action moved to soon.',
        });
    });

    it('labels and groups days in the viewer’s time zone, newest first', () => {
        const now = new Date(2026, 10, 21, 15, 0);
        expect(dayLabel(new Date(2026, 10, 21, 0, 5).toISOString(), now)).toBe('Today');
        expect(dayLabel(new Date(2026, 10, 20, 23, 55).toISOString(), now)).toBe('Yesterday');
        expect(dayLabel(new Date(2026, 10, 19, 9, 0).toISOString(), now)).toBe('Nov 19');
        expect(dayLabel(new Date(2025, 11, 30, 9, 0).toISOString(), now)).toBe('Dec 30, 2025');
        const groups = byDay(
            [
                item('note', 'a', new Date(2026, 10, 21, 12).toISOString()),
                item('call', 'b', new Date(2026, 10, 21, 9).toISOString()),
                item('email', 'c', new Date(2026, 10, 19, 9).toISOString()),
            ],
            now,
        );
        expect(groups.map((group) => [group.day, group.items.map((entry) => entry.body)])).toEqual([
            ['Today', ['a', 'b']],
            ['Nov 19', ['c']],
        ]);
    });
});
