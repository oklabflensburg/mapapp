import 'primeicons/primeicons.css'
import { createApp } from 'vue'
import { createPinia } from 'pinia'
import PrimeVue from 'primevue/config'
import Aura from '@primeuix/themes/aura'
import App from './App.vue'
import router from './router'
import i18n from './i18n'
import { useAuthStore } from './stores/auth'

const app = createApp(App)

app.use(createPinia())
app.use(router)
app.use(i18n)

// Restore the login state before the map UI decides what to render.
void useAuthStore().load()
app.use(PrimeVue, {
  theme: {
    preset: Aura,
  },
})

app.mount('#app')
