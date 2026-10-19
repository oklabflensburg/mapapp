<template>
  <RouterView />
</template>

<style>
/* Full-screen app: no default body margin, and the view chain fills the
   viewport so the map can size itself from the root down. */
html,
body,
#app {
  height: 100%;
  margin: 0;
}
</style>
