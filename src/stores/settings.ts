import { ref } from 'vue'
import { defineStore } from 'pinia'
import i18n from '../i18n'
import { useAuthStore } from './auth'

export type AppLocale = 'en' | 'de'
// How strongly a user wants routing to favor or disfavor a tag. Neutral is
// the implicit default when the user has not picked a level yet.
export type TagPriority = 'neutral' | 'avoid' | 'try_to_avoid' | 'prefer' | 'strongly_prefer'

const LOCALE_KEY = 'accessapp.settings.locale'
const TAG_PRIORITIES_KEY = 'accessapp.settings.tagPriorities'

const TAG_PRIORITY_LEVELS: readonly TagPriority[] = [
  'neutral',
  'avoid',
  'try_to_avoid',
  'prefer',
  'strongly_prefer',
]

// Tags shown until the backend list arrives (or when it cannot be fetched).
// The authoritative list is the one an admin manages in the backend's
// "User settings" admin section (RoutePreferenceTag).
const FALLBACK_PRIORITIZABLE_TAGS = ['stairs', 'toilets']

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

// Accepts only known levels, on any tag key; everything else is dropped.
// The tag keys themselves come from the backend, so they are not restricted
// here. Used for both the localStorage fallback and the backend response.
function normalizeTagPriorities(input: unknown): Record<string, TagPriority> {
  const priorities: Record<string, TagPriority> = {}
  if (typeof input !== 'object' || input === null) {
    return priorities
  }
  for (const [tag, value] of Object.entries(input)) {
    if (typeof value === 'string' && TAG_PRIORITY_LEVELS.includes(value as TagPriority)) {
      priorities[tag] = value as TagPriority
    }
  }
  return priorities
}

function readStoredTagPriorities(): Record<string, TagPriority> {
  try {
    return normalizeTagPriorities(JSON.parse(localStorage.getItem(TAG_PRIORITIES_KEY) ?? '{}'))
  } catch {
    return {}
  }
}

// Client-side settings from the profile page. They are persisted in
// localStorage; tag priorities are additionally stored per user on the
// backend when logged in. The tags offered as route preferences are managed
// by admins in the backend and loaded from there.
export const useSettingsStore = defineStore('settings', () => {
  const locale = ref<AppLocale>(readStored(LOCALE_KEY, 'en', ['en', 'de']))
  const prioritizableTags = ref<string[]>(FALLBACK_PRIORITIZABLE_TAGS)
  const tagPriorities = ref<Record<string, TagPriority>>(readStoredTagPriorities())

  // Apply the persisted locale on startup, before the first render.
  i18n.global.locale.value = locale.value

  function setLocale(value: AppLocale): void {
    locale.value = value
    i18n.global.locale.value = value
    writeStored(LOCALE_KEY, value)
  }

  function applyTagPriorities(priorities: Record<string, TagPriority>): void {
    tagPriorities.value = priorities
    writeStored(TAG_PRIORITIES_KEY, JSON.stringify(priorities))
  }

  // Replaces the tags offered as route preferences with the ones an admin
  // exposed in the backend; a failed request keeps the fallback list.
  async function loadRoutePreferenceTags(): Promise<void> {
    try {
      const response = await fetch('/api/route-preference-tags')
      if (!response.ok) {
        return
      }
      const data = (await response.json()) as { tags?: unknown }
      if (Array.isArray(data.tags) && data.tags.every((tag) => typeof tag === 'string')) {
        prioritizableTags.value = data.tags as string[]
      }
    } catch (error) {
      console.warn('Failed to load route preference tags', error)
    }
  }

  // Replaces the local tag priorities with the ones stored for the logged-in
  // user; a failed request keeps the localStorage fallback.
  async function loadTagPriorities(): Promise<void> {
    try {
      const response = await fetch('/api/tag-priorities')
      if (!response.ok) {
        return
      }
      const data = (await response.json()) as { priorities?: unknown }
      applyTagPriorities(normalizeTagPriorities(data.priorities))
    } catch (error) {
      console.warn('Failed to load tag priorities', error)
    }
  }

  function setTagPriority(tag: string, value: TagPriority): void {
    applyTagPriorities({ ...tagPriorities.value, [tag]: value })

    // Persist the priorities for the logged-in user; anonymous visitors
    // keep the localStorage-only behavior.
    if (useAuthStore().user) {
      void fetch('/api/tag-priorities', {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ priorities: tagPriorities.value }),
      }).catch((error) => console.warn('Failed to save tag priorities', error))
    }
  }

  return {
    locale,
    prioritizableTags,
    tagPriorities,
    setLocale,
    setTagPriority,
    loadRoutePreferenceTags,
    loadTagPriorities,
  }
})
