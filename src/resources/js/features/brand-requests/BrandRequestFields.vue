<script setup lang="ts">
import FieldGroup from '../../components/FieldGroup.vue';
import FormField from '../../components/FormField.vue';
import type { BrandFields } from './types';

/** The four business fields of a Brand workspace request, shared by the new and the revised request forms. */
defineProps<{ form: BrandFields; errors: Partial<Record<keyof BrandFields, string>>; prefix?: string }>();
</script>

<template>
    <FormField
        :id="`${prefix ?? 'brand'}-name`"
        v-model="form.brand_name"
        label="Brand name"
        autocomplete="organization"
        :maxlength="120"
        :error="errors.brand_name"
        hint="The name your partners know. It becomes the workspace name."
    />
    <FormField
        :id="`${prefix ?? 'brand'}-website`"
        v-model="form.website"
        label="Website or public business profile"
        autocomplete="url"
        :maxlength="500"
        :error="errors.website"
        hint="A full address starting with https:// or http://, such as your site or a public business page."
    />
    <FormField
        :id="`${prefix ?? 'brand'}-role`"
        v-model="form.applicant_role"
        label="Your role at the business"
        autocomplete="organization-title"
        :maxlength="120"
        :error="errors.applicant_role"
    />
    <FieldGroup
        :id="`${prefix ?? 'brand'}-use`"
        v-slot="{ id, describedby, invalid }"
        label="How you plan to use WeavDash"
        :error="errors.intended_use"
        hint="For example, the partner programme you want to run. Up to 2,000 characters."
    >
        <textarea
            :id="id"
            v-model="form.intended_use"
            rows="5"
            maxlength="2000"
            required
            :aria-invalid="invalid"
            :aria-describedby="describedby"
        />
    </FieldGroup>
</template>
