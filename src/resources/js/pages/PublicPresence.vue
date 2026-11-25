<script setup lang="ts">
import { useWorkspaceExperience } from '../composables/useWorkspaceExperience';
import { confirmAction } from '../composables/useConfirmation';
import { Head, router } from '@inertiajs/vue3';
import { Copy, ExternalLink, Plus, X } from '@lucide/vue';
import { computed, onBeforeUnmount, ref } from 'vue';
import PartnerChrome from '../features/portals/PartnerChrome.vue';
import FormField from '../components/FormField.vue';
import RichTextEditor from '../components/RichTextEditor.vue';
import DataTable, { type DataColumn } from '../components/DataTable.vue';
import EmptyState from '../components/EmptyState.vue';
import FilterChips, { type FilterChip } from '../components/FilterChips.vue';
import Pagination from '../components/Pagination.vue';
import StatusBadge, { type BadgeTone } from '../components/StatusBadge.vue';
import { Button } from '../components/ui/button';

type SocialLink = { label: string; url: string };
type LeadLink = {
    id: string;
    label: string;
    created_at: string;
    expires_at: string;
    revoked_at: string | null;
    status: 'active' | 'expired' | 'revoked';
    leads: number;
};
type LinkStatus = 'active' | 'expired' | 'revoked' | 'all';
const props = defineProps<{
    account: { name: string; email: string };
    workspace: { id: string; name: string };
    canWrite: boolean;
    kit: {
        id: string;
        title: string;
        bio: string;
        website: string;
        published: boolean;
        version: number;
        views: number;
        social_links?: SocialLink[];
        public_url?: string;
    } | null;
    links: {
        items: LeadLink[];
        page: number;
        perPage: number;
        total: number;
        counts: Record<LinkStatus, number>;
    };
    summary?: { views: number; activeLinks: number; leads: number };
    filters?: { status: LinkStatus };
    issuedLink: string | null;
    errors?: Record<string, string>;
    status?: string;
}>();
const { professional, path } = useWorkspaceExperience();
const noun = computed(() => (professional.value ? 'profile' : 'media kit'));
const heading = computed(() => (professional.value ? 'Professional profile / portfolio' : 'Media kit'));
const linkStatus = computed<LinkStatus>(() => props.filters?.status ?? 'active');
const base = computed(() => path('/partner/media-kit'));

// The editor keeps local values; the saved version advances only for the version this browser submitted.
const fromKit = () => ({
    title: props.kit?.title ?? '',
    bio: props.kit?.bio ?? '',
    website: props.kit?.website ?? '',
    socialLinks: props.kit?.social_links?.map((link) => ({ label: link.label, url: link.url })) ?? [],
    published: props.kit?.published ?? false,
});
const initial = fromKit();
const title = ref(initial.title);
const bio = ref(initial.bio);
const website = ref(initial.website);
const socialLinks = ref<SocialLink[]>(initial.socialLinks);
const published = ref(initial.published);
const consent = ref(false);
const busy = ref(false);
const version = ref(props.kit?.version ?? 0);
const snapshot = () =>
    JSON.stringify({
        title: title.value,
        bio: bio.value,
        website: website.value,
        socialLinks: socialLinks.value,
        published: published.value,
    });
const saved = ref(snapshot());
const dirty = computed(() => snapshot() !== saved.value);
const blocked = computed(() => !props.canWrite || busy.value || !!props.errors?.version);
function discard(): void {
    const values = fromKit();
    title.value = values.title;
    bio.value = values.bio;
    website.value = values.website;
    socialLinks.value = values.socialLinks;
    published.value = values.published;
    consent.value = false;
    saved.value = snapshot();
}
function requestSave(event: Event): void {
    if (props.kit?.published && !published.value) {
        confirmAction(
            professional.value ? 'Unpublish profile' : 'Unpublish media kit',
            `Unpublish ${props.kit.title}? Visitors will lose access to the public ${noun.value}.`,
            save,
            event,
            { tone: 'danger' },
        );
    } else save();
}
function save(): void {
    if (busy.value || props.errors?.version) return;
    const submittedVersion = version.value;
    const submitted = snapshot();
    router.put(
        '/media-kit',
        {
            title: title.value,
            bio: bio.value,
            website: website.value,
            social_links: socialLinks.value,
            published: published.value,
            consent: consent.value,
            version: submittedVersion,
        },
        {
            preserveState: true,
            preserveScroll: true,
            onStart: () => (busy.value = true),
            onFinish: () => (busy.value = false),
            onSuccess: () => {
                version.value = submittedVersion + 1;
                saved.value = submitted;
                consent.value = false;
            },
        },
    );
}

