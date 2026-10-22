/** API resource shapes (mirrors backend/app/Http/Resources). Lengths are metres as decimal strings. */

export type Decimal = string

export interface Paginated<T> {
  data: T[]
  meta: { current_page: number; last_page: number; per_page: number; total: number }
}

export interface EnumOption {
  value: string
  label: string
  color: string
}

export interface MapSettings {
  tile_url: string
  attribution: string
  max_zoom: number
  default_center: [number, number]
  default_zoom: number
  /** null = satellite base layer switched off */
  satellite_url: string | null
  satellite_attribution: string | null
  satellite_max_native_zoom: number
  /** Place names over the imagery ("Gibrid"); null = no hybrid variant */
  satellite_labels_url: string | null
  wms_layers: WmsLayer[]
}

export interface WmsLayer {
  name: string
  url: string
  layers: string
  format?: string
  transparent?: boolean
  attribution?: string
}

export interface Meta {
  enums: Record<string, EnumOption[]>
  roles: { value: string; label: string }[]
  map: MapSettings
  tolerance: { length_m: number; length_percent: number; material_percent: number; metal_percent: number }
  documents: { max_kb: number; allowed_extensions: string[]; required: Record<string, string[]> }
}

export interface OrganizationBrief {
  id: number
  name: string
  short_name: string | null
}

export interface Organization extends OrganizationBrief {
  parent_id: number | null
  type: string
  code: string
  address: string | null
  is_active: boolean
  is_demo: boolean
}

export interface CurrentUser {
  id: number
  name: string
  email: string
  position: string | null
  organization_id: number | null
  organization: OrganizationBrief | null
  roles: { name: string; label: string }[]
  permissions: string[]
  can_view_all_organizations: boolean
  demo_data_present: boolean
}

export interface UserRow {
  id: number
  name: string
  email: string
  phone: string | null
  position: string | null
  is_active: boolean
  organization_id: number | null
  organization: OrganizationBrief | null
  roles: { name: string; label: string }[]
  last_login_at: string | null
}

export type Geometry =
  | { type: 'LineString'; coordinates: [number, number][] }
  | { type: 'MultiLineString'; coordinates: [number, number][][] }

export interface CableSegment {
  id: number
  cable_line_id: number
  organization_id: number
  sequence: number
  code: string
  name: string | null
  start_point: string | null
  end_point: string | null
  location_description: string | null
  laying_type: string
  depth_m: Decimal | null
  design_length_m: Decimal | null
  measured_length_m: Decimal | null
  geometry_length_m: Decimal | null
  geometry: Geometry | null
  geometry_source: string | null
  geometry_source_note: string | null
  geometry_updated_at: string | null
  technical_condition: string
  usage_status: string
  risk_level: string
  complexity_flags: string[]
  is_complex: boolean
  inspection_status: string
  dismantling_status: string
  notes: string | null
  line?: { id: number; registry_number: string; name: string }
}

export interface CableLine {
  id: number
  registry_number: string
  cable_code: string | null
  name: string
  cable_type: string | null
  brand: string | null
  core_count: number | null
  laid_year: number | null
  laid_year_precision: string
  laid_year_label: string
  organization_id: number
  organization?: OrganizationBrief | null
  balance_holder_id: number
  balance_holder?: OrganizationBrief | null
  gas_pipeline_asset_id: number | null
  gas_pipeline?: { id: number; code: string; name: string } | null
  cable_type_id: number | null
  cable_type_ref?: { id: number; code: string; name: string } | null
  object_name: string | null
  start_point: string | null
  end_point: string | null
  design_length_m: Decimal | null
  measured_length_m: Decimal | null
  inventory_number?: string | null
  initial_cost?: Decimal | null
  accumulated_depreciation?: Decimal | null
  residual_value?: Decimal | null
  technical_condition: string
  usage_status: string
  risk_level: string
  inspection_status: string
  dismantling_status: string
  has_geometry: boolean
  is_complex: boolean
  is_demo: boolean
  notes: string | null
  segments_count?: number
  segments?: CableSegment[]
  documents_missing?: string[]
  created_by?: string | null
  updated_by?: string | null
  created_at: string
  updated_at: string
  can?: { update: boolean; update_financial: boolean; delete: boolean; edit_geometry: boolean }
}

