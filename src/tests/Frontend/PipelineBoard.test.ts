import { afterEach, expect, it, vi } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';
import PipelineBoard from '../../resources/js/features/pipeline/PipelineBoard.vue';
import type { Entry } from '../../resources/js/features/pipeline/types';

const wrappers: VueWrapper[] = [];
afterEach(() => {
    wrappers.forEach((wrapper) => wrapper.unmount());
    wrappers.length = 0;
    vi.useRealTimers();
});
const lead = (stage: string, currency = 'USD', value_minor = '100'): Entry => ({
    id: stage,
    brand_name: `${stage} studio`,
    contact_name: null,
    contact_email: null,
    stage,
    currency,
    value_minor,
    version: 1,
    next_action_due: null,
});
function board(extra: Record<string, unknown> = {}) {
    const wrapper = mount(PipelineBoard, {
        props: {
            entries: [lead('lead')],
            counts: [
                { stage: 'lead', count: 26 },
                { stage: 'pitched', count: 1 },
            ],
            totals: [{ currency: 'USD', amount_minor: '2700', count: 27 }],
            filters: { q: '', stage: '', due: false },
            nextCursor: 'page-two',
            ...extra,
        },
    });
    wrappers.push(wrapper);
    return wrapper;
}
it('uses matching counts across pages and labels page-only money separately', () => {
    const wrapper = board();
    const pitched = wrapper.get('section[aria-label="Pitched"]');
    expect(pitched.text()).toContain('1 leads on other pages');
    expect(pitched.find('h3').text()).toBe('Pitched');
    expect(pitched.find('.column-count').text()).toBe('1');
    expect(wrapper.get('section[aria-label="Lead"]').text()).toContain('Page value: USD 1.00');
    expect(wrapper.find('.pipeline-total').text()).toBe('USD 27.00');
});
it('keeps Repeat Client and Lost out of Active and retains exact separate currencies', () => {
    const wrapper = board({
        entries: [lead('active'), lead('repeat-client', 'PHP', '999999999999999999'), lead('lost')],
        counts: [
            { stage: 'active', count: 1 },
            { stage: 'repeat-client', count: 1 },
            { stage: 'lost', count: 1 },
        ],
    });
    expect(wrapper.get('section[aria-label="Active"]').findAll('button')).toHaveLength(1);
    expect(wrapper.get('section[aria-label="Repeat Client"]').text()).toContain('PHP 9,999,999,999,999,999.99');
    expect(wrapper.get('section[aria-label="Lost"]').text()).toContain('Lost');
    expect(wrapper.findAll('.pipeline-grid > section')).toHaveLength(5);
});
it('queries the server with reset cursors and restores controls on browser history props', async () => {
    vi.useFakeTimers();
    const wrapper = board();
    await wrapper.get('#pipeline-search').setValue('Older target');
    await vi.advanceTimersByTimeAsync(300);
    expect(wrapper.emitted('load')?.at(-1)).toEqual([{ q: 'Older target' }]);
    await wrapper.setProps({ filters: { q: 'Older target', stage: '', due: false } });
    await wrapper.setProps({ filters: { q: '', stage: 'lost', due: true } });
    expect((wrapper.get('#pipeline-search').element as HTMLInputElement).value).toBe('');
    expect((wrapper.get('input[type="checkbox"]').element as HTMLInputElement).checked).toBe(true);
    await wrapper.setProps({ filters: { q: 'Older target', stage: '', due: false } });
    expect((wrapper.get('#pipeline-search').element as HTMLInputElement).value).toBe('Older target');
    await wrapper
        .findAll('button')
        .find((button) => button.text() === 'Next 25 leads')!
        .trigger('click');
    expect(wrapper.emitted('load')?.at(-1)).toEqual([{ q: 'Older target', cursor: 'page-two' }]);
    await wrapper
        .findAll('button')
        .find((button) => button.text() === 'Lost')!
        .trigger('click');
    expect(wrapper.emitted('load')?.at(-1)).toEqual([{ q: 'Older target', stage: 'lost' }]);
});
it('does not erase newer search text when an earlier response returns', async () => {
    vi.useFakeTimers();
    const wrapper = board();
    await wrapper.get('#pipeline-search').setValue('Older');
    await vi.advanceTimersByTimeAsync(300);
    await wrapper.get('#pipeline-search').setValue('Older target');
    await wrapper.setProps({ filters: { q: 'Older', stage: '', due: false } });
    expect((wrapper.get('#pipeline-search').element as HTMLInputElement).value).toBe('Older target');
    await vi.advanceTimersByTimeAsync(300);
    expect(wrapper.emitted('load')?.at(-1)).toEqual([{ q: 'Older target' }]);
});

it('resets paging when a filter is edited before its debounce runs', async () => {
    vi.useFakeTimers();
    const wrapper = board();
    await wrapper.get('#pipeline-search').setValue('New query');
    await wrapper
        .findAll('button')
        .find((button) => button.text() === 'Next 25 leads')!
        .trigger('click');
    expect(wrapper.emitted('load')?.at(-1)).toEqual([{ q: 'New query' }]);
    await vi.advanceTimersByTimeAsync(300);
    expect(wrapper.emitted('load')).toHaveLength(1);
});

it('cancels an unsubmitted search when browser history restores another filter', async () => {
    vi.useFakeTimers();
    const wrapper = board();
    await wrapper.get('#pipeline-search').setValue('Unsubmitted draft');
    window.dispatchEvent(new PopStateEvent('popstate'));
    await wrapper.setProps({ filters: { q: 'Restored query', stage: 'lead', due: false } });
    await vi.advanceTimersByTimeAsync(300);
    expect(wrapper.emitted('load')).toBeUndefined();
    expect((wrapper.get('#pipeline-search').element as HTMLInputElement).value).toBe('Restored query');
});
