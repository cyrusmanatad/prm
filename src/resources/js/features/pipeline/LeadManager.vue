<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { Button } from '../../components/ui/button';
import FormField from '../../components/FormField.vue';
import EntryFields from './EntryFields.vue';
import { fromMinor, money, toMinor } from './money';
import type { LeadForm, PipelineWorkspace } from './types';
import { useValidationFocus } from '../../composables/useValidationFocus';

const props = defineProps<PipelineWorkspace & { workspace: { id: string; name: string }; errors?: Record<string, string>; status?: string }>();
const root = ref<HTMLElement>();
const dialog = ref<HTMLDialogElement>();
const creating = ref(false);
const active = computed(() => !!props.selected || creating.value);
function openCreate(): void { if (props.canWrite) creating.value = true; }
defineExpose({ openCreate });
onMounted(() => { if (location.hash === '#create' || new URLSearchParams(location.search).get('create') === '1') openCreate(); });
watch([() => props.selected?.id, creating], async () => {
    await nextTick();
    if (active.value) {
        if (!dialog.value?.open) dialog.value?.showModal();
        dialog.value?.querySelector<HTMLElement>(props.selected ? (props.canWrite ? '#edit-brand' : 'button') : '#create-brand')?.focus();
    } else { dialog.value?.close(); }
}, { immediate: true });
function close(): void {
    if (busy.value) return;
    if (props.selected) {
        const params = new URLSearchParams(location.search);
        params.delete('entry'); params.delete('create');
        router.get('/partner/leads', Object.fromEntries(params), { preserveScroll: true, preserveState: true });
    }
    else { creating.value = false; dialog.value?.close(); }
}
const busy = ref(false);
const formAction = ref('');
const loadedVersion = ref(0);
const formErrors = ref<Record<string, string>>({});
const errors = computed(() => ({ ...(props.errors ?? {}), ...formErrors.value }));
useValidationFocus(root, () => errors.value, () => !busy.value);
const blank = (): LeadForm => ({ brand_name: '', contact_name: '', contact_email: '', notes: '', currency: 'PHP', amount: '0', next_action_due: '' });
const createForm = ref(blank());
const editForm = ref(blank());
const stage = ref('lead');
const snooze = ref('');
const activityKind = ref('note');
const activityBody = ref('');
const confirmDelete = ref(false);
const query = ref(props.filters.q);
const stageFilter = ref(props.filters.stage);
const dueOnly = ref(props.filters.due);
const stamp = (value: string | null): string => value ? new Date(value).toLocaleString() : 'No next action';
const localInput = (value: string | null): string => {
    if (!value) return '';
    const date = new Date(value);
    return new Date(date.getTime() - date.getTimezoneOffset() * 60_000).toISOString().slice(0, 16);
};
watch([() => props.workspace.id, () => props.selected?.id], () => {
    const row = props.selected;
    if (row) creating.value = false;
    editForm.value = row ? { brand_name: row.brand_name, contact_name: row.contact_name ?? '', contact_email: row.contact_email ?? '',
        notes: row.notes, amount: fromMinor(row.value_minor, row.currency), currency: row.currency, next_action_due: localInput(row.next_action_due) } : blank();
    loadedVersion.value = row?.version ?? 0;
    stage.value = row?.stage ?? 'lead';
    activityBody.value = ''; snooze.value = ''; confirmDelete.value = false; formErrors.value = {};
}, { immediate: true });
watch(() => props.workspace.id, () => { createForm.value = blank(); });
// Keep unsaved edits after stale-version errors. A successful save refreshes the form.
function refreshEdit(): void {
    const row = props.selected;
    if (row) editForm.value = { brand_name: row.brand_name, contact_name: row.contact_name ?? '', contact_email: row.contact_email ?? '', notes: row.notes,
        amount: fromMinor(row.value_minor, row.currency), currency: row.currency, next_action_due: localInput(row.next_action_due) };
}
function visit(method: 'post' | 'patch' | 'delete', path: string, data: Record<string, string | number | null>, done?: () => void): void {
    if (busy.value || errors.value.version) return;
    formErrors.value = {};
    router.visit(path, { method, data, preserveScroll: true, preserveState: true,
        onStart: () => { busy.value = true; }, onFinish: () => { busy.value = false; }, onSuccess: () => {
            if (formAction.value === 'create' || formAction.value === 'update') {
                loadedVersion.value = props.selected?.version ?? 0;
                refreshEdit();
            } else if (typeof data.version === 'number') {
                // Keep the draft's acknowledged version if another edit won the redirect race.
                loadedVersion.value = data.version + 1;
            }
            if (formAction.value === 'snooze') editForm.value.next_action_due = localInput(props.selected?.next_action_due ?? null);
            done?.();
        },
    });
}
function details(form: LeadForm): Record<string, string | null> | null {
    const amount = toMinor(form.amount, form.currency);
    if (amount === null) { formErrors.value = { value_minor: 'Enter a valid value for this currency and a three-letter uppercase currency code.' }; return null; }
    return { brand_name: form.brand_name, contact_name: form.contact_name, contact_email: form.contact_email, notes: form.notes,
        value_minor: amount, currency: form.currency, next_action_due: form.next_action_due ? new Date(form.next_action_due).toISOString() : null };
}
function create(): void {
    formAction.value = 'create';
    const data = details(createForm.value);
    if (data) visit('post', '/pipeline', data, () => { createForm.value = blank(); creating.value = false; });
}
function update(): void {
    formAction.value = 'update';
    const data = details(editForm.value);
    if (data && props.selected) visit('patch', `/pipeline/${props.selected.id}`, { ...data, version: loadedVersion.value });
}
function command(action: string, data: Record<string, string | null>, done?: () => void): void {
    formAction.value = action;
    if (props.selected) visit('post', `/pipeline/${props.selected.id}/${action}`, { ...data, version: loadedVersion.value }, done);
}
function filter(cursor?: string): void {
    const params: Record<string, string> = {};
    if (query.value) params.q = query.value;
    if (stageFilter.value) params.stage = stageFilter.value;
    if (dueOnly.value) params.due = '1';
    if (cursor) params.cursor = cursor;
    router.get('/partner/leads', params, { preserveState: false });
}
function startOnboarding(): void {
    if(busy.value || errors.value.version || !props.selected) return;
    router.post('/onboarding-requests',{pipeline_entry_id:props.selected.id,label:`Onboarding for ${props.selected.brand_name}`.slice(0,160)},
        {onStart:()=>{busy.value=true;},onFinish:()=>{busy.value=false;}});
}

