// Fixture-based integration check; never changes backend data.
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { chromium, expect } from '@playwright/test'
const browser = await chromium.launch()
const page = await browser.newPage({ viewport: { width: 1280, height: 800 } })
const requests = []
let failCatalog = true
let failSave = false
const translations = {
  en: {
    labels: {footwalk: 'Sidewalk', cycle_lane: 'Cycle lane', parking: 'Parking', roadway: 'Roadway', width: 'Width', surface: 'Surface', lanes: 'Lanes'},
    values: {'["surface","asphalt"]': 'Asphalt', '["surface","paving_stones"]': 'Paving stones', '["surface","concrete"]': 'Concrete', '["width","<1"]': '< 1 m', '["width","1-2"]': '1–2 m', '["width",">2"]': '> 2 m'},
  },
  de: {
    labels: {footwalk: 'Gehweg', cycle_lane: 'Radfahrstreifen', parking: 'Parken', roadway: 'Fahrbahn', width: 'Breite', surface: 'Oberfläche', lanes: 'Fahrstreifen', 'aspect:1': 'Breiter Gehweg'},
    values: {'["surface","asphalt"]': 'Asphalt', '["surface","paving_stones"]': 'Pflastersteine', '["surface","concrete"]': 'Beton', '["width","<1"]': '< 1 m', '["width","1-2"]': '1–2 m', '["width",">2"]': '> 2 m'},
  },
}
const catalog = {
  wayVocabularies: { footway: [{ key: 'surface', values: ['asphalt', 'gravel'] }] },
  aspectTypes: [{ name: 'footwalk', attributes: [{ key: 'width', kind: 'range', values: ['<1', '>2'], labels: {'<1': '< 1 m', '>2': '> 2 m'} }] }],
}
const members = [
  { id: 1, provider: 'OSM', providerNodeId: 'n1', name: null, tags: [], latitude: 54.795, longitude: 9.427 },
  { id: 2, provider: 'OSM', providerNodeId: 'n2', name: null, tags: [], latitude: 54.795, longitude: 9.447 },
]
let group = { id: 10, name: 'Test street part', members, parents: [], area: null, tags: [], observations: [], approved: true }
await page.route('**/api/**', async route => {
  const path = new URL(route.request().url()).pathname
  if (path === '/api/me') return route.fulfill({ json: { user: { name: 'Editor', email: 'editor@test.example', roles: ['ROLE_EDITOR'] } } })
  if (path === '/api/editing-catalog') return route.fulfill({ status: failCatalog ? 503 : 200, json: failCatalog ? {error: 'editing_vocabulary_unavailable'} : catalog })
  if (path === '/api/default-observation-tags') return route.fulfill({ json: { tags: [{tag: 'global_only', values: []}] } })
  if (path.startsWith('/api/i18n/')) return route.fulfill({ json: translations[path.endsWith('/de') ? 'de' : 'en'] })
  if (path === '/api/nodes') {
    if (route.request().method() === 'GET') return route.fulfill({ json: [] })
    const input = route.request().postDataJSON()
    requests.push(input)
    return route.fulfill({ status: failSave ? 422 : 200, json: failSave ? {} : { ...input, id: 1, observations: input.tags, parents: [], children: [] } })
  }
  if (path === '/api/node-groups') {
    if (route.request().method() === 'POST') {
      const input = route.request().postDataJSON()
      requests.push(input)
      assert.equal('aspects' in input, false)
      if (failSave) return route.fulfill({status: 422, json: {error: 'invalid'}})
      group = { ...group, ...input, observations: input.tags }
      return route.fulfill({json: group})
    }
    return route.fulfill({ json: [group] })
  }
  if (path === '/api/route-preference-tags') return route.fulfill({ json: {tags: ['aspect:1'], labels: {'aspect:1': 'Wide sidewalk'}} })
  return route.fulfill({json: {}})
})
await page.route('**/data/flensburg.geojson', route => route.fulfill({ json: {
  type: 'FeatureCollection', features: [{ type: 'Feature', id: 'w123', properties: {highway: 'footway'}, geometry: {type: 'LineString', coordinates: [[9.42, 54.791], [9.45, 54.791]]} }],
} }))
try {
  await page.goto(process.env.APP_URL ?? 'http://localhost:5173')
  await expect(page.locator('.leaflet-overlay-pane canvas')).toBeVisible()
  // Select beyond the saved relation's endpoints to open the way editor.
  await page.mouse.click(460, 400)
  await expect(page.getByRole('button', {name: 'Retry', exact: true})).toBeVisible()
  failCatalog = false
  await page.getByRole('button', {name: 'Retry', exact: true}).click()
  await expect(page.getByRole('button', {name: 'Add observation', exact: true})).toHaveCount(0)
  await page.locator('.suggestion-select').click()
  await expect(page.getByRole('option')).toHaveText(['Surface'])
  await page.getByRole('option', {name: 'Surface', exact: true}).click()
  await expect(page.getByRole('textbox', {name: 'Key', exact: true})).toHaveAttribute('readonly', '')
  await page.getByRole('combobox', {name: 'Value', exact: true}).click()
  await expect(page.getByRole('option')).toHaveText(['Asphalt', 'gravel'])
  await page.getByRole('option', {name: 'gravel', exact: true}).click()
  failSave = true
  await page.getByRole('button', {name: 'Save', exact: true}).click()
  await expect(page.locator('.save-error')).toBeVisible()
  assert.deepEqual(requests.at(-1).tags, [{key: 'surface', value: 'gravel'}])
  failSave = false
  await page.getByRole('button', {name: 'Save', exact: true}).click()
  await expect(page.locator('.save-error')).toHaveCount(0)
  await page.getByRole('button', {name: 'Cancel', exact: true}).click()
  const availableTypes = catalog.aspectTypes
  catalog.aspectTypes = []
  await page.reload()
  await expect(page.locator('.leaflet-overlay-pane canvas')).toBeVisible()
  await page.mouse.click(640, 319)
  const partDrawer = page.locator('.p-drawer').filter({hasText: 'Test street part'})
  await expect(partDrawer).toBeVisible()
  await expect(partDrawer.locator('.cross-section-composer').getByRole('button', {name: 'Add observation', exact: true})).toBeDisabled()
  await expect(partDrawer.getByRole('status')).toHaveText('No observation types have been configured yet.')
  catalog.aspectTypes = availableTypes
  await partDrawer.getByRole('button', {name: 'Reload catalog', exact: true}).click()
  await expect(partDrawer.locator('.cross-section-composer').getByRole('button', {name: 'Add observation', exact: true})).toBeEnabled()
  await partDrawer.locator('.cross-section-composer').getByRole('button', {name: 'Add observation', exact: true}).click()
  await page.getByRole('combobox', {name: 'Width', exact: true}).click()
  await page.getByRole('option', {name: '< 1 m', exact: true}).last().click()
  // Observations are ordered by their addition; no per-row move buttons.
  await partDrawer.locator('.cross-section-composer').getByRole('button', {name: 'Add observation', exact: true}).click()
  await page.getByRole('combobox', {name: 'Width', exact: true}).nth(1).click()
  await page.getByRole('option', {name: '> 2 m', exact: true}).last().click()
  await partDrawer.getByRole('button', {name: 'Save', exact: true}).click()
  await expect(partDrawer.getByRole('button', {name: 'Save', exact: true})).not.toBeDisabled()
  assert.deepEqual(requests.at(-1).tags, [{key: 'way.footwalk.1.width', value: '<1'}, {key: 'way.footwalk.2.width', value: '>2'}])
  await page.reload()
  await expect(page.locator('.leaflet-overlay-pane canvas')).toBeVisible()
  await page.mouse.click(640, 319)
  await expect(page.getByRole('combobox', {name: 'Width', exact: true}).first()).toContainText('< 1 m')
  // Exercise the committed five-aspect example in the normal composer.
  const exampleCatalog = JSON.parse(await readFile(new URL('../../backend/config/examples/aspect-catalog.json', import.meta.url), 'utf8'))
  const examplePart = JSON.parse(await readFile(new URL('../../backend/config/examples/street-part.json', import.meta.url), 'utf8'))
  catalog.aspectTypes = exampleCatalog.types
  group = {...group, tags: examplePart.tags, observations: examplePart.tags}
  await page.reload()
  await expect(page.locator('.leaflet-overlay-pane canvas')).toBeVisible()
  await page.mouse.click(640, 319)
  const exampleDrawer = page.locator('.p-drawer').filter({hasText: 'Test street part'})
  const widths = exampleDrawer.getByRole('combobox', {name: 'Width', exact: true})
  await expect(widths).toHaveCount(5)
  await expect(widths.first()).toContainText('> 2 m')
  await expect(widths.nth(4)).toContainText('1–2 m')
  await widths.first().click()
  await page.getByRole('option', {name: '< 1 m', exact: true}).last().click()
  failSave = true
  await exampleDrawer.getByRole('button', {name: 'Save', exact: true}).click()
  await expect(exampleDrawer.locator('.save-error')).toBeVisible()
  await expect(widths.first()).toContainText('< 1 m')
  failSave = false
  await exampleDrawer.getByRole('button', {name: 'Save', exact: true}).click()
  await expect(exampleDrawer.getByRole('button', {name: 'Save', exact: true})).not.toBeDisabled()
  assert.equal(requests.at(-1).tags.find(tag => tag.key === 'way.footwalk.1.width').value, '<1')
  assert.equal(requests.at(-1).tags.find(tag => tag.key === 'way.footwalk.5.width').value, '1-2')
  assert.equal(requests.at(-1).tags.find(tag => tag.key === 'way.footwalk.5.surface').value, 'paving_stones')
  await page.reload()
  await expect(page.locator('.leaflet-overlay-pane canvas')).toBeVisible()
  await page.mouse.click(640, 319)
  await expect(page.getByRole('combobox', {name: 'Width', exact: true}).first()).toContainText('< 1 m')
  await expect(page.getByRole('combobox', {name: 'Width', exact: true}).nth(4)).toContainText('1–2 m')
  // Approval of width alone locks that value and its position, not surface.
  group.observations = group.tags.filter(tag => tag.key !== 'way.footwalk.1.width')
  await page.reload()
  await expect(page.locator('.leaflet-overlay-pane canvas')).toBeVisible()
  await page.mouse.click(640, 319)
  const locked = page.locator('.cross-section-composer .observation').first()
  await expect(locked.getByRole('combobox', {name: 'Width', exact: true})).toHaveAttribute('aria-disabled', 'true')
  await expect(locked.getByRole('combobox', {name: 'Surface', exact: true})).not.toHaveAttribute('aria-disabled', 'true')
  await expect(locked.getByRole('button', {name: 'Remove observation', exact: true})).toBeDisabled()
  await page.goto(new URL('/profile', page.url()).href)
  await expect(page.getByText('Wide sidewalk', {exact: true})).toBeVisible()
  await page.evaluate(() => localStorage.setItem('accessapp.settings.locale', 'de'))
  await page.reload()
  await expect(page.getByText('Breiter Gehweg', {exact: true})).toBeVisible()
  await page.goto(new URL('/', page.url()).href)
  await expect(page.locator('.leaflet-overlay-pane canvas')).toBeVisible()
  await page.mouse.click(640, 319)
  const germanComposer = page.locator('.cross-section-composer')
  // The type column carries a short "Typ" label instead of a legend.
  await expect(germanComposer.getByText('Typ', {exact: true}).first()).toBeVisible()
  await expect(germanComposer.getByRole('combobox', {name: 'Typ', exact: true}).first()).toContainText('Gehweg')
  await expect(germanComposer.getByRole('combobox', {name: 'Typ', exact: true}).nth(1)).toContainText('Radfahrstreifen')
  await expect(germanComposer.getByRole('combobox', {name: 'Breite', exact: true}).first()).toContainText('< 1 m')
  await expect(germanComposer.getByRole('combobox', {name: 'Oberfläche', exact: true}).nth(4)).toContainText('Pflastersteine')
  await expect(germanComposer.getByRole('button', {name: 'Beobachtung hinzufügen', exact: true})).toBeVisible()
  await expect(germanComposer.getByText(/Aspekt|Aspect/)).toHaveCount(0)
  assert.equal(group.tags.find(tag => tag.key === 'way.footwalk.1.width').value, '<1')
  console.log('PASS: empty aspect catalog recovery, controlled way choices, error/retry, stale draft, ordered cross-section save/reload, profile label, independent five-observation example, English/German controls and catalog labels')
} finally { await browser.close() }
