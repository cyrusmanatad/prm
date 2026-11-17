import { afterEach, expect, it, vi } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';
import DataTable from '../../resources/js/components/DataTable.vue';
import DetailList from '../../resources/js/components/DetailList.vue';
import FilterBar from '../../resources/js/components/FilterBar.vue';
import Pagination from '../../resources/js/components/Pagination.vue';
import StatCard from '../../resources/js/components/StatCard.vue';
import StatusBadge from '../../resources/js/components/StatusBadge.vue';
import TabNav from '../../resources/js/components/TabNav.vue';
import CopyReference from '../../resources/js/components/CopyReference.vue';

const { promise } = vi.hoisted(() => ({
    promise: vi.fn((task: Promise<unknown>, _messages: { error: string }) => task),
}));
vi.mock('@inertiajs/vue3', () => ({
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
}));
vi.mock('../../resources/js/composables/useNotify', () => ({ useNotify: () => ({ promise }) }));
const wrappers: VueWrapper[] = [];
function render<T extends VueWrapper>(wrapper: T): T {
    wrappers.push(wrapper);
    return wrapper;
}
afterEach(() => {
    wrappers.splice(0).forEach((wrapper) => wrapper.unmount());
    vi.clearAllMocks();
});
const pagination = (props: Record<string, unknown>) =>
    render(
        mount(Pagination, {
            props: {
                label: 'Pages',
                page: 1,
                perPage: 25,
                count: 25,
                total: 60,
                hrefFor: (page: number) => `/list?page=${page}`,
                ...props,
            },
        }),
    );

it('renders a labelled, keyboard-scrollable table with a row header and labelled cells', () => {
    const wrapper = render(
        mount(DataTable, {
            props: {
                caption: 'Accounts',
                columns: [
                    { key: 'name', label: 'Account' },
                    { key: 'count', label: 'Workspaces', numeric: true },
                ],
                rows: [{ id: 7, name: 'Jordan Vale', count: 2 }],
                rowKey: (row: object) => (row as { id: number }).id,
            },
            slots: { 'cell-count': '<template #cell-count="{ row }">{{ row.count }} total</template>' },
        }),
    );
    const region = wrapper.get('[role="region"]');
    expect(region.attributes('aria-label')).toBe('Accounts table');
    expect(region.attributes('tabindex')).toBe('0');
    expect(wrapper.get('caption').text()).toBe('Accounts');
    expect(wrapper.get('tbody th[scope="row"]').text()).toBe('Jordan Vale');
    const cell = wrapper.get('tbody td');
    expect(cell.attributes('data-label')).toBe('Workspaces');
    expect(cell.text()).toBe('2 total');
    expect(cell.classes()).toContain('wd-data-numeric');
});

it('states the range and scope truthfully and only links to pages that exist', () => {
    let wrapper = pagination({});
    expect(wrapper.get('[role="status"]').text()).toBe('Showing 1–25 of 60 matching records, all pages');
    expect(wrapper.findAll('a').map((link) => link.attributes('href'))).toEqual(['/list?page=2']);
    wrapper = pagination({ page: 3, count: 10 });
    expect(wrapper.get('[role="status"]').text()).toBe('Showing 51–60 of 60 matching records, all pages');
    expect(wrapper.findAll('a').map((link) => link.attributes('href'))).toEqual(['/list?page=2']);
    wrapper = pagination({ total: 25 });
    expect(wrapper.findAll('a')).toHaveLength(0);
    wrapper = pagination({ total: null, count: 4, noun: 'deliveries' });
    expect(wrapper.get('[role="status"]').text()).toBe('Showing 4 deliveries on this page; total unavailable');
    expect(wrapper.findAll('a')).toHaveLength(0);
    wrapper = pagination({ total: 0, count: 0 });
    expect(wrapper.get('[role="status"]').text()).toBe('No records');
});

