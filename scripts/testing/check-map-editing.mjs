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
    labels: {sidewalk: 'Sidewalk', cycleway: 'Cycle lane', parking: 'Parking', roadway: 'Roadway', width: 'Width', surface: 'Surface', lanes: 'Lanes'},
    values: {'["surface","asphalt"]': 'Asphalt', '["surface","paving_stones"]': 'Paving stones', '["surface","concrete"]': 'Concrete', '["width","<1"]': '< 1 m', '["width","1-2"]': '1–2 m', '["width",">2"]': '> 2 m'},
  },
  de: {
    labels: {sidewalk: 'Gehweg', cycleway: 'Radfahrstreifen', parking: 'Parken', roadway: 'Fahrbahn', width: 'Breite', surface: 'Oberfläche', lanes: 'Fahrstreifen', 'aspect.1': 'Breiter Gehweg'},
    values: {'["surface","asphalt"]': 'Asphalt', '["surface","paving_stones"]': 'Pflastersteine', '["surface","concrete"]': 'Beton', '["width","<1"]': '< 1 m', '["width","1-2"]': '1–2 m', '["width",">2"]': '> 2 m'},
  },
}
const catalog = {
  wayVocabularies: { footway: [{ key: 'surface', values: ['asphalt', 'gravel'] }] },
  aspectTypes: [{ name: 'sidewalk', attributes: [{ key: 'width', kind: 'range', values: ['<1', '>2'], labels: {'<1': '< 1 m', '>2': '> 2 m'} }] }],
}
const members = [
  { id: 1, provider: 'OSM', providerNodeId: 'n1', name: null, tags: [], latitude: 54.795, longitude: 9.427 },
  { id: 2, provider: 'OSM', providerNodeId: 'n2', name: null, tags: [], latitude: 54.795, longitude: 9.447 },
]
// One observation = one relation (specs/map-editing/observation-relations/
// spec.md): every fixture relation carries exactly one tag.
let relations = [{ id: 10, name: 'Test street part', members: members.map(({ latitude, longitude, ...rest }) => ({ ...rest, latitude, longitude })), parents: [], area: null, tags: [], observations: [], approved: false }]
let nextRelationId = 11
// Store members as the API returns them: provider-tagged node summaries.
const memberSummary = (reference, index) => ({ id: index + 1, provider: 'OSM', providerNodeId: reference.providerNodeId ?? `n${index}`, name: null, tags: [], latitude: reference.latitude ?? 54.795, longitude: reference.longitude ?? 9.427 })
await page.route('**/api/**', async route => {
  const path = new URL(route.request().url()).pathname
  if (path === '/api/me') return route.fulfill({ json: { user: { name: 'Editor', email: 'editor@test.example', roles: ['ROLE_EDITOR'] } } })
  if (path === '/api/editing-catalog') return route.fulfill({ status: failCatalog ? 503 : 200, json: failCatalog ? {error: 'editing_vocabulary_unavailable'} : catalog })
  if (path.startsWith('/api/i18n/')) return route.fulfill({ json: translations[path.endsWith('/de') ? 'de' : 'en'] })
  if (path === '/api/nodes') {
    if (route.request().method() === 'GET') return route.fulfill({ json: [] })
    return route.fulfill({ status: 422, json: {} })
  }
  if (path === '/api/node-groups') {
    if (route.request().method() === 'POST') {
      const input = route.request().postDataJSON()
      requests.push(input)
      if (failSave) return route.fulfill({status: 422, json: {error: 'invalid'}})
      // The relations model contract: at most one tag per save.
      assert.ok(input.tags === undefined || input.tags.length <= 1)
      const existing = 'id' in input && input.id ? relations.find(relation => relation.id === input.id) : undefined
      let relation
      if (existing) {
        relation = { ...existing, ...(input.members ? { members: input.members.map(memberSummary) } : {}), tags: input.tags ?? existing.tags, observations: input.tags ?? existing.observations }
        relations = relations.map(entry => entry.id === relation.id ? relation : entry)
      } else {
        relation = { id: nextRelationId++, name: null, members: input.members.map(memberSummary), parents: [], area: null, tags: input.tags ?? [], observations: input.tags ?? [], approved: false }
        relations = [relation, ...relations]
      }
      return route.fulfill({ json: relation })
    }
    return route.fulfill({ json: relations })
  }
  if (path === '/api/route-preference-tags') return route.fulfill({ json: {tags: ['aspect.1'], labels: {'aspect.1': 'Wide sidewalk'}} })
  return route.fulfill({json: {}})
})
await page.route('**/data/flensburg.geojson', route => route.fulfill({ json: {
  type: 'FeatureCollection', features: [{ type: 'Feature', id: 'w123', properties: {highway: 'footway'}, geometry: {type: 'LineString', coordinates: [[9.42, 54.791], [9.45, 54.791]]} }], freeform: true,
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
  // A whole-way observation is a single-member relation anchored on the way.
  const wayObservationSave = requests.at(-1)
  assert.deepEqual(wayObservationSave.tags, [{key: 'surface', value: 'gravel'}])
  assert.equal(wayObservationSave.members.length, 1)
  assert.equal(wayObservationSave.members[0].providerNodeId, 'w123')
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
  // A new observation is collected through the wizard: the element type,
  // its position across the way, then one select per catalog attribute.
  await partDrawer.locator('.cross-section-composer').getByRole('button', {name: 'Add observation', exact: true}).click()
  await expect(partDrawer.getByText('What do you want to observe?', {exact: true})).toBeVisible()
  await partDrawer.getByRole('button', {name: 'Sidewalk', exact: true}).click()
  await expect(partDrawer.getByText('At which position?', {exact: true})).toBeVisible()
  await partDrawer.getByRole('button', {name: 'Center', exact: true}).click()
  await page.getByRole('combobox', {name: 'Width', exact: true}).click()
  await page.getByRole('option', {name: '< 1 m', exact: true}).last().click()
  // Adding the drafted attributes leaves one row per element side.
  await partDrawer.locator('.cross-section-composer').getByRole('button', {name: 'Add observation', exact: true}).click()
  await expect(partDrawer.getByRole('combobox', {name: 'Side', exact: true}).first()).toContainText('Center')
  failSave = true
  await partDrawer.getByRole('button', {name: 'Save', exact: true}).click()
  await expect(partDrawer.locator('.save-error')).toBeVisible()
  failSave = false
  await partDrawer.getByRole('button', {name: 'Save', exact: true}).click()
  // The failed pass and its retry each save the single drafted relation; with
  // the way observation's failed pass and retry, four requests so far.
  await expect.poll(() => requests.length, {timeout: 5000}).toBe(4)
  await expect(partDrawer.getByRole('button', {name: 'Save', exact: true})).not.toBeDisabled()
  // One observation = one relation: the drafted value saves as its own relation.
  assert.deepEqual(requests.at(-1).tags, [{key: 'sidewalk.center.width', value: '<1'}])
  // Regression (parallel observations): a parallel observation relation from
  // another editor over the same range keeps its identity: the relation
  // editor still lists both observations side by side — the panel edits the
  // range, not one relation, so neither observation disappears.
  relations = [{ id: 13, name: null, members: members.map(member => ({ ...member })), parents: [], area: null, tags: [{key: 'sidewalk.center.width', value: '>2'}], observations: [{key: 'sidewalk.center.width', value: '>2'}], approved: false }, ...relations]
  requests.length = 0
  await page.reload()
  await expect(page.locator('.leaflet-overlay-pane canvas')).toBeVisible()
  await page.mouse.click(640, 319)
  const parallelDrawer = page.locator('.p-drawer')
  await expect(parallelDrawer).toBeVisible()
  const parallelWidths = parallelDrawer.getByRole('combobox', {name: 'Width', exact: true})
  await expect(parallelWidths).toHaveCount(2)
  const parallelValues = await parallelWidths.allTextContents()
  assert.ok(parallelValues.some(value => value.includes('< 1 m')), `missing '< 1 m' in ${JSON.stringify(parallelValues)}`)
  assert.ok(parallelValues.some(value => value.includes('> 2 m')), `missing '> 2 m' in ${JSON.stringify(parallelValues)}`)
  // Each existing observation keeps its identity: the side cannot be flipped
  // into a different observation (observing another side adds a new row).
  const parallelSides = parallelDrawer.getByRole('combobox', {name: 'Side', exact: true})
  await expect(parallelSides).toHaveCount(2)
  for (const side of await parallelSides.all()) {
    await expect(side).toHaveAttribute('aria-disabled', 'true')
  }
  // Saving unchanged parallel observations echoes exactly both relations —
  // nothing is re-keyed and nothing is deleted.
  await parallelDrawer.getByRole('button', {name: 'Save', exact: true}).click()
  await expect.poll(() => requests.length, {timeout: 5000}).toBe(2)
  const echoed = requests.slice().sort((a, b) => a.id - b.id)
  assert.deepEqual(echoed.map(request => request.id), [12, 13])
  assert.deepEqual(echoed[0].tags, [{key: 'sidewalk.center.width', value: '<1'}])
  assert.deepEqual(echoed[1].tags, [{key: 'sidewalk.center.width', value: '>2'}])
  // A pending range observation edited alone: the placed range keeps one
  // pending observation relation; editing updates exactly its relation.
  relations = [{ id: 10, name: 'Test street part', members: members.map(member => ({ ...member })), parents: [], area: null, tags: [{key: 'sidewalk.center.width', value: '<1'}], observations: [{key: 'sidewalk.center.width', value: '<1'}], approved: false }]
  requests.length = 0
  await page.reload()
  await expect(page.locator('.leaflet-overlay-pane canvas')).toBeVisible()
  await page.mouse.click(640, 319)
  const drawer = page.locator('.p-drawer').filter({hasText: 'Test street part'})
  await expect(drawer).toBeVisible()
  await page.getByRole('combobox', {name: 'Width', exact: true}).first().click()
  await page.getByRole('option', {name: '> 2 m', exact: true}).last().click()
  await drawer.getByRole('button', {name: 'Save', exact: true}).click()
  await expect.poll(() => requests.length, {timeout: 5000}).toBe(1)
  assert.deepEqual(requests.at(-1).tags, [{key: 'sidewalk.center.width', value: '>2'}])
  assert.equal(requests.at(-1).id, 10)
  // Approval freezes the observation relation: the approved aspect shows in
  // the tag table, its composer row is locked and cannot be edited.
  relations = [{ id: 10, name: 'Test street part', members: members.map(member => ({ ...member })), parents: [], area: null, tags: [{key: 'sidewalk.center.width', value: '>2'}], observations: [], approved: true }]
  requests.length = 0
  await page.reload()
  await expect(page.locator('.leaflet-overlay-pane canvas')).toBeVisible()
  await page.mouse.click(640, 319)
  const approvedDrawer = page.locator('.p-drawer').filter({hasText: 'Test street part'})
  await expect(approvedDrawer).toBeVisible()
  await expect(page.getByRole('combobox', {name: 'Width', exact: true})).toHaveCount(0)
  await page.goto(new URL('/profile', page.url()).href)
  await expect(page.getByText('Wide sidewalk', {exact: true})).toBeVisible()
  await page.evaluate(() => localStorage.setItem('accessapp.settings.locale', 'de'))
  await page.reload()
  await expect(page.getByText('Breiter Gehweg', {exact: true})).toBeVisible()
  await page.goto(new URL('/', page.url()).href)
  await expect(page.locator('.leaflet-overlay-pane canvas')).toBeVisible()
  await page.mouse.click(640, 319)
  const germanComposer = page.locator('.cross-section-composer')
  // The approved observation reads in the tag table; the wizard collects a
  // new observation in German, including the approved-attribute lock.
  await germanComposer.getByRole('button', {name: 'Beobachtung hinzufügen', exact: true}).click()
  await expect(germanComposer.getByText('Was möchtest du beobachten?', {exact: true})).toBeVisible()
  await germanComposer.getByRole('button', {name: 'Gehweg', exact: true}).click()
  await expect(germanComposer.getByText('An welcher Position?', {exact: true})).toBeVisible()
  await germanComposer.getByRole('button', {name: 'Mitte', exact: true}).click()
  // The center sidewalk's only attribute is already approved, so the wizard
  // has nothing left to offer there; going back picks another position.
  await expect(germanComposer.getByText('Alle Eigenschaften dieses Elements sind bereits freigegeben.', {exact: true})).toBeVisible()
  await germanComposer.getByRole('button', {name: 'Zurück', exact: true}).click()
  await germanComposer.getByRole('button', {name: 'Links', exact: true}).click()
  await germanComposer.getByRole('combobox', {name: 'Breite', exact: true}).click()
  await page.getByRole('option', {name: '< 1 m', exact: true}).last().click()
  await expect(germanComposer.getByRole('button', {name: 'Beobachtung hinzufügen', exact: true})).toBeEnabled()
  console.log('PASS: empty aspect catalog recovery, controlled way choices as anchored relation, error/retry, observation wizard, one observation = one relation, per-observation editing and approval lock, profile label, German controls and catalog labels')
} finally { await browser.close() }
