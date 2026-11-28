<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Pencil, Plus, Search, Unlink } from '@lucide/vue';
import DataTable, { type DataColumn } from '../components/DataTable.vue';
import DialogClose from '../components/DialogClose.vue';
import EmptyState from '../components/EmptyState.vue';
import FieldGroup from '../components/FieldGroup.vue';
import FilterChips, { type FilterChip } from '../components/FilterChips.vue';
import FormField from '../components/FormField.vue';
import Pagination from '../components/Pagination.vue';
import PartnerAvatar from '../components/PartnerAvatar.vue';
import SelectField from '../components/SelectField.vue';
import StatusBadge, { type BadgeTone } from '../components/StatusBadge.vue';
import { Button } from '../components/ui/button';
import { confirmAction } from '../composables/useConfirmation';
import { containDialogFocus } from '../composables/useDialogFocus';
import { useWorkspaceExperience } from '../composables/useWorkspaceExperience';
import { query } from '../features/admin/format';
import PartnerChrome from '../features/portals/PartnerChrome.vue';
import { stageLabel } from '../features/portals/activity';
import type { OwnRegistration, PageMeta, SharedProfile, StageOption } from '../features/portals/types';

type Brand = { id: string; brand_name: string; paused: boolean; shared?: number };
type ShareableLead = {
    id: string;
    brand_name: string;
    contact_name: string | null;
    contact_email: string | null;
    stage: string;
    registered_with: string[];
};
type State = 'shared' | 'not_shared' | 'all';
const props = defineProps<{
    account: { name: string; email: string };
    workspace: { id: string; name: string };
    canManage: boolean;
    brands: Brand[];
    lead: ShareableLead | null;
    leads?: ShareableLead[];
    registrations: OwnRegistration[];
    pagination: PageMeta;
    counts?: Record<State, number>;
    filters?: { state: State; brand: string; q: string };
    summary?: { brands: number; shared: number; notShared: number };
    profile: SharedProfile & { version: number };
    stages: StageOption[];
    errors?: Record<string, string>;
    /** Briefs each Brand shared with this workspace, keyed by partnership (D43). */
    campaigns?: Record<string, { id: string; title: string }[]>;
    presetCampaign?: string | null;
}>();
const { path } = useWorkspaceExperience();
const self = computed(() => path('/partner/brand-sharing'));
const filters = computed(() => props.filters ?? { state: 'all' as State, brand: '', q: '' });
const counts = computed(() => props.counts ?? { shared: 0, not_shared: 0, all: props.pagination.total });

// List address: filters reset the page; the lead context is not carried into list links.
function listHref(change: Partial<{ state: string; brand: string; q: string; page: number }> = {}): string {
    const next = { ...filters.value, page: props.pagination.page, ...change };
    if (change.state !== undefined || change.brand !== undefined || change.q !== undefined)
        next.page = change.page ?? 1;
    return `${self.value}${query({
        state: next.state === 'all' ? '' : next.state,
        brand: next.brand,
        q: next.q,
        page: next.page,
    })}`;
}
const chips = computed<FilterChip[]>(() => [
    { value: 'shared', label: 'Shared', count: counts.value.shared },
    { value: 'not_shared', label: 'Not shared', count: counts.value.not_shared },
    { value: 'all', label: 'All', count: counts.value.all },
]);
const q = ref(filters.value.q);
let typing: ReturnType<typeof setTimeout> | undefined;
watch(q, (value) => {
    clearTimeout(typing);
    if (value.trim() === filters.value.q) return;
    typing = setTimeout(
        () => router.get(listHref({ q: value.trim() }), {}, { preserveState: true, preserveScroll: true }),
        350,
    );
});
watch(
    () => filters.value.q,
    (value) => {
        if (document.activeElement?.id !== 'registration-search') q.value = value;
    },
);
onBeforeUnmount(() => clearTimeout(typing));
function filterBrand(event: Event): void {
    router.get(listHref({ brand: (event.target as HTMLSelectElement).value }), {}, { preserveScroll: true });
}
const filtered = computed(() => !!(filters.value.brand || filters.value.q || filters.value.state !== 'all'));

