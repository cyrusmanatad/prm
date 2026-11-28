<script setup lang="ts">
import { computed, watch } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import BrandChrome from '../features/portals/BrandChrome.vue';
import PartnerChrome from '../features/portals/PartnerChrome.vue';
import ContentSection from '../components/ContentSection.vue';
import EmptyState from '../components/EmptyState.vue';
import FieldGroup from '../components/FieldGroup.vue';
import FormField from '../components/FormField.vue';
import Pagination from '../components/Pagination.vue';
import SelectField from '../components/SelectField.vue';
import { Button } from '../components/ui/button';
import { dateLabel } from '../features/finance/amounts';

type Campaign = {
    id: string;
    title: string;
    due_date: string | null;
    partnership_id: string | null;
    brand_name: string | null;
    creator_name: string | null;
};
const props = defineProps<{
    experience: 'brand' | 'partner';
    workspace: { name: string };
    account: { name: string; email: string };
    canWrite: boolean;
    selected: (Campaign & { brief: string; version: number }) | null;
    listing: { data: Campaign[]; current_page: number; per_page: number; total: number } | null;
    partners: { id: string; name: string }[];
    status?: string;
}>();
const brand = computed(() => props.experience === 'brand');
const path = computed(() => '/' + props.experience + '/campaigns');
const create = useForm({ title: '' });
const edit = useForm({ brief: '', due_date: '', partner: '', version: 1 });
function loadBrief() {
    edit.brief = props.selected?.brief ?? '';
    edit.due_date = props.selected?.due_date ?? '';
    edit.partner = '';
    edit.version = props.selected?.version ?? 1;
}
watch(() => props.selected?.id, loadBrief, { immediate: true });
function save(share: boolean) {
    if (!props.selected || edit.processing) return;
    edit.transform((data) => ({ ...data, partner: share ? data.partner : null })).patch(
        path.value + '/' + props.selected.id + (share ? '/share' : '/draft'),
        { onSuccess: loadBrief },
    );
}
function audience(row: Campaign) {
    if (!brand.value) return row.brand_name;
    if (!row.partnership_id) return 'Private draft';
    return row.creator_name ? 'Shared with ' + row.creator_name : 'Partnership ended';
}
</script>

<template>
    <Head :title="selected?.title ?? 'Campaigns'" />
    <component
        :is="brand ? BrandChrome : PartnerChrome"
        :workspace-name="workspace.name"
        :account-name="account.name"
        :account-email="account.email"
    >
        <main id="main-content" class="wd-portal-page wd-section-stack py-6">
            <header class="wd-page-heading">
                <Link v-if="selected" :href="path" class="wd-link">All campaigns</Link>
                <h1 class="break-words">{{ selected?.title ?? 'Campaigns' }}</h1>
                <p v-if="selected" class="wd-description">{{ audience(selected) }}</p>
            </header>
            <p v-if="status" role="status">{{ status }}</p>
            <template v-if="selected">
                <ContentSection v-if="canWrite && !selected.partnership_id" title="Campaign brief">
                    <form class="grid gap-4" @submit.prevent="save(false)">
                        <FieldGroup
                            id="brief"
                            label="Brief"
                            :error="edit.errors.brief"
                            v-slot="{ id, describedby, invalid }"
                        >
                            <textarea
                                :id="id"
                                v-model="edit.brief"
                                rows="5"
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
                                :aria-invalid="invalid"
                                :aria-describedby="describedby"
                            />
                        </FieldGroup>
                        <p v-if="edit.errors.version" role="alert">
                            {{ edit.errors.version }}
                            <Link class="wd-link" :href="path + '/' + selected.id">Reopen campaign</Link>
                        </p>
                        <Button
                            type="submit"
                            class="wd-button wd-button-secondary justify-self-start"
                            :disabled="edit.processing || !!edit.errors.version"
                            >Save private draft</Button
                        >
                        <SelectField
                            v-if="partners.length"
                            id="campaign-partner"
                            v-model="edit.partner"
                            label="Share with Partner"
                            :options="partners.map((p) => ({ value: p.id, label: p.name }))"
                            :error="edit.errors.partner"
                        />
                        <p v-else class="wd-description">
                            An active Partner connection is required to share this brief.
                        </p>
                        <Button
                            v-if="partners.length"
                            class="wd-button wd-button-primary justify-self-start"
                            :disabled="!edit.partner || edit.processing || !!edit.errors.version"
                            @click="save(true)"
                            >Share brief</Button
                        >
                    </form>
                </ContentSection>
                <ContentSection v-else title="Campaign brief">
                    <p class="wd-description">
                        Due date · {{ selected.due_date ? dateLabel(selected.due_date) : 'Unscheduled' }}
                    </p>
                    <p class="whitespace-pre-wrap break-words">{{ selected.brief || 'No brief yet.' }}</p>
                </ContentSection>
            </template>
            <template v-else-if="listing">
                <ContentSection v-if="canWrite" title="Create a private draft">
                    <form class="grid gap-3" @submit.prevent="create.post(path, { onSuccess: () => create.reset() })">
                        <FormField
                            id="campaign-title"
                            v-model="create.title"
                            label="Campaign name"
                            :maxlength="160"
                            :error="create.errors.title"
                        />
                        <Button
                            type="submit"
                            class="wd-button wd-button-primary justify-self-start"
                            :disabled="create.processing"
                            >Create draft</Button
                        >
                    </form>
                </ContentSection>
                <ContentSection :title="brand ? 'Workspace campaigns' : 'Shared briefs'">
                    <ul v-if="listing.data.length" class="grid gap-3">
                        <li v-for="row in listing.data" :key="row.id">
                            <Link class="wd-link break-words" :href="path + '/' + row.id">{{ row.title }}</Link>
                            <p class="wd-description">
                                {{ audience(row)
                                }}<template v-if="row.due_date"> · Due {{ dateLabel(row.due_date) }}</template>
                            </p>
                        </li>
                    </ul>
                    <EmptyState
                        v-else
                        :title="brand ? 'No campaigns yet' : 'No shared briefs yet'"
                        :description="
                            brand
                                ? undefined
                                : 'Campaign briefs appear here when a connected Brand shares one with your workspace.'
                        "
                    />
                    <Pagination
                        v-if="listing.total > listing.per_page || listing.current_page > 1"
                        label="Campaign pages"
                        noun="campaigns"
                        :page="listing.current_page"
                        :per-page="listing.per_page"
                        :total="listing.total"
                        :count="listing.data.length"
                        :href-for="(page) => path + '?page=' + page"
                    />
                </ContentSection>
            </template>
        </main>
    </component>
</template>
