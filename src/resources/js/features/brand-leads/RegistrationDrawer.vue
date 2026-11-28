<script setup lang="ts">
import { nextTick, onMounted, ref, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import { ChevronRight } from '@lucide/vue';
import DialogClose from '../../components/DialogClose.vue';
import PartnerAvatar from '../../components/PartnerAvatar.vue';
import { containDialogFocus } from '../../composables/useDialogFocus';
import { dateTime } from '../admin/format';
import { relativeTime, stageLabel } from '../portals/activity';
import type { RegistrationDetail, StageOption } from '../portals/types';
import StageBadge from './StageBadge.vue';

/**
 * A registration shared with this Brand, read-only, in a side drawer over the list. The list keeps its
 * filters and page underneath; closing the drawer asks the page to drop `registration` from the address.
 */
const props = defineProps<{ registration: RegistrationDetail; stages: StageOption[] }>();
const emit = defineEmits<{ close: [] }>();
const dialog = ref<HTMLDialogElement>();

async function show(): Promise<void> {
    await nextTick();
    if (!dialog.value?.open) dialog.value?.showModal();
    dialog.value?.querySelector<HTMLElement>('.wd-dialog-close')?.focus();
}
onMounted(show);
watch(() => props.registration.id, show);

const since = (value?: string | null) =>
    value ? new Date(value).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : null;
/** "Nov 18, 3:37 PM", with the year when it is not this year; the exact time and zone are in the title. */
const stamp = (value: string) => {
    const date = new Date(value);
    return date.toLocaleString('en-US', {
        month: 'short',
        day: 'numeric',
        ...(date.getFullYear() === new Date().getFullYear() ? {} : { year: 'numeric' }),
        hour: 'numeric',
        minute: '2-digit',
    });
};
const updated = () =>
    new Date(props.registration.shared_updated_at).getTime() > new Date(props.registration.registered_at).getTime();
const contactShown = () => !!(props.registration.contact_name || props.registration.contact_email);
</script>

<template>
    <dialog
        ref="dialog"
        class="wd-dialog registration-drawer"
        aria-labelledby="registration-title"
        @cancel.prevent="emit('close')"
        @keydown="containDialogFocus($event, dialog)"
    >
        <header class="registration-head">
            <div class="registration-identity">
                <h2 id="registration-title">{{ registration.display_name }}</h2>
                <p class="registration-meta">
                    <StageBadge :stage="registration.stage" :label="stageLabel(stages, registration.stage)" />
                    <span
                        >Registered
                        <time :datetime="registration.registered_at" :title="dateTime(registration.registered_at)">{{
                            relativeTime(registration.registered_at).toLowerCase()
                        }}</time></span
                    >
                </p>
            </div>
            <DialogClose label="Close lead registration" @click="emit('close')" />
        </header>
        <div class="registration-body">
            <section class="registration-partner" aria-label="Partner">
                <PartnerAvatar :name="registration.partner_name" :seed="registration.partnership_id" :size="36" />
                <span class="registration-partner-copy">
                    <strong>{{ registration.partner_name }}</strong>
                    <small
                        >{{
                            since(registration.partner_since)
                                ? `Partner since ${since(registration.partner_since)}`
                                : 'Partner'
                        }}<template v-if="registration.partner_leads !== undefined">
                            · {{ registration.partner_leads }}
                            {{ registration.partner_leads === 1 ? 'lead' : 'leads' }} registered</template
                        ></small
                    >
                </span>
                <Link class="registration-pill" :href="`/brand/partners/${registration.partnership_id}`"
                    >Open partner<ChevronRight :size="14" aria-hidden="true"
                /></Link>
            </section>
            <section class="registration-section" aria-labelledby="registration-summary-title">
                <h3 id="registration-summary-title">Summary from the partner</h3>
                <p v-if="registration.summary" class="registration-text">{{ registration.summary }}</p>
                <p v-else class="registration-muted">No summary provided</p>
            </section>
            <section class="registration-section" aria-labelledby="registration-contact-title">
                <h3 id="registration-contact-title">Business contact</h3>
                <p v-if="contactShown()" class="registration-text">
                    {{ registration.contact_name }}
                    <a
                        v-if="registration.contact_email"
                        :href="`mailto:${registration.contact_email}`"
                        class="wd-link registration-email"
                        >{{ registration.contact_email }}</a
                    >
                </p>
                <p v-else class="registration-muted">
                    {{ registration.share_contact ? 'Not provided' : 'Not shared by the partner' }}
                </p>
            </section>
            <section class="registration-section" aria-labelledby="registration-timeline-title">
                <h3 id="registration-timeline-title">Timeline</h3>
                <dl class="registration-timeline">
                    <dt>Registered</dt>
                    <dd>
                        <time :datetime="registration.registered_at" :title="dateTime(registration.registered_at)">{{
                            stamp(registration.registered_at)
                        }}</time>
                    </dd>
                    <dt>Last update</dt>
                    <dd v-if="updated()">
                        <time
                            :datetime="registration.shared_updated_at"
                            :title="dateTime(registration.shared_updated_at)"
                            >{{ stamp(registration.shared_updated_at) }}</time
                        >
                    </dd>
                    <dd v-else class="registration-muted">No changes since registering</dd>
                    <template v-if="registration.campaign">
                        <dt>Source campaign</dt>
                        <dd>
                            <Link class="wd-link" :href="`/brand/campaigns/${registration.campaign.id}`">{{
                                registration.campaign.title
                            }}</Link>
                            · linked
                            <time
                                :datetime="registration.campaign.linked_at"
                                :title="dateTime(registration.campaign.linked_at)"
                                >{{ stamp(registration.campaign.linked_at) }}</time
                            >
                        </dd>
                    </template>
                </dl>
            </section>
            <p class="registration-note">
                Read-only. The partner manages this lead and its stage. Private notes, reminders, estimates and other
                prospects are not shared and cannot be requested here.
            </p>
        </div>
    </dialog>
</template>

<style scoped>
.registration-drawer[open] {
    inset: 0 0 0 auto;
    display: flex;
    flex-direction: column;
    width: min(460px, 100dvw);
    max-width: 100dvw;
    height: 100dvh;
    max-height: 100dvh;
    margin: 0;
    padding: 0;
    border-width: 0 0 0 1px;
    border-radius: 0;
    background: var(--wd-surface);
    color: var(--wd-ink);
}
.registration-head {
    display: flex;
    flex: none;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
    padding: 16px 20px 14px;
    border-bottom: 1px solid var(--wd-divider);
}
.registration-identity {
    display: flex;
    flex-direction: column;
    gap: 6px;
    min-width: 0;
}
.registration-identity h2 {
    margin: 0;
    font-family: var(--wd-font-display);
    font-size: 20px;
    line-height: 26px;
    overflow-wrap: anywhere;
}
.registration-meta {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 4px 8px;
    margin: 0;
    color: var(--wd-muted);
    font-size: 12.5px;
}
.registration-head :deep(.wd-dialog-close) {
    margin: -6px -8px 0 0;
}
.registration-body {
    display: flex;
    flex: 1 1 auto;
    flex-direction: column;
    gap: 16px;
    min-height: 0;
    padding: 16px 20px 20px;
    overflow-y: auto;
    overscroll-behavior: contain;
}
.registration-partner {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 10px;
    padding: 12px;
    border: 1px solid var(--wd-line);
    border-radius: 12px;
}
.registration-partner-copy {
    display: flex;
    flex: 1 1 180px;
    flex-direction: column;
    gap: 2px;
    min-width: 0;
}
.registration-partner-copy small {
    color: var(--wd-muted);
    font-size: 12px;
}
.registration-pill {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    min-height: 30px;
    padding: 0 10px;
    border: 1px solid var(--wd-control-line);
    border-radius: 999px;
    color: var(--wd-ink);
    font-size: 12.5px;
    font-weight: 700;
    text-decoration: none;
}
.registration-pill:hover {
    background: var(--wd-paper);
    color: var(--wd-brand);
}
.registration-section {
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.registration-section + .registration-section {
    padding-top: 14px;
    border-top: 1px solid var(--wd-divider);
}
.registration-section h3 {
    margin: 0;
    color: var(--wd-muted);
    font-family: var(--wd-font-body);
    font-size: 11.5px;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}
.registration-text {
    margin: 0;
    font-size: 14px;
    line-height: 1.5;
    overflow-wrap: anywhere;
    white-space: pre-line;
}
.registration-email {
    display: block;
}
.registration-muted {
    margin: 0;
    color: var(--wd-muted);
    font-size: 13.5px;
}
.registration-timeline {
    display: grid;
    grid-template-columns: 110px minmax(0, 1fr);
    gap: 8px 12px;
    margin: 0;
    font-size: 13.5px;
}
.registration-timeline dt {
    color: var(--wd-muted);
    font-weight: 600;
}
.registration-timeline dd {
    margin: 0;
}
.registration-note {
    margin: 0;
    padding: 10px 12px;
    border-radius: 10px;
    background: var(--wd-paper);
    color: var(--wd-muted);
    font-size: 12.5px;
}
@media (max-width: 599px), (pointer: coarse) {
    .registration-pill {
        min-height: 44px;
    }
}
@media (max-width: 640px) {
    /* Anchor to the device viewport, as the lead drawer does, so pointer coordinates reach its controls. */
    .registration-drawer[open] {
        inset: 0 auto 0 0;
        width: 100dvw;
        border-left: 0;
    }
}
</style>
