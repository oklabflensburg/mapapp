import { test } from 'node:test'
import assert from 'node:assert/strict'
import { createPinia, setActivePinia } from 'pinia'
import { useTagKeyLabelsStore } from '../../src/stores/tagKeyLabels.ts'

test('labels preserve contextual raw values and fall back safely', async () => {
  setActivePinia(createPinia())
  const originalFetch = globalThis.fetch
  globalThis.fetch = async () => ({ ok: true, json: async () => ({
    labels: { wheelchair: 'Rollstuhlzugänglichkeit', empty_key: '' },
    values: { '["wheelchair","yes"]': 'Ja' },
  }) })
  try {
    const store = useTagKeyLabelsStore()
    await store.load('de')
    assert.equal(store.label('wheelchair'), 'Rollstuhlzugänglichkeit')
    assert.equal(store.label('empty_key'), 'empty key')
    assert.equal(store.label('unknown_key'), 'unknown key')
    assert.equal(store.label('__proto__'), '  proto  ')
    assert.equal(store.valueLabel('wheelchair', 'yes'), 'Ja')
    assert.equal(store.valueLabel('name', 'yes'), 'yes')
    assert.equal(store.valueLabel('name', 'my_place & <name>'), 'my_place & <name>')
  } finally {
    globalThis.fetch = originalFetch
  }
})

test('a slow old locale response cannot replace the active language', async () => {
  setActivePinia(createPinia())
  const originalFetch = globalThis.fetch
  let finishGerman
  let germanStarted
  const started = new Promise(resolve => { germanStarted = resolve })
  globalThis.fetch = async (url) => ({ ok: true, json: async () => {
    if (url.endsWith('/de')) {
      germanStarted()
      return new Promise(resolve => { finishGerman = resolve })
    }
    return { labels: { wheelchair: 'Wheelchair access' }, values: { '["wheelchair","yes"]': 'Yes' } }
  } })
  try {
    const store = useTagKeyLabelsStore()
    const german = store.load('de')
    await started
    await store.load('en')
    finishGerman({ labels: { wheelchair: 'Rollstuhlzugänglichkeit' }, values: { '["wheelchair","yes"]': 'Ja' } })
    await german
    assert.equal(store.label('wheelchair'), 'Wheelchair access')
    assert.equal(store.valueLabel('wheelchair', 'yes'), 'Yes')
  } finally {
    globalThis.fetch = originalFetch
  }
})