// Table.
const columns = computed<DataColumn[]>(() =>
    [
        { key: 'display_name', label: 'Lead' },
        { key: 'brand_name', label: 'Brand' },
        { key: 'stage', label: 'Stage' },
        { key: 'state', label: 'Sharing' },
        { key: 'registered_at', label: 'First shared' },
        { key: 'actions', label: 'Actions' },
    ].filter((column) => props.canManage || column.key !== 'actions'),
);
function sharing(row: OwnRegistration): { label: string; tone: BadgeTone; note?: string } {
    if (row.state === 'active')
        return row.paused
            ? { label: 'Paused', tone: 'warning', note: 'Not visible while the Brand is unavailable.' }
            : { label: 'Shared', tone: 'success' };
    return {
        label: 'Not shared',
        tone: 'neutral',
        note: {
            partner: 'You withdrew this registration.',
            source_deleted: 'The lead was deleted.',
            partnership_ended: 'The partnership ended.',
        }[row.withdrawn_reason ?? 'partner'],
    };
}
const shortDate = (value: string) => {
    const date = new Date(value);
    return date.toLocaleDateString(undefined, {
        month: 'short',
        day: 'numeric',
        ...(date.getFullYear() === new Date().getFullYear() ? {} : { year: 'numeric' }),
    });
};
function withdraw(row: OwnRegistration, event: Event): void {
    confirmAction(
        'Withdraw registration',
        `Withdraw “${row.display_name}” from ${row.brand_name ?? 'this Brand'}? They will no longer see this lead or its updates. Your private lead is kept.`,
        () => router.delete(`/lead-registrations/${row.id}`, { data: { version: row.version }, preserveScroll: true }),
        event,
        { tone: 'danger' },
    );
}

// Share dialog. Arriving from a lead's "Register with a Brand" link opens it with that lead chosen.
const shareDialog = ref<HTMLDialogElement>();
const sharingOpen = ref(false);
let shareTrigger: HTMLElement | null = null;
const shareableLeads = computed<ShareableLead[]>(() => {
    const list = props.leads ?? [];
    return props.lead && !list.some((row) => row.id === props.lead!.id) ? [props.lead, ...list] : list;
});
// "Register a lead for this brief" arrives with the brief preselected, which also fixes the Brand.
const presetPartnership =
    Object.entries(props.campaigns ?? {}).find(([, list]) =>
        list.some((item) => item.id === props.presetCampaign),
    )?.[0] ?? '';
const registration = useForm({
    partnership: presetPartnership,
    entry: props.lead?.id ?? '',
    display_name: props.lead?.brand_name ?? '',
    summary: '',
    share_contact: false,
    campaign: presetPartnership ? (props.presetCampaign ?? '') : '',
});
function campaignOptions(partnership: string): { value: string; label: string }[] {
    const list = props.campaigns?.[partnership] ?? [];
    return list.length
        ? [{ value: '', label: 'None' }, ...list.map((item) => ({ value: item.id, label: item.title }))]
        : [];
}
const registrationCampaigns = computed(() => campaignOptions(registration.partnership));
watch(
    () => registration.partnership,
    () => {
        if (!registrationCampaigns.value.some((option) => option.value === registration.campaign))
            registration.campaign = '';
    },
);
const chosenLead = computed(() => shareableLeads.value.find((row) => row.id === registration.entry));
const leadOptions = computed(() => [
    { value: '', label: 'Choose a lead' },
    ...shareableLeads.value.map((row) => ({
        value: row.id,
        label: row.brand_name,
        hint: stageLabel(props.stages, row.stage),
    })),
]);
// A lead can be shared with each connected Brand once; paused Brands cannot receive a registration.
const brandOptions = computed(() => [
    { value: '', label: 'Choose a Brand' },
    ...props.brands
        .filter((brand) => !brand.paused && !chosenLead.value?.registered_with.includes(brand.id))
        .map((brand) => ({ value: brand.id, label: brand.brand_name })),
]);
watch(
    () => registration.entry,
    (entry, previous) => {
        const next = shareableLeads.value.find((row) => row.id === entry);
        const before = shareableLeads.value.find((row) => row.id === previous);
        if (next && (!registration.display_name || registration.display_name === before?.brand_name))
            registration.display_name = next.brand_name;
        if (registration.partnership && next?.registered_with.includes(registration.partnership))
            registration.partnership = '';
    },
);
const contact = computed(
    () =>
        [chosenLead.value?.contact_name, chosenLead.value?.contact_email].filter(Boolean).join(' · ') ||
        'no contact saved yet',
);
const canShare = computed(() => props.canManage && props.brands.some((brand) => !brand.paused));
async function openShare(event?: Event): Promise<void> {
    shareTrigger = (event?.currentTarget as HTMLElement | undefined) ?? null;
    sharingOpen.value = true;
    await nextTick();
    shareDialog.value?.showModal();
    document.getElementById(registration.entry ? 'registration-brand-trigger' : 'registration-lead-trigger')?.focus();
}
function closeShare(): void {
    shareDialog.value?.close();
}
function onShareClosed(): void {
    sharingOpen.value = false;
    shareTrigger?.focus();
}
function register(): void {
    registration.post('/lead-registrations', {
        preserveScroll: true,
        onSuccess: () => {
            registration.reset();
            registration.entry = '';
            registration.display_name = '';
            registration.campaign = '';
            closeShare();
        },
    });
}
onMounted(() => {
    if ((props.lead || presetPartnership) && canShare.value) void openShare();
});

