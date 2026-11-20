<script setup lang="ts">
import ContentSection from '../../components/ContentSection.vue';
import StatusBadge from '../../components/StatusBadge.vue';
import { Button } from '../../components/ui/button';
import type { ExportReceipt } from '../../types/privacy';
defineProps<{
    exports: ExportReceipt[];
    workspaces: { id: string; name: string }[];
    verified: boolean;
    busy: boolean;
    downloading: string | null;
    downloadError: string;
}>();
defineEmits<{ download: [receipt: ExportReceipt]; refresh: [] }>();
function date(value: string): string {
    return new Date(value).toLocaleString();
}
</script>
<template>
    <ContentSection
        title="Recent exports"
        description="Only you can download these archives. Changed data or sharing permissions require a fresh export."
    >
        <p v-if="downloadError" class="wd-field-error" role="alert">{{ downloadError }}</p>
        <p v-if="!exports.length" class="wd-empty">You have not created an export yet.</p>
        <ul v-else class="wd-workspace-list">
            <li
                v-for="receipt in exports"
                :key="receipt.id"
                class="flex flex-wrap items-center justify-between gap-4 py-4"
            >
                <div class="min-w-0">
                    <strong>{{
                        receipt.scope === 'account'
                            ? 'My account'
                            : (workspaces.find((org) => org.id === receipt.org_id)?.name ?? 'Workspace export')
                    }}</strong>
                    <p class="wd-description">
                        Created {{ date(receipt.created_at) }} · Expires {{ date(receipt.expires_at) }}
                    </p>
                    <StatusBadge
                        :label="
                            receipt.state === 'building'
                                ? 'Preparing'
                                : receipt.state === 'ready'
                                  ? 'Ready'
                                  : receipt.state === 'revoked'
                                    ? 'Access revoked'
                                    : receipt.state === 'failed'
                                      ? 'Could not prepare — create a new export'
                                      : 'Expired'
                        "
                    />
                </div>
                <Button
                    v-if="receipt.state === 'ready'"
                    type="button"
                    class="wd-button wd-button-secondary"
                    :disabled="busy || !verified"
                    :ariaLabel="`${downloading === receipt.id ? 'Downloading…' : 'Download ZIP'} for ${receipt.scope === 'account' ? 'account' : 'workspace'} export created ${date(receipt.created_at)}`"
                    @click="$emit('download', receipt)"
                    >{{ downloading === receipt.id ? 'Downloading…' : 'Download ZIP' }}</Button
                >
            </li>
        </ul>
        <Button type="button" class="wd-button wd-button-secondary" :disabled="busy" @click="$emit('refresh')"
            >Refresh exports</Button
        >
    </ContentSection>
</template>
