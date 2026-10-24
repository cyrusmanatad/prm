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
});
