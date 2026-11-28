<script setup lang="ts">
import { computed, watch } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import { Check, Circle, Info, Send } from '@lucide/vue';
import Breadcrumbs from '../../components/Breadcrumbs.vue';
import ContentSection from '../../components/ContentSection.vue';
import FieldGroup from '../../components/FieldGroup.vue';
import RecordHeader from '../../components/RecordHeader.vue';
import SelectField from '../../components/SelectField.vue';
import StatusBadge from '../../components/StatusBadge.vue';
import { Button } from '../../components/ui/button';
import { confirmAction } from '../../composables/useConfirmation';
import { dateOnly, dateTime } from '../admin/format';
import { calendarDate, campaignStatuses } from './format';
import type { CampaignDetail, CampaignPartner } from './types';

const props = defineProps<{ campaign: CampaignDetail; partners: CampaignPartner[] }>();

const path = computed(() => `/brand/campaigns/${props.campaign.id}`);
const edit = useForm({ brief: '', due_date: '', partner: '', version: 1 });
function load(): void {
    edit.defaults({
        brief: props.campaign.brief,
        due_date: props.campaign.due_date ?? '',
        partner: '',
        version: props.campaign.version,
    });
    edit.reset();
}
watch(() => [props.campaign.id, props.campaign.version], load, { immediate: true });

const partnerName = computed(() => props.partners.find((p) => p.id === edit.partner)?.name ?? '');
const checklist = computed(() => [
    { label: 'Brief written', done: edit.brief.trim() !== '' },
    { label: 'Due date set', done: edit.due_date !== '' },
    { label: 'Partner chosen', done: edit.partner !== '' },
]);
const ready = computed(() => checklist.value.every((item) => item.done));
const missing = computed(() =>
    checklist.value
        .filter((item) => !item.done)
        .map((item) => item.label.toLowerCase())
        .join(', '),
);

function save(share: boolean): void {
    if (edit.processing) return;
    edit.transform((data) => ({ ...data, partner: share ? data.partner : null })).patch(
        `${path.value}/${share ? 'share' : 'draft'}`,
        { preserveScroll: true },
    );
}
function share(event: Event): void {
    if (!ready.value || edit.processing) return;
    confirmAction(
        'Share brief',
        `Share “${props.campaign.title}” with ${partnerName.value}, due ${calendarDate(edit.due_date)}? Their members can read the brief and due date. Both become read-only — to change them, create a new campaign. Ending the relationship removes their access.`,
        () => save(true),
        event,
    );
}
</script>

<template>
    <Breadcrumbs :items="[{ label: 'Campaigns', href: '/brand/campaigns' }, { label: campaign.title }]" />
    <RecordHeader eyebrow="Campaign" :title="campaign.title" heading-id="campaign-title">
        <template #meta>
            <div>
                <dt class="wd-sr-only">Status</dt>
                <dd>
                    <StatusBadge :label="campaignStatuses.draft.label" :tone="campaignStatuses.draft.tone" />
                </dd>
            </div>
            <div>
                <dt>Created</dt>
                <dd>
                    <time :datetime="campaign.created_at" :title="dateTime(campaign.created_at)">{{
                        dateOnly(campaign.created_at)
                    }}</time>
                </dd>
            </div>
            <div>
                <dt>Visible to</dt>
                <dd>Your workspace only</dd>
            </div>
        </template>
    </RecordHeader>

    <form class="wd-record-columns" novalidate @submit.prevent="save(false)">
        <div class="wd-record-main">
            <ContentSection title="Brief" heading-id="brief-heading">
                <FieldGroup
                    id="brief"
                    label="What should the Partner do?"
                    hint="Audience, message, what counts as a lead and any deliverables. Read-only once shared."
                    :error="edit.errors.brief"
                    v-slot="{ id, describedby, invalid }"
                >
                    <textarea
                        :id="id"
                        v-model="edit.brief"
                        rows="8"
                        maxlength="8000"
                        :aria-invalid="invalid"
                        :aria-describedby="describedby"
                    />
                </FieldGroup>
                <FieldGroup
                    id="due-date"
                    label="Due date"
                    :error="edit.errors.due_date"
                    v-slot="{ id, describedby, invalid }"
                >
                    <input
                        :id="id"
                        v-model="edit.due_date"
                        type="date"
                        class="cp-date"
                        :aria-invalid="invalid"
                        :aria-describedby="describedby"
                    />
                </FieldGroup>
            </ContentSection>
            <ContentSection title="Share" heading-id="share-heading">
                <SelectField
                    v-if="partners.length"
                    id="campaign-partner"
                    v-model="edit.partner"
                    label="Partner"
                    hint="One active Partner. Their members can read the brief; other Partners cannot."
                    :options="partners.map((p) => ({ value: p.id, label: p.name }))"
                    :error="edit.errors.partner"
                />
                <p v-else class="wd-description">
                    An active Partner connection is required to share this brief.
                    <Link href="/brand" class="wd-link">Invite a Partner from the Partner hub</Link>
                </p>
            </ContentSection>
            <p v-if="edit.errors.version" role="alert" class="wd-field-error">
                {{ edit.errors.version }}
                <Link class="wd-link" :href="path">Reopen campaign</Link>
            </p>
            <div class="cp-actions">
                <Button
                    type="submit"
                    class="wd-button wd-button-secondary"
                    :disabled="edit.processing || !!edit.errors.version"
                    >Save private draft</Button
                >
                <Button
                    v-if="partners.length"
                    type="button"
                    class="wd-button wd-button-primary"
                    :disabled="!ready || edit.processing || !!edit.errors.version"
                    @click="share"
                    ><Send :size="16" aria-hidden="true" />Share brief…</Button
                >
                <span v-if="partners.length && !ready" id="share-missing" class="wd-hint"
                    >Still needed: {{ missing }}.</span
                >
            </div>
        </div>
        <aside class="wd-record-aside cp-sticky" aria-label="Sharing checklist">
            <ContentSection title="Ready to share?" heading-id="ready-heading">
                <ul class="cp-checklist">
                    <li v-for="item in checklist" :key="item.label" :class="{ 'is-done': item.done }">
                        <Check v-if="item.done" :size="16" aria-hidden="true" /><Circle
                            v-else
                            :size="16"
                            aria-hidden="true"
                        />{{ item.label }}<span class="wd-sr-only">: {{ item.done ? 'done' : 'not yet' }}</span>
                    </li>
                </ul>
                <p class="cp-note">
                    <Info :size="14" aria-hidden="true" />Sharing publishes the brief and due date together. You can't
                    edit them afterwards.
                </p>
            </ContentSection>
        </aside>
    </form>
</template>