export interface GasPipeline {
  id: number
  organization_id: number
  organization: string | null
  code: string
  name: string
  diameter_mm: number | null
  length_m: Decimal | null
  description: string | null
  is_active: boolean
  is_demo: boolean
}

export interface DocumentItem {
  id: number
  type: string
  title: string
  number: string | null
  document_date: string | null
  description: string | null
  original_name: string
  mime_type: string
  size_bytes: number
  sha256: string
  documentable_type: string
  documentable_id: number
  uploaded_by?: string | null
  created_at: string
  voided_at: string | null
  voided_by?: string | null
  void_reason: string | null
  download_url: string | null
  can_void: boolean
}

export interface InspectionItem {
  id: number
  cable_segment_id: number
  segment?: { id: number; code: string; name: string | null; location_description: string | null; line_name: string | null }
  inspected_on: string | null
  recorded_length_m: Decimal | null
  measured_length_m: Decimal | null
  length_difference_m: Decimal | null
  exceeds_tolerance: boolean
  difference_reason: string | null
  technical_condition: string | null
  usage_status: string | null
  usage_evidence: string | null
  risk_level: string | null
  complexity_flags: string[]
  laying_type: string | null
  depth_m: Decimal | null
  findings: string | null
  is_recorded: boolean
  recorded_at: string | null
  documents?: DocumentItem[]
}

export interface Inspection {
  id: number
  number: string
  organization_id: number
  organization?: string | null
  title: string
  description: string | null
  planned_start: string | null
  planned_end: string | null
  status: string
  is_demo: boolean
  items_count?: number
  recorded_items_count?: number
  members?: { id: number; user_id: number | null; full_name: string; position: string | null; role: string }[]
  items?: InspectionItem[]
  documents?: DocumentItem[]
  created_by?: string | null
  submitted_by?: string | null
  submitted_by_id: number | null
  submitted_at: string | null
  decided_by?: string | null
  decided_at: string | null
  decision_comment: string | null
  created_at: string
  can?: { manage: boolean; decide: boolean; attach: boolean }
}

export interface Approval {
  id: number
  type: string
  decision: string
  reference_number: string | null
  reference_date: string | null
  external_party: string | null
  comment: string | null
  document_id: number | null
  decided_by: string | null
  decided_at: string
  revoked_at: string | null
  revoked_by: string | null
  revoke_reason: string | null
}

export interface DismantlingEvent {
  id: number
  type: string
  from_status: string | null
  to_status: string | null
  occurred_at: string
  segment_code: string | null
  extracted_length_m: Decimal | null
  description: string | null
  user: string | null
}

export interface DismantlingOrder {
  id: number
  number: string
  organization_id: number
  organization?: string | null
  cable_line_id: number
  line?: { id: number; registry_number: string; name: string }
  work_plan_number: string | null
  order_number: string | null
  order_date: string | null
  brigade_name: string | null
  work_supervisor_id: number | null
  work_supervisor?: string | null
  work_supervisor_name: string | null
  planned_start: string | null
  planned_end: string | null
  actual_start: string | null
  actual_end: string | null
  planned_length_m: Decimal
  actual_length_m: Decimal
  difference_m: Decimal
  percent: number | null
  status: string
  allowed_transitions: string[]
  is_complex: boolean
  is_demo: boolean
  suspension_reason: string | null
  notes: string | null
  segments?: {
    id: number
    code: string
    name: string | null
    planned_length_m: Decimal | null
    risk_level: string
    complexity_flags: string[]
    inspection_status: string
    dismantling_status: string
    has_geometry: boolean
  }[]
  approvals?: Approval[]
  events?: DismantlingEvent[]
  documents?: DocumentItem[]
  material_receipts?: { id: number; number: string; receipt_date: string; status: string }[]
  checks?: Record<string, string[]> & { required_approvals?: string[] }
  act_masses: {
    aluminium_kg: Decimal | null
    lead_kg: Decimal | null
    copper_kg: Decimal | null
    document_id: number | null
    recorded_by?: string | null
    recorded_at: string | null
  }
  metal_balance?: OrderMetalBalance
  created_by?: string | null
  created_by_id: number | null
  created_at: string
  can?: {
    update: boolean
    transition: boolean
    authorize: boolean
    record_safety: boolean
    record_specialist: boolean
    record_event: boolean
    attach: boolean
  }
}

