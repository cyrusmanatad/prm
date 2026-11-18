<script setup lang="ts">
import { dateTime } from '../admin/format';
import SubmissionDetails from './SubmissionDetails.vue';
import { brandEvents, type BrandHistoryEntry } from './types';

/** Submissions and decisions, newest first. Administration also shows who acted. */
defineProps<{ history: BrandHistoryEntry[]; reviewer?: boolean }>();
const note = (entry: BrandHistoryEntry) => entry.feedback ?? entry.reason ?? null;
</script>

<template>
    <article v-for="entry in history" :key="entry.version" class="wd-record">
        <h3>{{ brandEvents[entry.event] ?? entry.event }}</h3>
        <p class="wd-hint">
            {{ dateTime(entry.occurred_at) }}<template v-if="reviewer && entry.actor"> · {{ entry.actor }}</template
            ><template v-if="reviewer && entry.operator"> · recorded by {{ entry.operator }}</template>
        </p>
        <p v-if="note(entry)" class="wd-pipeline-note">
            <strong>{{
                entry.event === 'rollout_approved'
                    ? 'Reason: '
                    : reviewer
                      ? 'Feedback for the applicant: '
                      : 'Feedback from the reviewer: '
            }}</strong
            >{{ note(entry) }}
        </p>
        <SubmissionDetails v-if="entry.submission" :fields="entry.submission" />
    </article>
</template>
