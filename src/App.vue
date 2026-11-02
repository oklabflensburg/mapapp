<template>
  <RouterView />
</template>

<style>
/* Keep the upper half of the map available throughout editing. The drawer
   body scrolls independently while its title and actions remain reachable. */
.p-drawer.map-editor.p-component {
  width: 100%;
  height: 45vh;
  height: 45dvh;
  max-height: 45dvh;
  border-radius: 1rem 1rem 0 0;
}

.map-editor .p-drawer-header {
  padding: 0.625rem max(1rem, env(safe-area-inset-right)) 0.625rem max(1rem, env(safe-area-inset-left));
  flex-shrink: 0;
}

.map-editor .p-drawer-content {
  min-height: 0;
  overflow-y: auto;
  overscroll-behavior: contain;
  padding: 0.5rem max(1rem, env(safe-area-inset-right)) 0.5rem max(1rem, env(safe-area-inset-left));
}

.map-editor .p-drawer-footer {
  display: flex;
  flex-wrap: wrap;
  justify-content: flex-end;
  align-items: center;
  gap: 0.5rem;
  padding: 0.625rem max(1rem, env(safe-area-inset-right)) max(0.625rem, env(safe-area-inset-bottom)) max(1rem, env(safe-area-inset-left));
}

.map-editor .p-drawer-footer .save-error {
  flex-basis: 100%;
  margin: 0;
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