it('summarises only applied filters and never claims a pending filter is applied', async () => {
    const wrapper = render(
        mount(FilterBar, {
            props: { label: 'Filter accounts', applied: [{ label: 'Name', value: 'Cedar' }] },
            slots: { default: '<input id="q" />' },
        }),
    );
    expect(wrapper.get('form').attributes('role')).toBe('search');
    expect(wrapper.get('[aria-label="Applied filters"]').text()).toContain('Name: Cedar');
    await wrapper.get('form').trigger('submit');
    expect(wrapper.emitted('submit')).toHaveLength(1);
    await wrapper
        .findAll('button')
        .find((button) => button.text() === 'Clear filters')!
        .trigger('click');
    expect(wrapper.emitted('clear')).toHaveLength(1);
    await wrapper.setProps({ pending: true });
    expect(wrapper.find('[aria-label="Applied filters"]').exists()).toBe(false);
    expect(wrapper.get('[role="status"]').text()).toBe('Updating results…');
    expect(wrapper.findAll('button').every((button) => button.attributes('disabled') !== undefined)).toBe(true);
    await wrapper.setProps({ pending: false, applied: [] });
    expect(wrapper.findAll('button').map((button) => button.text())).toEqual(['Apply filters']);
});

it('pairs every status tone with text and an icon', () => {
    for (const tone of ['neutral', 'success', 'warning', 'danger'] as const) {
        const wrapper = render(mount(StatusBadge, { props: { label: 'Outcome uncertain', tone } }));
        expect(wrapper.text()).toBe('Outcome uncertain');
        expect(wrapper.get('svg').attributes('aria-hidden')).toBe('true');
        expect(wrapper.classes()).toContain(`wd-badge-${tone}`);
    }
});

it('marks the current tab and shows unobserved figures as unavailable, not zero', () => {
    const tabs = render(
        mount(TabNav, {
            props: {
                label: 'Account sections',
                tabs: [
                    { href: '/a', label: 'Overview', current: true },
                    { href: '/a?tab=security', label: 'Security', current: false },
                ],
            },
        }),
    );
    expect(tabs.get('nav').attributes('aria-label')).toBe('Account sections');
    expect(tabs.findAll('a').map((link) => link.attributes('aria-current'))).toEqual(['page', undefined]);
    const missing = render(mount(StatCard, { props: { label: 'Failed jobs', value: null, scope: 'Not observed' } }));
    expect(missing.text()).toContain('Unavailable');
    expect(missing.text()).not.toContain('0');
    const zero = render(
        mount(StatCard, {
            props: { label: 'Failed jobs', value: 0, scope: 'Last 24 hours', href: '/admin/operations' },
        }),
    );
    expect(zero.get('.wd-stat-value').text()).toBe('0');
    expect(zero.get('a').attributes('href')).toBe('/admin/operations');
    const details = render(
        mount(DetailList, {
            props: {
                items: [
                    { key: 'reason', label: 'Reason', value: null },
                    { key: 'id', label: 'ID', value: 12 },
                ],
                missing: 'Not recorded in this event source',
            },
        }),
    );
    expect(details.findAll('dd').map((node) => node.text())).toEqual(['Not recorded in this event source', '12']);
});

it('reports clipboard failure instead of claiming a copy', async () => {
    const wrapper = render(mount(CopyReference, { props: { value: 'abc-123', label: 'workspace ID' } }));
    expect(wrapper.get('button').attributes('aria-label')).toBe('Copy workspace ID');
    Object.defineProperty(navigator, 'clipboard', { configurable: true, value: undefined });
    await wrapper.get('button').trigger('click');
    await expect(promise.mock.calls[0]![0]).rejects.toThrow('Clipboard unavailable');
    expect(promise.mock.calls[0]![1]!.error).toContain("Couldn't copy workspace ID");
    const writeText = vi.fn(() => Promise.resolve());
    Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText } });
    await wrapper.get('button').trigger('click');
    expect(writeText).toHaveBeenCalledWith('abc-123');
});
