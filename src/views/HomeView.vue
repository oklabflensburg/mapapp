<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import OsmMap from '../components/OsmMap.vue'
import type { MapStatus } from '../map/geolocation'
import NodeEditorDialog from '../components/NodeEditorDialog.vue'
import type { NodeEditTarget } from '../stores/userNodes'

const { t } = useI18n()
const mapStatus = ref<MapStatus>('locating')

const mapStatusMessage = computed(() => {
  switch (mapStatus.value) {
    case 'locating':
      return t('map.status.locating')
    case 'unsupported':
      return t('map.status.unsupported')
    case 'unavailable':
      return t('map.status.unavailable')
    default:
      return ''
  }
})

const editorVisible = ref(false)
const editTarget = ref<NodeEditTarget | null>(null)
const editMode = ref(false)

function openEditor(node: NodeEditTarget): void {
  editTarget.value = node
  editorVisible.value = true
}
</script>

<template>
  <main class="home">
    <p v-if="mapStatusMessage" class="map-status" role="status">{{ mapStatusMessage }}</p>
    <Button
      class="edit-toggle"
      :aria-label="t('map.editMode')"
      :aria-pressed="editMode"
      icon="pi pi-pencil"
      :severity="editMode ? 'primary' : 'secondary'"
      rounded
      @click="editMode = !editMode"
    />
    <OsmMap :edit-mode="editMode" @status="mapStatus = $event" @edit-node="openEditor" />
    <NodeEditorDialog v-model:visible="editorVisible" :node="editTarget" />
  </main>
</template>

<style scoped>
/* Mobile-first: the map is the app and fills the viewport. dvh tracks the
   dynamic viewport so mobile browser toolbars don't cut the map off. */
.home {
  position: relative;
  height: 100vh;
  height: 100dvh;
}

/* Floating status chip above the map; clear of notches and Leaflet's own
   top-left zoom controls. */
.map-status {
  position: absolute;
  z-index: 1100;
  top: calc(0.5rem + env(safe-area-inset-top));
  left: 50%;
  transform: translateX(-50%);
  margin: 0;
  padding: 0.375rem 0.75rem;
  border-radius: var(--p-content-border-radius);
  background: var(--p-content-background);
  color: var(--p-text-muted-color);
  box-shadow: var(--p-overlay-popover-shadow, 0 4px 6px -1px rgba(0, 0, 0, 0.1));
  font-size: 0.875rem;
  white-space: nowrap;
  max-width: calc(100vw - 2rem);
  overflow: hidden;
  text-overflow: ellipsis;
}

/* Edit-mode toggle, top-right so it clears the status chip and Leaflet's
   top-left zoom controls. */
.edit-toggle {
  position: absolute;
  z-index: 1100;
  top: calc(0.5rem + env(safe-area-inset-top));
  right: calc(0.5rem + env(safe-area-inset-right));
  width: 2.5rem;
  height: 2.5rem;
  justify-content: center;
  box-shadow: var(--p-overlay-popover-shadow, 0 4px 6px -1px rgba(0, 0, 0, 0.1));
}
</style>
