<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import * as L from 'leaflet'
import 'leaflet/dist/leaflet.css'
import { useUserNodesStore } from '../stores/userNodes'
import { nodeKey, tagValue, type NodeEditTarget, type SaveUserNodeInput, type TagEntry, type UserNode } from '../stores/userNodes'
import { useNodeGroupsStore, type NodeGroup } from '../stores/nodeGroups'
import { useAuthStore } from '../stores/auth'
import '../map/leafletIcons'
import {
  DEFAULT_CENTER,
  DEFAULT_ZOOM,
  FOCUS_ZOOM,
  MAP_MAX_ZOOM,
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
import type { NodeCreationInput } from '../map/nodeCreation'
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

// A node placed in edit mode keeps its own parent reference until Save
// commits the batch.
interface PendingNode {
  providerNodeId: string
  latitude: number
  longitude: number
  parent?: SaveUserNodeInput['parent']
}

// Keep placed nodes and partial saves on the map; the shared editor owns
// the name and observation drafts until the batch is submitted.
const pendingNodes = ref<PendingNode[]>([])
const savedBatchNodes = ref<UserNode[]>([])
const creation = computed(() => createMode.value ? {
  count: pendingNodes.value.length + savedBatchNodes.value.length,
  parentNames: [...new Set(Object.values(placedParents.value))],
  saving: creating.value,
  failed: createFailed.value,
} : null)
// Keep parent labels through partial saves and retries.
const placedParents = ref<Record<string, string>>({})

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

// A click on a relation's line selects the relation itself; tags
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
    routing.exit()
    clearSelection()
    emit('deselect-node')
    emit('deselect-relation')
  }
}

function toggleRoutingMode(): void {
  if (routing.mode) {
    routing.exit()
    return
  }
  createMode.value = false
  clearPendingNodes()
  clearSelection()
  emit('deselect-node')
  emit('deselect-relation')
  routing.enter()
}

function onKeydown(event: KeyboardEvent): void {
  if (event.key === 'Escape') {
    if (createMode.value && !creating.value) toggleCreateMode()
    else if (routing.mode) routing.exit()
  }
}

// Each placement belongs to the feature it hits. Selecting a child uses its
// existing parent, matching the backend's one-level parent/child model.
function placePendingNode(click: L.LatLng, target?: NodeEditTarget): void {
  const existing = target ? userNodes.byIdentity.get(nodeKey(target)) : undefined
  const parent = existing?.parents[0] ?? existing ?? target
  if (parent) {
    placedParents.value[nodeKey(parent)] = ('name' in parent ? parent.name : undefined)
      || ('tags' in parent ? tagValue(parent.tags, 'name') : undefined)
      || (parent.provider === 'OSM' ? osmData.tagsById?.get(parent.providerNodeId)?.name : undefined)
      || parent.providerNodeId
  }
  const node: PendingNode = {
    providerNodeId: crypto.randomUUID(),
    latitude: click.lat,
    longitude: click.lng,
    parent: parent
      ? parent.id !== undefined
        ? { id: parent.id }
        : { providerNodeId: parent.providerNodeId, latitude: target!.latitude, longitude: target!.longitude }
      : undefined,
  }
  pendingNodes.value = [...pendingNodes.value, node]
  pendingNodeLayer?.render(pendingNodes.value)
}

// The dialog name overrides any name tag on the node or relation; the other
// tag entries pass through unchanged.
function withNameTag(tags: TagEntry[] | undefined, name: string): TagEntry[] {
  const others = (tags ?? []).filter((tag) => tag.key !== 'name')
  return name !== '' ? [...others, { key: 'name', value: name }] : others
}