export interface WarehouseTransaction {
  id: number
  type: string
  quantity: Decimal
  status: string
  document_number: string | null
  document_date: string | null
  counterparty: string | null
  reason: string | null
  created_by: string | null
  created_by_id: number
  decided_by: string | null
  decision_comment: string | null
  created_at: string
}

export interface MaterialItem {
  id: number
  material_type: string
  description: string | null
  unit: string
  handed_over_quantity: Decimal
  accepted_quantity: Decimal
  difference: Decimal
  balance: Decimal
  measured_on: string
  storage_location: string | null
  segment_code: string | null
  transactions: WarehouseTransaction[]
}

export interface Discrepancy {
  kind: 'item' | 'order'
  item_id?: number
  message: string
  expected: string
  actual: string
  difference: string
}

export interface MaterialReceipt {
  id: number
  number: string
  receipt_date: string
  organization_id: number
  organization?: string | null
  dismantling_order_id: number
  dismantling_order?: { id: number; number: string; status: string; actual_length_m: Decimal }
  warehouse_name: string
  handed_over_by?: string | null
  handed_over_by_id: number
  received_by?: string | null
  received_by_id: number
  status: string
  discrepancy_explanation: string | null
  discrepancy_decided_by?: string | null
  discrepancy_decided_at: string | null
  notes: string | null
  is_demo: boolean
  items?: MaterialItem[]
  discrepancies?: Discrepancy[]
  documents?: DocumentItem[]
  created_at: string
  can?: { decide: boolean; move: boolean; attach: boolean; approve_adjustment: boolean }
}

export interface AuditLogEntry {
  id: number
  user_id: number | null
  user_name: string | null
  organization_id: number | null
  event: string
  auditable_type: string | null
  auditable_id: number | null
  auditable_label: string | null
  old_values: Record<string, unknown> | null
  new_values: Record<string, unknown> | null
  reason: string | null
  ip_address: string | null
  user_agent: string | null
  method: string | null
  url: string | null
  created_at: string
}

export interface AlertItem {
  id: number
  organization_id: number | null
  organization?: string | null
  type: string
  severity: string
  status: string
  subject_type: string | null
  subject_id: number | null
  title: string
  message: string
  data: Record<string, unknown> | null
  triggered_by?: string | null
  resolved_by?: string | null
  resolved_at: string | null
  resolution_note: string | null
  created_at: string
  updated_at: string
  can_resolve: boolean
}

export interface DashboardData {
  has_demo_data: boolean
  kpis: {
    lines_count: number
    segments_count: number
    total_length_m: Decimal
    inventoried_length_m: Decimal
    planned_dismantling_length_m: Decimal
    dismantled_length_m: Decimal
    dismantling_percent: number | null
    materials_received: { unit: string; quantity: Decimal }[]
    segments_requiring_inspection: number
    incomplete_documents: number
    incomplete_documents_breakdown: Record<string, number>
    hazardous_segments: number
    segments_without_geometry: number
    open_alerts: number
  }
  metals: Record<
    MetalKey,
    { theoretical_kg: Decimal; theoretical_complete: boolean; unknown_segments: number; act_kg: Decimal; warehouse_kg: Decimal }
  >
  by_organization: {
    organization_id: number
    name: string
    is_demo: boolean
    lines_count: number
    total_length_m: Decimal
    inventoried_length_m: Decimal
    planned_length_m: Decimal
    dismantled_length_m: Decimal
    inventory_percent: number | null
    dismantling_percent: number | null
  }[]
  status_distribution: { status: string; segments: number; length_m: Decimal }[]
  monthly_dismantling: { month: string; length_m: Decimal }[]
}

export interface ReportColumn {
  key: string
  label: string
  type: string
}

