<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { nextTick, ref, watch } from 'vue';
import PartnerDashboard from '../features/portals/PartnerDashboard.vue';
import BrandDashboard from '../features/portals/BrandDashboard.vue';
import type { PortalData } from '../features/portals/types';
import FormField from '../components/FormField.vue';
import ContentSection from '../components/ContentSection.vue';
import { Button } from '../components/ui/button';
const props = defineProps<
    PortalData & {
        portal: 'partner' | 'brand';
        invitations: { id: string; status: string; expires_at: string; email_state: string | null }[];
        inviteUrl?: string | null;
    }
>();
const emailStates: Record<string, string> = {
    pending: 'Email pending',
    sending: 'Email sending',
    sent: 'Email accepted by mail server; receipt unconfirmed',
    captured: 'Email captured locally; no external delivery',
    cancelled: 'Email cancelled',
    uncertain: 'Email delivery uncertain; review required before any new send',
};
const inviting = ref(false);
const invitationDialog = ref<HTMLDialogElement | null>(null);
const invitationLink = ref<string | null>(null);
function openInvitation(): void {
    invitationLink.value = null;
    form.clearErrors();
    inviting.value = true;
}
const form = useForm({ email: '', send_email: false });
const removing = ref<string | null>(null);
watch(inviting, async (value) => {
    await nextTick();
    if (value) {
        invitationDialog.value?.showModal();
        document.getElementById(invitationLink.value ? 'invite-url' : 'invite-email')?.focus();
    } else {
        invitationDialog.value?.close();
    }
});
watch(removing, async (value) => {
    if (value) {
        await nextTick();
        document.getElementById('confirm-remove')?.focus();
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
</script>
<template>
    <Head :title="portal === 'brand' ? 'Partner hub' : 'My leads'" />
    <BrandDashboard v-if="portal === 'brand'" :data="props" @invite="openInvitation" @remove="removing = $event" />
    <PartnerDashboard v-else :data="props" @remove="removing = $event" />
    <dialog
        ref="invitationDialog"
        class="invitation-dialog wd-card"
        aria-labelledby="invitation-title"
        @close="inviting = false"
        @cancel="inviting = false"
    >
        <div class="mb-4 flex items-start justify-between gap-4">
            <h2 id="invitation-title">{{ invitationLink ? 'Copy invitation link' : 'Invite a partner' }}</h2>
            <button type="button" class="wd-link" aria-label="Close invitation dialog" @click="inviting = false">
                Close
            </button>
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
            <Button type="submit" class="wd-button wd-button-primary" :disabled="form.processing">{{
                form.send_email ? 'Create and email invitation' : 'Create invitation link'
            }}</Button
            ><button type="button" class="wd-link" @click="inviting = false">Cancel</button>
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
    <section v-if="removing" class="wd-card mx-auto max-w-[800px]" aria-label="Partnership actions">
        <div v-if="removing">
            <h2>End partnership?</h2>
            <p>This removes shared relationship access on both sides and preserves private records.</p>
            <button
                id="confirm-remove"
                class="wd-button wd-button-primary"
                @click="
                    router.delete(`/partnerships/${removing}`, {
                        onSuccess: () => {
                            removing = null;
                        },
                    })
                "
            >
                Confirm end partnership</button
            ><button class="wd-link" @click="removing = null">Cancel</button>
        </div>
    </section>
    <div v-if="portal === 'brand' && canManage && invitations.length" class="mx-auto max-w-[1408px] px-6 pb-12">
        <ContentSection title="Invitation history">
            <p class="wd-hint">Refresh this page to check email delivery progress.</p>
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
                        class="wd-link text-left"
                        @click="router.delete(`/partner-invitations/${row.id}`)"
                    >
                        Revoke invitation
                    </button>
                </li>
            </ul></ContentSection
        >
    </div>
</template>

<style scoped>
.invitation-dialog {
    position: fixed;
    inset: 0;
    margin: auto;
    width: min(520px, calc(100% - 32px));
    max-height: calc(100dvh - 32px);
    overflow-y: auto;
    color: var(--wd-ink);
}
.invitation-dialog::backdrop {
    background: rgb(0 0 0 / 45%);
}
.invitation-history {
    padding-left: 0;
}
</style>
