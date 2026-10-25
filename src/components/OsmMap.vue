<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import * as L from 'leaflet'
import 'leaflet/dist/leaflet.css'
import { useUserNodesStore } from '../stores/userNodes'
import { nodeKey, type NodeEditTarget, type SaveUserNodeInput, type UserNode } from '../stores/userNodes'
import { useNodeGroupsStore, type GroupMemberReference, type NodeGroup } from '../stores/nodeGroups'
import { useAuthStore } from '../stores/auth'
import '../map/leafletIcons'
import {
  DEFAULT_CENTER,
  DEFAULT_ZOOM,
  FOCUS_ZOOM,
  MOVE_RENDER_DEBOUNCE_MS,
  OSM_TILE_URL,
  OSM_TILE_MAX_ZOOM,
  OSM_TILE_ATTRIBUTION,
} from '../map/mapConfig'
import { useOsmDataStore } from '../stores/osmData'
import { OsmFeatureLayer } from '../map/osmFeatureLayer'
import { UserNodeLayer } from '../map/userNodeLayer'
import { NodeGroupLayer } from '../map/nodeGroupLayer'
import { PendingNodeLayer } from '../map/pendingNodeLayer'
import { RoutingLayer } from '../map/routingLayer'
import { useRoutingStore } from '../stores/routing'
import { locateUser, type LocationLayers, type MapStatus } from '../map/geolocation'

// When set, the map starts centered on this position instead of the visitor's
// location and skips the locating status flow.
const props = defineProps<{
  previewCenter?: { latitude: number; longitude: number; zoom?: number }
}>()

const emit = defineEmits<{
  (event: 'status', status: MapStatus): void
  (event: 'edit-node', node: NodeEditTarget): void
  (event: 'deselect-node'): void
  (event: 'edit-relation', group: NodeGroup): void
  (event: 'deselect-relation'): void
}>()

const container = ref<HTMLDivElement>()
const { t, locale } = useI18n()
const auth = useAuthStore()
const routing = useRoutingStore()
const createMode = ref(false)
const creating = ref(false)
const createFailed = ref(false)

// A node placed in edit mode; it only reaches the backend when Save commits
// the batch. The label names the placed node in the dialog.
interface PendingNode {
  providerNodeId: string
  latitude: number
  longitude: number
  label: string
  parent?: SaveUserNodeInput['parent']
}

// Edit mode state: placed nodes, the nodes already saved from this batch and
// the name from the dialog (which names the relation, or the single node).
const pendingNodes = ref<PendingNode[]>([])
const savedBatchNodes = ref<UserNode[]>([])
const pendingName = ref('')

// The anchor of the pending relation: the first placed node, shown as the
// parent node the relation is attached to.
const pendingAnchorLabel = computed(() => pendingNodes.value[0]?.label ?? '')

// Relation mode collects the nodes the editor clicks; finishing creates a
// node relation from them (and opens its tag editor).
const relationMode = ref(false)
const relationCreating = ref(false)
const relationFailed = ref(false)
const relationMembers = ref<NodeEditTarget[]>([])

let map: L.Map | undefined
let locationLayers: LocationLayers = {}

// The selected node's provider id; null while nothing is selected. The node
// panel stays open as long as an element is selected.
let selectedProviderNodeId: string | undefined

// The selected relation's id; its area is highlighted while the relation
// panel is open. Reactive so re-renders pick up the highlight.
const selectedGroupId = ref<number | undefined>(undefined)

// Set when a click hit a rendered feature; Leaflet fires the feature's click
// handlers before the map-level click, so the map handler can tell feature
// clicks and clicks on empty space apart.
let clickOnFeature = false

function clearSelection(): void {
  selectedProviderNodeId = undefined
  selectedGroupId.value = undefined
  osmFeatureLayer?.clearSelection()
}

// Selects a relation: its whole area is highlighted and any node highlight
// is dropped. Exposed so the view highlights a relation that was opened from
// the node panel.
function selectRelation(group: NodeGroup): void {
  clearSelection()
  selectedGroupId.value = group.id
}

// A click inside a relation's corridor selects the relation itself; tags
// edited then apply to the whole street part, not to a single node.
function onSelectGroup(group: NodeGroup): void {
  clickOnFeature = true
  selectRelation(group)
  emit('edit-relation', group)
}

