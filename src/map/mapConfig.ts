import type * as L from 'leaflet'

export const DEFAULT_CENTER: L.LatLngExpression = [54.791, 9.437] // Flensburg
export const DEFAULT_ZOOM = 14
export const LOCATION_ZOOM = 16

export const OSM_DATA_URL = '/data/flensburg.geojson'
// Above this many visible features, buildings and points are dropped until
// zooming in; streets, parks and landuse stay visible at every zoom.
export const MAX_VISIBLE_FEATURES = 20_000
export const MOVE_RENDER_DEBOUNCE_MS = 150

export const OSM_TILE_URL = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png'
export const OSM_TILE_MAX_ZOOM = 19
export const OSM_TILE_ATTRIBUTION =
  '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
