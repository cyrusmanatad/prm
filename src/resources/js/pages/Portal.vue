<script setup lang="ts">
import { confirmAction } from '../composables/useConfirmation';
import { containDialogFocus } from '../composables/useDialogFocus';
import { Head, router, useForm } from '@inertiajs/vue3';
import { nextTick, ref, watch } from 'vue';
import PartnerDashboard from '../features/portals/PartnerDashboard.vue';
import BrandDashboard from '../features/portals/BrandDashboard.vue';
import type { PortalData } from '../features/portals/types';
import FormField from '../components/FormField.vue';
import DialogClose from '../components/DialogClose.vue';
import { emailStates } from '../features/notifications/emailStates';
import { Button } from '../components/ui/button';
const props = defineProps<
    PortalData & {
        portal: 'partner' | 'brand';
        invitations: { id: string; status: string; expires_at: string; email_state: string | null }[];
        inviteUrl?: string | null;
    }
>();
const inviting = ref(false);
const invitationDialog = ref<HTMLDialogElement | null>(null);
const invitationLink = ref<string | null>(null);
function openInvitation(): void {
    invitationLink.value = null;
    form.clearErrors();
    inviting.value = true;
}
const form = useForm({ email: '', send_email: false });

watch(inviting, async (value) => {
    await nextTick();
    if (value) {
        invitationDialog.value?.showModal();
        document.getElementById(invitationLink.value ? 'invite-url' : 'invite-email')?.focus();
    } else {
        invitationDialog.value?.close();
    }
});
watch(
    () => props.inviteUrl,
    async (value) => {
        if (value) {
            invitationLink.value = value;
            inviting.value = true;
            await nextTick();
            document.getElementById('invite-url')?.focus();
        }
    },
    { immediate: true },
);
function invite(): void {
    form.post('/partner-invitations', {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
        },
    });
}
function endPartnership(id: string, trigger: HTMLElement): void {
    const relationship = props.relationships.find((row) => row.id === id);
    if (!relationship) return;
    confirmAction(
        'End partnership',
        `End the partnership with ${props.portal === 'brand' ? relationship.creator_name : relationship.brand_name}? Shared relationship access ends on both sides. Private records are preserved.`,
        () => router.delete(`/partnerships/${id}`),
        trigger,
        { tone: 'danger' },
    );
}
</script>
<template>
    <Head :title="portal === 'brand' ? 'Partner hub' : 'My leads'" />
    <BrandDashboard v-if="portal === 'brand'" :data="props" @invite="openInvitation" @remove="endPartnership">
        <template v-if="canManage && invitations.length" #after-partners>
            <section class="rounded-2xl border border-line bg-white p-5" aria-labelledby="invitation-history-title">
                <h2 id="invitation-history-title" class="mb-2 font-display text-[18px] font-bold">
                    Invitation history
                </h2>
                <p class="wd-hint mb-3">Refresh this page to check email delivery progress.</p>
                <ul class="invitation-history">
                    <li
                        v-for="row in invitations"
                        :key="row.id"
                        class="grid gap-2 border-b border-[var(--wd-line)] py-3 sm:flex sm:flex-wrap sm:items-center sm:gap-4"
                    >
                        <span>{{ row.status }} · expires {{ new Date(row.expires_at).toLocaleDateString() }}</span
                        ><span role="status">{{
                            row.email_state ? emailStates[row.email_state] : 'Link only; email not requested'
                        }}</span
                        ><button
                            v-if="row.status === 'pending'"
                            type="button"
                            class="wd-action-danger"
                            @click="
                                confirmAction(
                                    'Revoke invitation',
                                    `Revoke invitation ${row.id}? The recipient will no longer be able to accept it. Pending email delivery will be cancelled.`,
                                    () => router.delete(`/partner-invitations/${row.id}`),
                                    $event,
                                    { tone: 'danger' },
                                )
                            "
                        >
                            Revoke invitation
                        </button>
                    </li>
                </ul>
            </section>
        </template>
    </BrandDashboard>
    <PartnerDashboard v-else :data="props" @remove="endPartnership" />
    <dialog
        ref="invitationDialog"
        class="wd-dialog"
        aria-labelledby="invitation-title"
        @close="inviting = false"
        @cancel="inviting = false"
        @keydown="containDialogFocus($event, invitationDialog)"
    >
        <div class="wd-dialog-header">
            <h2 id="invitation-title">{{ invitationLink ? 'Copy invitation link' : 'Invite a partner' }}</h2>
            <DialogClose label="Close invitation dialog" @click="inviting = false" />
        </div>
        <form v-if="!invitationLink" class="grid gap-4" @submit.prevent="invite">
            <p>Only the verified recipient can accept. The invitation expires in seven days.</p>
            <FormField
                id="invite-email"
                v-model="form.email"
                label="Recipient email"
                type="email"
                :maxlength="255"
                autocomplete="email"
                :error="form.errors.email"
            /><label for="send-invitation-email" class="flex items-start gap-2"
                ><input
                    id="send-invitation-email"
                    v-model="form.send_email"
                    type="checkbox"
                    class="shrink-0 accent-[var(--wd-brand)]"
                    aria-describedby="send-email-hint"
                />Email this invitation to the recipient</label
            >
            <p id="send-email-hint" class="wd-hint">
                Choose email to request delivery. Check invitation history for delivery status.
            </p>
            <p v-if="form.errors.send_email" role="alert">{{ form.errors.send_email }}</p>
            <div class="wd-dialog-footer">
                <button type="button" class="wd-button wd-button-secondary" @click="inviting = false">Cancel</button>
                <Button type="submit" class="wd-button wd-button-primary" :disabled="form.processing">{{
                    form.send_email ? 'Create and email invitation' : 'Create invitation link'
                }}</Button>
            </div>
        </form>
        <div v-if="invitationLink" class="grid gap-4">
            <p>Save this link now. It is shown once. Send it only to the intended recipient.</p>
            <input
                id="invite-url"
                aria-label="Invitation link"
                :value="invitationLink"
                readonly
                class="w-full rounded-lg border p-3"
                @focus="($event.target as HTMLInputElement).select()"
            />
        </div>
    </dialog>
</template>

<style scoped>
.invitation-history {
    padding-left: 0;
    list-style: none;
}
</style>
