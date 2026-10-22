import L from 'leaflet'

// leaflet.markercluster and Leaflet-Geoman are UMD plugins that extend the global `L`.
// This module must be imported before them so the global exists when they evaluate.
;(window as unknown as { L: typeof L }).L = L

export default L
