<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useEditingCatalogStore } from '../stores/editingCatalog'
import type { NodeCreationState, NodeCreationInput } from '../map/nodeCreation'
import type { AspectRow } from '../lib/crossSection'
import { isAspectRowEditable } from '../lib/crossSection'
import { buildTranslationProposals, splitNodeDraft, splitNodeObservationRelations, splitRelationRangeDraft, type TagRow } from '../lib/tagDrafts'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import Drawer from 'primevue/drawer'
import InputText from 'primevue/inputtext'
import { useOsmDataStore } from '../stores/osmData'
import DrawerResizeHandle from './DrawerResizeHandle.vue'
import CrossSectionComposer from './CrossSectionComposer.vue'
import ObservationEditor from './ObservationEditor.vue'
import TagTable from './TagTable.vue'
import { nodeKey, tagValue, useUserNodesStore, type NodeEditTarget, type RelatedNode } from '../stores/userNodes'
import { useNodeGroupsStore, type GroupMemberReference, type NodeGroup } from '../stores/nodeGroups'
import { useAuthStore } from '../stores/auth'
import { useSettingsStore } from '../stores/settings'

const props = defineProps<{
  visible: boolean
  node: NodeEditTarget | null
  relation?: NodeGroup | null
  creation?: NodeCreationState | null
}>()

const emit = defineEmits<{
  (event: 'update:visible', visible: boolean): void
  (event: 'edit-relation', group: NodeGroup): void
  (event: 'create', input: NodeCreationInput): void
  (event: 'saved-relation', group: NodeGroup): void
}>()

const { t } = useI18n()
const userNodes = useUserNodesStore()
const nodeGroups = useNodeGroupsStore()
const auth = useAuthStore()
const settings = useSettingsStore()

const catalog = useEditingCatalogStore()
const osmData = useOsmDataStore()

// One panel covers creating nodes, editing a node, and editing a node
// relation; the view keeps the targets mutually exclusive.
const isRelation = computed(() => !props.creation && !!props.relation && !props.node)
watch(() => props.visible, visible => { if (visible) void catalog.load() }, { immediate: true })

const pendingName = ref('')
const rows = ref<TagRow[]>([])
const observationRows = ref<TagRow[]>([])
// Cross-section draft; while creating it belongs to the relations the
// placed nodes form, and while editing it mirrors the relation's aspect tags.
const aspectRows = ref<AspectRow[]>([])
// The pending observation relations loaded into the draft: any of them not
// saved on the next save was removed, so its relation gets deleted.
const pendingRelationIds = ref<number[]>([])
const isRelationDraft = computed(() => (props.creation?.count ?? 0) > 1)
const hasIncompleteTags = computed(() => [...rows.value, ...observationRows.value]
  .some(row => !row.key.trim() || !row.value.trim())
  || ((isRelationDraft.value || isRelation.value) && aspectRows.value.some(row => !Object.values(row.values).some(value => value))))
// The required-fields message only appears once a save was attempted;
// a freshly added observation row is not an error yet.
const saveAttempted = ref(false)

const osmTagEntries = computed(() => Object.entries(props.node?.osmTags ?? {}).map(([key, value]) => ({ key, value })))
const savedNode = computed(() => props.node ? userNodes.byIdentity.get(nodeKey(props.node)) : undefined)

// Prefer refreshed node details after a parent is edited in this session.
const relationParents = computed(() => {
  const parents = props.relation?.parents
    ?? props.relation?.members.flatMap(member => userNodes.byIdentity.get(nodeKey(member))?.parents ?? [])
    ?? []
  return [...new Map(parents.map(parent => [nodeKey(parent), parent])).values()]
})

const relationParentName = (parent: RelatedNode): string | undefined => {
  const saved = userNodes.byIdentity.get(nodeKey(parent))
  return tagValue(saved?.tags, 'name')?.trim() || parent.name?.trim()
    || (parent.provider === 'OSM' ? osmData.tagsById?.get(parent.providerNodeId)?.name?.trim() : undefined)
}

