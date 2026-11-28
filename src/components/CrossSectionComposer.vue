<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import Select from 'primevue/select'
import SelectButton from 'primevue/selectbutton'
import { useEditingCatalogStore } from '../stores/editingCatalog'
import type { AspectSide, AspectRow } from '../lib/crossSection'
import { isAspectRowEditable } from '../lib/crossSection'
import { SIDES } from '../lib/crossSection'
import { useTagKeyLabelsStore } from '../stores/tagKeyLabels'
const rows = defineModel<AspectRow[]>({ required: true })
const props = defineProps<{ readonly?: boolean }>()
const catalog = useEditingCatalogStore()
const labels = useTagKeyLabelsStore()
const { t } = useI18n()
const types = computed(() => catalog.aspectTypes)
const typeOptions = computed(() => types.value.map(type => ({ ...type, label: labels.label(type.name) })))
const sideOptions = computed(() => SIDES.map(side => ({
  side,
  label: t(`crossSection.sides.${side}`),
})))
// Approved aspects show as tag info in the panel, not as locked selects; a
// row without any editable content left is not rendered at all.
const visibleRows = computed(() => rows.value
  .map((row, index) => ({ row, index }))
  .filter(({ row }) => isAspectRowEditable(row, types.value)))
// The row's catalog attributes that are still editable; approved ones are
// covered by the tag table. Every editable attribute becomes its own
// observation relation on save (see specs/map-editing/observation-relations/
// spec.md R4).
function editableAttributes(row: AspectRow) {
  return (types.value.find(type => type.name === row.type)?.attributes ?? [])
    .filter(attribute => !row.approvedKeys.includes(attribute.key))
}
const unavailable = computed(() => catalog.failed || catalog.loading)
function changeType(row: AspectRow, type: string): void {
  row.type = type
  const attributes = types.value.find(entry => entry.name === type)?.attributes ?? []
  row.values = Object.fromEntries(Object.entries(row.values).filter(([key, value]) =>
    attributes.some(attribute => attribute.key === key && attribute.values.includes(value))))
}
function remove(index: number): void {
  if (props.readonly || rows.value[index]?.approvedKeys.length) return
  rows.value.splice(index, 1)
}
function valueLabel(type: string, side: AspectSide, key: string, value: string): string {
  const rawKey = `${type}.${side}.${key}`
  const translated = labels.valueLabel(rawKey, value)
  if (translated !== value) return translated
  // Attribute translations are shared across every indexed observation.
  const attributeLabel = labels.valueLabel(key, value)
  if (attributeLabel !== value) return attributeLabel
  const attribute = types.value.find(entry => entry.name === type)?.attributes.find(attribute => attribute.key === key)
  return attribute?.labels?.[value] ?? value
}

// New observations are collected through a three-step form: the element type
// (sidewalk, cycleway …), its position across the way (left, center, right),
// then one select per configured attribute. Picking a type or side advances
// the form on its own; only the attributes wait for an explicit add.
// Step 0 means the form is closed; after adding an observation it reopens at
// the type step so a range of observations can be entered in one go.
const WIZARD_STEPS = 3
const wizardStep = ref(0)
const draftType = ref('')
const draftSide = ref<AspectSide>('center')
const draftValues = ref<Record<string, string>>({})

