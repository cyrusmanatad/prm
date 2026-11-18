<script setup lang="ts">
import DetailList from '../../components/DetailList.vue';
import { fieldLabels, type BrandFields } from './types';

/** Business information exactly as the applicant supplied it. The address is shown as text unless `link` is set. */
defineProps<{ fields: Partial<BrandFields>; link?: boolean }>();
</script>

<template>
    <DetailList
        :items="[
            { key: 'brand_name', label: fieldLabels.brand_name, value: fields.brand_name },
            { key: 'website', label: fieldLabels.website, value: fields.website },
            { key: 'applicant_role', label: fieldLabels.applicant_role, value: fields.applicant_role },
            { key: 'intended_use', label: fieldLabels.intended_use, value: fields.intended_use },
        ]"
        missing="Not provided"
    >
        <template v-if="link && fields.website" #website
            ><a
                :href="fields.website"
                class="wd-link inline-flex min-h-11 items-center"
                target="_blank"
                rel="noopener noreferrer nofollow"
                >{{ fields.website }}<span class="wd-sr-only"> (opens in a new tab)</span></a
            ></template
        >
        <template #intended_use
            ><span class="wd-pipeline-note">{{ fields.intended_use || 'Not provided' }}</span></template
        >
    </DetailList>
</template>