const relationParentNames = computed(() => [...new Set(relationParents.value.map(relationParentName))])

// Selecting a relation also shows the parent node's tags (e.g. the street
// the street part covers): every parent shows its own tags, an OSM parent
// additionally its original OSM tags.
const relationParentTagGroups = computed(() => relationParents.value.map(parent => ({
  key: nodeKey(parent),
  name: relationParentName(parent) ?? parent.providerNodeId,
  osmTagEntries: Object.entries(parent.provider === 'OSM' ? osmData.tagsById?.get(parent.providerNodeId) ?? {} : {}).map(([key, value]) => ({ key, value })),
  customTags: userNodes.byIdentity.get(nodeKey(parent))?.tags ?? parent.tags,
})).filter(group => group.osmTagEntries.length > 0 || group.customTags.length > 0))

// One breadcrumb line names the edited element in its hierarchy context, also
// while the name is still typed.
const parentLabel = computed(() => {
  if (props.creation) {
    return props.creation.parentNames.filter(Boolean).join(', ')
  }
  if (isRelation.value) {
    return relationParentNames.value.join(', ')
  }
  const parent = savedNode.value?.parents[0]
  if (!parent) {
    return ''
  }
  return parent.name?.trim()
    || (parent.provider === 'OSM' ? osmData.tagsById?.get(parent.providerNodeId)?.name?.trim() : undefined)
})
const nodeLabel = computed(() => {
  if (props.creation) {
    return pendingName.value
  }
  if (isRelation.value) {
    return props.relation?.name?.trim() ?? ''
  }
  return (tagValue(savedNode.value?.tags, 'name')?.trim()
    || (props.node?.provider === 'OSM' ? osmData.tagsById?.get(props.node.providerNodeId)?.name?.trim() : undefined)
    || '')
})
const headerLabel = computed(() => {
  const parts = [parentLabel.value, nodeLabel.value].map(part => (part ?? '').trim()).filter(Boolean)
  if (parts.length === 0) {
    return t(props.creation ? 'map.create.add' : isRelation.value ? 'relationEditor.title' : 'nodeEditor.title')
  }
  return parts.join(' > ')
})

// Relations (e.g. observation relations) that contain the selected node; the
// node or OSM feature is looked up by its provider identity.
const nodeRelations = computed(() =>
  props.node ? nodeGroups.byMember.get(nodeKey(props.node)) ?? [] : [])

// Only members with a name are listed; unnamed ones have no display text.
const namedMembers = computed(() => (props.relation?.members ?? []).filter(member => !!member.name?.trim()))

// The composer hides approved aspects (they show as tag info); without any
// editable content left, read-only visitors do not see the section at all.
const hasEditableAspects = computed(() =>
  aspectRows.value.some(row => isAspectRowEditable(row, catalog.aspectTypes)))

// The pending single-tag observation relations anchored on the selected node
// (or feature): editing their value saves their relation (see
// specs/map-editing/observation-relations/spec.md R4).
const nodeObservationRelations = computed(() => nodeRelations.value
  .filter(group => group.members.length === 1 && group.observations.length > 0))

// The member reference anchoring new observations for the current selection.
const anchorMembers = computed((): GroupMemberReference[] => {
  if (isRelation.value) {
    return props.relation!.members.map(member => ({ id: member.id }))
  }
  if (savedNode.value) {
    return [{ id: savedNode.value.id }]
  }
  return props.node
    ? [{ providerNodeId: props.node.providerNodeId, latitude: props.node.latitude, longitude: props.node.longitude }]
    : []
})

