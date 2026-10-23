<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { nextTick, ref, watch } from 'vue';
import PartnerDashboard from '../features/portals/PartnerDashboard.vue';
import BrandDashboard from '../features/portals/BrandDashboard.vue';
import type { PortalData } from '../features/portals/types';
const props = defineProps<PortalData & {portal: 'partner' | 'brand'; invitations: {id:string; status:string; expires_at:string}[]; inviteUrl?:string | null}>();
const inviting = ref(false);
const invitationDialog = ref<HTMLDialogElement | null>(null);
const invitationLink = ref<string | null>(null);
function openInvitation(): void { invitationLink.value = null; form.clearErrors(); inviting.value = true; }
const form = useForm({email:''});
const removing = ref<string | null>(null);
watch(inviting, async value => { await nextTick(); if (value) { invitationDialog.value?.showModal(); document.getElementById(invitationLink.value ? 'invite-url' : 'invite-email')?.focus(); } else { invitationDialog.value?.close(); } });
watch(removing, async value => { if (value) { await nextTick(); document.getElementById('confirm-remove')?.focus(); } });
watch(() => props.inviteUrl, async value => { if (value) { invitationLink.value = value; inviting.value = true; await nextTick(); document.getElementById('invite-url')?.focus(); } }, {immediate:true});
function invite(): void { form.post('/partner-invitations', {preserveScroll:true,onSuccess:()=>{form.reset();}}); }
</script>
<template>
    <Head :title="portal === 'brand' ? 'Partner hub' : 'My leads'" />
    <BrandDashboard v-if="portal === 'brand'" :data="props" @invite="openInvitation" @remove="removing = $event" />
    <PartnerDashboard v-else :data="props" @remove="removing = $event" />
    <dialog ref="invitationDialog" class="invitation-dialog wd-card" aria-labelledby="invitation-title" @close="inviting = false" @cancel="inviting = false">
        <div class="mb-4 flex items-start justify-between gap-4"><h2 id="invitation-title">{{ invitationLink ? 'Copy invitation link' : 'Invite a partner' }}</h2><button type="button" class="wd-link" aria-label="Close invitation dialog" @click="inviting = false">Close</button></div>
        <form v-if="!invitationLink" class="grid gap-4" @submit.prevent="invite"><p>Only the verified recipient can accept. The invitation expires in seven days. No email is sent.</p><div class="wd-field"><label for="invite-email">Recipient email</label><input id="invite-email" v-model="form.email" required type="email" maxlength="255" autocomplete="email" :aria-invalid="!!form.errors.email"></div><p v-if="form.errors.email" role="alert">{{ form.errors.email }}</p><button class="wd-button wd-button-primary" :disabled="form.processing">Create invitation link</button><button type="button" class="wd-link" @click="inviting = false">Cancel</button></form>
        <div v-if="invitationLink" class="grid gap-4"><p>Save this link now. It is shown once. Send it only to the intended recipient.</p><input id="invite-url" aria-label="Invitation link" :value="invitationLink" readonly class="w-full rounded-lg border p-3" @focus="($event.target as HTMLInputElement).select()"></div>
    </dialog>
    <section v-if="removing" class="wd-card mx-auto max-w-[800px]" aria-label="Partnership actions">
        <div v-if="removing"><h2>End partnership?</h2><p>This removes shared relationship access on both sides and preserves private records.</p><button id="confirm-remove" class="wd-button wd-button-primary" @click="router.delete(`/partnerships/${removing}`, {onSuccess:()=>{removing=null;}})">Confirm end partnership</button><button class="wd-link" @click="removing=null">Cancel</button></div>
    </section>
    <section v-if="portal === 'brand' && canManage && invitations.length" class="wd-card mx-auto max-w-[1408px]" aria-label="Invitations"><h2>Invitation history</h2><ul><li v-for="row in invitations" :key="row.id" class="py-2">{{ row.status }} · expires {{ new Date(row.expires_at).toLocaleDateString() }} <button v-if="row.status === 'pending'" type="button" class="wd-link" @click="router.delete(`/partner-invitations/${row.id}`)">Revoke invitation</button></li></ul></section>
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
.invitation-dialog::backdrop { background: rgb(0 0 0 / 45%); }
</style>