// Public page and summary.
const isLive = computed(() => !!props.kit?.published);
const publicUrl = computed(() => props.kit?.public_url ?? (props.kit ? `/kits/${props.kit.id}` : ''));
const views = computed(() => props.summary?.views ?? props.kit?.views ?? 0);
const notice = ref('');
let noticeTimer: ReturnType<typeof setTimeout> | undefined;
async function copy(text: string, what: string): Promise<void> {
    try {
        await navigator.clipboard.writeText(text);
        notice.value = `${what} copied`;
    } catch {
        notice.value = `Could not copy. Select the ${what.toLowerCase()} and copy it instead.`;
    }
    clearTimeout(noticeTimer);
    noticeTimer = setTimeout(() => (notice.value = ''), 4000);
}
onBeforeUnmount(() => clearTimeout(noticeTimer));
const previewLinks = computed(() => [
    ...(website.value ? [{ label: 'Visit website', url: website.value }] : []),
    ...socialLinks.value.filter((link) => link.label && link.url),
]);

// Lead links.
const linkLabel = ref('');
function createLink(): void {
    if (blocked.value) return;
    router.post(
        '/lead-links',
        { label: linkLabel.value.trim() },
        {
            preserveScroll: true,
            preserveState: true,
            onStart: () => (busy.value = true),
            onFinish: () => (busy.value = false),
            onSuccess: () => {
                linkLabel.value = '';
                panel.value = 'links';
            },
        },
    );
}
const statusChips = computed<FilterChip[]>(() =>
    (['active', 'expired', 'revoked', 'all'] as const).map((value) => ({
        value,
        label: { active: 'Active', expired: 'Expired', revoked: 'Revoked', all: 'All' }[value],
        count: props.links.counts[value],
    })),
);
function listHref(status: string, page = 1): string {
    const search = new URLSearchParams();
    if (status !== 'active') search.set('status', status);
    if (page > 1) search.set('page', String(page));
    const text = search.toString();
    return text ? `${base.value}?${text}` : base.value;
}
const columns: DataColumn[] = [
    { key: 'label', label: 'Label' },
    { key: 'created_at', label: 'Created' },
    { key: 'expires_at', label: 'Expires' },
    { key: 'status', label: 'Status' },
    { key: 'leads', label: 'Leads', numeric: true },
    { key: 'actions', label: 'Actions' },
];
const day = 86_400_000;
const shortDate = (value: string) =>
    new Date(value).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
function relative(value: string): string {
    const days = Math.round((new Date(value).getTime() - Date.now()) / day);
    if (days === 0) return 'today';
    return days > 0 ? `in ${days} ${days === 1 ? 'day' : 'days'}` : `${-days} ${days === -1 ? 'day' : 'days'} ago`;
}
function badge(row: LeadLink): { label: string; tone: BadgeTone } {
    if (row.status === 'revoked') return { label: 'Revoked', tone: 'neutral' };
    if (row.status === 'expired') return { label: 'Expired', tone: 'neutral' };
    return new Date(row.expires_at).getTime() - Date.now() <= 7 * day
        ? { label: 'Expires soon', tone: 'warning' }
        : { label: 'Active', tone: 'success' };
}
const linkName = (row: LeadLink) => row.label || `link created ${shortDate(row.created_at)}`;
function revoke(row: LeadLink, event: Event): void {
    confirmAction(
        'Revoke lead link',
        `Revoke ${row.label ? `“${row.label}”` : `the ${linkName(row)}`}? Anyone using it will lose access to its contact form. Leads already received stay in My leads.`,
        () =>
            router.delete(`/lead-links/${row.id}`, {
                data: { status: linkStatus.value, page: props.links.page },
                preserveScroll: true,
                preserveState: true,
                onStart: () => (busy.value = true),
                onFinish: () => (busy.value = false),
            }),
        event,
        { tone: 'danger' },
    );
}
const emptyLinks = computed(
    () =>
        ({
            active: 'No active lead links. Create one to share a contact form.',
            expired: 'No expired lead links.',
            revoked: 'No revoked lead links.',
            all: 'No lead links yet.',
        })[linkStatus.value],
);