function onEditNode(target: NodeEditTarget, click: L.LatLng): boolean {
  clickOnFeature = true
  if (routing.mode) {
    // Routing points are plain positions; feature selection is suppressed.
    routing.setPoint({ latitude: click.lat, longitude: click.lng })
    return false
  }
  if (relationMode.value || relationCreating.value) {
    // Clicking a member again removes it, so mis-clicks are undoable.
    const key = nodeKey(target)
    const index = relationMembers.value.findIndex((member) => nodeKey(member) === key)
    if (index === -1) {
      relationMembers.value = [...relationMembers.value, target]
    } else {
      relationMembers.value = relationMembers.value.filter((_, i) => i !== index)
    }
    return false
  }
  if (createMode.value || creating.value) {
    // Clicks only place pending nodes; saving happens in the dialog.
    if (!creating.value) placePendingNode(click, target)
    return false
  }
  // The selection moved to another element; drop the previous highlight.
  clearSelection()
  selectedProviderNodeId = nodeKey(target)
  emit('edit-node', target)
  return true
}

// Entering one map mode always leaves the other: edit mode places nodes,
// routing mode places route points, and both interpret map clicks.
function toggleCreateMode(): void {
  if (creating.value) return
  // Leaving edit mode discards placed but unsaved nodes.
  clearPendingNodes()
  createMode.value = !createMode.value
  if (createMode.value) {
    exitRelationMode()
    routing.exit()
    clearSelection()
    emit('deselect-node')
  }
}

function toggleRoutingMode(): void {
  if (routing.mode) {
    routing.exit()
    return
  }
  createMode.value = false
  clearPendingNodes()
  exitRelationMode()
  clearSelection()
  emit('deselect-node')
  routing.enter()
}

// Entering relation mode leaves the other map modes and drops the selection.
function toggleRelationMode(): void {
  if (relationCreating.value) return
  if (relationMode.value) {
    exitRelationMode()
    return
  }
  createMode.value = false
  clearPendingNodes()
  routing.exit()
  clearSelection()
  emit('deselect-node')
  relationMembers.value = []
  relationFailed.value = false
  relationMode.value = true
}

function exitRelationMode(): void {
  relationMode.value = false
  relationMembers.value = []
  relationFailed.value = false
}

async function finishRelation(): Promise<void> {
  if (relationCreating.value || relationMembers.value.length < 2) return
  relationCreating.value = true
  relationFailed.value = false
  const members: GroupMemberReference[] = relationMembers.value.map((member) =>
    member.id !== undefined
      ? { id: member.id }
      : { providerNodeId: member.providerNodeId, latitude: member.latitude, longitude: member.longitude })
  const saved = await nodeGroups.save({ members, tags: {} })
  relationCreating.value = false
  if (!saved) {
    relationFailed.value = true
    return
  }
  exitRelationMode()
  emit('edit-relation', saved)
}

function onKeydown(event: KeyboardEvent): void {
  if (event.key === 'Escape') {
    if (createMode.value && !creating.value) toggleCreateMode()
    else if (relationMode.value && !relationCreating.value) toggleRelationMode()
    else if (routing.mode) routing.exit()
  }
}

// Places a pending node at the click position; clicking a feature keeps the
// child-of-feature behavior for that node.
function placePendingNode(click: L.LatLng, target?: NodeEditTarget): void {
  const node: PendingNode = {
    providerNodeId: crypto.randomUUID(),
    latitude: click.lat,
    longitude: click.lng,
    label: target
      ? target.osmTags.name || target.providerNodeId
      : t('map.create.nodeLabel', { number: pendingNodes.value.length + 1 }),
    ...(target ? { parent: target.id !== undefined
      ? { id: target.id }
      : { providerNodeId: target.providerNodeId, latitude: target.latitude, longitude: target.longitude } } : {}),
  }
  pendingNodes.value = [...pendingNodes.value, node]
  pendingNodeLayer?.render(pendingNodes.value)
}

