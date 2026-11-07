<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useEditingCatalogStore } from '../stores/editingCatalog'
import type { NodeCreationState, NodeCreationInput } from '../map/nodeCreation'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import Drawer from 'primevue/drawer'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import { useOsmDataStore } from '../stores/osmData'
import ParentNodeTags from './ParentNodeTags.vue'
import { nodeKey, useUserNodesStore, type NodeEditTarget, type TagEntry } from '../stores/userNodes'
import { useNodeGroupsStore, type NodeGroup } from '../stores/nodeGroups'
import { useAuthStore } from '../stores/auth'
import { useTagKeyLabelsStore } from '../stores/tagKeyLabels'
import { useDefaultObservationTagsStore } from '../stores/defaultObservationTags'
import { useSettingsStore } from '../stores/settings'

interface TagRow {
  key: string
  value: string
  generatedRaw?: Partial<Record<'key' | 'value', string>>
  translations?: Record<string, { text: string; previous: string }>
}

const props = defineProps<{
  visible: boolean
  node: NodeEditTarget | null
  creation?: NodeCreationState | null
}>()

const emit = defineEmits<{
  (event: 'update:visible', visible: boolean): void
  (event: 'edit-relation', group: NodeGroup): void
  (event: 'create', input: NodeCreationInput): void
}>()

const { t } = useI18n()
const userNodes = useUserNodesStore()
const nodeGroups = useNodeGroupsStore()
const auth = useAuthStore()
const settings = useSettingsStore()
const tagKeyLabels = useTagKeyLabelsStore()
const defaultObservationTags = useDefaultObservationTagsStore()

const catalog = useEditingCatalogStore()
const osmData = useOsmDataStore()
const isWay = computed(() => {
  if (props.node?.provider !== 'OSM' || !/^w\d+$/.test(props.node.providerNodeId)) return false
  const feature = osmData.data?.features.find(entry => String(entry.feature.id) === props.node!.providerNodeId)?.feature
  return !feature || !!feature.properties?.highway || ['LineString', 'MultiLineString'].includes(feature.geometry.type)
})
const observationChoices = computed(() => isWay.value
  ? (catalog.wayVocabularies[props.node?.osmTags.highway ?? ''] ?? [])
  : defaultObservationTags.tags)
watch(() => props.visible, visible => { if (visible) void catalog.load() }, { immediate: true })
const pendingName = ref('')
const rows = ref<TagRow[]>([])
const observationRows = ref<TagRow[]>([])
const hasIncompleteTags = computed(() => [...rows.value, ...observationRows.value]
  .some(row => !row.key.trim() || !row.value.trim()))

const osmTagEntries = computed(() => Object.entries(props.node?.osmTags ?? {}))
const savedNode = computed(() => props.node ? userNodes.byIdentity.get(nodeKey(props.node)) : undefined)
const relationGroups = computed(() => [
  { label: 'nodeEditor.parents', nodes: savedNode.value?.parents ?? [] },
  { label: 'nodeEditor.children', nodes: savedNode.value?.children ?? [] },
])

// Relations (e.g. tagged parts of a road) that contain the selected node; the
// node or OSM feature is looked up by its provider identity.
const nodeRelations = computed(() =>
  props.node ? nodeGroups.byMember.get(nodeKey(props.node)) ?? [] : [])

function relationLabel(group: NodeGroup): string {
  const count = t('relationEditor.memberCount', { count: group.members.length })
  return group.name ? `${group.name} · ${count}` : count
}

// The observations pairs hold exactly the current user's unapproved tags
// (the backend hides other users' pending tags), so membership of the pair —
// not a key comparison against the merged tags list, which can carry several
// values of one key — decides the section. The observation value comes from
// the observations pair because the merged tags list may carry an approved
// value for the same key.
function splitRows(node: NonNullable<typeof savedNode.value>): void {
  rows.value = []
  observationRows.value = []
  const observationKeys = new Set(node.observations.map((observation) => `${observation.key}\0${observation.value}`))
  for (const { key, value } of node.tags) {
    if (observationKeys.has(`${key}\0${value}`)) {
      const row: TagRow = { key, value, translations: {} }
      const proposal = node.observationTranslations?.find(
        (translation) => translation.key === key && translation.value === value,
      )
      for (const [locale, translations] of Object.entries(proposal?.translations ?? {})) {
        for (const kind of ['key', 'value'] as const) {
          const text = translations[`${kind}Translation`]
          if (text !== undefined) {
            const id = JSON.stringify([locale, kind, key, ...(kind === 'value' ? [row.value] : [])])
            row.translations![id] = { text, previous: text }
          }
        }
      }
      observationRows.value.push(row)
    } else {
      rows.value.push({ key, value })
    }
  }
}

