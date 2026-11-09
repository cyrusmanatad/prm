<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
const page = usePage();
</script>

<template>
    <aside v-if="page.props.stagingMode" class="wd-staging-banner" aria-label="Staging environment">
        <strong>Staging</strong> · Synthetic data only. Create an account or use a review account. Email delivery is
        enabled.
        <span v-if="page.props.unscannedUploadsAllowed"
            >Uploads use private staging storage and are not scanned for malware.</span
        >
        <span v-else-if="page.props.uploadsEnabled === false">Attachments are disabled; continue without files.</span>
    </aside>
</template>
