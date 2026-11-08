<script setup lang="ts">
import { recordResourceLabel } from '../features/entitlements/resources';
import { useWorkspaceExperience } from '../composables/useWorkspaceExperience';
const { professional } = useWorkspaceExperience();
import { Head } from '@inertiajs/vue3';
import FinanceLayout from '../components/FinanceLayout.vue';
import ContentSection from '../components/ContentSection.vue';
defineProps<{
    account: { name: string; email: string };
    entitlements: {
        state: string;
        plan: null | { name: string; version: number; assignment_version: number; starts_at: string; ends_at: string };
        usage: { resource: string; used: number; limit: number }[];
    };
}>();
</script>
<template>
    <Head :title="professional ? 'Workspace subscription' : 'Pilot entitlements'" />
    <FinanceLayout
        :account-name="account.name"
        :account-email="account.email"
        :title="professional ? 'Workspace subscription' : 'Pilot entitlements'"
        active="entitlements"
    >
        <ContentSection :title="professional ? 'Workspace subscription' : 'Pilot plan'"
            ><p>State: {{ entitlements.state }}</p>
            <p v-if="!entitlements.plan">
                <template v-if="professional"
                    >Activation is pending. Contact your administrator. Saved work remains readable and
                    exportable.</template
                ><template v-else
                    >No pilot assigned. Development use has no configured commercial limits. Pricing, the active-brand
                    definition, and commercial quotas remain undecided.</template
                >
            </p>
            <template v-else
                ><p>
                    {{ entitlements.plan.name }} · Plan version {{ entitlements.plan.version }} · Assignment
                    {{ entitlements.plan.assignment_version }}
                </p>
                <p>{{ entitlements.plan.starts_at }} to {{ entitlements.plan.ends_at }}</p>
                <p>
                    <template v-if="professional"
                        >An active subscription permits business changes within the assigned record quotas. While
                        inactive, saved work remains readable and exportable.</template
                    ><template v-else
                        >Limits apply to new records. Existing data remains readable and editable after expiry or
                        downgrade. Missing resources in a pilot plan have a zero create limit. Voided drafts and
                        payments count toward limits.</template
                    >
                </p>
                <div class="wd-finance-table">
                    <table>
                        <caption>
                            Current resource usage
                        </caption>
                        <thead>
                            <tr>
                                <th scope="col">Resource</th>
                                <th scope="col">Used</th>
                                <th scope="col">Limit</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in entitlements.usage" :key="row.resource">
                                <th scope="row">
                                    {{ professional ? recordResourceLabel(row.resource) : row.resource }}
                                </th>
                                <td>{{ row.used }}</td>
                                <td>{{ row.limit }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </template>
        </ContentSection>
        <p class="wd-hint">
            {{
                professional
                    ? 'An administrator activates your workspace subscription.'
                    : 'Pilot administration is local and audited.'
            }}
            There is no checkout, subscription charging, or automatic upgrade.
        </p>
    </FinanceLayout>
</template>
