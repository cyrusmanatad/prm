<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import ContentSection from '../../components/ContentSection.vue';
import ActionGroup from '../../components/ActionGroup.vue';
import { Button } from '../../components/ui/button';
import { confirmAction } from '../../composables/useConfirmation';
import FollowUpComposer from './FollowUpComposer.vue';
import type { FollowUpDraft, LeadOption } from './follow-up-types';

defineProps<{ drafts: FollowUpDraft[]; leads: LeadOption[]; canWrite: boolean }>();
const selected = ref<FollowUpDraft | null>(null);
const action = useForm({ version: 1 });
function transition(draft: FollowUpDraft, kind: 'approve' | 'discard', event: Event): void {
    if (action.processing) return;
    confirmAction(
        kind === 'approve' ? 'Approve follow-up' : 'Discard follow-up',
        kind === 'approve'
            ? `Approve “${draft.subject}” to ${draft.recipient || 'the recipient you enter'} for manual sending? No email will be sent by WeavDash.`
            : `Discard “${draft.subject}”? It will no longer be available for approval or manual handoff.`,
        () => {
            action.version = draft.version;
            action.clearErrors();
            action.post(`/automation/drafts/${draft.id}/${kind}`, { preserveScroll: true });
        },
        event,
        { tone: kind === 'approve' ? 'default' : 'danger' },
    );
}
</script>

<template>
    <ContentSection
        title="Approval inbox"
        description="Your latest 50 private drafts, including discarded and cancelled history. No AI or mailbox connection is needed."
    >
        <p class="wd-hint">
            Approval permits downloading a plain-text message to send yourself. WeavDash does not send follow-up email
            or track receipt. Review your email client before sending; downloaded files cannot be recalled.
        </p>
        <div v-if="Object.keys(action.errors).length" class="wd-banner" role="alert">
            <p v-for="(error, key) in action.errors" :key="key">{{ error }}</p>
            <button class="wd-link" type="button" @click="router.get('/automation', {}, { preserveState: false })">
                Reload inbox
            </button>
        </div>
        <p v-if="!drafts.length" class="wd-empty">
            No follow-up drafts yet. Write a message below or schedule a private draft.
        </p>
        <article v-for="draft in drafts" :key="draft.id" class="wd-section-stack">
            <h3>{{ draft.subject || 'Untitled follow-up' }}</h3>
            <p>
                {{
                    draft.state === 'approved'
                        ? 'Approved for manual sending'
                        : draft.state === 'discarded'
                          ? 'Discarded'
                          : draft.state === 'cancelled'
                            ? 'Cancelled after a reply'
                            : 'Needs review'
                }}
                · Version {{ draft.version }}
            </p>
            <p>To: {{ draft.recipient || 'Enter a recipient before approval' }}</p>
            <p class="whitespace-pre-wrap break-words">{{ draft.body }}</p>
            <ActionGroup v-if="canWrite && (draft.state === 'draft' || draft.state === 'approved')">
                <Button
                    class="wd-button wd-button-secondary"
                    type="button"
                    :disabled="action.processing"
                    @click="selected = draft"
                    >Edit {{ draft.subject }}</Button
                >
                <Button
                    v-if="draft.state === 'draft'"
                    class="wd-button wd-button-primary"
                    type="button"
                    :disabled="action.processing"
                    @click="transition(draft, 'approve', $event)"
                    >Approve {{ draft.subject }}</Button
                >
                <a
                    v-if="draft.state === 'approved'"
                    class="wd-button wd-button-secondary"
                    :href="`/automation/drafts/${draft.id}/handoff?version=${draft.version}`"
                    download
                    >Download approved message</a
                >
                <Button
                    class="wd-button wd-button-secondary"
                    type="button"
                    :disabled="action.processing"
                    @click="transition(draft, 'discard', $event)"
                    >Discard {{ draft.subject }}</Button
                >
            </ActionGroup>
        </article>
    </ContentSection>
    <FollowUpComposer
        :key="`${selected?.id ?? 'new'}-${selected?.version ?? 0}`"
        :selected="selected"
        :leads="leads"
        :can-write="canWrite"
        @close="selected = null"
    />
</template>
