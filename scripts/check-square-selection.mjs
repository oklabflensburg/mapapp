// End-to-end check for selecting nodes inside a place=square area
// (Nordermarkt, Flensburg). Requires the dev server on port 5199:
//
//   npm run dev -- --port 5199 & node scripts/check-square-selection.mjs
//
// Verifies that clicking a node inside the square selects the node, not the
// surrounding square way/relation, once the zoom level renders points.

import { readFileSync } from 'node:fs'
import { chromium } from '@playwright/test'

const APP_URL = 'http://localhost:5199'
const VIEWPORT = { width: 1280, height: 800 }
// Must match DEFAULT_CENTER / DEFAULT_ZOOM in src/components/OsmMap.vue.
const CENTER = { lat: 54.791, lon: 9.437 }
const START_ZOOM = 14
// The fountain (Neptunbrunnen) inside the Nordermarkt square.
const TARGET = { lat: 54.7892845, lon: 9.4321815, id: 'n313361144' }

function project(lon, lat, zoom) {
  const scale = 256 * 2 ** zoom
  const x = ((lon + 180) / 360) * scale
  const sinLat = Math.sin((lat * Math.PI) / 180)
  const y = (0.5 - Math.log((1 + sinLat) / (1 - sinLat)) / (4 * Math.PI)) * scale
  return [x, y]
}

function toScreen(lon, lat, zoom) {
  const [centerX, centerY] = project(CENTER.lon, CENTER.lat, zoom)
  const [x, y] = project(lon, lat, zoom)
  return [VIEWPORT.width / 2 + (x - centerX), VIEWPORT.height / 2 + (y - centerY)]
}

const geojson = JSON.parse(
  readFileSync(new URL('../public/data/flensburg.geojson', import.meta.url), 'utf8'),
)
const target = geojson.features.find((f) => f.id === TARGET.id)
if (!target) {
  console.error('target node missing from geojson:', TARGET.id)
  process.exit(1)
}

const browser = await chromium.launch()
const page = await browser.newPage({ viewport: VIEWPORT })

await page.goto(APP_URL, { waitUntil: 'domcontentloaded' })
await page.waitForTimeout(4000)

async function selectedTags() {
  return page.$$eval('.p-drawer .tag-table tr', (rows) =>
    rows.map((row) => Array.from(row.children).map((cell) => cell.textContent.trim()).join('=')),
  )
}

// Starts at START_ZOOM; the loop below zooms to the level under test.
let currentZoom = START_ZOOM

async function clickAndReadTags(lon, lat, zoom) {
  const [x, y] = toScreen(lon, lat, zoom)
  await page.mouse.click(x, y)
  await page.waitForSelector('.p-drawer', { timeout: 3000 })
  return selectedTags()
}

async function closePanel() {
  await page.getByRole('button', { name: 'Cancel' }).click()
  await page.waitForSelector('.p-drawer', { state: 'hidden', timeout: 2000 })
}

async function selectSquareOrNode(lon, lat, zoom) {
  const tags = await clickAndReadTags(lon, lat, zoom)
  console.log(`selected tags at zoom ${zoom}:`, JSON.stringify(tags.slice(0, 4)))
  // The click lands on whichever node marker is nearest, so the exact node
  // can vary; the regression is only that the surrounding square must not
  // swallow the click.
  const squareSelected = tags.some((row) => row === 'place=square')
  console.log(`square selected at zoom ${zoom}:`, squareSelected)
  return { squareSelected }
}

// Point nodes must be rendered (and thus clickable) at every zoom, so the
// zoom level does not matter here: zoom 15 still exceeds the feature cap,
// where nodes inside the square used to be dropped.
let ok = true
for (const zoom of [15, 16]) {
  // Zoom controls keep the map center, so screen positions stay computable.
  while (currentZoom < zoom) {
    await page.click('.leaflet-control-zoom-in')
    await page.waitForTimeout(700)
    currentZoom++
  }
  while (currentZoom > zoom) {
    await page.click('.leaflet-control-zoom-out')
    await page.waitForTimeout(700)
    currentZoom--
  }
  const result = await selectSquareOrNode(TARGET.lon, TARGET.lat, zoom)
  ok = ok && !result.squareSelected
  await closePanel()
}

await browser.close()
process.exit(ok ? 0 : 1)