// Edit shared details of one registration.
const editing = ref<OwnRegistration | null>(null);
const editDialog = ref<HTMLDialogElement | null>(null);
let editTrigger: HTMLElement | null = null;
const edit = useForm({ version: 0, display_name: '', summary: '', share_contact: false, campaign: '' });
const editCampaigns = computed(() => (editing.value ? campaignOptions(editing.value.partnership_id ?? '') : []));
function openEdit(row: OwnRegistration, event: Event): void {
    editTrigger = event.currentTarget as HTMLElement;
    edit.clearErrors();
    edit.defaults({
        version: row.version,
        display_name: row.display_name,
        summary: row.summary,
        share_contact: row.share_contact,
        campaign: row.campaign_id ?? '',
    });
    edit.reset();
    editing.value = row;
}
watch(editing, async (value) => {
    await nextTick();
    if (value) {
        editDialog.value?.showModal();
        document.getElementById('edit-registration-name')?.focus();
    } else {
        editDialog.value?.close();
        editTrigger?.focus();
    }
});
function saveEdit(): void {
    if (!editing.value) return;
    edit.patch(`/lead-registrations/${editing.value.id}`, {
        preserveScroll: true,
        onSuccess: () => (editing.value = null),
    });
}

// Shared business profile: a read-only card with an edit dialog.
const profileDialog = ref<HTMLDialogElement>();
const profileOpen = ref(false);
let profileTrigger: HTMLElement | null = null;
const shared = useForm({
    version: props.profile.version,
    contact_name: props.profile.contact_name ?? '',
    contact_email: props.profile.contact_email ?? '',
    website_url: props.profile.website_url ?? '',
    description: props.profile.description,
});
watch(
    () => props.profile.version,
    (version) => (shared.version = version),
);
async function openProfile(event: Event): Promise<void> {
    profileTrigger = event.currentTarget as HTMLElement;
    shared.clearErrors();
    profileOpen.value = true;
    await nextTick();
    profileDialog.value?.showModal();
    document.getElementById('profile-contact-name')?.focus();
}
function onProfileClosed(): void {
    profileOpen.value = false;
    profileTrigger?.focus();
}
function saveProfile(): void {
    shared.put('/partner-shared-profile', {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => profileDialog.value?.close(),
    });
}
const profileEmpty = computed(
    () =>
        !props.profile.contact_name &&
        !props.profile.contact_email &&
        !props.profile.website_url &&
        !props.profile.description,
);
const brandShares = (brand: Brand) =>
    `${brand.shared ?? 0} ${brand.shared === 1 ? 'lead' : 'leads'} shared${brand.paused ? ' · not visible while paused' : ''}`;
</script>

