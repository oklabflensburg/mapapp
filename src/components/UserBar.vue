<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import Dialog from 'primevue/dialog'
import InputText from 'primevue/inputtext'
import { useAuthStore } from '../stores/auth'

const { t } = useI18n()
const auth = useAuthStore()

const loginVisible = ref(false)
const email = ref('')
const password = ref('')
const loginFailed = ref(false)
const loggingIn = ref(false)

function openLogin(): void {
  email.value = ''
  password.value = ''
  loginFailed.value = false
  loginVisible.value = true
}

async function submitLogin(): Promise<void> {
  loggingIn.value = true
  loginFailed.value = false
  const ok = await auth.login(email.value, password.value)
  loggingIn.value = false
  if (ok) {
    loginVisible.value = false
  } else {
    loginFailed.value = true
  }
}

async function logout(): Promise<void> {
  await auth.logout()
}
</script>

<template>
  <div v-if="auth.user" class="user-bar">
    <span class="user-name">{{ auth.user.name }}</span>
    <Button
      :label="t('auth.logout')"
      icon="pi pi-sign-out"
      text
      severity="secondary"
      @click="logout"
    />
  </div>
  <div v-else class="user-bar">
    <Button
      :label="t('auth.login')"
      icon="pi pi-sign-in"
      severity="secondary"
      @click="openLogin"
    />
  </div>

  <Dialog
    v-model:visible="loginVisible"
    :header="t('auth.loginTitle')"
    :modal="true"
    :style="{ width: '20rem' }"
  >
    <form class="login-form" @submit.prevent="submitLogin">
      <label for="login-email">{{ t('auth.email') }}</label>
      <InputText
        id="login-email"
        v-model="email"
        type="email"
        autocomplete="email"
        required
      />
      <label for="login-password">{{ t('auth.password') }}</label>
      <InputText
        id="login-password"
        v-model="password"
        type="password"
        autocomplete="current-password"
        required
      />
      <p v-if="loginFailed" class="login-error" role="alert">{{ t('auth.loginFailed') }}</p>
      <Button
        type="submit"
        :label="t('auth.login')"
        :loading="loggingIn"
        icon="pi pi-sign-in"
      />
    </form>
  </Dialog>
</template>

<style scoped>
/* Floating chip top-right, mirroring the status chip at the top center. */
.user-bar {
  position: absolute;
  z-index: 1100;
  top: calc(0.5rem + env(safe-area-inset-top));
  right: calc(0.5rem + env(safe-area-inset-right));
  display: flex;
  align-items: center;
  gap: 0.25rem;
  padding: 0.25rem 0.5rem;
  border-radius: var(--p-content-border-radius);
  background: var(--p-content-background);
  box-shadow: var(--p-overlay-popover-shadow, 0 4px 6px -1px rgba(0, 0, 0, 0.1));
}

.user-name {
  font-size: 0.875rem;
  color: var(--p-text-muted-color);
  overflow: hidden;
  text-overflow: ellipsis;
  max-width: 8rem;
  white-space: nowrap;
}

.login-form {
  display: flex;
  flex-direction: column;
  gap: 0.375rem;
}

.login-form label {
  font-size: 0.875rem;
  color: var(--p-text-muted-color);
}

.login-error {
  margin: 0;
  font-size: 0.875rem;
  color: var(--p-red-500);
}
</style>
