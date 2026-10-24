<script setup lang="ts">
import { computed } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import Select from 'primevue/select'
import { useAuthStore } from '../stores/auth'
import { useSettingsStore, type AppLocale, type RoutingProfile } from '../stores/settings'

const { t } = useI18n()
const router = useRouter()
const auth = useAuthStore()
const settings = useSettingsStore()

// ROLE_EDITOR -> editor, for a readable roles list on the account card.
const roleNames = computed(() =>
  (auth.user?.roles ?? []).map((role) => role.replace(/^ROLE_/, '').toLowerCase()),
)

const localeOptions = computed(() => [
  { value: 'en' as AppLocale, label: t('profile.settings.language.en') },
  { value: 'de' as AppLocale, label: t('profile.settings.language.de') },
])

const routingOptions = computed(() => [
  { value: 'foot_shortest' as RoutingProfile, label: t('profile.settings.routing.shortest') },
  { value: 'foot_wheelchair' as RoutingProfile, label: t('profile.settings.routing.wheelchair') },
])

function back(): void {
  router.push({ name: 'home' })
}
</script>

<template>
  <main class="profile">
    <header class="profile-header">
      <Button
        :label="t('profile.back')"
        icon="pi pi-arrow-left"
        text
        severity="secondary"
        @click="back"
      />
      <h1 class="profile-title">{{ t('profile.title') }}</h1>
    </header>

    <section class="card" :aria-label="t('profile.account')">
      <h2 class="card-title">{{ t('profile.account') }}</h2>
      <template v-if="auth.user">
        <dl class="account-list">
          <div class="account-row">
            <dt>{{ t('profile.name') }}</dt>
            <dd>{{ auth.user.name }}</dd>
          </div>
          <div class="account-row">
            <dt>{{ t('profile.email') }}</dt>
            <dd>{{ auth.user.email }}</dd>
          </div>
          <div class="account-row">
            <dt>{{ t('profile.roles') }}</dt>
            <dd>{{ roleNames.join(', ') }}</dd>
          </div>
        </dl>
      </template>
      <p v-else class="hint">{{ t('profile.loginRequired') }}</p>
    </section>

    <section class="card" :aria-label="t('profile.settings.title')">
      <h2 class="card-title">{{ t('profile.settings.title') }}</h2>

      <div class="setting">
        <label for="setting-locale">{{ t('profile.settings.language.label') }}</label>
        <p class="hint">{{ t('profile.settings.language.hint') }}</p>
        <Select
          id="setting-locale"
          :model-value="settings.locale"
          :options="localeOptions"
          option-label="label"
          option-value="value"
          @update:model-value="settings.setLocale($event as AppLocale)"
        />
      </div>

      <div class="setting">
        <label for="setting-routing">{{ t('profile.settings.routing.label') }}</label>
        <p class="hint">{{ t('profile.settings.routing.hint') }}</p>
        <Select
          id="setting-routing"
          :model-value="settings.routingProfile"
          :options="routingOptions"
          option-label="label"
          option-value="value"
          @update:model-value="settings.setRoutingProfile($event as RoutingProfile)"
        />
      </div>
    </section>
  </main>
</template>

<style scoped>
/* Mobile-first scrollable page; the map stays on the home route. */
.profile {
  min-height: 100vh;
  min-height: 100dvh;
  padding:
    calc(0.5rem + env(safe-area-inset-top))
    calc(0.5rem + env(safe-area-inset-right))
    calc(0.5rem + env(safe-area-inset-bottom))
    calc(0.5rem + env(safe-area-inset-left));
  max-width: 32rem;
  margin: 0 auto;
  box-sizing: border-box;
}

.profile-header {
  display: flex;
  align-items: center;
  gap: 0.25rem;
  margin-bottom: 0.75rem;
}

.profile-title {
  margin: 0;
  font-size: 1.125rem;
}

.card {
  margin-bottom: 0.75rem;
  padding: 0.75rem;
  border: 1px solid var(--p-content-border-color);
  border-radius: var(--p-content-border-radius);
  background: var(--p-content-background);
}

.card-title {
  margin: 0 0 0.5rem;
  font-size: 0.875rem;
  font-weight: 600;
  color: var(--p-text-muted-color);
}

.account-list {
  margin: 0;
}

.account-row {
  display: flex;
  flex-direction: column;
  gap: 0.125rem;
  padding: 0.375rem 0;
  border-bottom: 1px solid var(--p-content-border-color);
  font-size: 0.875rem;
  overflow-wrap: anywhere;
}

.account-row:last-child {
  border-bottom: none;
}

.account-row dt {
  font-weight: 600;
  color: var(--p-text-muted-color);
}

.account-row dd {
  margin: 0;
}

.setting {
  display: flex;
  flex-direction: column;
  gap: 0.375rem;
  padding: 0.375rem 0;
}

.setting + .setting {
  margin-top: 0.375rem;
}

.setting label {
  font-size: 0.875rem;
  font-weight: 600;
}

.setting .p-select {
  width: min(100%, 16rem);
}

.hint {
  margin: 0;
  font-size: 0.8rem;
  color: var(--p-text-muted-color);
}
</style>