<template>
    <Head title="Brand sharing" />
    <PartnerChrome :account-name="account.name" :account-email="account.email">
        <main id="main-content" class="blv-page bs wd-portal-page flex flex-col gap-3.5 pt-6 pb-12">
            <header class="bs-header">
                <div>
                    <h1>Brand sharing</h1>
                    <p class="bs-sub">
                        Your leads are private. A connected Brand sees a lead only after you share it with that Brand.
                    </p>
                </div>
                <button v-if="canShare" type="button" class="wd-table-button bs-primary" @click="openShare($event)">
                    <Plus :size="16" aria-hidden="true" />Share a lead
                </button>
            </header>
            <p v-if="!canManage" class="wd-warning" role="status">
                Only workspace owners and admins can share leads with a Brand, withdraw them or edit the shared profile.
                You can view what is shared.
            </p>
            <ul v-if="summary" class="bs-stats" aria-label="Sharing summary">
                <li>
                    <span>Connected Brands</span><strong>{{ summary.brands }}</strong>
                </li>
                <li>
                    <span>Leads shared now</span><strong>{{ summary.shared }}</strong>
                </li>
                <li>
                    <span>Withdrawn or ended</span><strong>{{ summary.notShared }}</strong>
                </li>
            </ul>
            <div class="bs-grid">
                <section class="bs-card" aria-labelledby="registrations-title">
                    <header class="bs-card-head">
                        <h2 id="registrations-title">Shared leads</h2>
                        <span class="bs-count">{{ counts.all }}</span>
                    </header>
                    <div class="bs-toolbar">
                        <FilterChips
                            label="Filter by sharing"
                            :chips="chips"
                            :current="filters.state"
                            :href-for="(value) => listHref({ state: value })"
                            compact
                        />
                        <label v-if="brands.length > 1" class="bs-inline-field">
                            <span class="wd-sr-only">Brand</span>
                            <select class="wd-table-input" :value="filters.brand" @change="filterBrand">
                                <option value="">All Brands</option>
                                <option v-for="brand in brands" :key="brand.id" :value="brand.id">
                                    {{ brand.brand_name }}
                                </option>
                            </select>
                        </label>
                        <form class="bs-search" role="search" aria-label="Search shared leads" @submit.prevent>
                            <label for="registration-search" class="wd-sr-only">Search leads or Brands</label>
                            <Search :size="15" aria-hidden="true" />
                            <input
                                id="registration-search"
                                v-model="q"
                                class="wd-table-input"
                                type="search"
                                maxlength="160"
                                placeholder="Search leads"
                                autocomplete="off"
                            />
                        </form>
                    </div>
                    <p v-if="errors?.version && !editing" class="wd-field-error bs-pad" role="alert">
                        {{ errors.version }}
                    </p>
                    <DataTable
                        v-if="registrations.length"
                        class="bs-table"
                        caption="Leads registered with Brands"
                        :columns="columns"
                        :rows="registrations"
                        :row-key="(row) => row.id"
                    >
                        <template #head-actions><span class="wd-sr-only">Actions</span></template>
                        <template #cell-display_name="{ row }">
                            <Link
                                v-if="row.entry_id"
                                :href="`${path('/partner/leads')}${query({ entry: row.entry_id })}`"
                                >{{ row.display_name }}</Link
                            >
                            <template v-else>{{ row.display_name }}</template>
                            <span class="wd-data-secondary bs-lead-meta">{{
                                [
                                    row.share_contact ? 'Contact shared' : 'Name only',
                                    row.campaign_title ? `Campaign: ${row.campaign_title}` : '',
                                    row.summary,
                                ]
                                    .filter(Boolean)
                                    .join(' · ')
                            }}</span>
                        </template>
                        <template #cell-brand_name="{ row }">{{ row.brand_name ?? 'Ended partnership' }}</template>
                        <template #cell-stage="{ row }">{{
                            row.state === 'active' ? stageLabel(stages, row.stage) : '—'
                        }}</template>
                        <template #cell-state="{ row }">
                            <StatusBadge :label="sharing(row).label" :tone="sharing(row).tone" />
                            <span v-if="sharing(row).note" class="wd-data-secondary">{{ sharing(row).note }}</span>
                        </template>
                        <template #cell-registered_at="{ row }">
                            <time :datetime="row.registered_at" :title="new Date(row.registered_at).toLocaleString()">{{
                                shortDate(row.registered_at)
                            }}</time>
                        </template>
                        <template #cell-actions="{ row }">
                            <span v-if="row.state === 'active'" class="bs-row-actions">
                                <button
                                    type="button"
                                    class="wd-table-icon-button bs-ghost"
                                    :aria-label="`Edit shared details of ${row.display_name}`"
                                    :title="`Edit shared details of ${row.display_name}`"
                                    @click="openEdit(row, $event)"
                                >
                                    <Pencil :size="15" aria-hidden="true" />
                                </button>
                                <button
                                    type="button"
                                    class="wd-table-icon-button bs-ghost is-danger"
                                    :aria-label="`Withdraw registration of ${row.display_name}`"
                                    :title="`Withdraw registration of ${row.display_name}`"
                                    @click="withdraw(row, $event)"
                                >
                                    <Unlink :size="15" aria-hidden="true" />
                                </button>
                            </span>
                        </template>
                    </DataTable>
                    <EmptyState
                        v-else
                        class="bs-empty"
                        :title="filtered ? 'No shared leads match' : 'No leads registered with a Brand'"
                        :description="
                            filtered
                                ? 'Try another filter or search.'
                                : 'Nothing from your pipeline is visible to any Brand.'
                        "
                    />
                    <Pagination
                        v-if="pagination.total > pagination.perPage"
                        class="bs-pagination"
                        label="Registered leads pages"
                        noun="registrations"
                        numbered
                        :page="pagination.page"
                        :per-page="pagination.perPage"
                        :count="registrations.length"
                        :total="pagination.total"
                        :href-for="(page) => listHref({ page })"
                    />
                </section>
                <div class="bs-side">
                    <section class="bs-card" aria-labelledby="brands-title">
                        <header class="bs-card-head">
                            <h2 id="brands-title">Connected Brands</h2>
                            <span class="bs-count">{{ brands.length }}</span>
                        </header>
                        <ul v-if="brands.length" class="bs-brands">
                            <li v-for="brand in brands" :key="brand.id">
                                <PartnerAvatar :name="brand.brand_name" :seed="brand.id" :size="28" />
                                <span class="bs-brand-name"
                                    ><strong>{{ brand.brand_name }}</strong
                                    ><small>{{ brandShares(brand) }}</small></span
                                >
                                <StatusBadge
                                    :label="brand.paused ? 'Paused' : 'Active'"
                                    :tone="brand.paused ? 'warning' : 'success'"
                                />
                            </li>
                        </ul>
                        <EmptyState
                            v-else
                            class="bs-empty"
                            title="No connected Brands"
                            description="Accept a Brand's invitation first. You can share leads with Brands you are connected to."
                        />
                    </section>
                    <section class="bs-card" aria-labelledby="profile-title">
                        <header class="bs-card-head">
                            <h2 id="profile-title">Your shared profile</h2>
                            <button
                                v-if="canManage"
                                type="button"
                                class="wd-table-button"
                                aria-label="Edit shared profile"
                                @click="openProfile"
                            >
                                <Pencil :size="14" aria-hidden="true" />Edit
                            </button>
                        </header>
                        <p v-if="profileEmpty" class="wd-hint bs-pad">
                            Optional. Add a business contact, website or short description for connected Brands.
                        </p>
                        <dl v-else class="bs-profile">
                            <template v-if="profile.contact_name">
                                <dt>Contact</dt>
                                <dd>{{ profile.contact_name }}</dd>
                            </template>
                            <template v-if="profile.contact_email">
                                <dt>Email</dt>
                                <dd>{{ profile.contact_email }}</dd>
                            </template>
                            <template v-if="profile.website_url">
                                <dt>Website</dt>
                                <dd>{{ profile.website_url }}</dd>
                            </template>
                            <template v-if="profile.description">
                                <dt>About</dt>
                                <dd>{{ profile.description }}</dd>
                            </template>
                        </dl>
                        <p class="bs-note">
                            <strong>Every connected Brand sees this profile.</strong> Your account email, phone number,
                            address and workspace members are never shared.
                        </p>
                    </section>
                </div>
            </div>
        </main>
    </PartnerChrome>
    <dialog
        ref="shareDialog"
        class="wd-dialog bs-dialog"
        aria-labelledby="share-title"
        @close="onShareClosed"
        @keydown="containDialogFocus($event, shareDialog)"
    >
        <div class="wd-dialog-header">
            <h2 id="share-title">Share a lead with a Brand</h2>
            <DialogClose label="Close share dialog" @click="closeShare" />
        </div>
        <form v-if="sharingOpen" :aria-busy="registration.processing" @submit.prevent="register">
            <fieldset class="wd-fields" :disabled="registration.processing">
                <legend class="wd-sr-only">Share a lead with a Brand</legend>
                <EmptyState
                    v-if="!shareableLeads.length"
                    title="No open leads to share"
                    description="Add a lead in My leads first."
                />
                <template v-else>
                    <div class="bs-pair">
                        <SelectField
                            id="registration-lead"
                            v-model="registration.entry"
                            label="Lead"
                            required
                            :options="leadOptions"
                            :searchable="leadOptions.length > 8"
                            :error="registration.errors.entry"
                        />
                        <SelectField
                            id="registration-brand"
                            v-model="registration.partnership"
                            label="Brand"
                            required
                            :options="brandOptions"
                            :searchable="brandOptions.length > 8"
                            :disabled="!registration.entry"
                            :error="registration.errors.partnership"
                        />
                    </div>
                    <SelectField
                        v-if="registrationCampaigns.length"
                        id="registration-campaign"
                        v-model="registration.campaign"
                        label="Source campaign (optional)"
                        hint="The brief this lead came from. The Brand sees the campaign and when you linked it."
                        :options="registrationCampaigns"
                        :error="registration.errors.campaign"
                    />
                    <p v-if="chosenLead && brandOptions.length === 1" class="wd-hint" role="status">
                        {{ chosenLead.brand_name }} is already shared with every available Brand. Paused Brands cannot
                        receive leads.
                    </p>
                    <FormField
                        id="registration-name"
                        v-model="registration.display_name"
                        label="Lead name shown to the Brand"
                        :maxlength="160"
                        hint="Starts as your private lead name. Change it if the Brand should see a different name."
                        :error="registration.errors.display_name"
                    />
                    <FieldGroup
                        id="registration-summary"
                        label="Summary for the Brand (optional)"
                        :hint="`What the lead is interested in. Do not paste private notes. ${registration.summary.length} / 1000`"
                        :error="registration.errors.summary"
                    >
                        <template #default="{ id, describedby, invalid }">
                            <textarea
                                :id="id"
                                v-model="registration.summary"
                                rows="3"
                                maxlength="1000"
                                :aria-describedby="describedby"
                                :aria-invalid="invalid"
                            ></textarea>
                        </template>
                    </FieldGroup>
                    <label for="registration-contact" class="flex items-start gap-2">
                        <input
                            id="registration-contact"
                            v-model="registration.share_contact"
                            type="checkbox"
                            class="mt-1 shrink-0 accent-[var(--wd-brand)]"
                            aria-describedby="registration-contact-hint"
                        />Also share this lead's contact name and email
                    </label>
                    <p id="registration-contact-hint" class="wd-hint">Currently: {{ contact }}.</p>
                    <div class="bs-sees" role="note">
                        <div>
                            <p class="bs-sees-title">What the Brand will see</p>
                            <p>
                                The lead name and summary, your workspace name, the lead's stage as you move it, and
                                when it was shared<template v-if="registration.share_contact"
                                    >, plus the contact name and email</template
                                >.
                            </p>
                        </div>
                        <div>
                            <p class="bs-sees-title">Never shared</p>
                            <p>
                                Private notes, estimated value, reminders, activity history, onboarding documents, your
                                other leads and other Brands.
                            </p>
                        </div>
                    </div>
                </template>
                <div class="wd-dialog-footer">
                    <button type="button" class="wd-button wd-button-secondary" @click="closeShare">Cancel</button>
                    <Button
                        type="submit"
                        class="wd-button wd-button-primary"
                        :disabled="registration.processing || !shareableLeads.length"
                        >Share lead</Button
                    >
                </div>
            </fieldset>
        </form>
    </dialog>
    <dialog
        ref="editDialog"
        class="wd-dialog"
        aria-labelledby="edit-registration-title"
        @cancel.prevent="editing = null"
        @keydown="containDialogFocus($event, editDialog)"
    >
        <div class="wd-dialog-header">
            <h2 id="edit-registration-title">Edit shared details</h2>
            <DialogClose label="Close shared details dialog" @click="editing = null" />
        </div>
        <form v-if="editing" class="grid gap-4" :aria-busy="edit.processing" @submit.prevent="saveEdit">
            <p>
                Shared with <strong>{{ editing.brand_name ?? 'this Brand' }}</strong
                >. The stage follows your private lead and is not edited here.
            </p>
            <FormField
                id="edit-registration-name"
                v-model="edit.display_name"
                label="Lead name shown to the Brand"
                :maxlength="160"
                :error="edit.errors.display_name"
            />
            <FieldGroup
                id="edit-registration-summary"
                label="Summary for the Brand (optional)"
                :error="edit.errors.summary"
            >
                <template #default="{ id, describedby, invalid }">
                    <textarea
                        :id="id"
                        v-model="edit.summary"
                        rows="3"
                        maxlength="1000"
                        :aria-describedby="describedby"
                        :aria-invalid="invalid"
                    ></textarea>
                </template>
            </FieldGroup>
            <label for="edit-registration-contact" class="flex items-start gap-2">
                <input
                    id="edit-registration-contact"
                    v-model="edit.share_contact"
                    type="checkbox"
                    class="mt-1 shrink-0 accent-[var(--wd-brand)]"
                />Share this lead's contact name and email
            </label>
            <SelectField
                v-if="editCampaigns.length"
                id="edit-registration-campaign"
                v-model="edit.campaign"
                label="Source campaign (optional)"
                hint="The Brand sees the campaign and when you linked it. Changing it records a new link date."
                :options="editCampaigns"
                :error="edit.errors.campaign"
            />
            <p v-if="edit.errors.version" class="wd-field-error" role="alert">{{ edit.errors.version }}</p>
            <div class="wd-dialog-footer">
                <button type="button" class="wd-button wd-button-secondary" @click="editing = null">Cancel</button>
                <Button type="submit" class="wd-button wd-button-primary" :disabled="edit.processing"
                    >Save shared details</Button
                >
            </div>
        </form>
    </dialog>
    <dialog
        ref="profileDialog"
        class="wd-dialog bs-dialog"
        aria-labelledby="profile-dialog-title"
        @close="onProfileClosed"
        @keydown="containDialogFocus($event, profileDialog)"
    >
        <div class="wd-dialog-header">
            <h2 id="profile-dialog-title">Edit shared profile</h2>
            <DialogClose label="Close shared profile dialog" @click="profileDialog?.close()" />
        </div>
        <form v-if="profileOpen" :aria-busy="shared.processing" @submit.prevent="saveProfile">
            <fieldset class="wd-fields" :disabled="shared.processing || !canManage">
                <legend class="wd-sr-only">Shared business profile</legend>
                <p class="wd-hint">
                    Optional. Every Brand you are connected to can see what you save here. Your account email, phone
                    number, address and workspace members are never shared.
                </p>
                <div class="bs-pair">
                    <FormField
                        id="profile-contact-name"
                        v-model="shared.contact_name"
                        label="Business contact name"
                        :required="false"
                        :maxlength="120"
                        autocomplete="off"
                        :error="shared.errors.contact_name"
                    />
                    <FormField
                        id="profile-contact-email"
                        v-model="shared.contact_email"
                        label="Business contact email"
                        type="email"
                        :required="false"
                        :maxlength="255"
                        autocomplete="off"
                        :error="shared.errors.contact_email"
                    />
                </div>
                <FormField
                    id="profile-website"
                    v-model="shared.website_url"
                    label="Website or public profile"
                    :required="false"
                    :maxlength="255"
                    hint="A full web address starting with https:// or http://."
                    :error="shared.errors.website_url"
                />
                <FieldGroup
                    id="profile-description"
                    label="Short business description"
                    :hint="`${shared.description.length} / 500`"
                    :error="shared.errors.description"
                >
                    <template #default="{ id, describedby, invalid }">
                        <textarea
                            :id="id"
                            v-model="shared.description"
                            rows="3"
                            maxlength="500"
                            :aria-describedby="describedby"
                            :aria-invalid="invalid"
                        ></textarea>
                    </template>
                </FieldGroup>
                <p v-if="shared.errors.version" class="wd-field-error" role="alert">
                    {{ shared.errors.version }}
                </p>
                <div class="wd-dialog-footer">
                    <button type="button" class="wd-button wd-button-secondary" @click="profileDialog?.close()">
                        Cancel
                    </button>
                    <Button type="submit" class="wd-button wd-button-primary">Save shared profile</Button>
                </div>
            </fieldset>
        </form>
    </dialog>
