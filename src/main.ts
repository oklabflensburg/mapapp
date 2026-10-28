import 'primeicons/primeicons.css'
import { createApp } from 'vue'
import { watch } from 'vue'
import { createPinia } from 'pinia'
import PrimeVue from 'primevue/config'
import Aura from '@primeuix/themes/aura'
import App from './App.vue'
import router from './router'
import i18n from './i18n'
import { useAuthStore } from './stores/auth'
import { useSettingsStore } from './stores/settings'
import { useTagKeyLabelsStore } from './stores/tagKeyLabels'
import { useDefaultObservationTagsStore } from './stores/defaultObservationTags'

const app = createApp(App)

app.use(createPinia())
app.use(router)
app.use(i18n)

// Restore the login state before the map UI decides what to render. With a
// restored session, the server-stored tag priorities replace the
// localStorage fallback. The tags offered as route preferences are
// admin-managed, so they always come from the backend.
const auth = useAuthStore()
const settings = useSettingsStore()
void settings.loadRoutePreferenceTags()
void auth.load().then(() => {
  if (auth.user) {
    void settings.loadTagPriorities()
  }
})
// Tag key labels come from the backend per locale and reload when the user
// switches the app language on the profile page.
const tagKeyLabels = useTagKeyLabelsStore()
void tagKeyLabels.load(settings.locale)
watch(() => settings.locale, (locale) => void tagKeyLabels.load(locale))
// The tag keys offered as default observations are admin-managed too, so
// they always come from the backend.
void useDefaultObservationTagsStore().load()
// Applying the persisted locale before mounting keeps the first render in
// the language chosen on the profile page.
settings
app.use(PrimeVue, {
  theme: {
    preset: Aura,
  },
})

app.mount('#app')
