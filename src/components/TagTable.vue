<script setup lang="ts">
import { useTagKeyLabelsStore } from '../stores/tagKeyLabels'

// A read-only key/value tag table; every tag surface of the editor uses it,
// from raw OSM tags to approved custom tags. Keys and values show their
// catalog labels instead of the raw identifiers.
defineProps<{
  entries: { key: string; value: string }[]
}>()

const tagKeyLabels = useTagKeyLabelsStore()
</script>

<template>
  <div class="tag-table-scroll">
    <table class="tag-table">
      <tr v-for="(entry, index) in entries" :key="index">
        <th>{{ tagKeyLabels.label(entry.key) }}</th>
        <td>{{ tagKeyLabels.valueLabel(entry.key, entry.value) }}</td>
      </tr>
    </table>
  </div>
</template>

<style scoped>
/* Fixed layout keeps the key/value columns aligned across all tag tables. */
.tag-table-scroll {
  max-height: 9rem;
  overflow-y: auto;
}

.tag-table {
  border-collapse: collapse;
  width: 100%;
  table-layout: fixed;
}

.tag-table th,
.tag-table td {
  text-align: left;
  padding: 0.1rem 0.4rem 0.1rem 0;
  font-size: 0.7rem;
  vertical-align: top;
  border-bottom: 1px solid var(--p-content-border-color);
  overflow-wrap: anywhere;
}

.tag-table th {
  width: 40%;
  font-weight: 600;
  color: var(--p-text-muted-color);
}
</style>