</template>

<style scoped>
.bs {
    color: var(--wd-ink);
    font-size: 14px;
}
.bs-header {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-end;
    justify-content: space-between;
    gap: 12px 24px;
}
.bs h1 {
    margin: 0;
    font-family: var(--wd-font-display);
    font-size: 28px;
    font-weight: 700;
    letter-spacing: -0.01em;
    line-height: 1.15;
}
.bs-sub {
    margin: 4px 0 0;
    color: var(--wd-muted);
    font-size: 13px;
}
.bs-primary {
    border-color: var(--wd-brand);
    background: var(--wd-brand);
    color: #fff;
}
.bs .bs-primary:hover:not(:disabled) {
    border-color: var(--wd-brand-hover);
    background: var(--wd-brand-hover);
    color: #fff;
}
.bs-stats {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 10px;
    margin: 0;
    padding: 0;
    list-style: none;
}
.bs-stats li {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    padding: 10px 14px;
    border: 1px solid var(--wd-line);
    border-radius: 10px;
    background: var(--wd-surface);
}
.bs-stats span {
    color: var(--wd-muted);
    font-size: 12px;
    font-weight: 600;
}
.bs-stats strong {
    font-family: var(--wd-font-display);
    font-size: 20px;
    line-height: 1;
}
.bs-grid {
    display: grid;
    grid-template-columns: minmax(0, 1.9fr) minmax(300px, 1fr);
    align-items: start;
    gap: 14px;
}
.bs-side {
    display: flex;
    flex-direction: column;
    gap: 14px;
    min-width: 0;
}
.bs-card {
    display: flex;
    flex-direction: column;
    min-width: 0;
    border: 1px solid var(--wd-line);
    border-radius: 12px;
    background: var(--wd-surface);
    overflow: clip;
}
.bs-card-head {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 12px 14px;
}
.bs-card-head h2 {
    margin: 0;
    font-family: var(--wd-font-display);
    font-size: 16px;
    font-weight: 600;
}
.bs-card-head > button {
    margin-left: auto;
}
.bs-count {
    margin-right: auto;
    margin-left: -6px;
}
#registrations-title,
#brands-title {
    margin-right: 0;
}
.bs-count {
    padding: 1px 7px;
    border-radius: 999px;
    background: var(--wd-divider);
    color: var(--wd-muted);
    font-size: 12px;
    font-weight: 700;
}
.bs-toolbar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
    padding: 0 14px 10px;
}
.bs-inline-field select {
    min-width: 150px;
    font-size: 13px;
    font-weight: 600;
}
.bs-search {
    position: relative;
    display: flex;
    flex: 1 1 180px;
    align-items: center;
}
.bs-search svg {
    position: absolute;
    left: 10px;
    color: var(--wd-muted);
    pointer-events: none;
}
.bs-search input {
    width: 100%;
    padding-left: 32px;
    font-size: 13px;
}
.bs-pad {
    margin: 0;
    padding: 0 14px 12px;
}
/* The table sits inside the card's side padding with its own rounded border, as on other pages. */
.bs-table {
    margin: 0 14px 14px;
}
.bs-table :deep(.wd-data-table) {
    font-size: 13px;
}
.bs-table :deep(.wd-data-table th),
.bs-table :deep(.wd-data-table td) {
    padding: 8px 10px;
    vertical-align: middle;
}
.bs-table :deep(.wd-data-table tr > :first-child) {
    padding-left: 14px;
}
.bs-table :deep(.wd-data-table tr > :last-child) {
    width: 1%;
    padding-right: 10px;
    text-align: right;
    white-space: nowrap;
}
.bs-table :deep(.wd-data-table tbody th) {
    min-width: 200px;
}
.bs-table :deep(.wd-data-table tbody th a) {
    font-weight: 700;
}
.bs-lead-meta {
    max-width: 320px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.bs-row-actions {
    display: inline-flex;
    gap: 2px;
}
.bs-ghost {
    border-color: transparent;
    background: transparent;
    color: var(--wd-muted);
}
.bs-empty {
    margin: 0 14px 14px;
}
.bs-pagination {
    padding: 0 14px 14px;
}
.bs-brands {
    margin: 0;
    padding: 0;
    list-style: none;
}
.bs-brands li {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 9px 14px;
    border-top: 1px solid var(--wd-divider);
}
.bs-brand-name {
    display: flex;
    flex: 1;
    flex-direction: column;
    min-width: 0;
    overflow-wrap: anywhere;
}
.bs-brand-name small {
    color: var(--wd-muted);
    font-size: 12px;
}
.bs-profile {
    display: grid;
    grid-template-columns: 70px minmax(0, 1fr);
    gap: 4px 10px;
    margin: 0;
    padding: 0 14px 12px;
}
.bs-profile dt {
    color: var(--wd-muted);
    font-size: 12px;
    font-weight: 600;
}
.bs-profile dd {
    margin: 0;
    overflow-wrap: anywhere;
}
.bs-note {
    margin: 0 14px 14px;
    padding: 10px 12px;
    border-radius: 10px;
    background: var(--wd-paper);
    color: var(--wd-body);
    font-size: 12px;
}
.bs-dialog[open] {
    display: flex;
    flex-direction: column;
    gap: 14px;
    width: min(540px, calc(100vw - 32px));
}
.bs-dialog .wd-dialog-header {
    margin-bottom: 0;
}
.bs-pair {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12px;
}
.bs-sees {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    border: 1px solid var(--wd-line);
    border-radius: 10px;
    font-size: 12.5px;
    overflow: hidden;
}
.bs-sees > div {
    padding: 10px 12px;
}
.bs-sees > div + div {
    border-left: 1px solid var(--wd-line);
    background: var(--wd-paper);
}
.bs-sees p {
    margin: 0;
}
.bs-sees-title {
    margin-bottom: 4px !important;
    font-weight: 700;
}
@media (min-width: 600px) and (pointer: fine) {
    .bs-header,
    .bs-toolbar,
    .bs-table,
    .bs-card-head {
        --wd-table-control: 32px;
    }
}
@media (max-width: 1099px) {
    .bs-grid {
        grid-template-columns: minmax(0, 1fr);
    }
}
@media (max-width: 599px) {
    .bs h1 {
        font-size: 24px;
    }
    .bs-stats {
        grid-template-columns: minmax(0, 1fr);
        gap: 6px;
    }
    .bs-stats li {
        padding: 8px 12px;
    }
    .bs-inline-field,
    .bs-inline-field select {
        width: 100%;
    }
    .bs-pair,
    .bs-sees {
        grid-template-columns: minmax(0, 1fr);
    }
    .bs-sees > div + div {
        border-top: 1px solid var(--wd-line);
        border-left: 0;
    }
    /* Each registration is a compact row: lead and status, then Brand and stage, with actions on the right. */
    .bs-table :deep(.wd-data-table tbody tr) {
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        gap: 2px 8px;
        margin: 0;
        padding: 10px 12px 10px 14px;
        border: 0;
        border-top: 1px solid var(--wd-divider);
        border-radius: 0;
    }
    .bs-table :deep(.wd-data-table tbody tr + tr) {
        margin-top: 0;
    }
    .bs-table :deep(.wd-data-table tbody tr > *) {
        width: auto;
        padding: 0;
        text-align: left;
    }
    .bs-table :deep(.wd-data-table tbody tr > th:first-child) {
        grid-column: 1;
        min-width: 0;
        padding-left: 0;
    }
    .bs-table :deep(.wd-data-table tbody td[data-label]::before) {
        display: none;
    }
    .bs-table :deep(.wd-data-table tbody td[data-label='Sharing']) {
        grid-row: 1;
        grid-column: 2;
        justify-self: end;
    }
    .bs-table :deep(.wd-data-table tbody td[data-label='Sharing'] .wd-data-secondary),
    .bs-table :deep(.wd-data-table tbody td[data-label='First shared']) {
        display: none;
    }
    .bs-table :deep(.wd-data-table tbody td[data-label='Brand']),
    .bs-table :deep(.wd-data-table tbody td[data-label='Stage']) {
        display: inline;
        grid-column: 1;
        color: var(--wd-muted);
        font-size: 12px;
    }
    .bs-table :deep(.wd-data-table tbody td[data-label='Actions']) {
        grid-row: 2 / 4;
        grid-column: 2;
        justify-self: end;
        width: auto;
    }
}
</style>
