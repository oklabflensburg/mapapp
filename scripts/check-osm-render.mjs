import { readFileSync } from 'node:fs'
import { chromium } from '@playwright/test'

const APP_URL = 'http://localhost:5199'
// Must match DEFAULT_CENTER / DEFAULT_ZOOM in src/components/OsmMap.vue.
const CENTER = { lat: 54.791, lon: 9.437 }
const ZOOM = 14

// Web Mercator projection to world pixels, matching Leaflet's CRS.
function project(lon, lat, zoom) {
  const scale = 256 * 2 ** zoom
  const x = ((lon + 180) / 360) * scale
  const sinLat = Math.sin((lat * Math.PI) / 180)
  const y = (0.5 - Math.log((1 + sinLat) / (1 - sinLat)) / (4 * Math.PI)) * scale
  return [x, y]
}

function unproject(x, y, zoom) {
  const scale = 256 * 2 ** zoom
  const lon = (x / scale) * 360 - 180
  const n = Math.PI * (1 - (2 * y) / scale)
  const lat = (180 / Math.PI) * Math.atan(Math.sinh(n))
  return [lon, lat]
}

const [centerX, centerY] = project(CENTER.lon, CENTER.lat, ZOOM)
function toScreen(lon, lat, viewport) {
  const [x, y] = project(lon, lat, ZOOM)
  return [viewport.width / 2 + (x - centerX), viewport.height / 2 + (y - centerY)]
}

const geojson = JSON.parse(
  readFileSync(new URL('../public/data/flensburg.geojson', import.meta.url), 'utf8'),
)

// A click with no feature hit must deselect. Find a spot the geojson leaves
// empty inside the current viewport: cells of ~58px with no feature at all,
// so the click cannot land on a feature or within its hit tolerance.
function emptyViewportPoint(viewport, panelWidth) {
  const cell = 0.005
  const counts = new Map()
  for (const feature of geojson.features) {
    const flat = []
    const walk = (c) => {
      if (typeof c[0] === 'number') flat.push(c)
      else for (const next of c) walk(next)
    }
    walk(feature.geometry.coordinates)
    for (const [lon, lat] of flat) {
      const key = `${Math.floor(lon / cell)},${Math.floor(lat / cell)}`
      counts.set(key, (counts.get(key) ?? 0) + 1)
    }
  }
  const [west, north] = unproject(centerX - viewport.width / 2, centerY - viewport.height / 2, ZOOM)
  const [east, south] = unproject(centerX + viewport.width / 2, centerY + viewport.height / 2, ZOOM)
  let best = null
  let bestDistance = Infinity
  for (let lon = west + cell; lon < east - cell; lon += cell) {
    for (let lat = south + cell; lat < north - cell; lat += cell) {
      const key = `${Math.floor(lon / cell)},${Math.floor(lat / cell)}`
      if (counts.get(key)) {
        continue
      }
      const [x, y] = project(lon, lat, ZOOM)
      // Keep clear of the node panel overlaying the right side.
      if (x > centerX + viewport.width / 2 - panelWidth - 20) {
        continue
      }
      const distance = (x - centerX) ** 2 + (y - centerY) ** 2
      if (distance < bestDistance) {
        bestDistance = distance
        best = [lon, lat]
      }
    }
  }
  return best
}

const browser = await chromium.launch()
const page = await browser.newPage({ viewport: { width: 1280, height: 800 } })

const geojsonResponse = page.waitForResponse((r) => r.url().includes('flensburg.geojson'))
await page.goto(APP_URL, { waitUntil: 'domcontentloaded' })

const response = await geojsonResponse
console.log('geojson status:', response.status())

// Wait for the fetch + parse + first render to finish.
await page.waitForTimeout(4000)

const canvas = await page.$('canvas.leaflet-zoom-animated')
console.log('canvas present:', Boolean(canvas))
if (canvas) {
  const box = await canvas.boundingBox()
  console.log('canvas size:', box ? `${Math.round(box.width)}x${Math.round(box.height)}` : 'none')
}

// Click a few spots near the center; Flensburg at zoom 14 should hit a
// building or street within a few tries. Each hit opens the node panel.
const mapBox = await (await page.$('.leaflet-container')).boundingBox()
const viewport = { width: mapBox.width, height: mapBox.height }
let panelShown = false
let lastClick = null
for (const [dx, dy] of [[0, 0], [30, 20], [-40, -30], [60, -50]]) {
  lastClick = [mapBox.x + mapBox.width / 2 + dx, mapBox.y + mapBox.height / 2 + dy]
  await page.mouse.click(lastClick[0], lastClick[1])
  try {
    await page.waitForSelector('.p-drawer', { timeout: 2000 })
    panelShown = true
    break
  } catch {
    // try next spot
  }
}
console.log('node panel shown:', panelShown)
if (!panelShown) {
  await browser.close()
  process.exit(1)
}

const rows = await page.$$eval('.p-drawer .tag-table tr', (trs) => trs.length)
console.log('tag rows in panel:', rows)

// Clicking the selected element again must keep it selected (panel stays).
await page.mouse.click(lastClick[0], lastClick[1])
try {
  await page.waitForSelector('.p-drawer', { timeout: 1000 })
  console.log('panel stays open after re-clicking the selected element')
} catch {
  console.error('panel closed after re-clicking the selected element')
  await browser.close()
  process.exit(1)
}

// Zooming must not deselect either; the panel stays open. Zoom controls do
// not trigger map clicks, and the map is zoomed back in so the screen
// positions computed for ZOOM stay valid.
await page.click('.leaflet-control-zoom-out')
await page.waitForTimeout(600)
let stillOpen = true
try {
  await page.waitForSelector('.p-drawer', { timeout: 1000 })
} catch {
  stillOpen = false
}
console.log('panel open after zooming out:', stillOpen)
await page.click('.leaflet-control-zoom-in')
await page.waitForTimeout(600)
if (!stillOpen) {
  await browser.close()
  process.exit(1)
}

// Only clicking somewhere else (no feature hit) deselects and closes the
// panel.
const emptyPoint = emptyViewportPoint(viewport, 384)
let deselected = false
if (emptyPoint) {
  const [x, y] = toScreen(emptyPoint[0], emptyPoint[1], viewport)
  await page.mouse.click(mapBox.x + x, mapBox.y + y)
  try {
    await page.waitForSelector('.p-drawer', { state: 'hidden', timeout: 2000 })
    deselected = true
  } catch {
    deselected = false
  }
} else {
  console.error('no empty spot found in the viewport')
}
console.log('panel closed after clicking elsewhere:', deselected)

await browser.close()
process.exit(deselected ? 0 : 1)