const hasDraftValues = computed(() => Object.values(draftValues.value).some(value => !!value))
// A drafted row for the chosen type and position: the new values join it
// instead of opening a parallel row for the same element.
const draftRow = computed(() => rows.value.find(row => row.type === draftType.value && row.side === draftSide.value) ?? null)
const draftTypeLabel = computed(() => typeOptions.value.find(option => option.name === draftType.value)?.label ?? draftType.value)
function openWizard(): void {
  if (props.readonly || unavailable.value || !types.value.length) return
  wizardStep.value = 1
  resetDraft()
}
function closeWizard(): void {
  wizardStep.value = 0
}
function resetDraft(): void {
  draftType.value = ''
  draftSide.value = 'center'
  draftValues.value = {}
}
function back(): void {
  if (wizardStep.value > 1) wizardStep.value -= 1
}
function chooseType(type: unknown): void {
  if (typeof type !== 'string') return
  draftType.value = type
  draftValues.value = {}
  wizardStep.value = 2
}
function chooseSide(side: unknown): void {
  if (side !== 'left' && side !== 'center' && side !== 'right') return
  draftSide.value = side
  wizardStep.value = 3
}
// Attributes already approved for the drafted element are moderated content:
// the form does not offer them again, like the drafted rows above.
const draftAttributes = computed(() => {
  const attributes = types.value.find(type => type.name === draftType.value)?.attributes ?? []
  const approved = new Set(draftRow.value?.approvedKeys ?? [])
  return attributes.filter(attribute => !approved.has(attribute.key))
})
function commitDraft(): void {
  if (!hasDraftValues.value) return
  const values = Object.fromEntries(Object.entries(draftValues.value).filter(([, value]) => !!value))
  const row = draftRow.value
  if (row) {
    Object.assign(row.values, values)
  } else {
    rows.value.push({ type: draftType.value, side: draftSide.value, values, approvedKeys: [] })
  }
  resetDraft()
  wizardStep.value = 1
}
</script>
<template>
  <div class="cross-section-composer">
    <p v-if="catalog.failed" role="alert">{{ t('crossSection.loadFailed') }}</p>
    <p v-else-if="!catalog.loading && !types.length" role="status">{{ t('crossSection.noTypes') }}</p>
    <Button v-if="!readonly && (catalog.failed || !types.length)" :label="t('crossSection.reload')" :loading="catalog.loading" text @click="catalog.reload()" />
    <!-- A row that carries an existing observation (relationId) keeps its element
         identity: observing another element or side is always a new row with its
         own relation, never a re-key of an existing observation. -->
    <div v-for="{ row, index } in visibleRows" :key="`${row.type}-${row.side}-${index}`" class="observation">
      <label class="type-label">
        {{ t('crossSection.type') }}
        <Select
          v-model="row.type"
          size="small"
          :options="typeOptions"
          option-label="label"
          option-value="name"
          :disabled="readonly || row.relationId !== undefined || row.approvedKeys.length > 0 || unavailable"
          :aria-label="t('crossSection.type')"
          @change="changeType(row, row.type)"
        />
      </label>
      <label class="side-label">
        {{ t('crossSection.side') }}
        <Select
          v-model="row.side"
          size="small"
          :options="sideOptions"
          option-label="label"
          option-value="side"
          :disabled="readonly || row.relationId !== undefined || row.approvedKeys.length > 0 || unavailable"
          :aria-label="t('crossSection.side')"
        />
      </label>
      <label v-for="attribute in editableAttributes(row)" :key="attribute.key">
        {{ labels.label(attribute.key) }}
        <Select
          v-model="row.values[attribute.key]"
          size="small"
          :options="attribute.values.map(value => ({ value, label: valueLabel(row.type, row.side, attribute.key, value) }))"
          option-label="label"
          option-value="value"
          :disabled="readonly || unavailable"
          :aria-label="labels.label(attribute.key)"
          show-clear
        />
        <small v-if="row.values[attribute.key] && !attribute.values.includes(row.values[attribute.key]!)">{{ valueLabel(row.type, row.side, attribute.key, row.values[attribute.key]!) }}</small>
      </label>
      <template v-for="(value, key) in row.values" :key="key">
        <span v-if="!types.find(type => type.name === row.type)?.attributes.some(attribute => attribute.key === key)
          && !row.approvedKeys.includes(String(key))">{{ labels.label(key) }}: {{ valueLabel(row.type, row.side, key, value) }}</span>
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

    <div v-if="wizardStep > 0" class="wizard" role="group" :aria-label="t('crossSection.add')">
      <div class="wizard-header">
        <Button
          v-if="wizardStep > 1"
          class="wizard-back"
          icon="pi pi-arrow-left"
          :aria-label="t('crossSection.wizard.back')"
          text
          severity="secondary"
          @click="back"
        />
        <span class="wizard-progress">{{ t('crossSection.wizard.stepOf', { current: wizardStep, total: WIZARD_STEPS }) }}</span>
        <Button
          icon="pi pi-times"
          :aria-label="t('crossSection.wizard.close')"
          text
          severity="secondary"
          @click="closeWizard"
        />
      </div>
      <div v-if="wizardStep === 1" class="wizard-step">
        <p class="wizard-question">{{ t('crossSection.wizard.typeQuestion') }}</p>
        <SelectButton
          :model-value="null"
          :options="typeOptions"
          option-label="label"
          option-value="name"
          :allow-empty="false"
          :aria-label="t('crossSection.type')"
          @update:model-value="chooseType"
        />
      </div>
      <div v-else-if="wizardStep === 2" class="wizard-step">
        <p class="wizard-question">{{ t('crossSection.wizard.positionQuestion') }}</p>
        <SelectButton
          :model-value="null"
          :options="sideOptions"
          option-label="label"
          option-value="side"
          :allow-empty="false"
          :aria-label="t('crossSection.side')"
          @update:model-value="chooseSide"
        />
      </div>
      <div v-else class="wizard-step">
        <p class="wizard-summary">
          <span>{{ draftTypeLabel }}</span>
          <span>{{ t(`crossSection.sides.${draftSide}`) }}</span>
        </p>
        <label v-for="attribute in draftAttributes" :key="attribute.key" class="wizard-attribute">
          {{ labels.label(attribute.key) }}
          <Select
            v-model="draftValues[attribute.key]"
            size="small"
            :options="attribute.values.map(value => ({ value, label: valueLabel(draftType, draftSide, attribute.key, value) }))"
            option-label="label"
            option-value="value"
            :aria-label="labels.label(attribute.key)"
            show-clear
          />
        </label>
        <p v-if="draftAttributes.length === 0" class="wizard-hint">{{ t('crossSection.wizard.nothingToObserve') }}</p>
        <Button
          :label="t('crossSection.add')"
          icon="pi pi-check"
          :disabled="!hasDraftValues"
          @click="commitDraft"
        />
      </div>
    </div>

    <Button
      v-if="!readonly && wizardStep === 0"
      :label="t('crossSection.add')"
      icon="pi pi-plus"
      :disabled="!types.length || unavailable"
      text
      @click="openWizard"
    />
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

