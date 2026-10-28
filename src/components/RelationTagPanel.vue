<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import Drawer from 'primevue/drawer'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import { useNodeGroupsStore, type NodeGroup } from '../stores/nodeGroups'
import { useAuthStore } from '../stores/auth'
import { useTagKeyLabelsStore } from '../stores/tagKeyLabels'
import { useDefaultObservationTagsStore } from '../stores/defaultObservationTags'

interface TagRow {
  key: string
  value: string
}

const props = defineProps<{
  visible: boolean
  group: NodeGroup | null
}>()

const emit = defineEmits<{
  (event: 'update:visible', visible: boolean): void
  (event: 'saved', group: NodeGroup): void
}>()

const { t } = useI18n()
const nodeGroups = useNodeGroupsStore()
const auth = useAuthStore()
const tagKeyLabels = useTagKeyLabelsStore()
const defaultObservationTags = useDefaultObservationTagsStore()

const rows = ref<TagRow[]>([])
const observationRows = ref<TagRow[]>([])

// Mirrors the node editor: approved tags are read-only for editors, and the
// observations map decides which entries are the current user's own pending
// ones. The saved response re-splits the sections.
function splitRows(group: NodeGroup): void {
  rows.value = []
  observationRows.value = []
  for (const [key, value] of Object.entries(group.tags)) {
    if (key in group.observations) {
      observationRows.value.push({ key, value: group.observations[key] })
    } else {
      rows.value.push({ key, value })
    }
  }
}

watch(
  () => [props.visible, props.group] as const,
  ([visible, group]) => {
    if (!visible || !group) {
      return
    }
    splitRows(group)
  },
)

function addObservationRow(): void {
  observationRows.value.push({ key: '', value: '' })
}

// The admin-exposed default tags not yet on this relation (neither as an
// approved tag nor as an observation), offered as a searchable dropdown.
// The dropdown shows the readable label; the raw key is the value that is
// added as an observation row. Searching matches both.
const suggestedObservationOptions = computed(() => {
  if (!auth.canEdit) {
    return []
  }
  const used = new Set([...rows.value, ...observationRows.value].map((row) => row.key.trim()))
  return defaultObservationTags.tags
    .filter((entry) => !used.has(entry.key))
    .map((entry) => ({ key: entry.key, label: tagKeyLabels.label(entry.key) }))
})

// The select stays uncontrolled (no value); picking an option immediately
// adds the observation row and returns the dropdown to its placeholder.
function addSuggestedObservation(key: unknown): void {
  if (typeof key === 'string') {
    observationRows.value.push({ key, value: '' })
  }
}

// Rows for admin-exposed default tags show the translated label as a
// read-only field instead of the raw key input, and their value comes from
// the admin-configured choices. Derived from the key (not a row flag) so it
// also holds after the rows are re-split from the saved response.
function isDefaultObservationTag(key: string): boolean {
  return defaultObservationTags.keys.includes(key.trim())
}

function defaultValuesFor(key: string): string[] {
  return defaultObservationTags.valuesFor(key.trim())
}

function removeObservationRow(index: number): void {
  observationRows.value.splice(index, 1)
}

function close(): void {
  emit('update:visible', false)
}

async function save(): Promise<void> {
  if (!props.group) {
    return
  }
  const tags: Record<string, string> = {}
  for (const row of [...rows.value, ...observationRows.value]) {
    const key = row.key.trim()
    if (key !== '') {
      tags[key] = row.value
    }
  }
  const saved = await nodeGroups.save({ id: props.group.id, tags })
  if (saved) {
    splitRows(saved)
    emit('saved', saved)
  }
}
</script>