// Placement counts and save progress must not reset the editor draft.
watch(
  [() => props.visible, () => props.node, () => props.relation, () => !!props.creation],
  ([visible, node, relation, creating]) => {
    saveAttempted.value = false
    if (visible && creating) {
      pendingName.value = ''
      rows.value = []
      observationRows.value = []
      aspectRows.value = []
      pendingRelationIds.value = []
      return
    }
    if (!visible) {
      return
    }
    if (relation && !node) {
      loadRelationDraft(relation)
      return
    }
    if (!node) {
      return
    }
    loadNodeDraft(node)
  },
)

// Every observation relation that shares the selected relation's ordered
// member sequence: the panel edits the range, not one relation, so parallel
// observations on the same range stay visible and editable side by side
// (spec specs/map-editing/observation-relations/spec.md R4).
function relationsOnRange(relation: NodeGroup): NodeGroup[] {
  const fresh = [...nodeGroups.groups].find(group => group.id === relation.id) ?? relation
  if (fresh.members.length === 0) {
    return [fresh]
  }
  const sameMembers = (group: NodeGroup) =>
    group.members.length === fresh.members.length
    && group.members.every((member, index) => member.id === fresh.members[index].id)
  return [...nodeGroups.groups].filter(sameMembers)
}

function loadRelationDraft(relation: NodeGroup): void {
  const onRange = relationsOnRange(relation)
  const draft = splitRelationRangeDraft(onRange)
  rows.value = draft.rows.map(row => ({ ...row }))
  observationRows.value = draft.observationRows.map(row => ({ ...row }))
  aspectRows.value = draft.aspectRows.map(row => ({ ...row }))
  pendingRelationIds.value = onRange.filter(group => group.observations.length > 0).map(group => group.id)
  if (relation.parents?.some(parent => parent.provider === 'OSM')
    || relation.members.some(member => userNodes.byIdentity.get(nodeKey(member))?.parents.some(parent => parent.provider === 'OSM'))) {
    void osmData.load()
  }
}

function loadNodeDraft(node: NodeEditTarget): void {
  const existing = userNodes.byIdentity.get(nodeKey(node))
  const tagDraft = existing ? splitNodeDraft(existing) : { rows: [] as TagRow[], observationRows: [] as TagRow[] }
  rows.value = tagDraft.rows.map(row => ({ ...row }))
  const observed = splitNodeObservationRelations(nodeObservationRelations.value)
  // Pending node metadata (names) stays on the node and still edits in the
  // observations section; everything else is an observation relation.
  observationRows.value = [
    ...tagDraft.observationRows.map(row => ({ ...row, persistedOnNode: true })),
    ...observed.observationRows.map(row => ({ ...row })),
  ]
  aspectRows.value = observed.aspectRows.map(row => ({ ...row }))
  pendingRelationIds.value = nodeObservationRelations.value.map(group => group.id)
}

function close(): void {
  if (props.creation?.saving) return
  emit('update:visible', false)
}

// Saves a pending observation: an edited value saves its relation, a new row
// creates a relation anchored on the current selection. Removing a pending
// observation row removes its relation (handled by deletion below).
async function saveObservationRow(row: TagRow, members: GroupMemberReference[]): Promise<NodeGroup | null> {
  const entry = { key: row.key.trim(), value: row.value }
  const translations = buildTranslationProposals(row, settings.locale)
  return row.relationId !== undefined
    ? nodeGroups.save({ id: row.relationId, tags: [entry], translationLocale: settings.locale, translations })
    : nodeGroups.save({ members, tags: [entry], translationLocale: settings.locale, translations })
}

// Saves one aspect entry: the relation's own aspect replaces its value;
// every other value becomes a new observation relation on the same range.
function aspectKey(row: AspectRow, aspect: string): string {
  return `${row.type}.${row.side}.${aspect}`
}

