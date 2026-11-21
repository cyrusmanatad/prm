<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { Download, Pencil, Send, Trash2 } from '@lucide/vue';
import DialogClose from '../../components/DialogClose.vue';
import StatusBadge from '../../components/StatusBadge.vue';
import { Button } from '../../components/ui/button';
import { containDialogFocus } from '../../composables/useDialogFocus';
import { useValidationFocus } from '../../composables/useValidationFocus';
import FollowUpFields from './FollowUpFields.vue';
import {
    canSend,
    draftStatus,
    draftTone,
    handoffHref,
    isOpen,
    type DraftAction,
    type FollowUpDraft,
} from './follow-up-types';

/**
 * One draft in a side drawer over the inbox: the full message, its status and the actions. Edit switches the
 * body to the message fields with an unsaved-changes footer; saving an edit removes any approval.
 */
const props = defineProps<{
    draft: FollowUpDraft;
    canWrite: boolean;
    busy: boolean;
    errors: Record<string, string>;
    reload: string;
    /** Open straight into Edit, as the row menu's Edit does. */
    editOnOpen?: boolean;
}>();
const emit = defineEmits<{ close: []; act: [draft: FollowUpDraft, kind: DraftAction, source: Event | HTMLElement] }>();
const dialog = ref<HTMLDialogElement>();
const body = ref<HTMLElement>();
const editing = ref(false);
const form = useForm({ recipient: '', subject: '', body: '', version: 1, pipeline_entry_id: '' });
useValidationFocus(
    body,
    () => form.errors,
    () => !form.processing,
);
const dirty = computed(
    () =>
        form.recipient !== props.draft.recipient ||
        form.subject !== props.draft.subject ||
        form.body !== props.draft.body,
);
const prepared = computed(() => {
    const when = props.draft.created_at
        ? new Date(props.draft.created_at).toLocaleString('en-US', {
              month: 'short',
              day: 'numeric',
              hour: 'numeric',
              minute: '2-digit',
          })
        : '';
    return [props.draft.schedule_run_id ? 'By a schedule' : 'Written by you', when].filter(Boolean).join(' · ');
});
function load(): void {
    form.recipient = props.draft.recipient;
    form.subject = props.draft.subject;
    form.body = props.draft.body;
    form.version = props.draft.version;
    form.pipeline_entry_id = props.draft.pipeline_entry_id;
    form.clearErrors();
}
async function show(): Promise<void> {
    editing.value = false;
    load();
    await nextTick();
    if (!dialog.value?.open) dialog.value?.showModal();
    if (props.editOnOpen && props.canWrite && isOpen(props.draft)) await edit();
    else dialog.value?.querySelector<HTMLElement>('.wd-dialog-close')?.focus();
}
onMounted(show);
watch(() => props.draft.id, show);
// A new version (after approval, send or a reply) replaces what the drawer shows.
watch(
    () => props.draft.version,
    () => {
        if (!editing.value) load();
    },
);
async function edit(): Promise<void> {
    load();
    editing.value = true;
    await nextTick();
    dialog.value?.querySelector<HTMLElement>('#edit-draft-recipient')?.focus();
}
async function stopEditing(): Promise<void> {
    load();
    editing.value = false;
    await nextTick();
    dialog.value?.querySelector<HTMLElement>('.auto-drawer-edit')?.focus();
}
function save(): void {
    if (form.processing || !dirty.value || form.errors.version) return;
    form.transform(({ pipeline_entry_id: _lead, ...data }) => data).patch(`/automation/drafts/${props.draft.id}`, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            editing.value = false;
        },
    });
}
</script>

