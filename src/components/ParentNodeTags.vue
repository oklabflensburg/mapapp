<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import type { RelatedNode } from '../stores/userNodes'
import { useOsmDataStore } from '../stores/osmData'
import { useTagKeyLabelsStore } from '../stores/tagKeyLabels'

const props = defineProps<{ parent: RelatedNode }>()
const { t } = useI18n()
const osmData = useOsmDataStore()
const tagKeyLabels = useTagKeyLabelsStore()
const osmTags = computed(() => props.parent.provider === 'OSM'
  ? osmData.tagsById?.get(props.parent.providerNodeId)
  : undefined)
const osmEntries = computed(() => Object.entries(osmTags.value ?? {}))
const customEntries = computed(() => Object.entries(props.parent.tags))
const groups = computed(() => [
  { label: 'nodeEditor.osmTags', entries: osmEntries.value },
  { label: 'nodeEditor.parentCustomTags', entries: customEntries.value },
])
const osmPending = computed(() => !osmData.data && !osmData.loadFailed)
const noTags = computed(() => customEntries.value.length === 0 && (
  props.parent.provider === 'local' || (osmTags.value !== undefined && osmEntries.value.length === 0)
))

onMounted(() => {
  if (props.parent.provider === 'OSM') void osmData.load()
})
</script>

<template>
  <div class="parent-tags">
    <p v-if="noTags" class="tag-hint">{{ t('nodeEditor.noParentTags') }}</p>
    <p v-if="parent.provider === 'OSM' && osmTags === undefined" class="tag-hint" role="status">
      {{ t(osmPending ? 'nodeEditor.parentOsmLoading' : 'nodeEditor.parentOsmUnavailable') }}
    </p>
    <template v-for="group in groups" :key="group.label">
      <div v-if="group.entries.length" class="tag-group">
        <h6>{{ t(group.label) }}</h6>
        <table :aria-label="t(group.label)">
          <tbody>
            <tr v-for="[key, value] in group.entries" :key="key">
              <th scope="row">{{ tagKeyLabels.label(key) }}</th>
              <td>{{ value }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>
  </div>
</template>

<style scoped>
.parent-tags {
  margin-top: 0.375rem;
}

h6 {
  margin: 0.375rem 0 0.125rem;
  font-size: 0.75rem;
  font-weight: 600;
  color: var(--p-text-muted-color);
}

table {
  width: 100%;
  border-collapse: collapse;
  table-layout: fixed;
  font-size: 0.8rem;
}

th,
td {
  padding: 0.2rem 0.375rem 0.2rem 0;
  text-align: left;
  vertical-align: top;
  overflow-wrap: anywhere;
}

th {
  width: 40%;
  font-weight: 600;
}

.tag-hint {
  margin: 0;
  color: var(--p-text-muted-color);
  font-size: 0.8rem;
}
</style>