// Narrow screens show one panel at a time.
const panel = ref<'edit' | 'preview' | 'links'>(
    props.issuedLink || linkStatus.value !== 'active' || props.links.page > 1 ? 'links' : 'edit',
);
</script>
<template>
    <Head :title="heading" />
    <PartnerChrome :account-name="account.name" :account-email="account.email">
        <main id="main-content" class="wd-kit mx-auto max-w-[1280px] px-6 pt-6 pb-12">
            <header class="kit-header">
                <div>
                    <p class="kit-eyebrow">{{ workspace.name }}</p>
                    <div class="kit-title-row">
                        <h1>{{ heading }}</h1>
                        <StatusBadge
                            :label="isLive ? 'Published' : kit ? 'Draft' : 'Not created'"
                            :tone="isLive ? 'success' : 'neutral'"
                        />
                    </div>
                </div>
                <div v-if="isLive" class="kit-header-actions">
                    <a
                        class="wd-table-button"
                        :href="publicUrl"
                        target="_blank"
                        rel="noopener noreferrer"
                        :aria-label="`Open public ${noun}`"
                        ><ExternalLink :size="15" aria-hidden="true" />Open<span class="kit-long">
                            public {{ noun }}</span
                        ></a
                    >
                    <button
                        type="button"
                        class="wd-table-button"
                        aria-label="Copy public link"
                        @click="copy(publicUrl, 'Public link')"
                    >
                        <Copy :size="15" aria-hidden="true" />Copy<span class="kit-long"> public link</span>
                    </button>
                </div>
            </header>
            <p v-if="notice" class="kit-notice" role="status">{{ notice }}</p>
            <div v-if="errors?.version" role="alert" class="wd-banner">
                <p>{{ errors.version }}</p>
                <button type="button" class="wd-link" @click="router.get(base, {}, { preserveState: false })">
                    Reload latest {{ professional ? 'profile' : 'kit' }}
                </button>
                <p class="wd-hint">Copy unsaved changes before reloading.</p>
            </div>
            <p v-if="!canWrite" class="wd-hint">Viewer access is read only.</p>
            <div class="kit-switch" role="group" aria-label="Show section">
                <button type="button" :aria-pressed="panel === 'edit'" @click="panel = 'edit'">Edit</button>
                <button type="button" :aria-pressed="panel === 'preview'" @click="panel = 'preview'">Preview</button>
                <button type="button" :aria-pressed="panel === 'links'" @click="panel = 'links'">
                    Lead links <span class="kit-count">{{ links.counts.active }}</span>
                </button>
            </div>
            <div class="kit-grid">
                <form
                    class="kit-card kit-editor"
                    :class="{ 'is-hidden-small': panel !== 'edit' }"
                    :aria-busy="busy"
                    aria-labelledby="kit-editor-title"
                    @submit.prevent="requestSave($event)"
                >
                    <header class="kit-card-head">
                        <h2 id="kit-editor-title">Edit {{ noun }}</h2>
                        <span class="wd-hint">Only what you add here is public</span>
                    </header>
                    <fieldset class="kit-fields" :disabled="blocked">
                        <legend class="wd-sr-only">{{ professional ? 'Professional profile' : 'Media kit' }}</legend>
                        <section class="kit-section" aria-labelledby="kit-profile-title">
                            <h3 id="kit-profile-title">{{ professional ? 'Identity and introduction' : 'Profile' }}</h3>
                            <FormField
                                id="kit-title"
                                v-model="title"
                                label="Public title"
                                :hint="`${title.length} / 160 characters`"
                                :maxlength="160"
                                :error="errors?.title"
                            />
                            <RichTextEditor
                                id="kit-bio"
                                v-model="bio"
                                label="Public introduction"
                                :max-length="5000"
                                :disabled="blocked"
                                :error="errors?.bio"
                            />
                            <FormField
                                id="kit-website"
                                v-model="website"
                                label="Public website (optional, https:// or http://)"
                                :required="false"
                                :maxlength="500"
                                :error="errors?.website"
                            />
                        </section>
                        <section class="kit-section" aria-labelledby="social-links-title">
                            <h3 id="social-links-title">
                                Social accounts <span class="kit-section-meta">{{ socialLinks.length }} of 8</span>
                            </h3>
                            <p class="wd-hint">Accounts are listed as links; they are not connected or verified.</p>
                            <div v-for="(link, index) in socialLinks" :key="index" class="kit-social">
                                <FormField
                                    :id="`social-label-${index}`"
                                    v-model="link.label"
                                    :label="`Account ${index + 1} name`"
                                    :maxlength="60"
                                    :error="errors?.[`social_links.${index}.label`]"
                                />
                                <FormField
                                    :id="`social-url-${index}`"
                                    v-model="link.url"
                                    :label="`Account ${index + 1} URL`"
                                    :maxlength="500"
                                    :error="errors?.[`social_links.${index}.url`]"
                                />
                                <button
                                    type="button"
                                    class="wd-table-icon-button kit-remove"
                                    :aria-label="`Remove social account ${index + 1}`"
                                    @click="socialLinks.splice(index, 1)"
                                >
                                    <X :size="16" aria-hidden="true" />
                                </button>
                            </div>
                            <button
                                type="button"
                                class="kit-add"
                                :disabled="socialLinks.length >= 8"
                                @click="socialLinks.push({ label: '', url: '' })"
                            >
                                <Plus :size="15" aria-hidden="true" />Add social account
                            </button>
                        </section>
                        <section class="kit-section" aria-labelledby="kit-publishing-title">
                            <h3 id="kit-publishing-title">{{ professional ? 'Publication' : 'Publishing' }}</h3>
                            <label class="wd-checkbox"
                                ><input v-model="published" type="checkbox" />{{
                                    professional ? 'Publish this professional profile' : 'Publish this media kit'
                                }}</label
                            >
                            <p class="wd-hint kit-indent">
                                {{
                                    published
                                        ? `Anyone with the public link can view this ${noun}.`
                                        : `Only members of ${workspace.name} can see this ${noun}.`
                                }}
                            </p>
                            <label v-if="published" class="wd-checkbox"
                                ><input
                                    v-model="consent"
                                    type="checkbox"
                                    :aria-invalid="errors?.consent ? true : undefined"
                                    :aria-describedby="errors?.consent ? 'kit-consent-error' : undefined"
                                />I agree to publish this title, introduction, website and social links for anyone with
                                the public URL.</label
                            >
                            <p v-if="errors?.consent" id="kit-consent-error" class="wd-field-error" role="alert">
                                {{ errors.consent }}
                            </p>
                        </section>
                    </fieldset>
                    <footer v-if="canWrite" class="kit-savebar">
                        <p v-if="dirty" class="kit-unsaved" role="status">
                            <span aria-hidden="true">●</span> Unsaved changes
                        </p>
                        <p v-else class="wd-hint">All changes saved</p>
                        <span class="kit-spacer"></span>
                        <Button
                            v-if="dirty"
                            type="button"
                            class="wd-button wd-button-secondary"
                            :disabled="busy"
                            @click="discard"
                            >Discard</Button
                        >
                        <Button type="submit" class="wd-button wd-button-primary" :disabled="blocked"
                            >Save {{ professional ? 'profile' : 'media kit' }}</Button
                        >
                    </footer>
                </form>
                <section
                    class="kit-card kit-public"
                    :class="{ 'is-hidden-small': panel !== 'preview' }"
                    aria-labelledby="kit-public-title"
                >
                    <header class="kit-card-head">
                        <h2 id="kit-public-title">Public {{ professional ? 'profile' : 'page' }}</h2>
                        <StatusBadge :label="isLive ? 'Live' : 'Not public'" :tone="isLive ? 'success' : 'neutral'" />
                    </header>
                    <div v-if="isLive" class="kit-url">
                        <label for="kit-public-url" class="wd-sr-only">Public link</label>
                        <input id="kit-public-url" class="wd-table-input" :value="publicUrl" readonly />
                        <button type="button" class="wd-table-button" @click="copy(publicUrl, 'Public link')">
                            <Copy :size="14" aria-hidden="true" />Copy
                        </button>
                    </div>
                    <p v-else class="wd-hint kit-pad">Publish and save to get a public link.</p>
                    <dl class="kit-stats">
                        <div>
                            <dt>Views</dt>
                            <dd>{{ views.toLocaleString() }}</dd>
                        </div>
                        <div>
                            <dt>Active lead links</dt>
                            <dd>{{ summary?.activeLinks ?? links.counts.active }}</dd>
                        </div>
                        <div>
                            <dt>Leads received</dt>
                            <dd>{{ (summary?.leads ?? 0).toLocaleString() }}</dd>
                        </div>
                    </dl>
                    <h3 class="kit-preview-label">Preview<span v-if="dirty"> · includes unsaved changes</span></h3>
                    <article class="kit-preview" aria-label="Public preview">
                        <p class="kit-preview-eyebrow">WeavDash · {{ heading }}</p>
                        <p class="kit-preview-title">{{ title || 'Your public title' }}</p>
                        <!-- Produced by the editor's limited schema; the server sanitizes it again on save and display. -->
                        <div v-if="bio" class="kit-preview-intro" v-html="bio"></div>
                        <p v-else class="wd-hint">Your introduction appears here.</p>
                        <ul v-if="previewLinks.length" class="kit-preview-links">
                            <li v-for="link in previewLinks" :key="link.label + link.url">{{ link.label }}</li>
                        </ul>
                    </article>
                    <p class="wd-hint kit-pad">Views are counted in total only. Individual visitors are not tracked.</p>
                </section>
                <section
                    class="kit-card kit-links"
                    :class="{ 'is-hidden-small': panel !== 'links' }"
                    aria-labelledby="lead-links-title"
                >
                    <header class="kit-card-head">
                        <h2 id="lead-links-title">Inbound lead links</h2>
                        <span class="kit-count">{{ links.counts.all }}</span>
                    </header>
                    <p class="wd-hint kit-pad kit-lead">
                        Each link opens a contact form only. Leads arrive in My leads. Links expire after 30 days and
                        can be revoked.
                    </p>
                    <form v-if="canWrite" class="kit-create" @submit.prevent="createLink">
                        <label for="lead-link-label">Label (optional)</label>
                        <input
                            id="lead-link-label"
                            v-model="linkLabel"
                            class="wd-table-input"
                            maxlength="80"
                            placeholder="For example, Instagram bio"
                            :aria-invalid="errors?.label ? 'true' : undefined"
                        />
                        <button type="submit" class="wd-table-button kit-primary" :disabled="blocked">
                            <Plus :size="15" aria-hidden="true" />Create lead link
                        </button>
                        <p v-if="errors?.label" class="wd-field-error" role="alert">{{ errors.label }}</p>
                    </form>
                    <div v-if="issuedLink" class="kit-issued">
                        <label for="issued-lead-link">Lead link (copy now)</label>
                        <div class="kit-url">
                            <input
                                id="issued-lead-link"
                                class="wd-table-input"
                                :value="issuedLink"
                                readonly
                                aria-describedby="issued-lead-link-hint"
                            />
                            <button type="button" class="wd-table-button" @click="copy(issuedLink, 'Lead link')">
                                <Copy :size="14" aria-hidden="true" />Copy
                            </button>
                        </div>
                        <p id="issued-lead-link-hint" class="wd-hint">
                            It is shown only once. Only a fingerprint of the link is stored.
                        </p>
                    </div>
                    <FilterChips
                        class="kit-chips"
                        label="Filter lead links by status"
                        :chips="statusChips"
                        :current="linkStatus"
                        :href-for="(value) => listHref(value)"
                        compact
                    />
                    <DataTable
                        v-if="links.items.length"
                        class="kit-table"
                        caption="Lead links"
                        :columns="columns"
                        :rows="links.items"
                        :row-key="(row) => row.id"
                    >
                        <template #head-actions><span class="wd-sr-only">Actions</span></template>
                        <template #cell-label="{ row }">
                            <span v-if="row.label">{{ row.label }}</span>
                            <span v-else class="kit-muted">Untitled link</span>
                        </template>
                        <template #cell-created_at="{ row }">
                            <time :datetime="row.created_at">{{ shortDate(row.created_at) }}</time>
                        </template>
                        <template #cell-expires_at="{ row }">
                            <span v-if="row.status === 'revoked'" class="kit-muted"
                                >Revoked {{ shortDate(row.revoked_at!) }}</span
                            >
                            <span v-else
                                ><time :datetime="row.expires_at">{{ shortDate(row.expires_at) }}</time
                                ><span class="kit-muted"> · {{ relative(row.expires_at) }}</span></span
                            >
                        </template>
                        <template #cell-status="{ row }">
                            <StatusBadge v-bind="badge(row)" />
                        </template>
                        <template #cell-actions="{ row }">
                            <button
                                v-if="canWrite && row.status === 'active'"
                                type="button"
                                class="wd-table-button kit-danger"
                                :aria-label="`Revoke lead link ${linkName(row)}`"
                                :disabled="blocked"
                                @click="revoke(row, $event)"
                            >
                                Revoke…
                            </button>
                        </template>
                    </DataTable>
                    <EmptyState v-else class="kit-empty" :title="emptyLinks" />
                    <Pagination
                        v-if="links.total > links.perPage"
                        class="kit-pagination"
                        label="Lead link pages"
                        noun="lead links"
                        numbered
                        :page="links.page"
                        :per-page="links.perPage"
                        :count="links.items.length"
                        :total="links.total"
                        :href-for="(page) => listHref(linkStatus, page)"
                    />
                </section>
            </div>
        </main>
    </PartnerChrome>
