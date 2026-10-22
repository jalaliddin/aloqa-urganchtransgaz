// Order matters: the global L must exist before the plugins evaluate
import L from './leafletGlobal'
import 'leaflet.markercluster'
import '@geoman-io/leaflet-geoman-free'
import '@geoman-io/leaflet-geoman-free/dist/leaflet-geoman.css'
import 'leaflet.markercluster/dist/MarkerCluster.css'
import 'leaflet/dist/leaflet.css'
import { Box, Loader, Paper } from '@mantine/core'
import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import { LayerGroup, LayersControl, MapContainer, TileLayer, useMap, useMapEvents, WMSTileLayer } from 'react-leaflet'
import type { Geometry, MapFeature, MapFeatureProperties } from '@/api/types'
import { useMeta } from '@/lib/meta'
import { availableBaseLayers, readBaseLayer, rememberBaseLayer, resolveBaseLayer, type BaseLayerDef, type BaseLayerKey } from './baseLayers'
import { useFeatureColor, type ColorMode } from './mapColors'

export interface GeometryEditState {
  segmentId: number
  /** Geometry being edited (null = start by drawing) */
  geometry: Geometry | null
  onChange: (geometry: Geometry | null) => void
  /** Increment to start drawing an additional line */
  drawToken: number
}

interface CableMapProps {
  features: MapFeature[]
  colorBy: ColorMode
  selectedLineId?: number | null
  selectedSegmentId?: number | null
  onSelect?: (properties: MapFeatureProperties) => void
  /** Called with "minLng,minLat,maxLng,maxLat" after the view settles */
  onViewChange?: (bbox: string) => void
  /** Change the value to fit the map to the current features (or to the selected line) */
  fitKey?: string | number | null
  editing?: GeometryEditState | null
  loading?: boolean
  showPegs?: boolean
  children?: ReactNode
  height?: number | string
  ariaLabel?: string
}

/** Keeps the latest value in a ref without writing to it during render. */
function useLatest<T>(value: T) {
  const ref = useRef(value)
  useEffect(() => {
    ref.current = value
  }, [value])
  return ref
}

const SIGNAL = '#E3B505'
const NAVY = '#1D3B53'

const toLatLngs = (geometry: Geometry): L.LatLngExpression[][] =>
  (geometry.type === 'LineString' ? [geometry.coordinates] : geometry.coordinates).map((line) => line.map(([lng, lat]) => [lat, lng] as L.LatLngTuple))

export function featureBounds(features: MapFeature[]): L.LatLngBounds | null {
  const bounds = L.latLngBounds([])
  features.forEach((f) => toLatLngs(f.geometry).forEach((line) => line.forEach((p) => bounds.extend(p))))
  return bounds.isValid() ? bounds : null
}

function bboxString(map: L.Map): string {
  const b = map.getBounds()
  const f = (n: number) => n.toFixed(5)
  return `${f(b.getWest())},${f(b.getSouth())},${f(b.getEast())},${f(b.getNorth())}`
}

