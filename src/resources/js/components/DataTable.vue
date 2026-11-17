<script setup lang="ts" generic="Row extends object">
export type DataColumn = { key: string; label: string; numeric?: boolean };

defineProps<{
    caption: string;
    columns: DataColumn[];
    rows: Row[];
    rowKey: (row: Row) => string | number;
    /** Keep a horizontally scrollable table on small screens instead of compact records. */
    scroll?: boolean;
}>();
function cell(row: Row, key: string): unknown {
    return (row as Record<string, unknown>)[key];
}
</script>

<template>
    <div
        class="wd-data-region"
        :class="{ 'wd-data-stacked': !scroll }"
        role="region"
        :aria-label="`${caption} table`"
        tabindex="0"
    >
        <table class="wd-data-table" role="table">
            <caption>
                {{
                    caption
                }}
            </caption>
            <thead role="rowgroup">
                <tr role="row">
                    <th
                        v-for="column in columns"
                        :key="column.key"
                        scope="col"
                        role="columnheader"
                        :class="{ 'wd-data-numeric': column.numeric }"
                    >
                        {{ column.label }}
                    </th>
                </tr>
            </thead>
            <tbody role="rowgroup">
                <tr v-for="row in rows" :key="rowKey(row)" role="row">
                    <template v-for="(column, index) in columns" :key="column.key">
                        <th v-if="index === 0" scope="row" role="rowheader">
                            <slot :name="`cell-${column.key}`" :row="row">{{ cell(row, column.key) }}</slot>
                        </th>
                        <td
                            v-else
                            role="cell"
                            v-bind="{ 'data-label': column.label }"
                            :class="{ 'wd-data-numeric': column.numeric }"
                        >
                            <slot :name="`cell-${column.key}`" :row="row">{{ cell(row, column.key) }}</slot>
                        </td>
                    </template>
                </tr>
            </tbody>
        </table>
    </div>
</template>
