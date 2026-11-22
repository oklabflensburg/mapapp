<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import Select from 'primevue/select'
import { useOsmDataStore } from '../stores/osmData'
import { useEditingCatalogStore } from '../stores/editingCatalog'
import { useAuthStore } from '../stores/auth'
import { useTagKeyLabelsStore } from '../stores/tagKeyLabels'
import ObservationTagRows from './ObservationTagRows.vue'
import TagTable from './TagTable.vue'
import { isOsmWay } from '../lib/osm'
import type { TagRow } from '../lib/tagDrafts'
import type { NodeEditTarget } from '../stores/userNodes'

const props = defineProps<{
  // The edited element; ways resolve their vocabulary through OSM data.
  node: NodeEditTarget | null
  // Relation observations are plain key/value pairs without translations.
  plain?: boolean
  // Approved tag rows of the same draft; their keys are not suggested again.
  approvedRows: TagRow[]
}>()

// The panel owns the observation draft rows because they are part of the
// saved draft; rows are added and removed here. saveAttempted belongs to the
// panel's save flow: a freshly added observation row must not read as an
// error even after a failed save attempt.
const rows = defineModel<TagRow[]>({ required: true })
const saveAttempted = defineModel<boolean>('saveAttempted', { required: true })

const { t } = useI18n()
const osmData = useOsmDataStore()
const catalog = useEditingCatalogStore()
const auth = useAuthStore()
const tagKeyLabels = useTagKeyLabelsStore()

const way = computed(() => isOsmWay(props.node, osmData.data?.features))

// Ways offer the editing catalog's controlled vocabulary; every other
// element uses free-form observations (structured cross-section data lives
// in the aspects system).
const observationChoices = computed(() => way.value
  ? (catalog.wayVocabularies[props.node?.osmTags.highway ?? ''] ?? [])
  : [])

// The admin-exposed default tags not yet on the element (neither as an
// approved tag nor as an observation), offered as a searchable dropdown.
// The dropdown shows the readable label; the raw key is the value that is
// added as an observation row. Searching matches both.
const suggestedObservationOptions = computed(() => {
  if (!auth.canEdit) {
    return []
  }
  const used = new Set([...props.approvedRows, ...rows.value].map((row) => row.key.trim()))
  return observationChoices.value
    .filter((entry) => !used.has(entry.key))
    .map((entry) => ({ key: entry.key, label: tagKeyLabels.label(entry.key) }))
})

function addObservationRow(): void {
  // A fresh row is not a validation error yet, so reset the attempt flag.
  saveAttempted.value = false
  rows.value = [...rows.value, { key: '', value: '' }]
}

// The select stays uncontrolled (no value); picking an option immediately
// adds the observation row and returns the dropdown to its placeholder.
function addSuggestedObservation(key: unknown): void {
  if (typeof key === 'string') {
    rows.value = [...rows.value, { key, value: '' }]
  }
}

function removeObservationRow(index: number): void {
  rows.value = rows.value.filter((_, position) => position !== index)
}
</script>

<template>
  <section v-if="auth.canEdit || rows.length > 0" class="observations-section">
    <template v-if="auth.canEdit">
      <p v-if="way && catalog.failed" role="alert">{{ t('crossSection.loadFailed') }}
        <Button :label="t('crossSection.retry')" text @click="catalog.load()" />
      </p>
      <p v-else-if="way && observationChoices.length === 0" class="empty-hint">{{ t('crossSection.noObservations') }}</p>
      <Select
        v-if="suggestedObservationOptions.length > 0"
        :model-value="null"
        :options="suggestedObservationOptions"
        option-label="label"
        option-value="key"
        :filter-fields="['label', 'key']"
        :placeholder="t('nodeEditor.suggestedTags')"
        :filter="true"
        :filter-placeholder="t('nodeEditor.searchTags')"
        size="small"
        class="suggestion-select"
        :aria-label="t('nodeEditor.suggestedTags')"
        @update:model-value="addSuggestedObservation"
      />
      <ObservationTagRows :rows="rows" :plain="plain" :way="way" :choices="observationChoices" @remove="removeObservationRow" />
      <Button
        v-if="!way"
        :aria-label="t('nodeEditor.addTag')"
        icon="pi pi-plus"
        text
        severity="secondary"
        @click="addObservationRow"
      />
    </template>
    <template v-else>
      <TagTable :entries="rows" />
    </template>
  </section>
</template>

<style scoped>
.observations-section {
  margin-bottom: 0.5rem;
}

.empty-hint {
  margin: 0 0 0.25rem;
  color: var(--p-text-muted-color);
}

/* The searchable suggestion dropdown fills the panel width, like the tag
   rows below it. */
.suggestion-select {
  display: flex;
  width: 100%;
  margin-bottom: 0.375rem;
}
</style>
