import { afterEach, describe, expect, it } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';
import SelectField from '../../resources/js/components/SelectField.vue';

const options = [
    { value: 'creator', label: 'Partner / creator' },
    { value: 'brand', label: 'Brand' },
];
const wrappers: VueWrapper[] = [];
function field(modelValue = 'creator') {
    const wrapper = mount(SelectField, {
        props: { id: 'workspace-kind', label: 'Workspace type', options, modelValue },
        attachTo: document.body,
    });
    wrappers.push(wrapper);
    return wrapper;
}
afterEach(() => {
    wrappers.splice(0).forEach((wrapper) => wrapper.unmount());
});

describe('SelectField', () => {
    it('selects the keyboard-focused option rather than the previously highlighted option', async () => {
        const wrapper = field();
        await wrapper.get('#workspace-kind-trigger').trigger('click');
        const brand = wrapper.get('#workspace-kind-option-brand');
        (brand.element as HTMLButtonElement).focus();
        await brand.trigger('keydown', { key: 'Enter' });
        expect(wrapper.emitted('update:modelValue')).toEqual([['brand']]);
        expect(document.activeElement).toBe(wrapper.get('#workspace-kind-trigger').element);
    });

    it('exposes the active option on the focused search and preserves text-navigation keys', async () => {
        const wrapper = field();
        await wrapper.get('#workspace-kind-trigger').trigger('click');
        const search = wrapper.get('#workspace-kind-search');
        await search.setValue('brand');
        expect(search.attributes('aria-activedescendant')).toBe('workspace-kind-option-brand');
        expect(search.attributes('role')).toBe('combobox');
        const event = new KeyboardEvent('keydown', { key: 'Home', bubbles: true, cancelable: true });
        search.element.dispatchEvent(event);
        expect(event.defaultPrevented).toBe(false);
    });

    it('keeps a native select in sync for forms', async () => {
        const wrapper = field();
        const select = wrapper.get('#workspace-kind');
        expect((select.element as HTMLSelectElement).value).toBe('creator');
        await select.setValue('brand');
        expect(wrapper.emitted('update:modelValue')).toEqual([['brand']]);
        expect(wrapper.emitted('change')).toEqual([['brand']]);
    });

    it('filters options from the search field and chooses a visible option', async () => {
        const wrapper = field();
        await wrapper.get('#workspace-kind-trigger').trigger('click');
        expect(wrapper.find('#workspace-kind-search').exists()).toBe(true);
        await wrapper.get('#workspace-kind-search').setValue('bra');
        expect(wrapper.findAll('[role="option"]').map((option) => option.text())).toEqual(['Brand']);
        await wrapper.get('[role="option"]').trigger('click');
        expect(wrapper.emitted('update:modelValue')).toEqual([['brand']]);
        expect(wrapper.find('#workspace-kind-search').exists()).toBe(false);
    });

    it('matches a hint, shows a mark, and enters the first match', async () => {
        const wrapper = mount(SelectField, {
            props: {
                id: 'currency',
                label: 'Currency',
                emptyText: 'No matches',
                searchPlaceholder: 'Search code or name',
                searchLabel: 'Search currencies',
                showMark: true,
                options: [
                    { value: 'PHP', label: 'PHP', hint: 'Philippine peso' },
                    { value: 'USD', label: 'USD', hint: 'US dollar' },
                ],
                modelValue: 'PHP',
            },
            attachTo: document.body,
        });
        wrappers.push(wrapper);
        await wrapper.get('#currency-trigger').trigger('click');
        await wrapper.get('#currency-search').setValue('peso');
        const match = wrapper.get('[role="option"]');
        expect(match.text()).toContain('PHP');
        expect(match.text()).toContain('Philippine peso');
        expect(match.attributes('aria-selected')).toBe('true');
        expect(match.find('svg').exists()).toBe(true);
        await wrapper.get('#currency-search').setValue('zzz');
        expect(wrapper.get('.wd-select-empty').text()).toBe('No matches');
        await wrapper.get('#currency-search').setValue('us');
        await wrapper.get('#currency-search').trigger('keydown', { key: 'Enter' });
        expect(wrapper.emitted('update:modelValue')).toEqual([['USD']]);
    });

    it('shows a placeholder until a value is chosen, and offers compact, inline and hidden-label variants', async () => {
        const empty = mount(SelectField, {
            props: { id: 'lead', label: 'Lead', options, modelValue: '', placeholder: 'Choose a lead' },
            attachTo: document.body,
        });
        wrappers.push(empty);
        expect(empty.get('.wd-select-placeholder').text()).toBe('Choose a lead');

        const inline = mount(SelectField, {
            props: { id: 'sort', label: 'Sort', options, modelValue: 'brand', compact: true, labelPlacement: 'inline' },
            attachTo: document.body,
        });
        wrappers.push(inline);
        expect(inline.classes()).toEqual(expect.arrayContaining(['is-compact', 'is-inline']));
        expect(inline.find('.wd-select-placeholder').exists()).toBe(false);
        expect(inline.get('label').classes()).not.toContain('wd-sr-only');

        const hidden = mount(SelectField, {
            props: { id: 'brand', label: 'Brand', options, modelValue: 'brand', labelPlacement: 'hidden' },
            attachTo: document.body,
        });
        wrappers.push(hidden);
        // The label is kept for assistive technology and still names the trigger.
        expect(hidden.get('label').classes()).toContain('wd-sr-only');
        expect(hidden.get('#brand-trigger').attributes('aria-labelledby')).toBe('brand-label');
        // The list opens under the trigger, not under a hint below the field.
        await hidden.get('#brand-trigger').trigger('click');
        expect(hidden.find('.wd-select-anchor > .wd-select-panel').exists()).toBe(true);
    });
});
