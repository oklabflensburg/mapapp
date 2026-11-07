<template>
  <RouterView />
</template>

<style>
/* Keep a good part of the map available while editing. The drawer body
   scrolls independently; its height can be adjusted with the drag handle
   (DrawerResizeHandle persists the choice as --map-editor-height). */
.p-drawer.map-editor.p-component {
  width: 100%;
  height: var(--map-editor-height, 45dvh);
  max-height: var(--map-editor-height, 45dvh);
  border-radius: 1rem 1rem 0 0;
}

/* The mobile panel stays compact: small headings, small controls. */
.map-editor .p-drawer-header {
  padding: 0.375rem max(1rem, env(safe-area-inset-right)) 0.375rem max(1rem, env(safe-area-inset-left));
  flex-shrink: 0;
}

.map-editor .p-drawer-header .p-drawer-title {
  font-size: 0.8125rem;
  font-weight: 600;
}

.map-editor .p-drawer-header .p-button {
  width: 1.75rem;
  height: 1.75rem;
}

.map-editor .p-drawer-content {
  min-height: 0;
  overflow-y: auto;
  overscroll-behavior: contain;
  padding: 0.5rem max(1rem, env(safe-area-inset-right)) 0.5rem max(1rem, env(safe-area-inset-left));
  font-size: 0.75rem;
}

.map-editor .p-drawer-footer {
  display: flex;
  flex-wrap: wrap;
  justify-content: flex-end;
  align-items: center;
  gap: 0.375rem;
  padding: 0.375rem max(1rem, env(safe-area-inset-right)) max(0.375rem, env(safe-area-inset-bottom)) max(1rem, env(safe-area-inset-left));
}

.map-editor .p-drawer-footer .p-button {
  font-size: 0.75rem;
  padding: 0.3125rem 0.625rem;
}

.map-editor .p-drawer-footer .save-error {
  flex-basis: 100%;
  margin: 0;
  font-size: 0.75rem;
}

/* Inputs follow the compact panel scale. */
.map-editor .p-inputtext,
.map-editor .p-select {
  font-size: 0.75rem;
}

.map-editor .p-inputtext {
  padding: 0.3125rem 0.5rem;
}

/* Full-screen app: no default body margin, and the view chain fills the
   viewport so the map can size itself from the root down. */
html,
body,
#app {
  height: 100%;
  margin: 0;
}

body {
  font-family:
    system-ui,
    -apple-system,
    'Segoe UI',
    Roboto,
    'Helvetica Neue',
    Arial,
    sans-serif;
}
</style>
