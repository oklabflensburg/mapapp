<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import { useTagKeyLabelsStore } from '../stores/tagKeyLabels'
import { useTagTranslations } from '../composables/useTagTranslations'
import type { TagRow } from '../lib/tagDrafts'

// Editable observation rows. Node and creation rows carry translation
// proposals (translated text above the raw identifier, with a slug generated
// between them); relation rows are plain key/value pairs (no translation API
// yet), selected by the plain prop. Way observations come from the catalog's
// controlled vocabulary: their keys are fixed and their values come from the
// configured choices.
const props = defineProps<{
  rows: TagRow[]
  plain?: boolean
  way?: boolean
  choices: { key: string; values: string[] }[]
}>()

const emit = defineEmits<{
  (event: 'remove', index: number): void
}>()

const { t } = useI18n()
const tagKeyLabels = useTagKeyLabelsStore()

function defaultValuesFor(key: string): string[] {
  return props.way ? props.choices.find(entry => entry.key === key.trim())?.values ?? [] : []
}

const { translationText, editTranslation, editRawTag } = useTagTranslations(
  (row, kind) => !!props.way && (kind === 'key' || defaultValuesFor(row.key).length > 0),
)
</script>

<template>
  <template v-if="!plain">
    <div v-for="(row, index) in rows" :key="index" class="tag-row">
      <div class="observation-field">
        <InputText
          :model-value="translationText(row, 'key')"
          :placeholder="t('nodeEditor.keyTranslation')"
          :aria-label="t('nodeEditor.keyTranslation')"
          @update:model-value="editTranslation(row, 'key', $event)"
        />
        <InputText
          :model-value="row.key"
          @update:model-value="editRawTag(row, 'key', $event)"
          :readonly="way"
          :invalid="!row.key.trim()"
          :placeholder="t('nodeEditor.key')"
          :aria-label="t('nodeEditor.key')"
          class="raw-tag-input"
        />
      </div>
      <div class="observation-field">
        <InputText
          :model-value="translationText(row, 'value')"
          :placeholder="t('nodeEditor.valueTranslation')"
          :aria-label="t('nodeEditor.valueTranslation')"
          @update:model-value="editTranslation(row, 'value', $event)"
        />
        <Select
          v-if="defaultValuesFor(row.key).length > 0"
          :model-value="row.value === '' ? null : row.value"
          :invalid="!row.value.trim()"
          :options="defaultValuesFor(row.key).map(value => ({ value, label: tagKeyLabels.valueLabel(row.key, value) }))"
          option-label="label"
          option-value="value"
          :placeholder="t('nodeEditor.value')"
          :aria-label="t('nodeEditor.value')"
          :editable="!way"
          show-clear
          size="small"
          class="raw-tag-input"
          @update:model-value="editRawTag(row, 'value', $event)"
        />
        <InputText v-else :model-value="row.value" :invalid="!row.value.trim()" @update:model-value="editRawTag(row, 'value', $event)" :placeholder="t('nodeEditor.value')" :aria-label="t('nodeEditor.value')" class="raw-tag-input" />
      </div>
      <Button
        :aria-label="t('nodeEditor.removeTag')"
        icon="pi pi-times"
        text
        severity="danger"
        @click="emit('remove', index)"
      />
    </div>
  </template>
  <template v-else>
    <!-- Relation observations are plain key/value pairs; structured
         relation data lives in the aspects system instead. -->
    <div v-for="(row, index) in rows" :key="index" class="tag-row">
      <InputText v-model="row.key" :invalid="!row.key.trim()" :placeholder="t('nodeEditor.key')" :aria-label="t('nodeEditor.key')" class="tag-key" />
      <InputText v-model="row.value" :invalid="!row.value.trim()" :placeholder="t('nodeEditor.value')" :aria-label="t('nodeEditor.value')" class="tag-value" />
      <Button
        :aria-label="t('nodeEditor.removeTag')"
        icon="pi pi-times"
        text
        severity="danger"
        @click="emit('remove', index)"
      />
    </div>
  </template>
</template>

<style scoped>
.tag-row {
  display: flex;
  gap: 0.25rem;
  align-items: center;
  margin-bottom: 0.25rem;
}

.tag-row .p-button {
  width: 1.5rem;
  height: 1.5rem;
  flex-shrink: 0;
}

.observation-field {
  display: flex;
  flex-direction: column;
  gap: 0.125rem;
  flex: 1;
  min-width: 0;
}

.observation-field > * {
  width: 100%;
  min-width: 0;
}

/* The raw identifier below the translation is secondary metadata. */
.observation-field .raw-tag-input {
  font-size: 0.65rem;
  padding: 0.1875rem 0.375rem;
  color: var(--p-text-muted-color);
}

.tag-key {
  flex: 1;
}

/* The translated label of a suggested tag reads as inert, not editable. */
.tag-key[readonly] {
  color: var(--p-text-muted-color);
  background: var(--p-content-background);
}

.tag-value {
  flex: 1.5;
}
</style>