async function saveObservationDrafts(): Promise<void> {
  const members = anchorMembers.value
  const savedIds = new Set<number>()
  let allSaved = true

  for (const row of observationRows.value) {
    if (row.persistedOnNode) {
      continue // Node metadata saved on the node, not a relation.
    }
    const saved = await saveObservationRow(row, members)
    if (saved) {
      savedIds.add(saved.id)
    } else {
      allSaved = false
    }
  }

  for (const row of aspectRows.value) {
    const ownsRelation = row.relationId !== undefined
    const ownAspect = row.originAspect
    const ownValue = ownAspect !== undefined ? row.values[ownAspect] : undefined
    if (ownsRelation) {
      savedIds.add(row.relationId!)
      if (ownValue !== undefined && ownValue !== '') {
        const saved = await nodeGroups.save({ id: row.relationId!, tags: [{ key: aspectKey(row, ownAspect!), value: ownValue }] })
        if (saved) {
          savedIds.add(saved.id)
        } else {
          allSaved = false
        }
      }
    }
    // Every further aspect value of the row is its own observation.
    for (const [aspect, value] of Object.entries(row.values)) {
      if ((ownsRelation && aspect === ownAspect) || value === '') {
        continue
      }
      const saved = await nodeGroups.save({ members, tags: [{ key: aspectKey(row, aspect), value }] })
      if (saved) {
        savedIds.add(saved.id)
      } else {
        allSaved = false
      }
    }
  }

  // Removed pending observations: a drafted relation that was not saved on
  // this pass had its row removed, so its relation goes away too.
  for (const id of pendingRelationIds.value) {
    if (!savedIds.has(id)) {
      const removed = await nodeGroups.remove(id)
      if (!removed) {
        allSaved = false
      }
    }
  }

  // Failed saves leave the drafted rows untouched, so a retry resubmits them;
  // only fully successful passes re-split the drafts from the fresh state.
  if (!allSaved) {
    return
  }

  // The drafts re-split from the fresh store state; the edited relation also
  // re-targets the panel with its approved/pending tag unchanged otherwise.
  if (isRelation.value && props.relation) {
    const fresh = [...nodeGroups.groups].find(group => group.id === props.relation!.id)
    // Removing the selected relation's observation deleted that relation:
    // re-target to another relation of the same range, or close the panel.
    const target = fresh ?? relationsOnRange(props.relation!)[0]
    if (!target) {
      emit('update:visible', false)
      return
    }
    loadRelationDraft(target)
    emit('saved-relation', target)
  } else if (props.node) {
    loadNodeDraft(props.node)
  }
}