function reloadSelected(): void {
    if (props.selected) router.get('/partner/leads', { entry: props.selected.id }, { preserveState: false });
}
</script>

<template>
    <div ref="root" class="wd-pipeline">
        <template v-if="!active"><p v-if="status" role="status" class="wd-status">{{ status }}</p>
        <div v-if="errors.version" class="wd-status" role="alert"><p>{{ errors.version }}</p><button type="button" class="wd-link" @click="reloadSelected">Reload latest lead</button><p class="wd-hint">Copy any unsaved changes before reloading.</p></div>
        <ul v-if="Object.keys(errors).some(key => key !== 'version')" class="wd-field-error" role="alert"><li v-for="(message, field) in errors" :key="field">{{ message }}</li></ul></template>
        <section aria-label="Pipeline totals" class="wd-pipeline-totals">
            <p v-if="!totals.length" class="wd-hint">No estimated value yet.</p>
            <p v-for="total in totals" :key="total.currency"><strong>{{ money(total.amount_minor, total.currency) }}</strong><br><span class="wd-hint">{{ total.count }} {{ total.count === 1 ? 'lead' : 'leads' }} · excludes Lost · current filters</span></p>
        </section>
        <form class="wd-pipeline-filters" aria-label="Filter leads" @submit.prevent="filter()">
            <FormField id="pipeline-search" v-model="query" :required="false" label="Search brands" :maxlength="160" />
            <div class="wd-field"><label for="stage-filter">Stage filter</label><select id="stage-filter" v-model="stageFilter"><option value="">All stages</option><option v-for="option in stages" :key="option.value" :value="option.value">{{ option.label }}</option></select></div>
            <label class="wd-checkbox"><input v-model="dueOnly" type="checkbox">Due next actions only</label>
            <Button class="wd-button wd-button-secondary" type="submit">Apply filters</Button>
            <Link class="wd-link" href="/partner/leads">Clear filters</Link>
        </form>
        <p v-if="!canWrite" class="wd-hint">Your viewer role provides read-only access to this pipeline.</p>
        <p v-if="!entries.length" class="wd-empty" role="status">{{ filters.q || filters.stage || filters.due ? 'No leads match these filters.' : 'Your pipeline is empty. Capture your first lead to get started.' }}</p>
        <div class="my-3 flex flex-wrap gap-4 text-[13px]"><Link class="wd-link" href="/partner/leads?stage=repeat-client">Repeat Client</Link><Link class="wd-link" href="/partner/leads?stage=lost">Lost</Link></div>
        <ul v-if="entries.some(e => ['repeat-client', 'lost'].includes(e.stage))" aria-label="Other stage leads"><li v-for="entry in entries.filter(e => ['repeat-client', 'lost'].includes(e.stage))" :key="entry.id"><Link class="wd-link" :href="`/partner/leads?entry=${entry.id}`">{{ entry.brand_name }} · {{ entry.stage }}</Link></li></ul>
        <slot />
        <p v-if="nextCursor" class="wd-hint">Showing 25 leads. Stage counts and totals include every matching lead.</p>
        <button v-if="nextCursor" class="wd-link" type="button" @click="filter(nextCursor)">Next 25 leads</button>
        <dialog ref="dialog" :class="['lead-dialog', { 'lead-drawer': selected }]" aria-labelledby="lead-dialog-title" @cancel.prevent="close">
            <div class="mb-4 flex items-start justify-between gap-4"><h2 id="lead-dialog-title">{{ selected ? selected.brand_name : 'Add lead' }}</h2><button type="button" class="wd-link shrink-0" :disabled="busy" @click="close">{{ selected ? 'Close lead' : 'Cancel' }}</button></div>
            <p v-if="status" role="status" class="wd-status">{{ status }}</p>
        <div v-if="errors.version" class="wd-status" role="alert"><p>{{ errors.version }}</p><button type="button" class="wd-link" @click="reloadSelected">Reload latest lead</button><p class="wd-hint">Copy any unsaved changes before reloading.</p></div>
        <ul v-if="Object.keys(errors).some(key => key !== 'version')" class="wd-field-error" role="alert"><li v-for="(message, field) in errors" :key="field">{{ message }}</li></ul>
            <form v-if="!selected && creating && canWrite" :aria-busy="busy" @submit.prevent="create">
                <fieldset class="wd-fields" :disabled="busy || !!errors.version"><legend class="wd-sr-only">New lead</legend>
                    <EntryFields v-model="createForm" prefix="create" :errors="formAction === 'create' ? errors : {}" />
                    <Button type="submit" class="wd-button wd-button-primary">{{ busy ? 'Saving…' : 'Capture lead' }}</Button>
                </fieldset>
            </form>
                        <section v-if="selected" class="wd-pipeline-detail" aria-label="Lead actions">
                <div class="wd-page-heading"><Link class="wd-link" :href="`/partner/contacts?entry=${selected.id}`">Manage contacts and reminders for this lead</Link></div>
                <template v-if="canWrite">
                    <form :aria-busy="busy" @submit.prevent="update"><fieldset class="wd-fields" :disabled="busy || !!errors.version"><legend>Lead details</legend>
                        <EntryFields v-model="editForm" prefix="edit" :errors="formAction === 'update' ? errors : {}" />
                        <Button class="wd-button wd-button-primary" type="submit">{{ busy ? 'Saving…' : 'Save lead' }}</Button>
                    </fieldset></form>
                    <Button type="button" class="wd-button wd-button-secondary" :disabled="busy || !!errors.version" @click="startOnboarding">Start onboarding</Button>
                    <div class="wd-pipeline-actions">
                        <form :aria-busy="busy" @submit.prevent="command('stage', { stage })"><fieldset class="wd-fields" :disabled="busy || !!errors.version"><legend>Move stage</legend>
                            <div class="wd-field"><label for="lead-stage">Stage</label><select id="lead-stage" v-model="stage"><option v-for="option in stages" :key="option.value" :value="option.value">{{ option.label }}</option></select></div>
                            <Button class="wd-button wd-button-secondary" type="submit">Move lead</Button>
                        </fieldset></form>
                        <form :aria-busy="busy" @submit.prevent="command('snooze', { next_action_due: snooze ? new Date(snooze).toISOString() : '' })"><fieldset class="wd-fields" :disabled="busy || !!errors.version"><legend>Snooze next action</legend>
                            <FormField id="snooze-due" v-model="snooze" label="Snooze until (your local time)" type="datetime-local" :error="formAction === 'snooze' ? errors.next_action_due : undefined" />
                            <Button class="wd-button wd-button-secondary" type="submit">Snooze next action</Button>
                        </fieldset></form>
                    </div>
                    <form :aria-busy="busy" @submit.prevent="command('activities', { kind: activityKind, body: activityBody }, () => { activityBody = ''; })"><fieldset class="wd-fields" :disabled="busy || !!errors.version"><legend>Log an activity</legend>
                        <div class="wd-field"><label for="activity-kind">Activity type</label><select id="activity-kind" v-model="activityKind"><option value="note">Note</option><option value="email">Email</option><option value="chat">Chat</option><option value="call">Call</option></select></div>
                        <div class="wd-field"><label for="activity-body">Activity details</label><textarea id="activity-body" v-model="activityBody" maxlength="5000" rows="3" /></div>
                        <p class="wd-hint">Records a past conversation or private note. No message is sent.</p>
                        <Button class="wd-button wd-button-secondary" type="submit">Record activity</Button>
                    </fieldset></form>
                    <details><summary>Delete lead</summary><p>Deletes this lead, its private notes, and its activity history.</p>
                        <label class="wd-checkbox"><input v-model="confirmDelete" type="checkbox">I want to delete this lead</label>
                        <Button type="button" class="wd-button wd-button-secondary" :disabled="busy || !!errors.version || !confirmDelete" @click="visit('delete', `/pipeline/${selected.id}`, { version: loadedVersion })">Delete permanently</Button>
                    </details>
                </template>
                <div v-else class="wd-fields"><p>{{ selected.contact_name }} {{ selected.contact_email }}</p><p>{{ money(selected.value_minor, selected.currency) }}</p><p class="wd-pipeline-note">{{ selected.notes || 'No private notes yet.' }}</p><p>{{ stamp(selected.next_action_due) }}</p></div>
                <h3>Activity history</h3>
                <p class="wd-hint">Most recent 50 events.</p>
                <ol class="wd-pipeline-history"><li v-for="item in selected.activities" :key="item.id"><strong>{{ item.kind }}</strong> · <time :datetime="item.occurred_at">{{ stamp(item.occurred_at) }}</time><p>{{ item.body }}</p></li></ol>
            </section>

        </dialog>
    </div>
</template>
<style scoped>
.lead-dialog {
    position: fixed;
    inset: 0;
    margin: auto;
    width: min(640px, calc(100% - 32px));
    max-height: calc(100dvh - 32px);
    overflow-y: auto;
    padding: 24px;
    border: 1px solid var(--wd-line);
    border-radius: 16px;
    background: var(--wd-surface);
    color: var(--wd-ink);
    overscroll-behavior: contain;
}
.lead-dialog.lead-drawer {
    inset: 0 0 0 auto;
    margin: 0;
    width: min(640px, 100%);
    height: 100dvh;
    max-height: 100dvh;
    border-radius: 16px 0 0 16px;
}
.lead-dialog::backdrop { background: rgb(0 0 0 / 45%); }
</style>
