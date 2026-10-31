// Checks that ?lat=&lon=&zoom= pins the map start position and skips
// geolocation (the admin node preview deep link).
import assert from 'node:assert/strict'
import { chromium, expect } from '@playwright/test'

const browser = await chromium.launch()
const appUrl = process.env.APP_URL ?? 'http://localhost:5173'

// Position to pin via the URL, distinct from DEFAULT_CENTER (54.791, 9.437)
// and from the granted geolocation fix (Berlin), so the loaded tiles tell
// the three cases apart.
const lat = 54.78
const lon = 9.42
const zoom = 17

function expectedTile(lat, lon, zoom) {
  const x = Math.floor(((lon + 180) / 360) * 2 ** zoom)
  const latRad = (lat * Math.PI) / 180
  const y = Math.floor(((1 - Math.log(Math.tan(latRad) + 1 / Math.cos(latRad)) / Math.PI) / 2) * 2 ** zoom)
  return { x, y }
}

const target = expectedTile(lat, lon, zoom)
const berlin = expectedTile(52.52, 13.405, zoom)
const center = expectedTile(54.791, 9.437, zoom)
console.log('expected tile of deep link:', target, 'berlin:', berlin, 'default center:', center)

const context = await browser.newContext({
  viewport: { width: 1280, height: 800 },
  permissions: ['geolocation'],
  geolocation: { latitude: 52.52, longitude: 13.405 },
})

const page = await context.newPage()
await page.route('**/data/flensburg.geojson', route =>
  route.fulfill({ json: { type: 'FeatureCollection', features: [] } }))
await page.route('**/api/nodes', route => route.fulfill({ json: [] }))

const tileUrls = []
page.on('request', request => {
  const match = request.url().match(/tile\.openstreetmap\.org\/(\d+)\/(\d+)\/(\d+)\.png/)
  if (match) tileUrls.push({ z: +match[1], x: +match[2], y: +match[3] })
})

await page.goto(`${appUrl}/?lat=${lat}&lon=${lon}&zoom=${zoom}`)
await expect(page.locator('.leaflet-container')).toBeVisible()
await page.waitForTimeout(1500)

const loadedTiles = tileUrls.filter(t => t.z === zoom)
console.log(`z${zoom} tiles loaded:`, loadedTiles)

// The pinned position's tile must be among the loaded tiles, and neither the
// geolocation fix nor the default center may be the map's view.
const sawTarget = loadedTiles.some(t => t.x === target.x && t.y === target.y)
const sawBerlin = loadedTiles.some(t => t.x === berlin.x && t.y === berlin.y)
const sawDefaultCenter = loadedTiles.some(t => t.x === center.x && t.y === center.y)
assert.ok(sawTarget, `expected the pinned tile ${JSON.stringify(target)} among loaded tiles`)
assert.ok(!sawBerlin, 'geolocation must be skipped when the URL pins the position')
assert.ok(!sawDefaultCenter, 'the default center must not be used when the URL pins the position')

// No locating chip: the geolocation status flow never starts.
const status = await page.locator('.map-status').count()
assert.equal(status, 0, 'no map status chip expected')

// Without the deep link the app still geolocates (default path untouched).
const page2 = await context.newPage()
const tileUrls2 = []
await page2.route('**/data/flensburg.geojson', route =>
  route.fulfill({ json: { type: 'FeatureCollection', features: [] } }))
await page2.route('**/api/nodes', route => route.fulfill({ json: [] }))
page2.on('request', request => {
  const match = request.url().match(/tile\.openstreetmap\.org\/(\d+)\/(\d+)\/(\d+)\.png/)
  if (match) tileUrls2.push({ z: +match[1], x: +match[2], y: +match[3] })
})
await page2.goto(appUrl)
await expect(page2.locator('.leaflet-container')).toBeVisible()
await page2.waitForTimeout(2000)
const berlin16 = expectedTile(52.52, 13.405, 16)
const sawBerlin2 = tileUrls2.filter(t => t.z === 16).some(t => t.x === berlin16.x && t.y === berlin16.y)
console.log('control run saw Berlin z16 tiles:', sawBerlin2)
assert.ok(sawBerlin2, 'without the deep link the map should center on the geolocation fix')

await browser.close()
console.log('deep-link preview check passed')