async function save(): Promise<void> {
  if ((!props.node && !props.creation && !props.relation) || hasIncompleteTags.value) {
    saveAttempted.value = true
    return
  }
  // Creating collects the observations for the placed range; OsmMap saves
  // every observation as its own relation.
  if (props.creation) {
    emit('create', {
      tags: observationRows.value.map(({ key, value }) => ({ key: key.trim(), value })),
      translationLocale: settings.locale,
      translations: observationRows.value.flatMap(row => buildTranslationProposals(row, settings.locale)),
      name: pendingName.value,
      crossSection: aspectRows.value,
    })
    return
  }
  // Pending node metadata (e.g. names) remains node data and saves on the
  // node; all other rows are observation relations.
  const nodeRows = observationRows.value.filter(row => row.persistedOnNode)
  if (nodeRows.length > 0 && props.node && !isRelation.value && !props.creation) {
    const tags = [
      ...rows.value.map(({ key, value }) => ({ key: key.trim(), value })),
      ...nodeRows.map(({ key, value }) => ({ key: key.trim(), value })),
    ]
    const savedNodeResult = await userNodes.save({
      provider: props.node.provider,
      providerNodeId: props.node.providerNodeId,
      latitude: props.node.latitude,
      longitude: props.node.longitude,
      tags,
      translationLocale: settings.locale,
      translations: nodeRows.flatMap(row => buildTranslationProposals(row, settings.locale)),
    })
    if (!savedNodeResult) {
      // The save error shows in the panel; relation saves wait for a retry.
      return
    }
  }
  await saveObservationDrafts()
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
    @update:visible="emit('update:visible', $event)"
  >
    <template #header>
      <div class="panel-title" :title="headerLabel">{{ headerLabel }}</div>
    </template>
    <DrawerResizeHandle />
    <div v-if="creation" role="form" :aria-label="t('map.create.pendingDialog')" class="creation-details">
      <InputText
        id="pending-node-name"
        v-model="pendingName"
        :disabled="creation.saving"
        :aria-label="t('map.create.name')"
        :placeholder="t('map.create.namePlaceholder')"
      />
      <p v-if="creation.failed" class="save-error" role="alert">{{ t('map.create.failed') }}</p>
    </div>
    <fieldset :disabled="creation?.saving" class="editor-fields">
      <!-- Two or more placed nodes form observation relations, so their edit
           options are available here instantly: every drafted observation
           saves as its own relation, no second editing view is needed. -->
      <section v-if="creation && isRelationDraft" class="cross-section-section" :aria-label="t('crossSection.title')">
        <h4 class="section-title">{{ t('crossSection.title') }}</h4>
        <CrossSectionComposer v-model="aspectRows" />
      </section>

      <!-- The relation editor shares the panel style: members on a compact
           list, then the parent's tag tables and the relation's approved
           tag, then the composer draft. Unnamed members are not
           listed at all. -->
      <section v-if="isRelation" class="relations-section members-section" :aria-label="t('relationEditor.members')">
        <ul class="member-list">
          <li v-for="member in namedMembers" :key="member.id" class="member-item">
            <span>{{ member.name }}</span>
          </li>
        </ul>
      </section>
      <!-- The parent node's tags name the element (e.g. the street) the
           relation belongs to; OSM parents show their original OSM tags.
           The parent's name above its tables replaces a section heading. -->
      <section
        v-if="isRelation && relationParentTagGroups.length > 0"
        class="relations-section parent-tags-section"
        :aria-label="t('relationEditor.parentTags')"
      >
        <div v-for="group in relationParentTagGroups" :key="group.key" class="parent-node">
          <span class="parent-node-name" :title="group.name">{{ group.name }}</span>
          <div v-if="group.osmTagEntries.length > 0">
            <details class="collapsible-tags">
              <summary class="section-title">{{ t('nodeEditor.osmTags') }}</summary>
              <TagTable :entries="group.osmTagEntries" />
            </details>
          </div>
          <TagTable v-if="group.customTags.length > 0" :entries="group.customTags" />
        </div>
      </section>

      <section v-if="!creation && !isRelation" class="relations-section node-relations-section" :aria-label="t('nodeEditor.nodeRelations')">
        <div v-for="group in nodeRelations" :key="group.id" class="node-relation">
          <div class="node-relation-heading">
            <span>{{ group.name ?? '' }}</span>
            <Button
              v-if="auth.canEdit"
              :aria-label="t('nodeEditor.editRelation')"
              icon="pi pi-pencil"
              text
              severity="secondary"
              @click="emit('edit-relation', group)"
            />
          </div>
          <TagTable v-if="group.tags.length > 0" :entries="group.tags" />
        </div>
      </section>

      <section v-if="osmTagEntries.length > 0" class="osm-tags-section">
        <!-- Raw OSM tags are secondary, so the section starts collapsed. -->
        <details class="collapsible-tags">
          <summary class="section-title">{{ t('nodeEditor.osmTags') }}</summary>
          <TagTable :entries="osmTagEntries" />
        </details>
      </section>

      <section class="custom-tags-section">
        <p v-if="!auth.canEdit" class="empty-hint">{{ t('nodeEditor.loginRequired') }}</p>
        <!-- Approved tags are managed by admins, so they are read-only here. -->
        <TagTable v-if="rows.length > 0" :entries="rows" />
        <p v-if="!creation && (userNodes.saveFailed || nodeGroups.saveFailed)" class="save-error" role="alert">
          {{ t(userNodes.saveConflict || nodeGroups.saveConflict ? 'nodeEditor.translationConflict' : 'nodeEditor.saveFailed') }}
        </p>
      </section>

      <!-- The composer draft follows the read-only tag sections: like
           the observations below, it collects the editor's own pending
           observations; approved aspects already show in the tag tables above. -->
      <section v-if="isRelation && (auth.canEdit || hasEditableAspects)" class="cross-section-section" :aria-label="t('crossSection.title')">
        <h4 class="section-title">{{ t('crossSection.title') }}</h4>
        <CrossSectionComposer v-model="aspectRows" :readonly="!auth.canEdit" />
      </section>

      <ObservationEditor
        v-model="observationRows"
        v-model:save-attempted="saveAttempted"
        :node="node"
        :plain="isRelation"
        :approved-rows="rows"
      />

    </fieldset>

    <template #footer>
      <!-- Incomplete rows only surface as an error once a save was attempted. -->
      <p v-if="auth.canEdit && saveAttempted && hasIncompleteTags" class="save-error" role="alert">{{ t('nodeEditor.requiredTagFields') }}</p>
      <Button :label="t(creation ? 'map.create.abort' : 'nodeEditor.cancel')" :disabled="creation?.saving" text severity="secondary" @click="close" />
      <Button
        v-if="auth.canEdit"
        :label="t(creation?.failed ? 'map.create.retry' : 'nodeEditor.save')"
        :loading="creation ? creation.saving : isRelation ? nodeGroups.saving : userNodes.saving || nodeGroups.saving"
        :disabled="creation?.count === 0"
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
  gap: 0.25rem;
  margin-bottom: 0.5rem;
}

