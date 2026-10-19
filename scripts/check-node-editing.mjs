// End-to-end check for the node editing feature. Requires the Symfony
// backend on port 8080 (docker compose up) and the dev server on port 5199:
//
//   npm run dev -- --port 5199 & node scripts/check-node-editing.mjs
//
// Verifies: edit-mode toggle -> clicking an OSM node opens the editor
// dialog -> adding a tag and saving persists the user node in the backend.

import { readFileSync } from 'node:fs'
import { chromium } from '@playwright/test'

const APP_URL = process.env.APP_URL ?? 'http://localhost:5199'
const API_URL = process.env.API_URL ?? 'http://localhost:8080'
const VIEWPORT = { width: 1280, height: 800 }
// Must match DEFAULT_CENTER / DEFAULT_ZOOM in src/components/OsmMap.vue.
const CENTER = { lat: 54.791, lon: 9.437 }
const ZOOM = 14
const TAG_VALUE = 'e2e-check-node-editing'

// Web Mercator projection to world pixels, matching Leaflet's CRS.
function project(lon, lat, zoom) {
  const scale = 256 * 2 ** zoom
  const x = ((lon + 180) / 360) * scale
  const sinLat = Math.sin((lat * Math.PI) / 180)
  const y = (0.5 - Math.log((1 + sinLat) / (1 - sinLat)) / (4 * Math.PI)) * scale
  return [x, y]
}

function nearestPoint(collection) {
  let best = null
  let bestDistance = Infinity
  for (const feature of collection.features) {
    if (feature.geometry.type !== 'Point' || feature.id === undefined) {
      continue
    }
    const [lon, lat] = feature.geometry.coordinates
    const distance = (lon - CENTER.lon) ** 2 + (lat - CENTER.lat) ** 2
    if (distance < bestDistance) {
      bestDistance = distance
      best = feature
    }
  }
  return best
}

// Bounding-box center of the building nearest to the map center, as click
// target for a way (non-point) feature.
function nearestBuildingCenter(collection) {
  let best = null
  let bestDistance = Infinity
  for (const feature of collection.features) {
    if (!('building' in (feature.properties ?? {}))) {
      continue
    }
    const flat = []
    const walk = (c) => {
      if (typeof c[0] === 'number') flat.push(c)
      else for (const next of c) walk(next)
    }
    walk(feature.geometry.coordinates)
    if (flat.length === 0) {
      continue
    }
    const lon = flat.reduce((sum, c) => sum + c[0], 0) / flat.length
    const lat = flat.reduce((sum, c) => sum + c[1], 0) / flat.length
    const distance = (lon - CENTER.lon) ** 2 + (lat - CENTER.lat) ** 2
    if (distance < bestDistance) {
      bestDistance = distance
      best = { lon, lat }
    }
  }
  return best
}

const geojson = JSON.parse(
  readFileSync(new URL('../public/data/flensburg.geojson', import.meta.url), 'utf8'),
)
const target = nearestPoint(geojson)
if (!target) {
  console.error('no point feature found in geojson')
  process.exit(1)
}

const [centerX, centerY] = project(CENTER.lon, CENTER.lat, ZOOM)
const [targetX, targetY] = project(target.geometry.coordinates[0], target.geometry.coordinates[1], ZOOM)
const clickX = VIEWPORT.width / 2 + (targetX - centerX)
const clickY = VIEWPORT.height / 2 + (targetY - centerY)

const browser = await chromium.launch()
const page = await browser.newPage({ viewport: VIEWPORT })

const geojsonResponse = page.waitForResponse((r) => r.url().includes('flensburg.geojson'))
await page.goto(APP_URL, { waitUntil: 'domcontentloaded' })
console.log('geojson status:', (await geojsonResponse).status())

// Wait for the fetch + parse + first render to finish.
await page.waitForTimeout(4000)

// Without edit mode, clicking the node shows the read-only popup.
await page.mouse.click(clickX, clickY)
let popupShown = false
try {
  await page.waitForSelector('.osm-tags', { timeout: 2000 })
  popupShown = true
} catch {
  // The exact pixel can miss by a few px; the edit-mode click below has the
  // same tolerance, so a miss here means a miss there too.
}
console.log('read-only popup without edit mode:', popupShown)
if (!popupShown) {
  console.error('click did not hit the target node')
  await browser.close()
  process.exit(1)
}

// Activate edit mode; the same click must now open the editor dialog.
await page.click('.edit-toggle')
await page.mouse.click(clickX, clickY)
await page.waitForSelector('.p-dialog', { timeout: 5000 })
console.log('editor dialog opened:', Boolean(await page.getByText('Edit node').count()))

// Add a custom tag and save. The dialog may already show rows for an
// existing user node; the new row is always the last one.
await page.getByRole('button', { name: 'Add tag' }).click()
await page.getByPlaceholder('Key').last().fill('check')
await page.getByPlaceholder('Value').last().fill(TAG_VALUE)
await page.getByRole('button', { name: 'Save' }).click()
await page.waitForSelector('.p-dialog', { state: 'hidden', timeout: 5000 })
console.log('dialog closed after save')

// The user node must be persisted in the backend.
const response = await fetch(`${API_URL}/api/nodes`)
const nodes = await response.json()
const saved = nodes.find((node) => node.tags.check === TAG_VALUE)
if (!saved) {
  console.error('saved user node not found via GET /api/nodes')
  await browser.close()
  process.exit(1)
}
console.log('persisted user node:', saved.providerNodeId, JSON.stringify(saved.tags))

// Clicking a way (e.g. a building) in edit mode must also open the editor.
const building = nearestBuildingCenter(geojson)
const [bx, by] = project(building.lon, building.lat, ZOOM)
const buildingClick = { x: VIEWPORT.width / 2 + (bx - centerX), y: VIEWPORT.height / 2 + (by - centerY) }
const mapElement = await page.$('.map')
if (!mapElement) {
  console.error('map container missing; url:', page.url())
  console.error('body:', (await page.evaluate(() => document.body.innerHTML)).slice(0, 1000))
  await browser.close()
  process.exit(1)
}
const mapBox = await mapElement.boundingBox()
let wayDialogShown = false
const spots = [
  [buildingClick.x, buildingClick.y],
  [mapBox.x + mapBox.width / 2 + 30, mapBox.y + mapBox.height / 2 + 20],
  [mapBox.x + mapBox.width / 2 - 40, mapBox.y + mapBox.height / 2 - 30],
]
for (const [x, y] of spots) {
  await page.mouse.click(x, y)
  try {
    await page.waitForSelector('.p-dialog', { timeout: 2000 })
    wayDialogShown = true
    break
  } catch {
    // try next spot
  }
}
console.log('way click opened editor:', wayDialogShown)
if (wayDialogShown) {
  await page.getByRole('button', { name: 'Cancel' }).click()
  await page.waitForSelector('.p-dialog', { state: 'hidden', timeout: 2000 })
}

await browser.close()
process.exit(wayDialogShown ? 0 : 1)
