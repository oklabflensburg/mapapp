<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const STORAGE_KEY = 'mapEditorPanelHeightDvh'
const MIN_DVH = 20
const MAX_DVH = 90
const DEFAULT_DVH = 45
const KEYBOARD_STEP_DVH = 5

const handle = ref<HTMLElement>()

let dragging = false
let startY = 0
let startHeightPx = 0

function storedHeightDvh(): number {
  const raw = Number.parseFloat(localStorage.getItem(STORAGE_KEY) ?? '')
  return Number.isFinite(raw) && raw >= MIN_DVH && raw <= MAX_DVH ? raw : DEFAULT_DVH
}

function apply(heightDvh: number, persist = true): void {
  document.documentElement.style.setProperty('--map-editor-height', `${heightDvh}dvh`)
  if (persist) {
    localStorage.setItem(STORAGE_KEY, String(heightDvh))
  }
}

function onPointerDown(event: PointerEvent): void {
  dragging = true
  startY = event.clientY
  startHeightPx = (storedHeightDvh() / 100) * window.innerHeight
  handle.value?.setPointerCapture(event.pointerId)
}

function onPointerMove(event: PointerEvent): void {
  if (!dragging || !handle.value?.hasPointerCapture(event.pointerId)) {
    return
  }
  const minPx = (MIN_DVH / 100) * window.innerHeight
  const maxPx = (MAX_DVH / 100) * window.innerHeight
  const heightPx = Math.min(Math.max(startHeightPx + startY - event.clientY, minPx), maxPx)
  apply((heightPx / window.innerHeight) * 100)
}

function endDrag(event: PointerEvent): void {
  if (!dragging) {
    return
  }
  dragging = false
  apply(storedHeightDvh())
  handle.value?.releasePointerCapture?.(event.pointerId)
}

function onKeyDown(event: KeyboardEvent): void {
  if (event.key !== 'ArrowUp' && event.key !== 'ArrowDown') {
    return
  }
  event.preventDefault()
  const direction = event.key === 'ArrowUp' ? 1 : -1
  apply(Math.min(Math.max(storedHeightDvh() + direction * KEYBOARD_STEP_DVH, MIN_DVH), MAX_DVH))
}

// The chosen height must apply before the first drag, also after a reload.
onMounted(() => apply(storedHeightDvh()))
</script>

<template>
  <div
    ref="handle"
    class="drawer-resize-handle"
    role="separator"
    aria-orientation="horizontal"
    :aria-label="t('map.editor.resize')"
    tabindex="0"
    @pointerdown="onPointerDown"
    @pointermove="onPointerMove"
    @pointerup="endDrag"
    @pointercancel="endDrag"
    @keydown="onKeyDown"
  >
    <span class="drawer-resize-handle-grip"></span>
  </div>
</template>

<style scoped>
/* Sticky so the height stays adjustable while the panel content scrolls. */
.drawer-resize-handle {
  position: sticky;
  top: -0.5rem;
  z-index: 1;
  display: flex;
  justify-content: center;
  align-items: center;
  height: 1.25rem;
  margin: -0.5rem 0 0;
  padding: 0 1rem;
  cursor: ns-resize;
  touch-action: none;
  background: var(--p-drawer-background, var(--p-content-background));
}

.drawer-resize-handle:focus-visible {
  outline: 1px solid var(--p-primary-color);
  outline-offset: -1px;
}

.drawer-resize-handle-grip {
  width: 2.25rem;
  height: 0.25rem;
  border-radius: 999px;
  background: var(--p-text-muted-color);
  opacity: 0.5;
}
</style>
