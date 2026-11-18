<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import ContentSection from '../components/ContentSection.vue';
import DataTable, { type DataColumn } from '../components/DataTable.vue';
import DialogClose from '../components/DialogClose.vue';
import EmptyState from '../components/EmptyState.vue';
import FieldGroup from '../components/FieldGroup.vue';
import FormField from '../components/FormField.vue';
import Pagination from '../components/Pagination.vue';
import SelectField from '../components/SelectField.vue';
import StatusBadge, { type BadgeTone } from '../components/StatusBadge.vue';
import { Button } from '../components/ui/button';
import { confirmAction } from '../composables/useConfirmation';
import { containDialogFocus } from '../composables/useDialogFocus';
import { useWorkspaceExperience } from '../composables/useWorkspaceExperience';
import { dateTime, query } from '../features/admin/format';
import PartnerChrome from '../features/portals/PartnerChrome.vue';
import { stageLabel } from '../features/portals/activity';
import type { OwnRegistration, PageMeta, SharedProfile, StageOption } from '../features/portals/types';

const props = defineProps<{
    account: { name: string; email: string };
    workspace: { id: string; name: string };
    canManage: boolean;
    brands: { id: string; brand_name: string; paused: boolean }[];
    lead: {
        id: string;
        brand_name: string;
        contact_name: string | null;
        contact_email: string | null;
        stage: string;
        registered_with: string[];
    } | null;
    registrations: OwnRegistration[];
    pagination: PageMeta;
    profile: SharedProfile & { version: number };
    stages: StageOption[];
    errors?: Record<string, string>;
}>();
const { path } = useWorkspaceExperience();
const self = computed(() => path('/partner/brand-sharing'));

// A lead can be registered with each connected Brand once; paused Brands cannot receive a registration.
const brandOptions = computed(() => [
    { value: '', label: 'Choose a Brand' },
    ...props.brands
        .filter((brand) => !brand.paused && !props.lead?.registered_with.includes(brand.id))
        .map((brand) => ({ value: brand.id, label: brand.brand_name })),
]);
const registration = useForm({
    partnership: '',
    entry: props.lead?.id ?? '',
    display_name: props.lead?.brand_name ?? '',
    summary: '',
    share_contact: false,
});
const contact = computed(
    () => [props.lead?.contact_name, props.lead?.contact_email].filter(Boolean).join(' · ') || 'no contact saved yet',
);
function register(): void {
    registration.post('/lead-registrations', { preserveScroll: true });
}

const columns: DataColumn[] = [
    { key: 'display_name', label: 'Lead' },
    { key: 'brand_name', label: 'Brand' },
    { key: 'stage', label: 'Stage' },
    { key: 'state', label: 'Sharing' },
    { key: 'registered_at', label: 'First registered' },
    { key: 'actions', label: 'Actions' },
];
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
function withdraw(row: OwnRegistration, event: Event): void {
    confirmAction(
        'Withdraw registration',
        `Withdraw “${row.display_name}” from ${row.brand_name ?? 'this Brand'}? They will no longer see this lead or its updates. Your private lead is kept.`,
        () => router.delete(`/lead-registrations/${row.id}`, { data: { version: row.version }, preserveScroll: true }),
        event,
        { tone: 'danger' },
    );
}