export interface ReportDefinition {
  key: string
  title: string
  columns: ReportColumn[]
  status_filter: string | null
  date_label: string | null
}

export interface MapFeatureProperties {
  segment_id: number
  segment_code: string
  segment_name: string | null
  line_id: number
  registry_number: string
  cable_code: string | null
  line_name: string
  organization: string | null
  gas_pipeline: string | null
  laid_year: string
  location_description: string | null
  laying_type: string
  technical_condition: string
  usage_status: string
  risk_level: string
  complexity_flags: string[]
  inspection_status: string
  dismantling_status: string
  design_length_m: Decimal | null
  measured_length_m: Decimal | null
  geometry_length_m: Decimal | null
  geometry_source: string | null
  is_demo: boolean
}

export interface MapFeature {
  type: 'Feature'
  id: number
  geometry: Geometry
  properties: MapFeatureProperties
}

export interface MapFeatureCollection {
  type: 'FeatureCollection'
  features: MapFeature[]
}

// ── Non-ferrous metal content ────────────────────────────────────────────────
// kg/m and masses are decimal strings; null always means "unknown" (never 0).

export type MetalKey = 'aluminium' | 'lead' | 'copper'
export const METALS: MetalKey[] = ['aluminium', 'lead', 'copper']

export interface MetalComposition {
  id: number
  scope_type: 'cable_type' | 'cable_line' | 'cable_segment'
  scope_id: number
  scope_label: string | null
  aluminium_kg_per_m: Decimal | null
  lead_kg_per_m: Decimal | null
  copper_kg_per_m: Decimal | null
  source_type: string
  source_reference: string
  document_id: number | null
  document_title?: string | null
  note: string | null
  status: string
  entered_by?: string | null
  entered_by_id: number
  entered_at: string
  decided_by?: string | null
  decided_at: string | null
  decision_comment: string | null
  superseded_at: string | null
  is_demo: boolean
  can_decide: boolean
}

export interface CableTypeItem {
  id: number
  code: string
  name: string
  brand: string | null
  core_count: number | null
  description: string | null
  is_active: boolean
  is_demo: boolean
  lines_count?: number
  pending_count?: number
  current_composition?: MetalComposition | null
  compositions?: MetalComposition[]
}

export interface SegmentMetalRow {
  segment_id: number
  code: string
  length_m: Decimal | null
  composition_id: number | null
  scope: 'cable_type' | 'cable_line' | 'cable_segment' | null
  complete: boolean
  metals: Record<MetalKey, { kg_per_m: Decimal | null; theoretical_kg: Decimal | null; known: boolean }>
}

export interface MetalTotals {
  known_kg: Decimal
  complete: boolean
  unknown_segments: string[]
}

export interface MetalCalculationEntry {
  id: number
  segment_code: string | null
  trigger: string
  composition_id: number | null
  length_m: Decimal | null
  aluminium_kg: Decimal | null
  lead_kg: Decimal | null
  copper_kg: Decimal | null
  is_complete: boolean
  details: Record<string, unknown> | null
  calculated_by: string | null
  created_at: string
}

export interface LineMetalContent {
  cable_type: { id: number; name: string; code: string } | null
  segments: SegmentMetalRow[]
  totals: Record<MetalKey, MetalTotals>
  complete: boolean
  versions: MetalComposition[]
  history: MetalCalculationEntry[]
  can: { submit: boolean }
}

export interface MassDifference {
  kg: Decimal | null
  percent: number | null
  exceeds: boolean | null
}

export interface OrderMetalBalance {
  extracted_length_m: Decimal
  unassigned_length_m: Decimal
  tolerance_percent: number
  act_recorded_at: string | null
  metals: Record<
    MetalKey,
    {
      theoretical_planned_kg: Decimal | null
      theoretical_kg: Decimal | null
      theoretical_known_part_kg: Decimal
      theoretical_complete: boolean
      act_kg: Decimal | null
      warehouse_kg: Decimal | null
      act_vs_theoretical: MassDifference
      warehouse_vs_act: MassDifference
      warehouse_vs_theoretical: MassDifference
    }
  >
}
