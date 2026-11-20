<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AdminShell from '../layouts/AdminShell.vue';
import EmptyState from '../components/EmptyState.vue';
import Pagination from '../components/Pagination.vue';
import PrivacyReviewCard from '../features/privacy/PrivacyReviewCard.vue';
import type { ClosureRequest } from '../types/privacy';
defineProps<{
    listing: {
        total: number;
        rows: (ClosureRequest & {
            requested_by: string;
            requester_email: string;
            handler: string | null;
            assigned_to: number | null;
            counts: Record<string, number>;
        })[];
    };
    page: number;
}>();
</script>
<template>
    <Head title="Privacy requests" />
    <AdminShell
        title="Privacy requests"
        description="Assign and review closure requests. Private manifests and tenant content stay hidden."
        eyebrow="Governance"
    >
        <div class="space-y-6">
            <EmptyState
                v-if="!listing.rows.length"
                title="No privacy requests"
                description="Account and workspace closure requests will appear here."
            />
            <PrivacyReviewCard v-for="request in listing.rows" :key="request.id" :request="request" />
            <Pagination
                label="Privacy request pages"
                :page="page"
                :per-page="20"
                :count="listing.rows.length"
                :total="listing.total"
                noun="privacy requests"
                :href-for="(next) => `/admin/privacy-requests?page=${next}`"
            />
        </div>
    </AdminShell>
</template>
