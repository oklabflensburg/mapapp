import * as L from 'leaflet'
import type { NodeGroup } from '../stores/nodeGroups'
import { drawDirectionArrows } from './directionArrows'

export interface GroupPreviewPoint {
  latitude: number
  longitude: number
}

// A pending relation (no approved tag yet) matches the pending node markers.
const PENDING_COLOR = '#d97706'
const PENDING_LINE_OPTIONS: L.PolylineOptions = {
  color: PENDING_COLOR,
  weight: 2.5,
  opacity: 0.9,
}

// An approved relation reads as published, like an approved node marker.
const APPROVED_COLOR = '#2e7d32'
const APPROVED_LINE_OPTIONS: L.PolylineOptions = {
  ...PENDING_LINE_OPTIONS,
  color: APPROVED_COLOR,
}

// The selected relation's line stands out, so the street part still reads as
// the selected element; the approved/pending color stays recognizable.
const SELECTED_LINE_OVERRIDES: L.PolylineOptions = {
  weight: 4,
}

// How the relation lines behave in a render: which relation is highlighted,
// and whether clicking a line selects its relation (browsing) or passes
// through to the nodes and features beneath (create, relation and routing
// modes).
export interface NodeGroupRenderOptions {
  selectedGroupId?: number
  onSelect?: (group: NodeGroup) => void
  // Whether to show direction arrows on the selected relation.
  showArrows?: boolean
  // Whether the collected batch's direction is known (one shared parent
  // way): the preview then shows arrowheads along the inherited direction
  // (see specs/map-editing/relation-parent-direction/spec.md). A preview
  // without a direction stays arrowless.
  previewShowsDirection?: boolean
}

// Dashed preview of the street part being collected in relation mode.
const PREVIEW_LINE_OPTIONS: L.PolylineOptions = {
  ...PENDING_LINE_OPTIONS,
  dashArray: '6 6',
  interactive: false,
}

// Renders every saved relation as the line through its ordered members, drawn
// at a minimal, constant on-screen width. The meter-wide backend corridor
// (see backend/src/Service/StreetPartArea.php) exists for routing only —
// rendering the member line keeps the street part visible at every zoom
// level instead of shrinking with the corridor's meters. Lines are rebuilt
// with the other layers so they stay above the OSM features but below the
// node markers on the shared canvas renderer. While browsing, clicking a
// line selects its whole relation; while nodes are being collected into a
// relation (relation mode, or two or more placed nodes in edit mode), the
// line through them is drawn live so the group is visible before it is saved.
export class NodeGroupLayer {
  private readonly map: L.Map
  private layer: L.LayerGroup | undefined

  constructor(map: L.Map) {
    this.map = map
  }

  render(groups: NodeGroup[], previewPoints: GroupPreviewPoint[], options: NodeGroupRenderOptions = {}): void {
    this.layer?.remove()
    this.layer = L.layerGroup().addTo(this.map)

    const { onSelect, selectedGroupId, showArrows } = options
    for (const group of groups) {
      const positions = memberPositions(group)
      if (!positions) {
        continue
      }
      const selected = selectedGroupId === group.id
      const lineOptions = group.approved
        ? { ...APPROVED_LINE_OPTIONS, color: APPROVED_COLOR }
        : { ...PENDING_LINE_OPTIONS, color: PENDING_COLOR }
      const line = L.polyline(positions, {
        ...lineOptions,
        ...(selected ? SELECTED_LINE_OVERRIDES : {}),
        // Without a click handler the lines stay a visual aid whose clicks
        // pass through to the layers beneath.
        interactive: onSelect !== undefined,
      })
      if (onSelect !== undefined) {
        // Clicking a relation's line selects the relation itself, not the
        // node or OSM way under the cursor.
        line.on('click', () => onSelect(group))
      }
      line.addTo(this.layer)
      // Drawn right after their line, so each relation's arrows stay above
      // its own stroke; they never intercept clicks (see directionArrows).
      if (selected && showArrows !== false) {
        drawDirectionArrows(this.layer, this.map, positions, { color: lineOptions.color })
      }
    }

    if (previewPoints.length >= 2) {
      const positions = previewPoints.map((point): L.LatLngTuple => [point.latitude, point.longitude])
      L.polyline(positions, PREVIEW_LINE_OPTIONS).addTo(this.layer)
      if (options.previewShowsDirection && showArrows !== false) {
        drawDirectionArrows(this.layer, this.map, positions, { color: PENDING_COLOR })
      }
    }
  }

  destroy(): void {
    this.layer?.remove()
    this.layer = undefined
  }
}

// The relation's member line in Leaflet's [lat, lon] order, or undefined
// when the members cannot form a line.
function memberPositions(group: NodeGroup): L.LatLngTuple[] | undefined {
  const positions = group.members
    .filter((member) => Number.isFinite(member.latitude) && Number.isFinite(member.longitude))
    .map((member): L.LatLngTuple => [member.latitude, member.longitude])

  return positions.length >= 2 ? positions : undefined
}
