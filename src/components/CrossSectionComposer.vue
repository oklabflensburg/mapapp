<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import Select from 'primevue/select'
import { useEditingCatalogStore } from '../stores/editingCatalog'
import type { AspectRow } from '../lib/crossSection'
import { useTagKeyLabelsStore } from '../stores/tagKeyLabels'
const rows = defineModel<AspectRow[]>({ required: true })
const props = defineProps<{ readonly?: boolean }>()
const catalog = useEditingCatalogStore()
const labels = useTagKeyLabelsStore()
const { t } = useI18n()
const types = computed(() => catalog.aspectTypes)
const typeOptions = computed(() => types.value.map(type => ({ ...type, label: labels.label(type.name) })))
const structureLocked = computed(() => props.readonly || rows.value.some(row => row.approvedKeys.length > 0))
const unavailable = computed(() => catalog.failed || catalog.loading)
function changeType(row: AspectRow, type: string): void {
  row.type = type
  const attributes = types.value.find(entry => entry.name === type)?.attributes ?? []
  row.values = Object.fromEntries(Object.entries(row.values).filter(([key, value]) =>
    attributes.some(attribute => attribute.key === key && attribute.values.includes(value))))
}
function renumber(list: AspectRow[] = rows.value): void {
  list.forEach((row, index) => { row.index = String(index + 1) })
}
function add(): void {
  if (props.readonly || unavailable.value || !types.value.length) return
  const lastIndex = rows.value.reduce((max, row) => BigInt(row.index) > max ? BigInt(row.index) : max, 0n)
  const row: AspectRow = { type: types.value[0]!.name, index: String(lastIndex + 1n), values: {}, approvedKeys: [] }
  rows.value.push(row)
  if (!structureLocked.value) renumber()
}
function remove(index: number): void {
  if (props.readonly || rows.value[index]?.approvedKeys.length) return
  rows.value.splice(index, 1)
  if (!structureLocked.value) renumber()
}
function valueLabel(row: AspectRow, key: string, value: string): string {
  const rawKey = `way.${row.type}.${row.index}.${key}`
  const translated = labels.valueLabel(rawKey, value)
  if (translated !== value) return translated
  // Attribute translations are shared across every indexed observation.
  const attributeLabel = labels.valueLabel(key, value)
  return attributeLabel !== value ? attributeLabel : types.value.find(type => type.name === row.type)?.attributes.find(attribute => attribute.key === key)?.labels?.[value] ?? value
}
</script>
<template>
  <div class="cross-section-composer">
    <p v-if="catalog.failed" role="alert">{{ t('crossSection.loadFailed') }}</p>
    <p v-else-if="!catalog.loading && !types.length" role="status">{{ t('crossSection.noTypes') }}</p>
    <Button v-if="!readonly && (catalog.failed || !types.length)" :label="t('crossSection.reload')" :loading="catalog.loading" text @click="catalog.reload()" />
    <fieldset v-for="(row, index) in rows" :key="`${row.type}-${row.index}`">
      <legend>{{ t('crossSection.observation', { position: row.index }) }}</legend>
      <div class="observation-row">
        <Select
          v-model="row.type"
          class="type-select"
          size="small"
          :options="typeOptions"
          option-label="label"
          option-value="name"
          :disabled="readonly || row.approvedKeys.length > 0 || unavailable"
          :aria-label="t('crossSection.type')"
          @change="changeType(row, row.type)"
        />
        <Button
          v-if="!readonly"
          icon="pi pi-times"
          :aria-label="t('crossSection.remove')"
          :disabled="row.approvedKeys.length > 0 || unavailable"
          severity="danger"
          text
          @click="remove(index)"
        />
      </div>
      <label v-for="attribute in types.find(type => type.name === row.type)?.attributes ?? []" :key="attribute.key">
        {{ labels.label(attribute.key) }}
        <Select
          v-model="row.values[attribute.key]"
          size="small"
          :options="attribute.values.map(value => ({ value, label: valueLabel(row, attribute.key, value) }))"
          option-label="label"
          option-value="value"
          :disabled="readonly || row.approvedKeys.includes(attribute.key) || unavailable"
          :aria-label="labels.label(attribute.key)"
          show-clear
        />
        <small v-if="row.values[attribute.key] && !attribute.values.includes(row.values[attribute.key]!)">{{ valueLabel(row, attribute.key, row.values[attribute.key]!) }}</small>
      </label>
      <template v-for="(value, key) in row.values" :key="key">
        <span v-if="!types.find(type => type.name === row.type)?.attributes.some(attribute => attribute.key === key)">{{ labels.label(key) }}: {{ valueLabel(row, key, value) }}</span>
      </template>
    </fieldset>
    <Button v-if="!readonly" :label="t('crossSection.add')" icon="pi pi-plus" :disabled="!types.length || unavailable" text @click="add()" />
  </div>
</template>
<style scoped>
fieldset {
  display: flex;
  flex-wrap: wrap;
  gap: 0.375rem;
  margin: 0.375rem 0;
  padding: 0.375rem;
  border: 1px solid var(--p-content-border-color);
}

legend {
  padding: 0 0.25rem;
  font-size: 0.65rem;
  font-weight: 600;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  color: var(--p-text-muted-color);
}

/* The type selector is the row's leading control: it gets the room, the
   remove action sits next to it, the attribute selects wrap below. */
.observation-row {
  display: flex;
  flex-basis: 100%;
  align-items: center;
  gap: 0.25rem;
}

.type-select {
  flex: 1;
  min-width: 11rem;
}

.observation-row .p-button {
  width: 1.5rem;
  height: 1.5rem;
  flex-shrink: 0;
}

label {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  flex: 1 1 7.5rem;
  min-width: 6rem;
}

label small {
  font-size: 0.65rem;
  color: var(--p-text-muted-color);
}

span {
  flex-basis: 100%;
}
</style>