// Commits the batch: every pending node is saved, then a single node stays a
// plain node (the dialog name becomes its name tag) while two or more become
// members of a new node relation (the name both names the relation and is
// stored as its name tag). Saved nodes
// are kept on failure, so a retry never saves a node twice.
async function savePendingNodes(input: NodeCreationInput): Promise<void> {
  if (creating.value) return
  if (pendingNodes.value.length === 0 && savedBatchNodes.value.length === 0) return
  creating.value = true
  createFailed.value = false
  try {
    while (pendingNodes.value.length > 0) {
      const node = pendingNodes.value[0]
      const name = input.name.trim()
      const isSingle = savedBatchNodes.value.length + pendingNodes.value.length === 1
      const saved = await userNodes.save({
        provider: 'local',
        providerNodeId: node.providerNodeId,
        latitude: node.latitude,
        longitude: node.longitude,
        tags: isSingle ? withNameTag(input.tags, name) : [],
        ...(isSingle ? { translationLocale: input.translationLocale, translations: input.translations } : {}),
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

    const name = input.name.trim()
    const members = savedBatchNodes.value.map((node) => ({ id: node.id }))
    const group = await nodeGroups.save({
      members,
      // The dialog name also becomes a name tag, so it reaches the
      // translation catalog like a node name does (see NodeGroupManager).
      tags: withNameTag(input.tags, name),
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
  placedParents.value = {}
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
defineExpose({ clearSelection, selectRelation, creation, savePendingNodes, abortCreation })

// User nodes (saved edits) rendered on top of the OSM layer.
const userNodes = useUserNodesStore()
const nodeGroups = useNodeGroupsStore()
const osmData = useOsmDataStore()

// Local nodes remain selectable after approval. The API returns approved
// nodes plus the current user's pending nodes. OSM overlays keep markers
// only for pending observations or nodes awaiting their first tags.
const visibleUserNodes = computed(() =>
  userNodes.nodes.filter((node) =>
    node.provider === 'local' ||
    node.observations.length > 0 || node.tags.length === 0))

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

// Relation lines are clickable only while browsing: in the create and
// routing modes their clicks must reach the nodes and features beneath.
const areasInteractive = computed(
  () =>
    !routing.mode &&
    !createMode.value &&
    !creating.value,
)

// Live preview of the relation being formed: the pending (and already saved)
// batch nodes in edit mode, where two or more nodes become a node relation
// on save. NodeGroupLayer draws the dashed line through them as soon as a
// second node exists.
const relationPreviewPoints = computed(() => {
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

// Logging in or out changes what the API returns: nodes and relations
// without approved content are only visible to their author, so the stores
// must be reloaded with the new session instead of keeping the previous
// user's data on the map.
watch(
  () => auth.user?.email,
  () => {
    void userNodes.load()
    void nodeGroups.load()
  },
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
  map = L.map(container.value, {
    renderer: L.canvas({ tolerance: 10 }),
    maxZoom: MAP_MAX_ZOOM,
  }).setView(DEFAULT_CENTER, DEFAULT_ZOOM)
  window.addEventListener('keydown', onKeydown)

  L.tileLayer(OSM_TILE_URL, {
    maxNativeZoom: OSM_TILE_MAX_ZOOM,
    maxZoom: MAP_MAX_ZOOM,
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
        :disabled="creating"
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
    </div>
    <p v-if="routing.loading" role="status">{{ t('map.routing.calculating') }}</p>
    <p v-if="routing.noRoute" role="alert">{{ t('map.routing.noRoute') }}</p>
    <p v-if="routing.failed" role="alert">{{ t('map.routing.failed') }}</p>
    <p v-if="routeSummary" role="status">{{ routeSummary }}</p>
  </div>
</template>

<style scoped>
.map {
  width: 100%;
  height: 100%;
  z-index: 0;
}

.map.create-mode,
.map.create-mode :deep(.leaflet-interactive) {
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

.create-toggle.active,
.routing-toggle.active {
  background: var(--p-primary-color, #059669);
  border-color: var(--p-primary-color, #059669);
  color: var(--p-primary-contrast-color, #fff);
  outline: 2px solid var(--p-primary-color, #059669);
  outline-offset: 2px;
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
