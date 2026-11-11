<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import Select from 'primevue/select'
import { useEditingCatalogStore } from '../stores/editingCatalog'
import type { AspectDirection, AspectRow } from '../lib/crossSection'
import { compareRows, isAspectRowEditable } from '../lib/crossSection'
import { DIRECTIONS } from '../lib/crossSection'
import { useTagKeyLabelsStore } from '../stores/tagKeyLabels'
const rows = defineModel<AspectRow[]>({ required: true })
const props = defineProps<{ readonly?: boolean }>()
const catalog = useEditingCatalogStore()
const labels = useTagKeyLabelsStore()
const { t } = useI18n()
const types = computed(() => catalog.aspectTypes)
const typeOptions = computed(() => types.value.map(type => ({ ...type, label: labels.label(type.name) })))
const directionOptions = computed(() => DIRECTIONS.map(direction => ({
  direction,
  label: t(`crossSection.directions.${direction}`),
})))
// Approved aspects show as tag info in the panel, not as locked selects; a
// row without any editable content left is not rendered at all.
const visibleRows = computed(() => rows.value
  .map((row, index) => ({ row, index }))
  .filter(({ row }) => isAspectRowEditable(row, types.value)))
// The row's catalog attributes that are still editable; approved ones are
// covered by the tag table.
function editableAttributes(row: AspectRow) {
  return (types.value.find(type => type.name === row.type)?.attributes ?? [])
    .filter(attribute => !row.approvedKeys.includes(attribute.key))
}
const structureLocked = computed(() => props.readonly || rows.value.some(row => row.approvedKeys.length > 0))
const unavailable = computed(() => catalog.failed || catalog.loading)
function changeType(row: AspectRow, type: string): void {
  row.type = type
  const attributes = types.value.find(entry => entry.name === type)?.attributes ?? []
  row.values = Object.fromEntries(Object.entries(row.values).filter(([key, value]) =>
    attributes.some(attribute => attribute.key === key && attribute.values.includes(value))))
}
// Positions are per direction: after any structural edit the editable draft
// is renumbered 1…n in canonical display order within each direction.
function renumber(list: AspectRow[] = rows.value): void {
  const counters = new Map<AspectDirection, bigint>()
  for (const row of [...list].sort(compareRows)) {
    const next = (counters.get(row.direction) ?? 0n) + 1n
    counters.set(row.direction, next)
    row.index = String(next)
  }
}
function changeDirection(): void {
  if (!structureLocked.value) renumber()
}
function add(): void {
  if (props.readonly || unavailable.value || !types.value.length) return
  const direction: AspectDirection = 'center'
  const lastIndex = rows.value.reduce((max, row) =>
    row.direction === direction && BigInt(row.index) > max ? BigInt(row.index) : max, 0n)
  const row: AspectRow = { type: types.value[0]!.name, direction, index: String(lastIndex + 1n), values: {}, approvedKeys: [] }
  rows.value.push(row)
  if (!structureLocked.value) renumber()
}
function remove(index: number): void {
  if (props.readonly || rows.value[index]?.approvedKeys.length) return
  rows.value.splice(index, 1)
  if (!structureLocked.value) renumber()
}
function valueLabel(row: AspectRow, key: string, value: string): string {
  const rawKey = `way.${row.type}.${row.direction}.${row.index}.${key}`
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
    <div v-for="{ row, index } in visibleRows" :key="`${row.type}-${row.direction}-${row.index}`" class="observation">
      <label class="type-label">
        {{ t('crossSection.type') }}
        <Select
          v-model="row.type"
          size="small"
          :options="typeOptions"
          option-label="label"
          option-value="name"
          :disabled="readonly || row.approvedKeys.length > 0 || unavailable"
          :aria-label="t('crossSection.type')"
          @change="changeType(row, row.type)"
        />
      </label>
      <label class="direction-label">
        {{ t('crossSection.direction') }}
        <Select
          v-model="row.direction"
          size="small"
          :options="directionOptions"
          option-label="label"
          option-value="direction"
          :disabled="readonly || row.approvedKeys.length > 0 || unavailable || structureLocked"
          :aria-label="t('crossSection.direction')"
          @change="changeDirection"
        />
      </label>
      <label v-for="attribute in editableAttributes(row)" :key="attribute.key">
        {{ labels.label(attribute.key) }}
        <Select
          v-model="row.values[attribute.key]"
          size="small"
          :options="attribute.values.map(value => ({ value, label: valueLabel(row, attribute.key, value) }))"
          option-label="label"
          option-value="value"
          :disabled="readonly || unavailable"
          :aria-label="labels.label(attribute.key)"
          show-clear
        />
        <small v-if="row.values[attribute.key] && !attribute.values.includes(row.values[attribute.key]!)">{{ valueLabel(row, attribute.key, row.values[attribute.key]!) }}</small>
      </label>
      <template v-for="(value, key) in row.values" :key="key">
        <span v-if="!types.find(type => type.name === row.type)?.attributes.some(attribute => attribute.key === key)
          && !row.approvedKeys.includes(String(key))">{{ labels.label(key) }}: {{ valueLabel(row, key, value) }}</span>
      </template>
      <Button
        v-if="!readonly"
        class="remove-observation"
        icon="pi pi-times"
        :aria-label="t('crossSection.remove')"
        :disabled="row.approvedKeys.length > 0 || unavailable"
        severity="danger"
        text
        @click="remove(index)"
      />
    </div>
    <Button v-if="!readonly" :label="t('crossSection.add')" icon="pi pi-plus" :disabled="!types.length || unavailable" text @click="add()" />
  </div>
</template>
<style scoped>
/* No fieldset box around an observation: it flows in one slim row
   whenever it fits (labelled type select, attribute selects, remove
   action) and wraps only when the panel runs out of width. */
.observation {
  display: flex;
  flex-wrap: wrap;
  gap: 0.25rem;
  margin: 0.25rem 0;
  padding: 0.25rem 0;
  border-bottom: 1px solid var(--p-content-border-color);
  align-items: flex-end;
}

label {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  flex: 1 1 5rem;
  min-width: 4.5rem;
  font-size: 0.65rem;
  color: var(--p-text-muted-color);
}

label .p-select {
  width: 100%;
}

label small {
  font-size: 0.65rem;
  color: var(--p-text-muted-color);
}

.remove-observation {
  width: 1.5rem;
  height: 1.5rem;
  flex: 0 0 auto;
}

span {
  flex-basis: 100%;
}
</style>
