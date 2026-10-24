import 'primeicons/primeicons.css'
import { createApp } from 'vue'
import { createPinia } from 'pinia'
import PrimeVue from 'primevue/config'
import Aura from '@primeuix/themes/aura'
import App from './App.vue'
import router from './router'
import i18n from './i18n'
import { useAuthStore } from './stores/auth'
import { useSettingsStore } from './stores/settings'

const app = createApp(App)

app.use(createPinia())
app.use(router)
app.use(i18n)

// Restore the login state before the map UI decides what to render.
void useAuthStore().load()
// Applying the persisted locale before mounting keeps the first render in
// the language chosen on the profile page.
useSettingsStore()
app.use(PrimeVue, {
  theme: {
    preset: Aura,
  },
})

app.mount('#app')
