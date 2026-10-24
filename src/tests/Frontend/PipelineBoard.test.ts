import { mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, describe, expect, it } from 'vitest';
import PipelineBoard from '../../resources/js/features/pipeline/PipelineBoard.vue';
import type { Entry, PipelineWorkspace } from '../../resources/js/features/pipeline/types';

const past = '2020-01-01T00:00:00Z';
const future = '2099-01-01T00:00:00Z';
function entry(brand: string, amount: number, stage: string, due: string | null = null): Entry {
    return {
        id: brand,
        brand_name: brand,
        contact_name: null,
        contact_email: null,
        stage,
        value_minor: String(amount * 100),
        currency: 'USD',
        next_action_due: due,
        version: 1,
    };
}
const entries = [
    entry('Cedar Studio', 650, 'lead'),
    entry('Aurora Goods', 800, 'pitched', past),
    entry('Summit Outdoors', 950, 'negotiating', past),
    entry('Harbor Coffee', 1100, 'onboarded'),
    entry('Bloom Skincare', 1250, 'active'),
    entry('Atlas Travel', 1400, 'repeat-client'),
    entry('Willow Home', 500, 'lost', past),
];
const counts = [
    { stage: 'lead', count: 1 },
    { stage: 'pitched', count: 1 },
    { stage: 'negotiating', count: 1 },
    { stage: 'onboarded', count: 1 },
    { stage: 'active', count: 1 },
    { stage: 'repeat-client', count: 1 },
    { stage: 'lost', count: 1 },
];
const totals = [{ currency: 'USD', amount_minor: '615000', count: 6 }];
const filters: PipelineWorkspace['filters'] = { q: '', stage: '', due: false };
const wrappers: VueWrapper[] = [];
afterEach(() => {
    wrappers.forEach((wrapper) => wrapper.unmount());
    wrappers.length = 0;
});
function board() {
    const wrapper = mount(PipelineBoard, {
        props: { entries, counts, totals, filters, nextCursor: null },
    });
    wrappers.push(wrapper);
    return wrapper;
}

describe('pipeline board', () => {
    it('shows open leads, repeat clients in Active, and a total that ignores Lost', () => {
        const wrapper = board();
        expect(wrapper.get('.pipeline-total').text()).toBe('USD 6,150.00');
        expect(wrapper.text()).toContain('6 leads · excludes Lost');
        expect(wrapper.get('[aria-label="Lead"]').text()).toContain('Cedar Studio');
        expect(wrapper.get('[aria-label="Pitched"]').text()).toContain('Action due');
        expect(wrapper.get('[aria-label="Negotiating"]').text()).toContain('Summit Outdoors');
        const active = wrapper.get('[aria-label="Active"]');
        expect(active.text()).toContain('Bloom Skincare');
        expect(active.text()).toContain('Atlas Travel');
        expect(active.text()).toContain('Repeat client');
        expect(active.text()).toContain('USD 2,650.00');
        expect(wrapper.text()).not.toContain('Willow Home');
    });

    it('filters repeat clients, lost leads, search, stage, and due actions', async () => {
        const wrapper = board();
        const clickTab = async (label: string) => {
            await wrapper
                .findAll('button')
                .find((button) => button.text().includes(label))!
                .trigger('click');
        };
        await clickTab('Repeat clients');
        expect(wrapper.get('[aria-label="Active"]').text()).toContain('Atlas Travel');
        expect(wrapper.get('[aria-label="Lead"]').text()).toContain('No leads');
        expect(wrapper.get('[aria-label="Lead"]').text()).toContain('USD 0.00');
        expect(wrapper.text()).toContain('USD 6,150.00');

        await clickTab('Lost');
        expect(wrapper.get('[aria-label="Active"]').text()).toContain('Willow Home');
        expect(wrapper.get('[aria-label="Active"]').text()).toContain('Action due');
        expect(wrapper.text()).not.toContain('No lost leads');

        await clickTab('All active');
        await wrapper.get('input[aria-label="Search brands"]').setValue('harbor');
        expect(wrapper.text()).toContain('Harbor Coffee');
        expect(wrapper.text()).not.toContain('Cedar Studio');
        expect(wrapper.get('.pipeline-total').text()).toBe('USD 6,150.00');

        await wrapper.get('[aria-label="Stage filter"]').trigger('click');
        const panel = wrapper.get('.stage-panel');
        expect(panel.text().indexOf('Active')).toBeLessThan(panel.text().indexOf('Due next actions'));
        expect(panel.find('.stage-divider').exists()).toBe(true);
        expect(panel.text()).toContain('Clear filters');
        await wrapper.get('input[aria-label="Search stages"]').setValue('pit');
        expect(wrapper.findAll('[role="option"]').map((option) => option.text())).toEqual(['Pitched']);
        expect(panel.text()).toContain('Due next actions');
        await wrapper.get('button.stage-clear').trigger('click');
        expect(wrapper.text()).toContain('Cedar Studio');

        await wrapper.get('[aria-label="Stage filter"]').trigger('click');
        await wrapper
            .findAll('[role="option"]')
            .find((option) => option.text().includes('Pitched'))!
            .trigger('click');
        expect(wrapper.find('[aria-label="Lead"]').exists()).toBe(false);
        expect(wrapper.get('[aria-label="Pitched"]').text()).toContain('Aurora Goods');

        await wrapper.get('[aria-label="Stage filter"]').trigger('click');
        await wrapper.get('button.stage-all').trigger('click');
        await wrapper.get('[aria-label="Stage filter"]').trigger('click');
        await wrapper.get('button.due-row').trigger('click');
        expect(wrapper.text()).toContain('Aurora Goods');
        expect(wrapper.text()).toContain('Summit Outdoors');
        expect(wrapper.text()).not.toContain('Cedar Studio');
        expect(wrapper.get('button.due-row').attributes('aria-pressed')).toBe('true');
    });

    it('uses the empty copy for a segment with no leads', async () => {
        const wrapper = mount(PipelineBoard, {
            props: { entries: [], counts: [], totals: [], filters, nextCursor: null },
        });
        wrappers.push(wrapper);
        expect(wrapper.get('h3').text()).toBe('No leads match these filters');
        await wrapper
            .findAll('button')
            .find((button) => button.text().includes('Lost'))!
            .trigger('click');
        expect(wrapper.get('h3').text()).toBe('No lost leads');
        expect(wrapper.text()).toContain('Leads you mark as lost will appear here.');
        await wrapper
            .findAll('button')
            .find((button) => button.text().includes('Repeat clients'))!
            .trigger('click');
        expect(wrapper.get('h3').text()).toBe('No repeat clients yet');
    });
});
