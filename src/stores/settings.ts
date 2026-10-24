import { ref } from 'vue'
import { defineStore } from 'pinia'
import i18n from '../i18n'

export type AppLocale = 'en' | 'de'
export type RoutingProfile = 'foot_shortest' | 'foot_wheelchair'

const LOCALE_KEY = 'accessapp.settings.locale'
const PROFILE_KEY = 'accessapp.settings.routingProfile'

function readStored<T extends string>(key: string, fallback: T, allowed: readonly T[]): T {
  try {
    const value = localStorage.getItem(key) as T | null
    return value !== null && allowed.includes(value) ? value : fallback
  } catch {
    return fallback
  }
}

function writeStored(key: string, value: string): void {
  try {
    localStorage.setItem(key, value)
  } catch {
    // Storage may be unavailable (private mode, quota); settings then only
    // last for the current session.
  }
}

// Client-side settings from the profile page. They are local to the browser
// and persisted in localStorage, not on the backend.
export const useSettingsStore = defineStore('settings', () => {
  const locale = ref<AppLocale>(readStored(LOCALE_KEY, 'en', ['en', 'de']))
  const routingProfile = ref<RoutingProfile>(
    readStored(PROFILE_KEY, 'foot_shortest', ['foot_shortest', 'foot_wheelchair']),
  )

  // Apply the persisted locale on startup, before the first render.
  i18n.global.locale.value = locale.value

  function setLocale(value: AppLocale): void {
    locale.value = value
    i18n.global.locale.value = value
    writeStored(LOCALE_KEY, value)
  }

  function setRoutingProfile(value: RoutingProfile): void {
    routingProfile.value = value
    writeStored(PROFILE_KEY, value)
  }

  return { locale, routingProfile, setLocale, setRoutingProfile }
})