// Commits the batch: every pending node is saved, then a single node stays a
// plain node (the dialog name becomes its name tag) while two or more become
// members of a new node relation (the name names the relation). Saved nodes
// are kept on failure, so a retry never saves a node twice.
async function savePendingNodes(): Promise<void> {
  if (creating.value) return
  if (pendingNodes.value.length === 0 && savedBatchNodes.value.length === 0) return
  creating.value = true
  createFailed.value = false
  try {
    while (pendingNodes.value.length > 0) {
      const node = pendingNodes.value[0]
      const name = pendingName.value.trim()
      const isSingle = savedBatchNodes.value.length + pendingNodes.value.length === 1
      const saved = await userNodes.save({
        provider: 'local',
        providerNodeId: node.providerNodeId,
        latitude: node.latitude,
        longitude: node.longitude,
        tags: isSingle && name !== '' ? { name } : {},
        ...(node.parent ? { parent: node.parent } : {}),
      })
      if (!saved) {
        createFailed.value = true
        return
      }
      pendingNodes.value = pendingNodes.value.slice(1)
      pendingNodeLayer?.render(pendingNodes.value)
      savedBatchNodes.value = [...savedBatchNodes.value, saved]
    }

    if (savedBatchNodes.value.length === 1) {
      const saved = savedBatchNodes.value[0]
      finishBatch()
      createMode.value = false
      clearSelection()
      selectedProviderNodeId = nodeKey(saved)
      emit('edit-node', { ...saved, osmTags: {} })
      return
    }

    const name = pendingName.value.trim()
    const members = savedBatchNodes.value.map((node) => ({ id: node.id }))
    const group = await nodeGroups.save({
      members,
      tags: {},
      ...(name !== '' ? { name } : {}),
    })
    if (!group) {
      createFailed.value = true
      return
    }
    finishBatch()
    createMode.value = false
    clearSelection()
    emit('edit-relation', group)
  } finally {
    creating.value = false
  }
}

function finishBatch(): void {
  pendingNodes.value = []
  savedBatchNodes.value = []
  pendingName.value = ''
  createFailed.value = false
  pendingNodeLayer?.render([])
}

function clearPendingNodes(): void {
  finishBatch()
}

// Abort discards every placed but unsaved node and leaves edit mode.
function abortCreation(): void {
  if (creating.value) return
  finishBatch()
  createMode.value = false
}

// Re-requests the position and moves the view onto it; locateUser replaces
// the previous marker and accuracy circle.
function locateSelf(): void {
  locationLayers = locateUser(() => map, (status) => emit('status', status), locationLayers)
}

// Exposed so the view can drop the map highlight when a panel closes, and
// highlight a relation opened from the node panel.
defineExpose({ clearSelection, selectRelation })

// User nodes (saved edits) rendered on top of the OSM layer.
const userNodes = useUserNodesStore()
const nodeGroups = useNodeGroupsStore()
const osmData = useOsmDataStore()

// The observations map carries exactly the current user's unapproved tags,
// so markers stay only for nodes with pending observations: once every tag
// is approved the dot disappears. Nodes without any tags yet (just created,
// awaiting their first observation) keep their dot so they stay selectable.
const visibleUserNodes = computed(() =>
  userNodes.nodes.filter((node) =>
    Object.keys(node.observations).length > 0 || Object.keys(node.tags).length === 0))

// "1.2 km · 14 min walk" style summary of the found route.
const routeSummary = computed(() => {
  const route = routing.route
  if (!route) return ''
  const distance = route.distance < 1000
    ? `${Math.round(route.distance)} m`
    : `${new Intl.NumberFormat(locale.value, { maximumFractionDigits: 1 }).format(route.distance / 1000)} km`
  return t('map.routing.summary', {
    distance,
    minutes: Math.max(1, Math.round(route.time / 60)),
  })
})

let osmFeatureLayer: OsmFeatureLayer | undefined
let nodeGroupLayer: NodeGroupLayer | undefined
let userNodeLayer: UserNodeLayer | undefined
let pendingNodeLayer: PendingNodeLayer | undefined
let routingLayer: RoutingLayer | undefined
let renderTimer: ReturnType<typeof setTimeout> | undefined

// Areas are clickable only while browsing: in the create, relation and
// routing modes their clicks must reach the nodes and features beneath.
const areasInteractive = computed(
  () =>
    !routing.mode &&
    !createMode.value &&
    !creating.value &&
    !relationMode.value &&
    !relationCreating.value,
)