</template>
<style scoped>
.wd-kit {
    display: flex;
    flex-direction: column;
    gap: 14px;
    color: var(--wd-ink);
    font-size: 14px;
}
.kit-header {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-end;
    justify-content: space-between;
    gap: 12px 24px;
}
.kit-eyebrow {
    margin: 0 0 2px;
    color: var(--wd-muted);
    font-size: 13px;
}
.kit-title-row {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px 12px;
}
.wd-kit h1 {
    margin: 0;
    font-family: var(--wd-font-display);
    font-size: 28px;
    font-weight: 700;
    line-height: 1.15;
    letter-spacing: -0.01em;
}
.kit-header-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}
.kit-notice {
    margin: 0;
    color: var(--wd-success);
    font-size: 13px;
    font-weight: 700;
}
.kit-switch {
    display: none;
}
.kit-grid {
    display: grid;
    grid-template-columns: minmax(0, 1.45fr) minmax(320px, 1fr);
    grid-template-areas: 'editor public' 'links links';
    align-items: start;
    gap: 14px;
}
.kit-editor {
    grid-area: editor;
}
.kit-public {
    grid-area: public;
}
.kit-links {
    grid-area: links;
}
.kit-card {
    display: flex;
    flex-direction: column;
    min-width: 0;
    border: 1px solid var(--wd-line);
    border-radius: 12px;
    background: var(--wd-surface);
    overflow: clip;
}
.kit-card-head {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 6px 10px;
    padding: 12px 16px;
}
.kit-links .kit-card-head {
    justify-content: flex-start;
}
.wd-kit h2 {
    margin: 0;
    font-family: var(--wd-font-display);
    font-size: 16px;
    font-weight: 600;
}
.kit-count {
    padding: 1px 7px;
    border-radius: 999px;
    background: var(--wd-divider);
    color: var(--wd-muted);
    font-size: 12px;
    font-weight: 700;
}
.kit-fields {
    min-width: 0;
    margin: 0;
    padding: 0;
    border: 0;
}
.kit-section {
    display: flex;
    flex-direction: column;
    gap: 12px;
    padding: 12px 16px 16px;
    border-top: 1px solid var(--wd-divider);
}
.wd-kit h3 {
    display: flex;
    justify-content: space-between;
    margin: 0;
    color: var(--wd-muted);
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.04em;
    text-transform: uppercase;
}
.kit-section-meta {
    font-weight: 600;
    letter-spacing: 0;
    text-transform: none;
}
.kit-section :deep(.wd-field input) {
    min-height: 38px;
    padding: 7px 10px;
    font-size: 14px;
}
.kit-section > .wd-hint {
    margin-top: -6px;
}
.kit-social {
    display: grid;
    grid-template-columns: minmax(0, 0.7fr) minmax(0, 1.3fr) auto;
    align-items: end;
    gap: 8px;
}
.kit-remove {
    border-color: transparent;
    background: transparent;
    color: var(--wd-muted);
    min-height: 38px;
}
.kit-add {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    width: fit-content;
    min-height: 32px;
    padding: 0;
    border: 0;
    background: none;
    color: var(--wd-brand);
    font: inherit;
    font-size: 13px;
    font-weight: 700;
}
.kit-add:disabled {
    color: var(--wd-muted);
}
.kit-indent {
    margin-top: -8px;
    padding-left: 28px;
}
.kit-savebar {
    position: sticky;
    bottom: 0;
    z-index: 1;
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px 10px;
    padding: 10px 16px;
    border-top: 1px solid var(--wd-divider);
    background: var(--wd-paper);
}
.kit-savebar p {
    margin: 0;
}
.kit-unsaved {
    color: var(--wd-warning);
    font-size: 13px;
    font-weight: 700;
}
.kit-spacer {
    flex: 1;
}
.kit-url {
    display: flex;
    gap: 6px;
    padding: 0 16px 12px;
}
.kit-url input {
    flex: 1;
    min-width: 0;
    background: var(--wd-paper);
    color: var(--wd-body);
    font-size: 13px;
}
.kit-pad {
    margin: 0;
    padding: 0 16px 12px;
}
.kit-stats {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    margin: 0;
    border-top: 1px solid var(--wd-divider);
}
.kit-stats div {
    padding: 10px 16px;
}
.kit-stats div + div {
    border-left: 1px solid var(--wd-divider);
}
.kit-stats dt {
    color: var(--wd-muted);
    font-size: 12px;
    font-weight: 600;
}
.kit-stats dd {
    margin: 0;
    font-family: var(--wd-font-display);
    font-size: 20px;
    font-weight: 700;
    font-variant-numeric: tabular-nums;
}
.wd-kit h3.kit-preview-label {
    display: block;
    padding: 10px 16px 6px;
    border-top: 1px solid var(--wd-divider);
}
.kit-preview {
    margin: 0 16px 12px;
    padding: 16px 18px;
    border: 1px solid var(--wd-line);
    border-radius: 10px;
    background: var(--wd-paper);
    overflow-wrap: anywhere;
}
.kit-preview-eyebrow {
    margin: 0;
    color: var(--wd-muted);
    font-size: 11px;
}
.kit-preview-title {
    margin: 4px 0 8px;
    font-family: var(--wd-font-display);
    font-size: 19px;
    font-weight: 700;
    line-height: 1.2;
}
.kit-preview-intro {
    color: var(--wd-body);
    font-size: 13px;
    line-height: 1.6;
}
.kit-preview-intro :deep(p),
.kit-preview-intro :deep(ul),
.kit-preview-intro :deep(ol) {
    margin: 0 0 8px;
}
.kit-preview-intro :deep(ul),
.kit-preview-intro :deep(ol) {
    padding-left: 20px;
}
.kit-preview-intro :deep(ul) {
    list-style: disc;
}
.kit-preview-intro :deep(ol) {
    list-style: decimal;
}
.kit-preview-intro :deep(li > p) {
    margin: 0;
}
.kit-preview-intro :deep(h3) {
    display: block;
    margin: 10px 0 4px;
    color: var(--wd-ink);
    font-family: var(--wd-font-display);
    font-size: 15px;
    letter-spacing: 0;
    text-transform: none;
}
.kit-preview-intro :deep(a) {
    color: var(--wd-brand);
}
.kit-preview-links {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin: 8px 0 0;
    padding: 0;
    list-style: none;
}
.kit-preview-links li {
    padding: 3px 10px;
    border: 1px solid var(--wd-line);
    border-radius: 999px;
    background: var(--wd-surface);
    color: var(--wd-brand);
    font-size: 12px;
    font-weight: 700;
}
.kit-lead {
    margin-top: -6px;
}
.kit-create {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
    padding: 0 16px 12px;
}
.kit-create label {
    color: var(--wd-body);
    font-size: 12px;
    font-weight: 700;
}
.kit-create input {
    flex: 0 1 280px;
    min-width: 0;
    font-size: 13px;
}
.kit-create .wd-field-error {
    flex-basis: 100%;
    margin: 0;
}
.kit-primary {
    border-color: var(--wd-brand);
    background: var(--wd-brand);
    color: #fff;
}
.wd-kit .kit-primary:hover:not(:disabled) {
    border-color: var(--wd-brand-hover);
    background: var(--wd-brand-hover);
    color: #fff;
}
.kit-danger {
    color: var(--wd-danger);
}
.wd-kit .kit-danger:hover:not(:disabled) {
    background: var(--wd-danger-bg);
    color: var(--wd-danger);
}
.kit-issued {
    display: flex;
    flex-direction: column;
    gap: 6px;
    margin: 0 16px 12px;
    padding: 10px 12px;
    border: 1px solid #cfe7dc;
    border-radius: 10px;
    background: var(--wd-success-bg);
    color: var(--wd-success);
}
.kit-issued label {
    font-size: 13px;
    font-weight: 700;
}
.kit-issued .kit-url {
    padding: 0;
}
.kit-issued .kit-url input {
    background: var(--wd-surface);
}
.kit-issued .wd-hint {
    color: var(--wd-success);
}
.kit-chips {
    padding: 0 16px 10px;
}
.kit-muted {
    color: var(--wd-muted);
}
.kit-table {
    border-top: 1px solid var(--wd-divider);
}
.kit-table :deep(.wd-data-table) {
    font-size: 13px;
}
.kit-table :deep(.wd-data-table th),
.kit-table :deep(.wd-data-table td) {
    padding: 8px 12px;
    vertical-align: middle;
}
.kit-table :deep(.wd-data-table tr > :first-child) {
    padding-left: 16px;
}
.kit-table :deep(.wd-data-table tr > :last-child) {
    width: 1%;
    text-align: right;
    white-space: nowrap;
}
.kit-table :deep(.wd-data-table tbody th) {
    min-width: 160px;
    font-weight: 600;
}
.kit-empty {
    margin: 0 16px 16px;
}
.kit-pagination {
    padding: 8px 16px;
    border-top: 1px solid var(--wd-divider);
}
@media (min-width: 600px) and (pointer: fine) {
    .kit-table,
    .kit-create,
    .kit-url,
    .kit-header-actions {
        --wd-table-control: 32px;
    }
}
@media (max-width: 1099px) {
    .kit-grid {
        grid-template-columns: minmax(0, 1fr);
        grid-template-areas: 'editor' 'public' 'links';
    }
    .kit-switch {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 2px;
        padding: 3px;
        border-radius: 9px;
        background: var(--wd-divider);
    }
    .kit-switch button {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        min-height: 36px;
        border: 0;
        border-radius: 7px;
        background: transparent;
        color: var(--wd-muted);
        font: inherit;
        font-weight: 700;
    }
    .kit-switch button[aria-pressed='true'] {
        background: var(--wd-surface);
        box-shadow: 0 1px 2px rgb(34 26 38 / 12%);
        color: var(--wd-brand-hover);
    }
    .kit-card.is-hidden-small {
        display: none;
    }
}
@media (max-width: 599px) {
    .wd-kit {
        padding-inline: 16px;
    }
    .wd-kit h1 {
        font-size: 24px;
    }
    .kit-header-actions {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        width: 100%;
    }
    .kit-social {
        grid-template-columns: minmax(0, 1fr) auto;
    }
    .kit-social > :nth-child(2) {
        grid-row: 2;
    }
    .kit-social > .kit-remove {
        grid-row: 1 / 3;
        grid-column: 2;
        align-self: center;
    }
    .kit-stats dd {
        font-size: 18px;
    }
    .kit-create input {
        flex-basis: 100%;
    }
    .kit-create button {
        flex: 1;
    }
    .kit-long {
        display: none;
    }
    .kit-savebar p {
        flex-basis: 100%;
    }
    .kit-spacer {
        display: none;
    }
    .kit-savebar :deep(.wd-button) {
        flex: 1;
    }
    /* Each lead link is one compact row: label and status, then expiry and leads, with Revoke on the right. */
    .kit-table :deep(.wd-data-table tbody tr) {
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        align-items: center;
        gap: 2px 8px;
        margin: 0;
        padding: 10px 12px 10px 16px;
        border: 0;
        border-top: 1px solid var(--wd-divider);
        border-radius: 0;
    }
    .kit-table :deep(.wd-data-table tbody tr + tr) {
        margin-top: 0;
    }
    .kit-table :deep(.wd-data-table tbody tr > *) {
        padding: 0;
        width: auto;
        text-align: left;
    }
    .kit-table :deep(.wd-data-table tbody tr > th:first-child) {
        grid-row: 1;
        grid-column: 1;
        min-width: 0;
        padding-left: 0;
    }
    .kit-table :deep(.wd-data-table tbody td[data-label='Status']) {
        grid-row: 1;
        grid-column: 2;
        justify-self: end;
    }
    .kit-table :deep(.wd-data-table tbody td[data-label='Created']) {
        display: none;
    }
    .kit-table :deep(.wd-data-table tbody td[data-label='Expires']),
    .kit-table :deep(.wd-data-table tbody td[data-label='Leads']) {
        grid-column: 1;
        color: var(--wd-muted);
        font-size: 12px;
    }
    .kit-table :deep(.wd-data-table tbody td[data-label='Leads']::before) {
        content: 'Leads: ';
        display: inline;
        font-size: 12px;
        font-weight: 600;
    }
    .kit-table :deep(.wd-data-table tbody td[data-label='Expires']::before),
    .kit-table :deep(.wd-data-table tbody td[data-label='Status']::before),
    .kit-table :deep(.wd-data-table tbody td[data-label='Actions']::before) {
        display: none;
    }
    .kit-table :deep(.wd-data-table tbody td[data-label='Actions']) {
        grid-row: 2 / 4;
        grid-column: 2;
        justify-self: end;
        width: auto;
    }
}
</style>