<template>
  <Drawer
    :visible="visible"
    position="right"
    :modal="false"
    :dismissable="false"
    :header="group?.name || t('relationEditor.title')"
    :style="{ width: '24rem', maxWidth: '100vw' }"
    @update:visible="emit('update:visible', $event)"
  >
    <section v-if="group" class="members-section" :aria-label="t('relationEditor.members')">
      <h4 class="section-title">{{ t('relationEditor.members') }}</h4>
      <p class="empty-hint">{{ t('relationEditor.memberCount', { count: group.members.length }) }}</p>
      <ul class="member-list">
        <li v-for="member in group.members" :key="member.id" class="member-item">
          <span>{{ member.name || member.providerNodeId }}</span>
          <small>{{ member.provider }}<template v-if="member.name"> · {{ member.providerNodeId }}</template></small>
        </li>
      </ul>
    </section>

    <section class="custom-tags-section">
      <p v-if="!group || (rows.length === 0 && observationRows.length === 0)" class="empty-hint">
        {{ t('relationEditor.empty') }}
      </p>
      <p v-if="group && !auth.canEdit" class="empty-hint">{{ t('nodeEditor.loginRequired') }}</p>
      <!-- Approved tags are managed by admins, so they are read-only here. -->
      <div v-if="rows.length > 0" class="tag-table-scroll">
        <table class="tag-table">
          <tr v-for="row in rows" :key="row.key">
            <th>{{ tagKeyLabels.label(row.key) }}</th>
            <td>{{ row.value }}</td>
          </tr>
        </table>
      </div>
      <p v-if="nodeGroups.saveFailed" class="save-error" role="alert">
        {{ t('nodeEditor.saveFailed') }}
      </p>
    </section>

    <section v-if="group && (auth.canEdit || observationRows.length > 0)" class="observations-section">
      <h4 class="section-title">{{ t('nodeEditor.observations') }}</h4>
      <template v-if="auth.canEdit">
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
        <div v-for="(row, index) in observationRows" :key="index" class="tag-row">
          <!-- Keys picked from the suggested-tag dropdown are fixed: the
               translated label shows read-only (raw key in the tooltip),
               the free-form rows keep the editable key input. -->
          <InputText
            v-if="isDefaultObservationTag(row.key)"
            :model-value="tagKeyLabels.label(row.key)"
            :title="row.key"
            readonly
            class="tag-key"
          />
          <InputText v-else v-model="row.key" :placeholder="t('nodeEditor.key')" class="tag-key" />
          <!-- Keys with admin-configured values pick from a dropdown, but a
               user may also propose an own value by typing it (editable
               input); clearing it empties the value again. -->
          <Select
            v-if="isDefaultObservationTag(row.key) && defaultValuesFor(row.key).length > 0"
            :model-value="row.value === '' ? null : row.value"
            :options="defaultValuesFor(row.key)"
            :placeholder="t('nodeEditor.value')"
            :editable="true"
            show-clear
            class="tag-value"
            @update:model-value="row.value = $event ?? ''"
          />
          <InputText v-else v-model="row.value" :placeholder="t('nodeEditor.value')" class="tag-value" />
          <Button
            :aria-label="t('nodeEditor.removeTag')"
            icon="pi pi-times"
            text
            severity="danger"
            @click="removeObservationRow(index)"
          />
        </div>
        <Button
          :label="t('nodeEditor.addObservation')"
          icon="pi pi-plus"
          text
          severity="secondary"
          @click="addObservationRow"
        />
      </template>
      <template v-else>
        <div class="tag-table-scroll">
          <table class="tag-table">
            <tr v-for="row in observationRows" :key="row.key">
              <th>{{ tagKeyLabels.label(row.key) }}</th>
              <td>{{ row.value }}</td>
            </tr>
          </table>
        </div>
      </template>
    </section>

    <template #footer>
      <Button :label="t('nodeEditor.cancel')" text severity="secondary" @click="close" />
      <Button
        v-if="auth.canEdit"
        :label="t('nodeEditor.save')"
        :loading="nodeGroups.saving"
        icon="pi pi-check"
        @click="save"
      />
    </template>
  </Drawer>
</template>

<style scoped>
.section-title {
  margin: 0 0 0.375rem;
  font-size: 0.875rem;
  font-weight: 600;
  color: var(--p-text-muted-color);
}

.members-section,
.observations-section,
.custom-tags-section {
  margin-bottom: 0.75rem;
}

.custom-tags-section {
  margin-top: 0.5rem;
}

.member-list {
  list-style: none;
  padding: 0;
  margin: 0.25rem 0 0;
}

.member-item {
  display: flex;
  flex-direction: column;
  gap: 0.125rem;
  padding: 0.375rem 0;
  border-bottom: 1px solid var(--p-content-border-color);
  font-size: 0.875rem;
  overflow-wrap: anywhere;
}

.member-item small {
  color: var(--p-text-muted-color);
}

.tag-table-scroll {
  max-height: 12rem;
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
  font-size: 0.8rem;
  vertical-align: top;
  border-bottom: 1px solid var(--p-content-border-color);
  overflow-wrap: anywhere;
}

.tag-table th {
  width: 40%;
  font-weight: 600;
  color: var(--p-text-muted-color);
}

.empty-hint {
  margin: 0 0 0.375rem;
  font-size: 0.875rem;
  color: var(--p-text-muted-color);
}

.tag-row {
  display: flex;
  gap: 0.375rem;
  align-items: center;
  margin-bottom: 0.375rem;
}

/* The searchable suggestion dropdown fills the panel width, like the tag
   rows below it. */
.suggestion-select {
  display: flex;
  width: 100%;
  margin-bottom: 0.5rem;
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

.save-error {
  margin: 0.5rem 0 0;
  font-size: 0.875rem;
  color: var(--p-red-500);
}
</style>