// Live preview of the relation being formed: the relation members in
// relation mode, or the pending (and already saved) batch nodes in edit
// mode, where two or more nodes become a node relation on save.
// NodeGroupLayer draws the corridor around them as soon as a second node
// exists.
const relationPreviewPoints = computed(() => {
  if (relationMode.value || relationCreating.value) {
    return relationMembers.value.map((member) => ({ latitude: member.latitude, longitude: member.longitude }))
  }
  if (createMode.value || creating.value) {
    return [...savedBatchNodes.value, ...pendingNodes.value].map(
      (node) => ({ latitude: node.latitude, longitude: node.longitude }),
    )
  }

  return []
})

function renderNodeGroups(): void {
  nodeGroupLayer?.render(nodeGroups.groups, relationPreviewPoints.value, {
    selectedGroupId: selectedGroupId.value,
    onSelect: areasInteractive.value ? onSelectGroup : undefined,
  })
}

// Rebuilds the OSM layer first, then the node-group area lines after it, then
// the user-node markers, and the pending and routing layers last, so they are
// drawn (and hit-tested) in that order on the shared canvas renderer.
function renderLayers(): void {
  osmFeatureLayer?.render()
  renderNodeGroups()
  userNodeLayer?.render(visibleUserNodes.value)
  pendingNodeLayer?.render(pendingNodes.value)
  routingLayer?.render(routing.start, routing.end, routing.route)
}

function scheduleRenderLayers(): void {
  if (renderTimer) {
    clearTimeout(renderTimer)
  }
  renderTimer = setTimeout(renderLayers, MOVE_RENDER_DEBOUNCE_MS)
}

watch(
  () => nodeGroups.groups,
  () => renderNodeGroups(),
)

watch(
  relationPreviewPoints,
  () => renderNodeGroups(),
)

watch(
  [selectedGroupId, areasInteractive],
  () => renderNodeGroups(),
)

watch(
  () => visibleUserNodes.value,
  () => userNodeLayer?.render(visibleUserNodes.value),
)

watch(
  [() => routing.start, () => routing.end, () => routing.route],
  () => routingLayer?.render(routing.start, routing.end, routing.route),
)

async function loadOsmFeatures(): Promise<void> {
  const data = await osmData.load()
  if (!data) {
    return
  }
  osmFeatureLayer?.setFeatures(data.features)
  renderLayers()
}

onMounted(() => {
  if (!container.value) {
    return
  }

  // Canvas hit-testing is exact by default, so thin lines (e.g. 1.5px roads)
  // would need pixel-perfect clicks. tolerance widens the hit area around
  // every path without changing how it is drawn.
  map = L.map(container.value, { renderer: L.canvas({ tolerance: 10 }) }).setView(DEFAULT_CENTER, DEFAULT_ZOOM)
  window.addEventListener('keydown', onKeydown)

  L.tileLayer(OSM_TILE_URL, {
    maxZoom: OSM_TILE_MAX_ZOOM,
    attribution: OSM_TILE_ATTRIBUTION,
  }).addTo(map)

  osmFeatureLayer = new OsmFeatureLayer({
    map,
    onEditNode,
    onSelect: () => userNodeLayer?.bringToFront(),
  })
  userNodeLayer = new UserNodeLayer({
    map,
    onEditNode,
    getOsmTags: (providerNodeId) => osmData.tagsById?.get(providerNodeId) ?? {},
  })
  routingLayer = new RoutingLayer(map)
  nodeGroupLayer = new NodeGroupLayer(map)
  pendingNodeLayer = new PendingNodeLayer(map)

  // Moving or zooming the map rebuilds the layers but never deselects; only
  // a click on empty space (no feature hit) drops the selection.
  map.on('click', (event: L.LeafletMouseEvent) => {
    if (clickOnFeature) {
      clickOnFeature = false
      return
    }
    if (routing.mode) {
      routing.setPoint({ latitude: event.latlng.lat, longitude: event.latlng.lng })
      return
    }
    if (createMode.value || creating.value) {
      if (!creating.value) placePendingNode(event.latlng)
      return
    }
    // Empty space holds no node to relate; relation mode only collects
    // clicks on nodes and map features.
    if (relationMode.value || relationCreating.value) {
      return
    }
    if (selectedProviderNodeId !== undefined) {
      clearSelection()
      emit('deselect-node')
    } else if (selectedGroupId.value !== undefined) {
      clearSelection()
      emit('deselect-relation')
    }
  })

  map.on('moveend', scheduleRenderLayers)
  void loadOsmFeatures()
  void userNodes.load()
  void nodeGroups.load()

  if (props.previewCenter) {
    map.setView([props.previewCenter.latitude, props.previewCenter.longitude], props.previewCenter.zoom ?? FOCUS_ZOOM)
  } else {
    locationLayers = locateUser(() => map, (status) => emit('status', status), locationLayers)
  }
})

