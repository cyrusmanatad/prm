import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';
import { nextTick } from 'vue';
import MultiSelectField from '../../resources/js/components/MultiSelectField.vue';

const wrappers: VueWrapper[] = [];
// jsdom has no layout; the field scrolls the active option into view.
beforeEach(() => {
    Element.prototype.scrollIntoView = vi.fn();
});
afterEach(() => wrappers.splice(0).forEach((wrapper) => wrapper.unmount()));

const options = [
    { value: 'a', label: 'Aiko Slow Home' },
    { value: 'b', label: 'Bennett Living Studio' },
    { value: 'c', label: 'Casa Silva Creative' },
];
function field(modelValue: string[] = [], extra: Record<string, unknown> = {}) {
    const wrapper = mount(MultiSelectField, {
        attachTo: document.body,
        props: {
            id: 'audience',
            label: 'Partners',
            noun: 'Partner',
            placeholder: 'Choose Partners…',
            options,
            modelValue,
            'onUpdate:modelValue': (value: string[]) => wrapper.setProps({ modelValue: value }),
            ...extra,
        },
    });
    wrappers.push(wrapper);
    return wrapper;
}
const model = (wrapper: VueWrapper) => (wrapper.props() as { modelValue: string[] }).modelValue;

it('shows chosen values as chips with their own remove buttons and returns focus to the trigger', async () => {
    const wrapper = field(['b']);
    expect(wrapper.find('.wd-multiselect-placeholder').exists()).toBe(false);
    const trigger = wrapper.get('#audience-trigger');
    expect(wrapper.get(`#${trigger.attributes('aria-labelledby')!.split(' ')[1]}`).text()).toBe(
        'Bennett Living Studio',
    );
    await wrapper.get('button[aria-label="Remove Bennett Living Studio"]').trigger('click');
    await nextTick();
    expect(model(wrapper)).toEqual([]);
    expect(document.activeElement).toBe(trigger.element);
    expect(wrapper.get('.wd-multiselect-placeholder').text()).toBe('Choose Partners…');
});

it('stays open while choosing several values by keyboard, searches, and closes on Escape', async () => {
    const wrapper = field();
    const trigger = wrapper.get('#audience-trigger');
    await trigger.trigger('keydown', { key: 'Enter' });
    await nextTick();
    const search = wrapper.get('input[type="search"]');
    expect(document.activeElement).toBe(search.element);
    expect(wrapper.get('[role="listbox"]').attributes('aria-multiselectable')).toBe('true');
    await search.trigger('keydown', { key: ' ' });
    await search.trigger('keydown', { key: 'ArrowDown' });
    await search.trigger('keydown', { key: 'Enter' });
    expect(model(wrapper)).toEqual(['a', 'b']);
    expect(wrapper.find('.wd-select-panel').exists()).toBe(true);
    expect(wrapper.text()).toContain('2 of 3 Partners selected');
    await search.setValue('casa');
    expect(wrapper.findAll('[role="option"]').map((option) => option.text())).toEqual(['Casa Silva Creative']);
    await search.trigger('keydown', { key: 'Enter' });
    expect(model(wrapper)).toEqual(['a', 'b', 'c']);
    await search.trigger('keydown', { key: 'Escape' });
    await nextTick();
    expect(wrapper.find('.wd-select-panel').exists()).toBe(false);
    expect(trigger.attributes('aria-expanded')).toBe('false');
    expect(document.activeElement).toBe(trigger.element);
});

it('selects all visible options, clears them, and closes with Done', async () => {
    const wrapper = field(['a']);
    await wrapper.get('#audience-trigger').trigger('click');
    await nextTick();
    const [all, clear] = wrapper.findAll('.wd-multiselect-action');
    await all!.trigger('click');
    expect(model(wrapper)).toEqual(['a', 'b', 'c']);
    await clear!.trigger('click');
    expect(model(wrapper)).toEqual([]);
    await wrapper.get('.wd-multiselect-foot button').trigger('click');
    await nextTick();
    expect(wrapper.find('.wd-select-panel').exists()).toBe(false);
});

it('shows a locked choice read-only with its reason and an error linked to the trigger', async () => {
    const wrapper = field(['c'], { locked: true, hint: 'Set by the source campaign.', error: 'Choose a Partner.' });
    const trigger = wrapper.get('#audience-trigger');
    expect(trigger.attributes('disabled')).toBeDefined();
    expect(wrapper.find('.wd-multiselect-remove').exists()).toBe(false);
    expect(trigger.attributes('aria-describedby')).toBe('audience-hint audience-error');
    expect(trigger.attributes('aria-invalid')).toBe('true');
    await trigger.trigger('click');
    expect(wrapper.find('.wd-select-panel').exists()).toBe(false);
});
