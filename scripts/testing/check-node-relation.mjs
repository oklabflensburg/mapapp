// Deterministic browser check; requires npm run dev. API, session, and OSM
// fixtures are intercepted so this check never writes to the development
// database. Verifies: edit mode connects two placed nodes with a dashed
// preview line, saving creates the relation rendered as the solid line
// through its members at a minimal, zoom-independent on-screen width,
// clicking the relation's line opens its editor while browsing, and a
// reload renders the relation's member line from the listed relations.
import assert from 'node:assert/strict'
import { chromium, expect } from '@playwright/test'

const browser = await chromium.launch()
const page = await browser.newPage({ viewport: { width: 1280, height: 800 } })
const center = { lat: 54.791, lng: 9.437 }
const fixture = {
  type: 'FeatureCollection',
  features: [
    // An in-viewport street keeps the OSM canvas layer alive; it runs well
    // south of the sampled member-line pixels so it cannot interfere with
    // the amber checks.
    { type: 'Feature', id: 'w-street-south', properties: { name: 'Street South', highway: 'residential' }, geometry: { type: 'LineString', coordinates: [[9.42, 54.792], [9.45, 54.792]] } },
  ],
}

// The two member points in page pixels at the initial zoom 14.
const a = { x: 380, y: 270 }
const b = { x: 450, y: 310 }

const nodes = []
const groups = []
const groupRequests = []
let nextNodeId = 1
let nextGroupId = 1

const tagListValue = (tags, key) => (tags ?? []).find(tag => tag.key === key)?.value

await page.route('**/data/flensburg.geojson', route => route.fulfill({ json: fixture }))
// Editing requires an editor session; the check never touches the backend.
await page.route('**/api/me', route => route.fulfill({
  json: { user: { name: 'Check Editor', email: 'check-editor@example.com', roles: ['ROLE_EDITOR'] } },
}))
await page.route('**/api/nodes', async route => {
  if (route.request().method() === 'GET') return route.fulfill({ json: [] })
  const input = route.request().postDataJSON()
  const node = {
    id: nextNodeId++, provider: 'local', parentIds: [],
    createdAt: new Date().toISOString(), updatedAt: new Date().toISOString(),
    tags: [], observations: [], parents: [], children: [], ...input,
  }
  nodes.push(node)
  return route.fulfill({ status: 200, json: node })
})
await page.route('**/api/node-groups', async route => {
  if (route.request().method() === 'GET') return route.fulfill({ json: groups })
  const input = route.request().postDataJSON()
  groupRequests.push(input)
  const group = {
    id: nextGroupId++, name: input.name ?? null, members: [], area: null,
    tags: input.tags ?? [], observations: [],
    createdAt: new Date().toISOString(), updatedAt: new Date().toISOString(),
  }
  for (const reference of input.members ?? []) {
    const node = nodes.find(candidate => candidate.id === reference.id)
    if (node) {
      group.members.push({
        id: node.id, provider: node.provider, providerNodeId: node.providerNodeId,
        name: tagListValue(node.tags, 'name') ?? null, tags: node.tags ?? [],
        latitude: node.latitude, longitude: node.longitude,
      })
    }
  }
  groups.push(group)
  return route.fulfill({ status: 201, json: group })
})

const add = page.getByRole('button', { name: 'Add node', exact: true })
const dialog = page.getByRole('form', { name: 'Placed nodes', exact: true })
const save = page.locator('.p-drawer').getByRole('button', { name: 'Save', exact: true })

async function ready() {
  await expect(page.locator('.leaflet-overlay-pane canvas')).toBeVisible()
  await expect(add).toBeVisible()
}

async function closeEditor() {
  await page.locator('.p-drawer').getByRole('button', { name: 'Cancel', exact: true }).click()
  await expect(page.locator('.p-drawer')).toBeHidden()
}

