// Run after `npm run build`: node --test scripts/testing/pwa-navigation.test.mjs
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'

globalThis.self = globalThis
const { NavigationRoute } = await import('workbox-routing/NavigationRoute.js')

test('the production service worker leaves Symfony navigation to the network', () => {
  const worker = readFileSync(new URL('../../dist/sw.js', import.meta.url), 'utf8')
  const match = worker.match(/denylist:\[(\/.*?\/)\]/)
  assert.ok(match, 'production worker must include the backend navigation denylist')
  const pattern = new RegExp(match[1].slice(1, -1))
  const route = new NavigationRoute(() => {}, { denylist: [pattern] })
  const matches = (path) => route.match({
    url: new URL(path, 'https://map.example'),
    request: { mode: 'navigate' },
  })

  for (const path of [
    '/admin', '/admin/', '/admin/login',
    '/admin?routeName=app_admin_pending_observations',
    '/admin/login?next=dashboard', '/api', '/api?query=test', '/api/me',
  ]) {
    assert.equal(matches(path), false, path)
  }
  for (const path of ['/', '/profile', '/profile?tab=settings', '/administrator', '/apiary']) {
    assert.equal(matches(path), true, path)
  }
})