// Placement counts and save progress must not reset the editor draft.
watch(
  [() => props.visible, () => props.node, () => !!props.creation],
  ([visible, node, creating]) => {
    if (visible && creating) {
      pendingName.value = ''
      rows.value = []
      observationRows.value = []
      return
    }
    if (!visible || !node) {
      return
    }
    const existing = userNodes.byIdentity.get(nodeKey(node))
    if (existing) {
      splitRows(existing)
    } else {
      rows.value = []
      observationRows.value = []
    }
  },
)

function addObservationRow(): void {
  observationRows.value.push({ key: '', value: '' })
}

// The admin-exposed default tags not yet on this node (neither as an
// approved tag nor as an observation), offered as a searchable dropdown.
// The dropdown shows the readable label; the raw key is the value that is
// added as an observation row. Searching matches both.
const suggestedObservationOptions = computed(() => {
  if (!auth.canEdit) {
    return []
  }
  const used = new Set([...rows.value, ...observationRows.value].map((row) => row.key.trim()))
  return observationChoices.value
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

// Suggested keys keep their raw key fixed and offer configured value choices.
// Derive this from the key so it also holds after reloading saved rows.
function isDefaultObservationTag(key: string): boolean {
  return isWay.value || defaultObservationTags.keys.includes(key.trim())
}

function defaultValuesFor(key: string): string[] {
  return isWay.value ? observationChoices.value.find(entry => entry.key === key.trim())?.values ?? [] : defaultObservationTags.valuesFor(key.trim())
}

// Keep language drafts separate and use raw identifiers for catalog lookups.
function translationId(row: TagRow, kind: 'key' | 'value'): string {
  return JSON.stringify([settings.locale, kind, row.key.trim(), ...(kind === 'value' ? [row.value] : [])])
}

function catalogTranslation(row: TagRow, kind: 'key' | 'value'): string {
  return kind === 'key'
    ? tagKeyLabels.labels[row.key.trim()] ?? ''
    : tagKeyLabels.values[JSON.stringify([row.key.trim(), row.value])] ?? ''
}

function translationText(row: TagRow, kind: 'key' | 'value'): string {
  return row.translations?.[translationId(row, kind)]?.text ?? catalogTranslation(row, kind)
}

function editTranslation(row: TagRow, kind: 'key' | 'value', text: string | undefined): void {
  const translation = text ?? ''
  const fixedVocabulary = isWay.value && (kind === 'key' || defaultValuesFor(row.key).length > 0)
  if (!fixedVocabulary && (!row[kind].trim() || row.generatedRaw?.[kind] === row[kind])) {
    const generated = translation.normalize('NFKD').replace(/\p{M}/gu, '')
      .toLowerCase().replace(/ß/g, 'ss').replace(/[^\p{L}\p{N}]+/gu, '_').replace(/^_+|_+$/g, '')
    editRawTag(row, kind, generated, true)
    row.generatedRaw ??= {}
    row.generatedRaw[kind] = generated
  }
  const id = translationId(row, kind)
  row.translations ??= {}
  row.translations[id] = { text: translation, previous: row.translations[id]?.previous ?? catalogTranslation(row, kind) }
}

function editRawTag(row: TagRow, kind: 'key' | 'value', text: string | null | undefined, generated = false): void {
  if (!generated && row.generatedRaw) delete row.generatedRaw[kind]
  const keyDraft = row.translations?.[translationId(row, 'key')]
  const valueDraft = row.translations?.[translationId(row, 'value')]
  row[kind] = text ?? ''
  // Keep text the user entered even when they fill the smaller raw field last.
  // Conflict checking now compares against the newly selected identifiers.
  if (kind === 'key' && keyDraft) {
    row.translations![translationId(row, 'key')] = { text: keyDraft.text, previous: catalogTranslation(row, 'key') }
  }
  if (valueDraft) {
    row.translations![translationId(row, 'value')] = { text: valueDraft.text, previous: catalogTranslation(row, 'value') }
  }
}

function removeObservationRow(index: number): void {
  observationRows.value.splice(index, 1)
}

function close(): void {
  if (props.creation?.saving) return
  emit('update:visible', false)
}

async function save(): Promise<void> {
  if ((!props.node && !props.creation) || hasIncompleteTags.value) {
    return
  }
  const tags: TagEntry[] = []
  for (const row of [...rows.value, ...observationRows.value]) {
    const key = row.key.trim()
    if (key !== '') {
      tags.push({ key, value: row.value })
    }
  }
  // Saving keeps the panel open: it stays as long as the element is
  // selected, and only closing it deselects. New and edited tags are
  // unapproved, so re-split the sections from the saved response.
  const draft = {
    tags,
    translationLocale: settings.locale,
    translations: observationRows.value.flatMap((row) => {
      const key = row.key.trim()
      if (!key) return []
      const keyDraft = row.translations?.[translationId(row, 'key')]
      const valueDraft = row.translations?.[translationId(row, 'value')]
      return keyDraft || valueDraft ? [{
        key,
        value: row.value,
        keyTranslation: keyDraft?.text,
        valueTranslation: valueDraft?.text,
        previousKeyTranslation: keyDraft?.previous,
        previousValueTranslation: valueDraft?.previous,
      }] : []
    }),
  }
  if (props.creation) {
    emit('create', { ...draft, name: pendingName.value })
    return
  }
  const saved = await userNodes.save({
    ...draft,
    provider: props.node!.provider,
    providerNodeId: props.node!.providerNodeId,
    latitude: props.node!.latitude,
    longitude: props.node!.longitude,
  })
  if (saved) {
    splitRows(saved)
  }
}
</script>

<template>
  <Drawer
    :visible="visible"
    position="bottom"
    class="map-editor"
    :closable="!creation?.saving"
    :close-on-escape="!creation?.saving"
    :modal="false"
    :dismissable="false"
    :header="t(creation ? 'map.create.add' : 'nodeEditor.title')"
    @update:visible="emit('update:visible', $event)"
  >
    <div v-if="creation" role="form" :aria-label="t('map.create.pendingDialog')" class="creation-details">
      <p role="status">{{ t('map.create.placed', { count: creation.count }) }}</p>
      <p v-if="creation.count === 0" class="empty-hint">{{ t('map.create.placeHint') }}</p>
      <label for="pending-node-name">{{ t('map.create.name') }}</label>
      <InputText id="pending-node-name" v-model="pendingName" :disabled="creation.saving" :placeholder="t('map.create.namePlaceholder')" />
      <p class="empty-hint">{{ t('nodeEditor.parents') }}: {{ creation.parentNames.join(', ') || t('map.create.noParent') }}</p>
      <p v-if="creation.failed" class="save-error" role="alert">{{ t('map.create.failed') }}</p>
    </div>
    <fieldset :disabled="creation?.saving" class="editor-fields">
      <section v-if="savedNode && !creation" class="relations-section" :aria-label="t('nodeEditor.relations')">
        <h4 class="section-title">{{ t('nodeEditor.relations') }}</h4>
        <p v-if="!savedNode.parents.length && !savedNode.children.length" class="empty-hint">
          {{ t('nodeEditor.noRelations') }}
        </p>
        <template v-for="group in relationGroups" :key="group.label">
          <div v-if="group.nodes.length" class="relation-group">
            <h5 class="relation-heading">{{ t(group.label) }}</h5>
            <ul class="relation-list">
              <li v-for="related in group.nodes" :key="related.id" class="relation-item">
                <span>{{ related.name || related.providerNodeId }}</span>
                <small>{{ related.provider }}<template v-if="related.name"> · {{ related.providerNodeId }}</template></small>
                <ParentNodeTags v-if="group.label === 'nodeEditor.parents'" :parent="related" />
              </li>
            </ul>
          </div>
        </template>
      </section>

      <section v-if="!creation" class="relations-section node-relations-section" :aria-label="t('nodeEditor.nodeRelations')">
        <h4 class="section-title">{{ t('nodeEditor.nodeRelations') }}</h4>
        <p v-if="nodeRelations.length === 0" class="empty-hint">
          {{ t('nodeEditor.noNodeRelations') }}
        </p>
        <div v-for="group in nodeRelations" :key="group.id" class="node-relation">
          <div class="node-relation-heading">
            <span>{{ relationLabel(group) }}</span>
            <Button
              v-if="auth.canEdit"
              :label="t('nodeEditor.editRelation')"
              icon="pi pi-pencil"
              text
              severity="secondary"
              @click="emit('edit-relation', group)"
            />
          </div>
          <div v-if="group.tags.length > 0" class="tag-table-scroll">
            <table class="tag-table">
              <tr v-for="(tag, index) in group.tags" :key="index">
                <th>{{ tagKeyLabels.label(tag.key) }}</th>
                <td>{{ tagKeyLabels.valueLabel(tag.key, tag.value) }}</td>
              </tr>
            </table>
          </div>
        </div>
      </section>

      <section v-if="osmTagEntries.length > 0" class="osm-tags-section">
        <h4 class="section-title">{{ t('nodeEditor.osmTags') }}</h4>
        <div class="tag-table-scroll">
          <table class="tag-table">
            <tr v-for="[key, value] in osmTagEntries" :key="key">
              <th>{{ tagKeyLabels.label(key) }}</th>
              <td>{{ tagKeyLabels.valueLabel(key, value) }}</td>
            </tr>
          </table>
        </div>
      </section>

      <section class="custom-tags-section">
        <p v-if="rows.length === 0 && observationRows.length === 0 && auth.canEdit" class="empty-hint">
          {{ t('nodeEditor.empty') }}
        </p>
        <p v-if="!auth.canEdit" class="empty-hint">{{ t('nodeEditor.loginRequired') }}</p>
        <!-- Approved tags are managed by admins, so they are read-only here. -->
        <div v-if="rows.length > 0" class="tag-table-scroll">
          <table class="tag-table">
            <tr v-for="(row, index) in rows" :key="index">
              <th>{{ tagKeyLabels.label(row.key) }}</th>
              <td>{{ tagKeyLabels.valueLabel(row.key, row.value) }}</td>
            </tr>
          </table>
        </div>
        <p v-if="!creation && userNodes.saveFailed" class="save-error" role="alert">
          {{ t(userNodes.saveConflict ? 'nodeEditor.translationConflict' : 'nodeEditor.saveFailed') }}
        </p>
      </section>

      <section v-if="auth.canEdit || observationRows.length > 0" class="observations-section">
        <h4 class="section-title">{{ t('nodeEditor.observations') }}</h4>
        <template v-if="auth.canEdit">
          <p v-if="isWay && catalog.failed" role="alert">{{ t('crossSection.loadFailed') }}
            <Button :label="t('crossSection.retry')" text @click="catalog.load()" />
          </p>
          <p v-else-if="isWay && observationChoices.length === 0" class="empty-hint">{{ t('crossSection.noObservations') }}</p>
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
            <div class="observation-field">
              <label :for="`observation-key-translation-${index}`">{{ t('nodeEditor.keyTranslation') }}</label>
              <InputText
                :id="`observation-key-translation-${index}`"
                :model-value="translationText(row, 'key')"
                :placeholder="t('nodeEditor.keyTranslation')"
                @update:model-value="editTranslation(row, 'key', $event)"
              />
              <InputText
                :model-value="row.key"
                @update:model-value="editRawTag(row, 'key', $event)"
                :readonly="isWay || (isDefaultObservationTag(row.key) && row.generatedRaw?.key === undefined)"
                :invalid="!row.key.trim()"
                :placeholder="t('nodeEditor.key')"
                :aria-label="t('nodeEditor.key')"
                class="raw-tag-input"
              />
            </div>
            <div class="observation-field">
              <label :for="`observation-value-translation-${index}`">{{ t('nodeEditor.valueTranslation') }}</label>
              <InputText
                :id="`observation-value-translation-${index}`"
                :model-value="translationText(row, 'value')"
                :placeholder="t('nodeEditor.valueTranslation')"
                @update:model-value="editTranslation(row, 'value', $event)"
              />
              <Select
                v-if="isDefaultObservationTag(row.key) && defaultValuesFor(row.key).length > 0"
                :model-value="row.value === '' ? null : row.value"
                :invalid="!row.value.trim()"
                :options="defaultValuesFor(row.key).map(value => ({ value, label: tagKeyLabels.valueLabel(row.key, value) }))"
                option-label="label"
                option-value="value"
                :placeholder="t('nodeEditor.value')"
                :aria-label="t('nodeEditor.value')"
                :editable="!isWay"
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
              @click="removeObservationRow(index)"
            />
          </div>
          <Button
            v-if="!isWay"
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
              <tr v-for="(row, index) in observationRows" :key="index">
                <th>{{ tagKeyLabels.label(row.key) }}</th>
                <td>{{ tagKeyLabels.valueLabel(row.key, row.value) }}</td>
              </tr>
            </table>
          </div>
        </template>
      </section>

    </fieldset>

    <template #footer>
      <p v-if="auth.canEdit && hasIncompleteTags" class="save-error" role="alert">{{ t('nodeEditor.requiredTagFields') }}</p>
      <Button :label="t(creation ? 'map.create.abort' : 'nodeEditor.cancel')" :disabled="creation?.saving" text severity="secondary" @click="close" />
      <Button
        v-if="auth.canEdit"
        :label="t(creation?.failed ? 'map.create.retry' : 'nodeEditor.save')"
        :loading="creation ? creation.saving : userNodes.saving"
        :disabled="hasIncompleteTags || (creation !== null && creation !== undefined && creation.count === 0)"
        icon="pi pi-check"
        @click="save"
      />
    </template>
  </Drawer>
</template>

<style scoped>
.editor-fields {
  border: 0;
  padding: 0;
  margin: 0;
  min-width: 0;
}

.creation-details {
  display: grid;
  gap: 0.5rem;
  margin-bottom: 1rem;
}

.creation-details p {
  margin: 0;
}

.creation-details label {
  font-size: 0.875rem;
  color: var(--p-text-muted-color);
}

.section-title {
  margin: 0 0 0.375rem;
  font-size: 0.875rem;
  font-weight: 600;
  color: var(--p-text-muted-color);
}

.osm-tags-section,
.relations-section,
.observations-section,
.custom-tags-section {
  margin-bottom: 0.75rem;
}

/* A small gap sets the read-only custom tag table apart from the OSM tags above. */
.custom-tags-section {
  margin-top: 0.5rem;
}

.relation-heading {
  margin: 0.5rem 0 0.25rem;
  font-size: 0.8rem;
  font-weight: 600;
}

.relation-list {
  list-style: none;
  padding: 0;
  margin: 0;
}

.relation-item {
  display: flex;
  flex-direction: column;
  gap: 0.125rem;
  padding: 0.375rem 0;
  border-bottom: 1px solid var(--p-content-border-color);
  font-size: 0.875rem;
  overflow-wrap: anywhere;
}

.relation-item small {
  color: var(--p-text-muted-color);
}

.node-relations-section .node-relation {
  padding: 0.375rem 0;
  border-bottom: 1px solid var(--p-content-border-color);
  font-size: 0.875rem;
}

.node-relation-heading {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 0.375rem;
  color: var(--p-text-muted-color);
}

/* Fixed layout keeps the key/value columns aligned across all tag tables. */
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

.observation-field {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  flex: 1;
  min-width: 0;
}

.observation-field label {
  font-size: 0.75rem;
  color: var(--p-text-muted-color);
}

.observation-field > * {
  width: 100%;
  min-width: 0;
}

.observation-field .raw-tag-input {
  font-size: 0.7rem;
  padding: 0.25rem 0.375rem;
  color: var(--p-text-muted-color);
}

.save-error {
  margin: 0.5rem 0 0;
  font-size: 0.875rem;
  color: var(--p-red-500);
}
</style>

<style>
/* The panel reflects the selection state instead of appearing transiently,
   so it shows and hides instantly rather than sliding. The classes are
   global because PrimeVue renders the drawer in a portal. */
.p-drawer-enter-active,
.p-drawer-leave-active {
  animation: none !important;
}
</style>