onBeforeUnmount(() => {
  window.removeEventListener('keydown', onKeydown)
  if (renderTimer) {
    clearTimeout(renderTimer)
  }
  osmFeatureLayer?.destroy()
  nodeGroupLayer?.destroy()
  userNodeLayer?.destroy()
  pendingNodeLayer?.destroy()
  routingLayer?.destroy()
  locationLayers.accuracyCircle?.remove()
  locationLayers.marker?.remove()
  map?.remove()
  map = undefined
  osmFeatureLayer = undefined
  nodeGroupLayer = undefined
  userNodeLayer = undefined
  pendingNodeLayer = undefined
  routingLayer = undefined
  locationLayers = {}
})
</script>

<template>
  <div ref="container" class="map" :class="{ 'create-mode': createMode, 'routing-mode': routing.mode }" />
  <Button
    class="locate-button"
    icon="pi pi-map-marker"
    severity="secondary"
    :aria-label="t('map.locate.button')"
    :title="t('map.locate.button')"
    @click="locateSelf"
  />
  <!-- Routing is browsing, not editing: available to every visitor. -->
  <div class="map-controls">
    <div class="control-buttons">
      <Button
        class="routing-toggle"
        :class="{ active: routing.mode }"
        icon="pi pi-directions"
        :aria-label="t('map.routing.button')"
        :title="t('map.routing.button')"
        :aria-pressed="routing.mode"
        :severity="routing.mode ? 'primary' : 'secondary'"
        @click="toggleRoutingMode"
      />
      <!-- Adding nodes requires an editor; anonymous visitors can only browse. -->
      <Button
        v-if="auth.canEdit"
        class="create-toggle"
        :class="{ active: createMode }"
        icon="pi pi-plus"
        :aria-label="t('map.create.add')"
        :title="t('map.create.add')"
        :aria-pressed="createMode"
        :severity="createMode ? 'primary' : 'secondary'"
        :disabled="creating"
        @click="toggleCreateMode"
      />
      <!-- Relating nodes also requires an editor. -->
      <Button
        v-if="auth.canEdit"
        class="relation-toggle"
        :class="{ active: relationMode }"
        icon="pi pi-link"
        :aria-label="t('map.relation.button')"
        :title="t('map.relation.button')"
        :aria-pressed="relationMode"
        :severity="relationMode ? 'primary' : 'secondary'"
        :disabled="relationCreating"
        @click="toggleRelationMode"
      />
    </div>
    <p v-if="routing.mode" role="status">{{ t('map.routing.hint') }}</p>
    <p v-if="routing.loading" role="status">{{ t('map.routing.calculating') }}</p>
    <p v-if="routing.noRoute" role="alert">{{ t('map.routing.noRoute') }}</p>
    <p v-if="routing.failed" role="alert">{{ t('map.routing.failed') }}</p>
    <p v-if="routeSummary" role="status">{{ routeSummary }}</p>
    <p v-if="createMode" role="status">{{ t('map.create.hint') }}</p>
    <!-- The dialog for the nodes placed in edit mode; nothing is sent until
         Save commits the batch. -->
    <div
      v-if="createMode && pendingNodes.length > 0"
      class="pending-dialog"
      role="form"
      :aria-label="t('map.create.pendingDialog')"
    >
      <p class="pending-count" role="status">{{ t('map.create.placed', { count: pendingNodes.length }) }}</p>
      <div class="pending-field">
        <label class="pending-label" for="pending-node-name">{{ t('map.create.name') }}</label>
        <InputText
          id="pending-node-name"
          v-model="pendingName"
          :placeholder="t('map.create.namePlaceholder')"
          class="pending-name"
        />
      </div>
      <p class="pending-parent">
        {{ t('map.create.parentNode') }}:
        <span>{{ pendingAnchorLabel || t('map.create.noParent') }}</span>
      </p>
      <div class="pending-actions">
        <Button :label="t('map.create.save')" icon="pi pi-check" :loading="creating" @click="savePendingNodes" />
        <Button
          :label="t('map.create.abort')"
          icon="pi pi-times"
          text
          severity="danger"
          :disabled="creating"
          @click="abortCreation"
        />
      </div>
    </div>
    <div v-if="createFailed" role="alert">
      <p>{{ t('map.create.failed') }}</p>
      <Button :label="t('map.create.retry')" :disabled="creating" @click="savePendingNodes" />
    </div>
    <p v-if="relationMode" role="status">{{ t('map.relation.hint') }}</p>
    <p v-if="relationMode && relationMembers.length > 0" role="status">
      {{ t('map.relation.members', { count: relationMembers.length }) }}
    </p>
    <div v-if="relationMode" class="relation-actions">
      <Button
        :label="t('map.relation.create')"
        icon="pi pi-check"
        :disabled="relationMembers.length < 2"
        :loading="relationCreating"
        @click="finishRelation"
      />
      <Button :label="t('map.relation.cancel')" text severity="secondary" :disabled="relationCreating" @click="toggleRelationMode" />
    </div>
    <div v-if="relationFailed" role="alert">
      <p>{{ t('map.relation.failed') }}</p>
      <Button :label="t('map.create.retry')" :disabled="relationCreating" @click="finishRelation" />
    </div>
  </div>
