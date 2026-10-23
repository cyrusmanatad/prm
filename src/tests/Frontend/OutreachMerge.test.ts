import { describe, expect, it } from 'vitest';
import { mergeText } from '../../resources/js/features/outreach/merge';

describe('plain text outreach merging', () => {
    it('uses literal values without recursively interpreting syntax or HTML', () => {
        expect(mergeText('Hi {{contact_name}}, {{ brand_name }} — {{creator_name}}', {
            contact_name: '<script>alert(1)</script>', brand_name: '{{creator_name}}', creator_name: '$&',
        })).toEqual({ text: 'Hi <script>alert(1)</script>, {{creator_name}} — $&', unresolved: [] });
    });
    it('keeps unknown, missing, and malformed placeholders visible for correction', () => {
        expect(mergeText('{{contact_name}} {{unknown}} {{broken', { contact_name: ' ', brand_name: 'Brand', creator_name: 'Name' }))
            .toEqual({ text: '{{contact_name}} {{unknown}} {{broken', unresolved: ['contact_name', 'unknown', 'malformed placeholder'] });
    });
});
