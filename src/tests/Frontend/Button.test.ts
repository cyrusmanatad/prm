import { expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import { Button } from '../../resources/js/components/ui/button';

it('forwards record-specific accessible names to its native button', () => {
    const wrapper = mount(Button, { props: { ariaLabel: 'Edit Sam Contact' }, slots: { default: 'Edit' } });
    expect(wrapper.get('button').attributes('aria-label')).toBe('Edit Sam Contact');
    wrapper.unmount();
});