/* The wizard frames one question at a time: a slim header carrying the step
   counter and navigation, then the step's own choices. */
.wizard {
  padding: 0.5rem;
  border: 1px solid var(--p-content-border-color);
  border-radius: var(--p-content-border-radius);
}

.wizard-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.25rem;
}

.wizard-header .p-button {
  width: 1.5rem;
  height: 1.5rem;
}

.wizard-progress {
  font-size: 0.65rem;
  font-weight: 600;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  color: var(--p-text-muted-color);
}

.wizard-step {
  margin-top: 0.25rem;
}

.wizard-question {
  margin: 0 0 0.375rem;
  font-size: 0.875rem;
}

/* The choice buttons wrap onto multiple lines on narrow panels; every
   option is a large tap target. */
.wizard-step .p-selectbutton {
  display: flex;
  flex-wrap: wrap;
  width: 100%;
}

/* One attribute select per row: the label above its select spans the
   panel width, so the values are compared vertically. */
.wizard-attribute {
  flex-basis: 100%;
  margin-bottom: 0.375rem;
}

.wizard-summary {
  display: flex;
  gap: 0.25rem;
  margin: 0 0 0.375rem;
  font-size: 0.65rem;
  font-weight: 600;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  color: var(--p-text-muted-color);
}

.wizard-summary span + span::before {
  content: '·';
  margin-right: 0.25rem;
  color: var(--p-text-muted-color);
}

.wizard-hint {
  margin: 0 0 0.375rem;
  color: var(--p-text-muted-color);
}
</style>
