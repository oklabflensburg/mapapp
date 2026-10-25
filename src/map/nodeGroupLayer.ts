import * as L from 'leaflet'
import type { NodeGroup } from '../stores/nodeGroups'

export interface GroupAreaPoint {
  latitude: number
  longitude: number
}

const AREA_LINE_OPTIONS: L.PolylineOptions = {
  color: '#d97706',
  weight: 2.5,
  opacity: 0.9,
  fillColor: '#d97706',
  fillOpacity: 0.08,
}

// The selected relation's area stands out, so the whole street part reads as
// the selected element.
const SELECTED_AREA_LINE_OPTIONS: L.PolylineOptions = {
  ...AREA_LINE_OPTIONS,
  weight: 4,
  fillOpacity: 0.25,
}

// How the areas behave in a render: which group's area is highlighted, and
// whether clicking an area selects its relation (browsing) or passes through
// to the nodes and features beneath (create, relation and routing modes).
export interface NodeGroupRenderOptions {
  selectedGroupId?: number
  onSelect?: (group: NodeGroup) => void
}

// Dashed preview of the street part being collected in relation mode.
const PREVIEW_LINE_OPTIONS: L.PolylineOptions = {
  color: '#d97706',
  weight: 2.5,
  opacity: 0.9,
  dashArray: '6 6',
  interactive: false,
}

// Half the preview corridor's width, matching the backend's corridor width
// (see backend/src/Service/StreetPartArea.php) so the live preview matches
// the area saved for the relation.
const PREVIEW_CORRIDOR_RADIUS_METERS = 2

// The preview corridor: filled like a saved area so the collected members
// read as a group instantly, dashed so it still reads as provisional.
const PREVIEW_AREA_OPTIONS: L.PolylineOptions = {
  ...AREA_LINE_OPTIONS,
  dashArray: '6 6',
  interactive: false,
}

// Renders every saved node group as its street part area — the corridor of
// a fixed width around the line through the ordered members, computed by the
// backend — so the map shows how the member nodes are connected. Rebuilt
// with the other layers so the lines stay above the OSM features but below
// the node markers on the shared canvas renderer. While browsing, clicking
// an area selects its whole relation; while nodes are being collected into
// a relation (relation mode, or two or more placed nodes in edit mode), the
// corridor around them is drawn live so the group is visible before it is
// saved.
export class NodeGroupLayer {
  private readonly map: L.Map
  private layer: L.LayerGroup | undefined

  constructor(map: L.Map) {
    this.map = map
  }

  render(groups: NodeGroup[], previewPoints: GroupAreaPoint[], options: NodeGroupRenderOptions = {}): void {
    this.layer?.remove()
    this.layer = L.layerGroup().addTo(this.map)

    const { onSelect, selectedGroupId } = options
    for (const group of groups) {
      const positions = areaRing(group) ?? memberLineRing(group)
      if (!positions) {
        continue
      }
      const polygon = L.polygon(positions, {
        ...(selectedGroupId === group.id ? SELECTED_AREA_LINE_OPTIONS : AREA_LINE_OPTIONS),
        // Without a click handler the areas stay a visual aid whose clicks
        // pass through to the layers beneath.
        interactive: onSelect !== undefined,
      })
      if (onSelect !== undefined) {
        // Clicking inside the corridor selects the relation itself, not the
        // node or OSM way under the cursor.
        polygon.on('click', () => onSelect(group))
      }
      polygon.addTo(this.layer)
    }

    if (previewPoints.length >= 2) {
      const positions = previewPoints.map(
        (point): L.LatLngTuple => [point.latitude, point.longitude],
      )
      L.polygon(corridorRing(positions, PREVIEW_CORRIDOR_RADIUS_METERS), PREVIEW_AREA_OPTIONS).addTo(this.layer)
      L.polyline(positions, PREVIEW_LINE_OPTIONS).addTo(this.layer)
    }
  }

  destroy(): void {
    this.layer?.remove()
    this.layer = undefined
  }
}

// The backend's corridor geometry; GeoJSON rings are [lon, lat] while
// Leaflet expects [lat, lon].
function areaRing(group: NodeGroup): L.LatLngTuple[] | undefined {
  const ring = group.area?.coordinates[0]
  if (!Array.isArray(ring) || ring.length < 4 || !Number.isFinite(ring[0][0])) {
    return undefined
  }
  return ring.map((point): L.LatLngTuple => [point[1], point[0]])
}

// Fallback for groups without a corridor (e.g. older data): the line through
// the members in order, closed to a degenerate polygon.
function memberLineRing(group: NodeGroup): L.LatLngTuple[] | undefined {
  const positions = group.members
    .filter((member) => Number.isFinite(member.latitude) && Number.isFinite(member.longitude))
    .map((member): L.LatLngTuple => [member.latitude, member.longitude])
  if (positions.length < 2) {
    return undefined
  }
  return positions
}

// The corridor around the preview line: every member position is offset
// perpendicular to the line's course by the radius on both sides. The
// backend's PostGIS buffer computes the exact corridor on save; this local
// approximation only has to look right while collecting.
function corridorRing(positions: L.LatLngTuple[], radius: number): L.LatLngTuple[] {
  const left: L.LatLngTuple[] = []
  const right: L.LatLngTuple[] = []
  for (let i = 0; i < positions.length; ++i) {
    const angle = directionAngle(positions[i], positions[i - 1], positions[i + 1])
    left.push(offsetPoint(positions[i], angle + 90, radius))
    right.push(offsetPoint(positions[i], angle - 90, radius))
  }

  return [...left, ...right.reverse()]
}

// The line's course through the point (0° = north, clockwise): the bisector
// of the incoming and outgoing segments at interior points, or the single
// segment's direction at the ends.
function directionAngle(point: L.LatLngTuple, previous?: L.LatLngTuple, next?: L.LatLngTuple): number {
  const incoming = previous !== undefined ? unitDirection(previous, point) : null
  const outgoing = next !== undefined ? unitDirection(point, next) : null
  const east = (incoming?.[0] ?? 0) + (outgoing?.[0] ?? 0)
  const north = (incoming?.[1] ?? 0) + (outgoing?.[1] ?? 0)

  return (Math.atan2(east, north) * 180) / Math.PI
}

// Unit direction of a segment as [east, north] components, or null when the
// two positions coincide.
function unitDirection(from: L.LatLngTuple, to: L.LatLngTuple): [number, number] | null {
  const dLatitude = to[0] - from[0]
  const dLongitude = (to[1] - from[1]) * Math.cos(((from[0] + to[0]) * Math.PI) / 360)
  const length = Math.hypot(dLongitude, dLatitude)

  return length === 0 ? null : [dLongitude / length, dLatitude / length]
}

// The point moved the given distance along the bearing (0° = north).
function offsetPoint([latitude, longitude]: L.LatLngTuple, bearing: number, meters: number): L.LatLngTuple {
  const radians = (bearing * Math.PI) / 180

  return [
    latitude + (meters * Math.cos(radians)) / 111_320,
    longitude + (meters * Math.sin(radians)) / (111_320 * Math.cos((latitude * Math.PI) / 180)),
  ]
}
