import { chromium } from '@playwright/test'

const browser = await chromium.launch()
const page = await browser.newPage()

const geojsonResponse = page.waitForResponse((r) => r.url().includes('flensburg.geojson'))
await page.goto('http://localhost:5199/', { waitUntil: 'domcontentloaded' })

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
// building or street within a few tries.
const mapBox = await (await page.$('.leaflet-container')).boundingBox()
let popupShown = false
for (const [dx, dy] of [[0, 0], [30, 20], [-40, -30], [60, -50]]) {
  await page.mouse.click(mapBox.x + mapBox.width / 2 + dx, mapBox.y + mapBox.height / 2 + dy)
  try {
    await page.waitForSelector('.osm-tags', { timeout: 2000 })
    popupShown = true
    break
  } catch {
    // try next spot
  }
}
console.log('popup with tag table shown:', popupShown)
if (popupShown) {
  const rows = await page.$$eval('.osm-tags tr', (trs) => trs.length)
  console.log('popup tag rows:', rows)
}

await browser.close()
process.exit(popupShown ? 0 : 1)