// The amber relation stroke (#d97706) is drawn onto the shared overlay
// canvas, so the check samples the pixels around a page position for it.
async function amberAt(x, y) {
  return page.evaluate(({ x, y }) => {
    const canvas = document.querySelector('.leaflet-overlay-pane canvas')
    if (!canvas) {
      return false
    }
    const rect = canvas.getBoundingClientRect()
    const data = canvas.getContext('2d').getImageData(
      Math.max(0, Math.floor((x - rect.left - 9) * canvas.width / rect.width)),
      Math.max(0, Math.floor((y - rect.top - 9) * canvas.height / rect.height)),
      Math.ceil(19 * canvas.width / rect.width),
      Math.ceil(19 * canvas.height / rect.height),
    ).data
    for (let i = 0; i < data.length; i += 4) {
      const [r, g, bl, alpha] = [data[i], data[i + 1], data[i + 2], data[i + 3]]
      if (alpha > 150 && r > 150 && g > 80 && bl < 90 && r - bl > 90) {
        return true
      }
    }
    return false
  }, { x, y })
}

// A point on the member line: the fraction from a to b, shifted and scaled
// like Leaflet when zooming around the map center (640, 400).
function memberPoint(fraction, zoom = 14) {
  const x = a.x + (b.x - a.x) * fraction
  const y = a.y + (b.y - a.y) * fraction
  const shrink = 2 ** (zoom - 14)
  return { x: 640 + (x - 640) * shrink, y: 400 + (y - 400) * shrink }
}

const amberAlong = async (fractions, zoom = 14) => {
  const samples = await Promise.all(
    fractions.map(fraction => {
      const point = memberPoint(fraction, zoom)
      return amberAt(point.x, point.y)
    }),
  )
  return samples.every(Boolean)
}

try {
  await page.goto(process.env.APP_URL ?? 'http://localhost:5173')
  await ready()

  // Edit mode places the relation's members; two placed nodes are connected
  // by the dashed preview line.
  await add.click()
  await expect(add).toHaveAttribute('aria-pressed', 'true')
  await page.mouse.click(a.x, a.y)
  await expect(dialog).toContainText('1 nodes placed')
  await page.mouse.click(b.x, b.y)
  await expect(dialog).toContainText('2 nodes placed')
  await expect.poll(() => {
    // Dashed: some point along the line is amber (a solid check could pass
    // while the dash covers a sampled point).
    return Promise.all([0.25, 0.5, 0.75].map(fraction => {
      const point = memberPoint(fraction)
      return amberAt(point.x, point.y)
    })).then(samples => samples.some(Boolean))
  }).toBe(true)

  // Saving the placed nodes creates the relation, which renders as the solid
  // line through its members.
  await dialog.getByLabel('Name', { exact: true }).fill('Hochallee north part')
  await save.click()
  await expect(dialog).toBeHidden()
  await expect(page.locator('.p-drawer')).toBeVisible()
  assert.equal(groupRequests.length, 1, 'saving two placed nodes creates one relation')
  assert.deepEqual(
    groupRequests[0].members.map(member => member.id),
    nodes.map(node => node.id),
    'the relation contains both placed nodes',
  )
  await expect.poll(() => amberAlong([0.3, 0.5, 0.7])).toBe(true)

  // While browsing, clicking the relation's line selects the relation and
  // opens its editor (its header is the relation's name).
  await closeEditor()
  const mid = memberPoint(0.5)
  await page.mouse.click(mid.x, mid.y)
  const relationDrawer = page.locator('.p-drawer').filter({ hasText: 'Hochallee north part' })
  await expect(relationDrawer).toBeVisible()
  // The members section lists both nodes without a heading or count line.
  await expect(relationDrawer.getByRole('region', { name: 'Nodes', exact: true }).locator('.member-item')).toHaveCount(2)
  await relationDrawer.getByRole('button', { name: 'Cancel', exact: true }).click()
  await expect(page.locator('.p-drawer')).toBeHidden()

  // The member line's width is constant on screen: zooming out keeps the
  // relation visible instead of thinning with the map's meters.
  const zoomOut = page.locator('.leaflet-control-zoom-out')
  await zoomOut.click()
  await page.waitForTimeout(600)
  await zoomOut.click()
  await expect.poll(() => amberAlong([0.3, 0.5, 0.7], 12)).toBe(true)
  // After a reload the relation renders from the listed relations.
  await page.reload()
  await ready()
  await expect.poll(() => amberAlong([0.3, 0.5, 0.7])).toBe(true)
  console.log('PASS: relation creation, dashed preview, solid member line, line click, zoom-independent width, reload rendering')
} finally {
  await browser.close()
}