</template>

<style scoped>
.map {
  width: 100%;
  height: 100%;
  z-index: 0;
}

.map.create-mode,
.map.create-mode :deep(.leaflet-interactive),
.map.relation-mode,
.map.relation-mode :deep(.leaflet-interactive) {
  cursor: crosshair;
}

.map.routing-mode,
.map.routing-mode :deep(.leaflet-interactive) {
  cursor: crosshair;
}

.map-controls {
  position: absolute;
  z-index: 1000;
  top: calc(5.5rem + env(safe-area-inset-top));
  left: 0.75rem;
  max-width: min(20rem, calc(100vw - 1.5rem));
}

.control-buttons {
  display: flex;
  gap: 0.5rem;
}

.map-controls p {
  margin: 0.5rem 0;
  padding: 0.5rem 0.75rem;
  background: var(--p-content-background);
  border-radius: var(--p-content-border-radius);
  box-shadow: 0 2px 6px #0002;
  font-size: 0.875rem;
}

/* Panel styling for the placed-nodes dialog, matching the hint chips. */
.pending-dialog {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  margin: 0.5rem 0;
  padding: 0.75rem;
  background: var(--p-content-background);
  border-radius: var(--p-content-border-radius);
  box-shadow: 0 2px 6px #0002;
  font-size: 0.875rem;
}

.pending-dialog .pending-count {
  margin: 0;
  padding: 0;
  background: transparent;
  box-shadow: none;
}

.pending-field {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
}

.pending-label {
  font-size: 0.8rem;
  color: var(--p-text-muted-color);
}

.pending-parent {
  margin: 0;
  padding: 0;
  background: transparent;
  box-shadow: none;
  color: var(--p-text-muted-color);
}

.pending-parent span {
  color: var(--p-text-color);
}

.pending-actions {
  display: flex;
  gap: 0.5rem;
}

.create-toggle.active,
.relation-toggle.active,
.routing-toggle.active {
  background: var(--p-primary-color, #059669);
  border-color: var(--p-primary-color, #059669);
  color: var(--p-primary-contrast-color, #fff);
  outline: 2px solid var(--p-primary-color, #059669);
  outline-offset: 2px;
}

.relation-actions {
  display: flex;
  gap: 0.5rem;
  margin: 0.5rem 0;
}

/* Bottom-right corner: clear of the top-left zoom and create controls and
   the top-center status chip. */
.locate-button {
  position: absolute;
  z-index: 1000;
  bottom: calc(0.75rem + env(safe-area-inset-bottom));
  right: calc(0.75rem + env(safe-area-inset-right));
  box-shadow: 0 2px 6px #0002;
}
</style>
