<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, nextTick, ref } from 'vue';
import { confirmAction } from '../composables/useConfirmation';
import { containDialogFocus } from '../composables/useDialogFocus';
import DialogClose from '../components/DialogClose.vue';
import FormField from '../components/FormField.vue';
import ComingSoonStrip from '../features/portals/ComingSoonStrip.vue';
import PartnerChrome from '../features/portals/PartnerChrome.vue';
import type { Relationship } from '../features/portals/types';

const props = defineProps<{
    account: { name: string; email: string };
    workspace: { id: string; name: string; kind: 'partner' };
    relationships: Relationship[];
    canManage: boolean;
}>();
const date = (value: string) => new Date(value).toLocaleDateString();
const summary = computed(() => [
    { label: 'Connected brands', value: String(props.relationships.length) },
    {
        label: 'Partner since',
        value: props.relationships.length ? date(props.relationships[0]!.accepted_at) : '—',
    },
]);
function endPartnership(row: Relationship, trigger: HTMLElement): void {
    confirmAction(
        'End partnership',
        `End the partnership with ${row.brand_name}? Shared relationship access ends on both sides. Private records are preserved.`,
        () => router.delete(`/partnerships/${row.id}`, { preserveScroll: true }),
        trigger,
        { tone: 'danger' },
    );
}
// Registering a brand creates a separate Business workspace owned by this account (D32).
const brandDialog = ref<HTMLDialogElement | null>(null);
const brandForm = useForm({ name: '', kind: 'brand' });
async function openBrand(): Promise<void> {
    brandForm.clearErrors();
    brandDialog.value?.showModal();
    await nextTick();
    document.getElementById('brand-name')?.focus();
}
function registerBrand(): void {
    if (brandForm.processing) return;
    brandForm.post('/workspaces', { preserveScroll: true, onSuccess: () => router.visit('/brand') });
}
</script>

<template>
    <Head title="Partner home" />
    <PartnerChrome portal="partner" current="home" :account-name="account.name" :account-email="account.email">
        <main id="main-content" class="mx-auto flex max-w-[1408px] flex-col gap-6 px-6 pt-7 pb-12">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h1 class="font-display text-[34px] leading-[1.1] font-bold">
                        Hello, {{ account.name.split(' ')[0] }}
                    </h1>
                    <p class="mt-2 text-[15px] text-ink-soft">The brands you partner with, in one place.</p>
                </div>
                <div class="flex flex-wrap gap-2.5">
                    <Link
                        href="/join"
                        class="rounded-[10px] border border-line-strong bg-white px-4 py-[11px] text-[14px] font-semibold text-ink"
                        >Join another brand</Link
                    >
                    <button
                        type="button"
                        class="rounded-[10px] bg-brand px-4 py-[11px] text-[14px] font-semibold text-white"
                        @click="openBrand"
                    >
                        Register my brand
                    </button>
                </div>
            </div>

            <section aria-label="Summary" class="grid grid-cols-[repeat(auto-fit,minmax(150px,1fr))] gap-4">
                <div
                    v-for="card in summary"
                    :key="card.label"
                    class="rounded-[14px] border border-line bg-white px-5 py-4"
                >
                    <div class="text-[13px] font-semibold text-muted">{{ card.label }}</div>
                    <div class="mt-1 font-display text-[24px] font-bold">{{ card.value }}</div>
                </div>
            </section>

            <section class="rounded-2xl border border-line bg-white p-5" aria-labelledby="brands-title">
                <h2 id="brands-title" class="font-display text-[18px] font-bold">Your brands</h2>
                <ul
                    v-if="relationships.length"
                    class="mt-3 grid gap-3 sm:grid-cols-[repeat(auto-fill,minmax(280px,1fr))]"
                >
                    <li
                        v-for="row in relationships"
                        :key="row.id"
                        class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-line px-4 py-3.5"
                    >
                        <span>
                            <strong class="block text-[15px]">{{ row.brand_name }}</strong>
                            <span class="text-[13px] text-muted">Partner since {{ date(row.accepted_at) }}</span>
                        </span>
                        <button
                            v-if="canManage"
                            type="button"
                            class="wd-action-danger text-[13px]"
                            @click="endPartnership(row, $event.currentTarget as HTMLElement)"
                        >
                            End partnership
                        </button>
                    </li>
                </ul>
                <p v-else class="wd-empty mt-3">
                    No brands yet. Accept a brand invitation or use a brand's sign-up link to connect.
                </p>
            </section>

            <ComingSoonStrip :items="['Projects', 'Contracts', 'Messages', 'Files', 'Onboarding']" />
        </main>
    </PartnerChrome>

    <dialog
        ref="brandDialog"
        class="wd-dialog"
        aria-labelledby="brand-dialog-title"
        @keydown="containDialogFocus($event, brandDialog)"
    >
        <div class="wd-dialog-header">
            <h2 id="brand-dialog-title">Register my brand</h2>
            <DialogClose label="Close register my brand dialog" @click="brandDialog?.close()" />
        </div>
        <form class="grid gap-4" :aria-busy="brandForm.processing" @submit.prevent="registerBrand">
            <p>
                This creates a separate Business workspace you own and opens its Brand portal. Your brand partnerships
                stay in this Partner workspace.
            </p>
            <FormField
                id="brand-name"
                v-model="brandForm.name"
                label="Brand name"
                autocomplete="organization"
                :maxlength="120"
                :error="brandForm.errors.name"
            />
            <div class="wd-dialog-footer">
                <button type="button" class="wd-button wd-button-secondary" @click="brandDialog?.close()">
                    Cancel
                </button>
                <button type="submit" class="wd-button wd-button-primary" :disabled="brandForm.processing">
                    {{ brandForm.processing ? 'Creating…' : 'Create Business workspace' }}
                </button>
            </div>
        </form>
    </dialog>
</template>
