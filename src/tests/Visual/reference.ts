// Separate Vite test server only. No production route loads this entry point.
import '../../resources/css/app.css';
import { createApp, h } from 'vue';
import PartnerDashboard from '../../resources/js/features/portals/PartnerDashboard.vue';
import BrandDashboard from '../../resources/js/features/portals/BrandDashboard.vue';
import { pipelineStages } from '../../resources/js/features/pipeline/stages';
import type { PortalData } from '../../resources/js/features/portals/types';
const brand = new URLSearchParams(location.search).get('portal') === 'brand';
const entries = [
    { brand_name: 'Harbor Coffee', stage: 'lead', value_minor: '1500000' },
    { brand_name: 'Bloom Skincare', stage: 'lead', value_minor: '3700000' },
    { brand_name: 'Cedar Studio', stage: 'pitched', value_minor: '2400000' },
    { brand_name: 'Aurora Goods', stage: 'negotiating', value_minor: '4000000' },
    { brand_name: 'Summit Outdoors', stage: 'onboarded', value_minor: '3050000' },
    { brand_name: 'Habi Home', stage: 'active', value_minor: '4000000' },
].map((row, index) => ({
    ...row,
    id: `fixture-${index}`,
    currency: 'PHP',
    contact_name: null,
    contact_email: null,
    next_action_due: null,
    version: 1,
}));
const data: PortalData = {
    account: { name: 'Bea Santos', email: 'bea@example.test' },
    workspace: { id: 'fixture', name: 'Habi Home', kind: brand ? 'brand' : 'creator' },
    relationships: [],
    canManage: true,
    pipeline: {
        entries,
        counts: pipelineStages.map((stage) => ({
            stage: stage.value,
            count: entries.filter((row) => row.stage === stage.value).length,
        })),
        totals: [{ currency: 'PHP', amount_minor: '18650000', count: 6 }],
        nextCursor: null,
        selected: null,
        canWrite: true,
        stages: pipelineStages.map(({ value, label }) => ({ value, label })),
        filters: { q: '', stage: '', due: false },
    },
};
createApp({ render: () => h(brand ? BrandDashboard : PartnerDashboard, { data, fixture: true }) }).mount('#app');