const editing = ref<OwnRegistration | null>(null);
const editDialog = ref<HTMLDialogElement | null>(null);
let editTrigger: HTMLElement | null = null;
const edit = useForm({ version: 0, display_name: '', summary: '', share_contact: false });
function openEdit(row: OwnRegistration, event: Event): void {
    editTrigger = event.currentTarget as HTMLElement;
    edit.clearErrors();
    edit.defaults({
        version: row.version,
        display_name: row.display_name,
        summary: row.summary,
        share_contact: row.share_contact,
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
function saveProfile(): void {
    shared.put('/partner-shared-profile', { preserveScroll: true });
}
</script>

<template>
    <Head title="Brand sharing" />
    <PartnerChrome current="brand-sharing" :account-name="account.name" :account-email="account.email">
        <main id="main-content" class="blv-page mx-auto flex max-w-[1100px] flex-col gap-6 px-6 pt-7 pb-12">
            <div>
                <h1 class="font-display text-[32px] font-bold">Brand sharing</h1>
                <p class="mt-1.5 max-w-[760px] text-[15px] text-ink-soft">
                    Your leads are private. A connected Brand sees a lead only after you register it with that Brand
                    here, and only the details listed below.
                </p>
            </div>
            <p v-if="!canManage" class="wd-warning" role="status">
                Only workspace owners and admins can register leads with a Brand, withdraw them or edit the shared
                profile. You can view what is shared.
            </p>

            <ContentSection
                v-if="canManage"
                title="Register a lead with a Brand"
                description="Registering tells one connected Brand that you are bringing them this lead."
            >
                <EmptyState
                    v-if="!brands.length"
                    title="No connected Brands"
                    description="Accept a Brand's invitation first. You can register leads with Brands you are connected to."
                />
                <EmptyState
                    v-else-if="!lead"
                    title="Choose a lead first"
                    description="Open a lead from My leads and choose Register with a Brand."
                >
                    <Link :href="path('/partner/leads')" class="wd-link">Go to my leads</Link>
                </EmptyState>
                <EmptyState
                    v-else-if="brandOptions.length === 1"
                    :title="`${lead.brand_name} is already registered with every available Brand`"
                    description="Edit or withdraw a registration in the list below. Paused Brands cannot receive registrations."
                />
                <form v-else :aria-busy="registration.processing" @submit.prevent="register">
                    <fieldset class="wd-fields" :disabled="registration.processing">
                        <legend class="wd-sr-only">Register a lead with a Brand</legend>
                        <p>
                            Lead: <strong>{{ lead.brand_name }}</strong> · {{ stageLabel(stages, lead.stage) }}
                        </p>
                        <SelectField
                            id="registration-brand"
                            v-model="registration.partnership"
                            label="Brand"
                            required
                            :options="brandOptions"
                            :searchable="brandOptions.length > 8"
                            :error="registration.errors.partnership"
                        />
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
                            hint="For example what the lead is interested in. Do not paste private notes."
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
                        <div class="rounded-xl bg-cream p-4 text-[14px]" role="note">
                            <p class="font-bold">What the Brand will see</p>
                            <ul class="mt-1 list-disc pl-5">
                                <li>The lead name and summary above, and your workspace name</li>
                                <li>The lead's current stage, kept up to date as you move it</li>
                                <li>When you registered it and when a shared detail last changed</li>
                                <li v-if="registration.share_contact">The lead's contact name and email</li>
                            </ul>
                            <p class="mt-2 font-bold">Never shared</p>
                            <p>
                                Private notes, estimated value, reminders, activity history, onboarding documents, your
                                other leads and other Brands.
                            </p>
                        </div>
                        <p v-if="registration.errors.entry" class="wd-field-error" role="alert">
                            {{ registration.errors.entry }}
                        </p>
                        <div>
                            <Button type="submit" class="wd-button wd-button-primary">Register lead with Brand</Button>
                        </div>
                    </fieldset>
                </form>
            </ContentSection>

            <ContentSection
                title="Leads registered with Brands"
                description="Withdraw a registration at any time to stop sharing it. Its first registration date is kept if you share it again."
            >
                <p v-if="errors?.version && !editing" class="wd-field-error" role="alert">{{ errors.version }}</p>
                <DataTable
                    v-if="registrations.length"
                    caption="Leads registered with Brands"
                    :columns="canManage ? columns : columns.filter((column) => column.key !== 'actions')"
                    :rows="registrations"
                    :row-key="(row) => row.id"
                >
                    <template #cell-display_name="{ row }">
                        <Link
                            v-if="row.entry_id"
                            :href="`${path('/partner/leads')}${query({ entry: row.entry_id })}`"
                            >{{ row.display_name }}</Link
                        >
                        <template v-else>{{ row.display_name }}</template>
                    </template>
                    <template #cell-brand_name="{ row }">{{ row.brand_name ?? 'Ended partnership' }}</template>
                    <template #cell-stage="{ row }">{{
                        row.state === 'active' ? stageLabel(stages, row.stage) : '—'
                    }}</template>
                    <template #cell-state="{ row }">
                        <StatusBadge :label="sharing(row).label" :tone="sharing(row).tone" />
                        <span v-if="sharing(row).note" class="wd-data-secondary">{{ sharing(row).note }}</span>
                    </template>
                    <template #cell-registered_at="{ row }">{{ dateTime(row.registered_at) }}</template>
                    <template #cell-actions="{ row }">
                        <div v-if="row.state === 'active'" class="wd-action-group">
                            <button
                                type="button"
                                class="wd-link"
                                v-bind="{ 'aria-label': `Edit shared details of ${row.display_name}` }"
                                @click="openEdit(row, $event)"
                            >
                                Edit shared details
                            </button>
                            <button
                                type="button"
                                class="wd-action-danger"
                                v-bind="{ 'aria-label': `Withdraw registration of ${row.display_name}` }"
                                @click="withdraw(row, $event)"
                            >
                                Withdraw
                            </button>
                        </div>
                    </template>
                </DataTable>
                <EmptyState
                    v-else
                    title="No leads registered with a Brand"
                    description="Nothing from your pipeline is visible to any Brand."
                />
                <Pagination
                    class="mt-4"
                    label="Registered leads pages"
                    noun="registrations"
                    :page="pagination.page"
                    :per-page="pagination.perPage"
                    :count="registrations.length"
                    :total="pagination.total"
                    :href-for="(page) => `${self}${query({ page })}`"
                />
            </ContentSection>

            <ContentSection
                title="Business profile shared with connected Brands"
                description="Optional. Every Brand you are connected to can see what you save here. Your account email, phone number, address and workspace members are never shared."
            >
                <form :aria-busy="shared.processing" @submit.prevent="saveProfile">
                    <fieldset class="wd-fields" :disabled="shared.processing || !canManage">
                        <legend class="wd-sr-only">Shared business profile</legend>
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
                            hint="A business address you are happy for Brands to use."
                            :error="shared.errors.contact_email"
                        />
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
                        <div v-if="canManage">
                            <Button type="submit" class="wd-button wd-button-primary">Save shared profile</Button>
                        </div>
                    </fieldset>
                </form>
            </ContentSection>
        </main>
    </PartnerChrome>
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
            <p v-if="edit.errors.version" class="wd-field-error" role="alert">{{ edit.errors.version }}</p>
            <div class="wd-dialog-footer">
                <button type="button" class="wd-button wd-button-secondary" @click="editing = null">Cancel</button>
                <Button type="submit" class="wd-button wd-button-primary" :disabled="edit.processing"
                    >Save shared details</Button
                >
            </div>
        </form>
    </dialog>
</template>