<template>
    <dialog
        ref="dialog"
        class="wd-dialog auto-drawer"
        aria-labelledby="draft-drawer-title"
        @cancel.prevent="emit('close')"
        @keydown="containDialogFocus($event, dialog)"
    >
        <header class="auto-drawer-head">
            <div class="auto-drawer-identity">
                <h2 id="draft-drawer-title">{{ draft.subject || 'Untitled follow-up' }}</h2>
                <p class="auto-drawer-status">
                    <StatusBadge :label="`${draftStatus(draft)} · Version ${draft.version}`" :tone="draftTone(draft)" />
                </p>
            </div>
            <DialogClose label="Close follow-up draft" :disabled="form.processing" @click="emit('close')" />
        </header>
        <form
            v-if="editing"
            id="edit-draft-form"
            class="auto-drawer-body"
            novalidate
            :aria-busy="form.processing"
            @submit.prevent="save"
        >
            <div ref="body" class="auto-drawer-scroll">
                <p class="auto-fine">Saving an edit removes approval. Approve the new version before sending.</p>
                <div v-if="form.errors.version" class="wd-banner auto-banner" role="alert">
                    <p>{{ form.errors.version }}</p>
                    <a class="wd-link" :href="reload">Reload drafts</a>
                </div>
                <fieldset class="auto-fields" :disabled="form.processing">
                    <legend class="wd-sr-only">Edit follow-up message</legend>
                    <FollowUpFields :values="form" prefix="edit-draft" :errors="form.errors" />
                </fieldset>
            </div>
        </form>
        <div v-else ref="body" class="auto-drawer-body auto-drawer-scroll">
            <div v-if="Object.keys(errors).length" class="wd-banner auto-banner" role="alert">
                <p v-for="(error, key) in errors" :key="key">{{ error }}</p>
                <a class="wd-link" :href="reload">Reload drafts</a>
            </div>
            <dl class="auto-facts">
                <dt>Lead</dt>
                <dd>{{ draft.lead_name || 'Not available' }}</dd>
                <dt>To</dt>
                <dd>{{ draft.recipient || 'Enter a recipient before approval' }}</dd>
                <dt>Prepared</dt>
                <dd>{{ prepared }}</dd>
            </dl>
            <p class="auto-message">{{ draft.body }}</p>
            <p v-if="draft.outreach_state" class="auto-fine">
                An email request exists for this version. See Email history; it will not be sent again automatically.
            </p>
            <p v-else-if="draft.state === 'draft'" class="auto-fine">
                Approving allows a manual download or a separate, confirmed Send. WeavDash never sends on approval.
            </p>
            <p v-else-if="draft.state === 'approved'" class="auto-fine">
                Download it for your own email client, or send it through WeavDash. Queued or accepted mail is not proof
                of receipt, and a download cannot be recalled.
            </p>
        </div>
        <footer v-if="canWrite && editing" class="auto-sticky auto-drawer-foot">
            <p v-if="dirty" class="auto-dirty" role="status"><span aria-hidden="true"></span>Unsaved changes</p>
            <p v-else class="auto-clean">No unsaved changes</p>
            <Button
                type="button"
                class="wd-button wd-button-secondary"
                :disabled="form.processing"
                @click="stopEditing"
                >{{ dirty ? 'Discard' : 'Cancel' }}</Button
            >
            <Button
                type="submit"
                form="edit-draft-form"
                class="wd-button wd-button-primary"
                :disabled="form.processing || !dirty || !!form.errors.version"
                >{{ form.processing ? 'Saving…' : 'Save draft' }}</Button
            >
        </footer>
        <footer v-else-if="canWrite && isOpen(draft)" class="auto-drawer-foot">
            <button
                type="button"
                class="wd-table-button auto-danger"
                :disabled="busy"
                v-bind="{ 'aria-label': `Discard ${draft.subject}` }"
                @click="emit('act', draft, 'discard', $event)"
            >
                <Trash2 :size="15" aria-hidden="true" />Discard
            </button>
            <span class="auto-spacer"></span>
            <button
                type="button"
                class="wd-table-button auto-drawer-edit"
                :disabled="busy"
                v-bind="{ 'aria-label': `Edit ${draft.subject}` }"
                @click="edit"
            >
                <Pencil :size="15" aria-hidden="true" />Edit
            </button>
            <a v-if="draft.state === 'approved'" class="wd-table-button" :href="handoffHref(draft)" download
                ><Download :size="15" aria-hidden="true" /><span>Download approved message</span></a
            >
            <button
                v-if="draft.state === 'draft'"
                type="button"
                class="wd-table-button auto-primary"
                :disabled="busy"
                v-bind="{ 'aria-label': `Approve ${draft.subject}` }"
                @click="emit('act', draft, 'approve', $event)"
            >
                Approve
            </button>
            <button
                v-if="canSend(draft)"
                type="button"
                class="wd-table-button auto-primary"
                :disabled="busy"
                @click="emit('act', draft, 'send', $event)"
            >
                <Send :size="15" aria-hidden="true" />Send approved message
            </button>
        </footer>
    </dialog>
</template>