.creation-details p {
  margin: 0;
}

.section-title {
  margin: 0 0 0.25rem;
  font-size: 0.65rem;
  font-weight: 600;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  color: var(--p-text-muted-color);
}

.osm-tags-section,
.relations-section,
.cross-section-section,
.custom-tags-section {
  margin-bottom: 0.5rem;
}

/* A small gap sets the read-only custom tag table apart from the OSM tags above. */
.custom-tags-section {
  margin-top: 0.25rem;
}

/* Collapsible tag tables (native details) start collapsed; the summary
   reads like a section title with a chevron that turns when expanded. */
.collapsible-tags summary {
  display: flex;
  align-items: center;
  gap: 0.25rem;
  cursor: pointer;
  list-style: none;
  user-select: none;
}

.collapsible-tags summary::-webkit-details-marker {
  display: none;
}

.collapsible-tags summary::after {
  content: '›';
  line-height: 1;
  color: var(--p-text-muted-color);
  transition: rotate 0.15s ease;
}

.collapsible-tags[open] summary::after {
  rotate: 90deg;
}

/* The tag table inside a collapsible starts below its summary line. The rule
   reaches the TagTable root, which carries this scope's attribute. */
.collapsible-tags .tag-table-scroll {
  margin-top: 0.25rem;
}

.node-relations-section .node-relation {
  padding: 0.25rem 0;
  border-bottom: 1px solid var(--p-content-border-color);
}

.node-relation-heading {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 0.375rem;
  color: var(--p-text-muted-color);
}

.node-relation-heading .p-button {
  width: 1.5rem;
  height: 1.5rem;
}

.member-list {
  list-style: none;
  padding: 0;
  margin: 0;
}

.member-item {
  display: flex;
  flex-direction: column;
  padding: 0.25rem 0;
  border-bottom: 1px solid var(--p-content-border-color);
  overflow-wrap: anywhere;
}

/* One block per parent node: the parent's name above its tag tables. */
.parent-node {
  padding: 0.25rem 0;
  border-bottom: 1px solid var(--p-content-border-color);
}

.parent-node-name {
  display: block;
  color: var(--p-text-muted-color);
  overflow-wrap: anywhere;
}

.empty-hint {
  margin: 0 0 0.25rem;
  color: var(--p-text-muted-color);
}

.save-error {
  margin: 0.375rem 0 0;
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
