<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import OsmMap, { type MapStatus } from '../components/OsmMap.vue'
import { useHelloStore } from '../stores/hello'

const { t } = useI18n()
const hello = useHelloStore()
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

onMounted(() => {
  hello.fetchHello()
})
</script>

<template>
  <main class="home">
    <h1>{{ t('hello.title') }}</h1>
    <p v-if="hello.message" data-testid="backend-message">{{ hello.message }}</p>
    <p v-else-if="hello.error" class="error">{{ hello.error }}</p>
    <p v-if="mapStatusMessage" class="map-status">{{ mapStatusMessage }}</p>
    <OsmMap @status="mapStatus = $event" />
    <Button class="reload" :label="t('hello.reload')" @click="hello.fetchHello()" />
  </main>
</template>

<style scoped>
.home {
  max-width: 60rem;
  margin: 2rem auto;
  padding: 0 1rem;
}

.error {
  color: #c0392b;
}

.map-status {
  color: #666;
}

.reload {
  margin-top: 1rem;
}
</style>