function ViewWatcher({ onViewChange }: { onViewChange?: (bbox: string) => void }) {
  const timer = useRef<number | undefined>(undefined)
  const map = useMapEvents({
    moveend: () => {
      window.clearTimeout(timer.current)
      timer.current = window.setTimeout(() => onViewChange?.(bboxString(map)), 250)
    },
  })

  useEffect(() => {
    onViewChange?.(bboxString(map))
    return () => window.clearTimeout(timer.current)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  return null
}

/** Reports the base layer the user picks in the layers control. */
function BaseLayerWatcher({ layers, onChange }: { layers: BaseLayerDef[]; onChange: (key: BaseLayerKey) => void }) {
  useMapEvents({
    baselayerchange: (event) => {
      const layer = layers.find((l) => l.name === event.name)
      if (layer) onChange(layer.key)
    },
  })
  return null
}

/**
 * Cable routes as canvas polylines, coloured by the legend mode; selected line gets a signal-yellow halo.
 * On imagery every route also gets a white casing, otherwise dark status colours sink into the photo.
 */
function FeatureLayer({
  features, colorBy, selectedLineId, selectedSegmentId, onSelect, hiddenSegmentId, casing,
}: {
  features: MapFeature[]
  colorBy: ColorMode
  selectedLineId?: number | null
  selectedSegmentId?: number | null
  onSelect?: (p: MapFeatureProperties) => void
  hiddenSegmentId?: number | null
  casing?: boolean
}) {
  const map = useMap()
  const { colorOf } = useFeatureColor(colorBy)
  const renderer = useMemo(() => L.canvas({ padding: 0.5, tolerance: 6 }), [])
  const onSelectRef = useLatest(onSelect)

  useEffect(() => {
    const group = L.layerGroup()
    const visible = features.filter((f) => f.id !== hiddenSegmentId)

    // Halo first so it sits underneath the coloured line
    visible
      .filter((f) => f.properties.line_id === selectedLineId)
      .forEach((f) => {
        L.polyline(toLatLngs(f.geometry), {
          renderer, color: SIGNAL, weight: f.id === selectedSegmentId ? 14 : 11, opacity: 0.9, interactive: false,
        }).addTo(group)
      })

    if (casing) {
      visible.forEach((f) => {
        L.polyline(toLatLngs(f.geometry), {
          renderer, color: '#fff', weight: f.properties.line_id === selectedLineId ? 8 : 7, opacity: 0.85, lineCap: 'round', interactive: false,
        }).addTo(group)
      })
    }

    visible.forEach((feature) => {
      const p = feature.properties
      const selected = p.line_id === selectedLineId
      const line = L.polyline(toLatLngs(feature.geometry), {
        renderer,
        color: colorOf(p),
        weight: selected ? 5 : 4,
        opacity: selectedLineId && !selected ? 0.55 : 0.95,
        dashArray: p.is_demo ? '8 6' : undefined,
        lineCap: 'round',
      })
      line.bindTooltip(
        `<strong>${escapeHtml(p.segment_code)}</strong><br/>${escapeHtml(p.line_name)}${p.is_demo ? '<br/><em>DEMO geometriya</em>' : ''}`,
        { sticky: true, direction: 'top' },
      )
      line.on('click', () => onSelectRef.current?.(p))
      line.addTo(group)
    })

    group.addTo(map)
    return () => {
      group.remove()
    }
  }, [features, colorOf, selectedLineId, selectedSegmentId, hiddenSegmentId, casing, map, renderer])

  return null
}

/** Start / end route pegs, clustered when zoomed out. */
function PegLayer({ features, onSelect }: { features: MapFeature[]; onSelect?: (p: MapFeatureProperties) => void }) {
  const map = useMap()
  const onSelectRef = useLatest(onSelect)

  useEffect(() => {
    const cluster = L.markerClusterGroup({
      showCoverageOnHover: false,
      maxClusterRadius: 40,
      iconCreateFunction: (c) =>
        L.divIcon({ html: `<span>${c.getChildCount()}</span>`, className: 'marker-cluster-peg', iconSize: L.point(30, 30) }),
    })

    features.forEach((feature) => {
      const lines = toLatLngs(feature.geometry)
      const first = lines[0]?.[0]
      const lastLine = lines[lines.length - 1]
      const last = lastLine?.[lastLine.length - 1]
      const p = feature.properties
      ;[
        [first, 'route-peg', 'boshi'],
        [last, 'route-peg route-peg--end', 'oxiri'],
      ].forEach(([point, className, label]) => {
        if (!point) return
        const marker = L.marker(point as L.LatLngExpression, {
          icon: L.divIcon({ className: className as string, iconSize: [14, 14] }),
          keyboard: true,
          title: `${p.segment_code} — ${label}`,
          alt: `${p.segment_code} uchastka ${label}`,
        })
        marker.on('click', () => onSelectRef.current?.(p))
        cluster.addLayer(marker)
      })
    })

    map.addLayer(cluster)
    return () => {
      map.removeLayer(cluster)
    }
  }, [features, map])

  return null
}

function FitBounds({ features, selectedLineId, fitKey }: { features: MapFeature[]; selectedLineId?: number | null; fitKey?: string | number | null }) {
  const map = useMap()
  const featuresRef = useLatest(features)
  const selectedRef = useLatest(selectedLineId)

  // Only an explicit fitKey change moves the view; clicking a route on the map does not
  useEffect(() => {
    if (fitKey === undefined || fitKey === null) return
    const current = featuresRef.current
    const target = selectedRef.current ? current.filter((f) => f.properties.line_id === selectedRef.current) : current
    const bounds = featureBounds(target.length > 0 ? target : current)
    if (bounds) map.fitBounds(bounds, { padding: [40, 40], maxZoom: 16 })
  }, [fitKey, map, featuresRef, selectedRef])

  return null
}

/** Geoman-based editing of one segment's geometry (one or more polylines), drawn in `color`. */
function GeometryEditor({ editing, color }: { editing: GeometryEditState; color: string }) {
  const map = useMap()
  const groupRef = useRef<L.FeatureGroup | null>(null)
  const onChangeRef = useLatest(editing.onChange)
  const colorRef = useLatest(color)
  const drawLine = () =>
    map.pm.enableDraw('Line', { templineStyle: { color: colorRef.current }, hintlineStyle: { color: colorRef.current, dashArray: [5, 5] } })

  const emit = () => {
    const lines = (groupRef.current?.getLayers() ?? [])
      .filter((layer): layer is L.Polyline => layer instanceof L.Polyline)
      .map((layer) => (layer.getLatLngs() as L.LatLng[]).map((p) => [round(p.lng), round(p.lat)] as [number, number]))
      .filter((line) => line.length >= 2)

    onChangeRef.current(
      lines.length === 0 ? null : lines.length === 1 ? { type: 'LineString', coordinates: lines[0] } : { type: 'MultiLineString', coordinates: lines },
    )
  }

  // Build editable layers once per edited segment
  useEffect(() => {
    map.pm.setLang('uz' as Parameters<typeof map.pm.setLang>[0], GEOMAN_UZ, 'en')
    map.pm.setGlobalOptions({ allowSelfIntersection: true, snappable: true, snapDistance: 15 })
    const group = L.featureGroup().addTo(map)
    groupRef.current = group

    if (editing.geometry) {
      toLatLngs(editing.geometry).forEach((latlngs) => {
        const layer = L.polyline(latlngs, { color: colorRef.current, weight: 5 })
        group.addLayer(layer)
        layer.pm.enable({ allowSelfIntersection: true })
        layer.on('pm:edit pm:vertexremoved pm:vertexadded pm:markerdragend', emit)
      })
      const bounds = group.getBounds()
      if (bounds.isValid()) map.fitBounds(bounds, { padding: [60, 60], maxZoom: 17 })
    }

    const onCreate = (event: { layer: L.Layer }) => {
      const layer = event.layer as L.Polyline
      map.removeLayer(layer)
      layer.setStyle({ color: colorRef.current, weight: 5 })
      group.addLayer(layer)
      layer.pm.enable({ allowSelfIntersection: true })
      layer.on('pm:edit pm:vertexremoved pm:vertexadded pm:markerdragend', emit)
      emit()
    }
    map.on('pm:create', onCreate)

    if (!editing.geometry) drawLine()

    return () => {
      map.off('pm:create', onCreate)
      map.pm.disableDraw()
      group.eachLayer((layer) => (layer as L.Polyline).pm?.disable())
      group.remove()
      groupRef.current = null
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [editing.segmentId, map])

  useEffect(() => {
    if (editing.drawToken > 0) drawLine()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [editing.drawToken, map])

  // Switching between the scheme map and imagery mid-edit recolours the lines already drawn
  useEffect(() => {
    groupRef.current?.eachLayer((layer) => (layer as L.Polyline).setStyle({ color }))
  }, [color])

  return null
}

const round = (n: number) => Math.round(n * 1e7) / 1e7

const GEOMAN_UZ = {
  tooltips: {
    firstVertex: 'Trassa boshini belgilash uchun xaritaga bosing',
    continueLine: 'Davom ettirish uchun bosing',
    finishLine: 'Tugatish uchun oxirgi nuqtani yana bosing',
  },
} as const

function escapeHtml(value: string): string {
  return value.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]!)
}

export function CableMap({
  features, colorBy, selectedLineId, selectedSegmentId, onSelect, onViewChange, fitKey, editing, loading,
  showPegs = true, children, height = '100%', ariaLabel = 'Kabel trassalari xaritasi',
}: CableMapProps) {
  const { data: meta } = useMeta()
  // The user's last choice (remembered across pages); resolved against what the settings currently offer
  const [preferredBase, setPreferredBase] = useState(readBaseLayer)

  if (!meta) {
    return (
      <Box h={height} style={{ display: 'grid', placeItems: 'center' }}>
        <Loader aria-label="Xarita yuklanmoqda" />
      </Box>
    )
  }

  const settings = meta.map
  const baseLayers = availableBaseLayers(settings)
  const base = resolveBaseLayer(preferredBase, baseLayers)
  const imagery = settings.satellite_url ? (
    <TileLayer
      url={settings.satellite_url}
      attribution={settings.satellite_attribution ?? ''}
      maxZoom={settings.max_zoom}
      maxNativeZoom={Math.min(settings.satellite_max_native_zoom, settings.max_zoom)}
    />
  ) : null
  const chooseBase = (key: BaseLayerKey) => {
    setPreferredBase(key)
    rememberBaseLayer(key)
  }

  return (
    // isolation keeps Leaflet's internal z-indexes (400–1000) from covering the header, drawers and modals
    <Box pos="relative" h={height} role="region" aria-label={ariaLabel} style={{ isolation: 'isolate' }}>
      <MapContainer
        center={settings.default_center}
        zoom={settings.default_zoom}
        maxZoom={settings.max_zoom}
        preferCanvas
        style={{ height: '100%', width: '100%' }}
        keyboard
      >
        <LayersControl position="topright">
          <LayersControl.BaseLayer checked={base.key === 'scheme'} name="Xarita">
            <TileLayer url={settings.tile_url} attribution={settings.attribution} maxZoom={settings.max_zoom} />
          </LayersControl.BaseLayer>
          {baseLayers.some((l) => l.key === 'satellite') && (
            <LayersControl.BaseLayer checked={base.key === 'satellite'} name="Sun'iy yo'ldosh">
              {imagery}
            </LayersControl.BaseLayer>
          )}
          {baseLayers.some((l) => l.key === 'hybrid') && (
            <LayersControl.BaseLayer checked={base.key === 'hybrid'} name="Gibrid">
              <LayerGroup>
                {imagery}
                <TileLayer
                  url={settings.satellite_labels_url!}
                  maxZoom={settings.max_zoom}
                  maxNativeZoom={Math.min(settings.satellite_max_native_zoom, settings.max_zoom)}
                />
              </LayerGroup>
            </LayersControl.BaseLayer>
          )}
          {settings.wms_layers.map((layer) => (
            <LayersControl.Overlay key={layer.name} name={layer.name}>
              <WMSTileLayer
                url={layer.url}
                params={{ layers: layer.layers, format: layer.format ?? 'image/png', transparent: layer.transparent ?? true }}
                attribution={layer.attribution}
              />
            </LayersControl.Overlay>
          ))}
        </LayersControl>
        <BaseLayerWatcher layers={baseLayers} onChange={chooseBase} />
        <ViewWatcher onViewChange={onViewChange} />
        <FeatureLayer
          features={features}
          colorBy={colorBy}
          selectedLineId={selectedLineId}
          selectedSegmentId={selectedSegmentId}
          onSelect={editing ? undefined : onSelect}
          hiddenSegmentId={editing?.segmentId}
          casing={base.imagery}
        />
        {showPegs && !editing && <PegLayer features={features} onSelect={onSelect} />}
        <FitBounds features={features} selectedLineId={selectedLineId} fitKey={fitKey} />
        {editing && <GeometryEditor key={editing.segmentId} editing={editing} color={base.imagery ? SIGNAL : NAVY} />}
      </MapContainer>
      {loading && (
        <Paper pos="absolute" top={10} left={56} px="xs" py={4} style={{ zIndex: 500 }} aria-live="polite">
          <Loader size="xs" />
        </Paper>
      )}
      {children}
    </Box>
  )
}
