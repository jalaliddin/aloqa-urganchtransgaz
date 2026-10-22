import {
  ActionIcon, Alert, Anchor, Badge, Button, Group, Paper, SimpleGrid, Stack, Table, Tabs, Text, Timeline, Tooltip,
} from '@mantine/core'
import {
  IconAlertTriangle, IconClipboardCheck, IconEdit, IconMap2, IconMapPinOff, IconPlus, IconTool, IconTrash,
} from '@tabler/icons-react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { Link, useNavigate, useParams, useSearchParams } from 'react-router'
import { api } from '@/api/client'
import type { AuditLogEntry, CableLine, CableSegment, DismantlingOrder, Paginated } from '@/api/types'
import { useAuth } from '@/auth/AuthContext'
import { DocumentsPanel } from '@/components/common/DocumentsPanel'
import { notifySuccess } from '@/components/common/notify'
import { PageHeader } from '@/components/common/PageHeader'
import { ReasonModal } from '@/components/common/ReasonModal'
import { EmptyState, ErrorState, PageSkeleton } from '@/components/common/States'
import { StatusBadge } from '@/components/common/StatusBadge'
import { CableMap } from '@/components/map/CableMap'
import { useMapFeatures } from '@/components/map/useMapFeatures'
import { AuditTable } from '@/pages/audit/AuditTable'
import { formatDate, formatDateTime, formatLength, formatMoney } from '@/lib/format'
import { useEnum } from '@/lib/meta'
import { LineMetalTab } from './LineMetalTab'
import { SegmentFormModal } from './SegmentFormModal'

export default function CableLineDetailPage() {
  const { id } = useParams()
  const lineId = Number(id)
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { can } = useAuth()
  const [params, setParams] = useSearchParams()
  const tab = params.get('tab') ?? 'overview'
  const [segmentModal, setSegmentModal] = useState<{ open: boolean; segment: CableSegment | null }>({ open: false, segment: null })
  const [deletingSegment, setDeletingSegment] = useState<CableSegment | null>(null)
  const [deletingLine, setDeletingLine] = useState(false)
  const docTypes = useEnum('document_type')
  const laying = useEnum('laying_type')

  const { data: line, isLoading, error, refetch } = useQuery({
    queryKey: ['cable-line', lineId],
    queryFn: async () => (await api.get<{ data: CableLine }>(`/cable-lines/${lineId}`)).data.data,
  })

  if (isLoading) return <PageSkeleton />
  if (error || !line) return <ErrorState error={error} onRetry={() => void refetch()} />

  const refresh = () => {
    void queryClient.invalidateQueries({ queryKey: ['cable-line', lineId] })
    void queryClient.invalidateQueries({ queryKey: ['cable-lines'] })
  }

  return (
    <>
      <PageHeader
        title={`${line.registry_number} — ${line.name}`}
        description={[line.cable_type, line.brand, line.organization?.name].filter(Boolean).join(', ')}
        badges={
          <Group gap={6}>
            {line.is_demo && <Badge color="orange">DEMO</Badge>}
            <StatusBadge enumKey="dismantling_status" value={line.dismantling_status} />
          </Group>
        }
        actions={
          <>
            <Button variant="default" component={Link} to={`/map?selected=${line.id}`} leftSection={<IconMap2 size={16} />}>
              Xaritada
            </Button>
            {(line.can?.update || line.can?.update_financial) && (
              <Button component={Link} to={`/registry/${line.id}/edit`} leftSection={<IconEdit size={16} />}>
                Tahrirlash
              </Button>
            )}
            {line.can?.delete && (
              <Button variant="light" color="red" leftSection={<IconTrash size={16} />} onClick={() => setDeletingLine(true)}>
                O'chirish
              </Button>
            )}
          </>
        }
      />

      {line.documents_missing && line.documents_missing.length > 0 && (
        <Alert color="orange" icon={<IconAlertTriangle size={18} />} mb="md" title="Majburiy hujjat yetishmaydi">
          {line.documents_missing.map((t) => docTypes.label(t)).join(', ')} biriktirilmagan.{' '}
          <Anchor size="sm" onClick={() => setParams({ tab: 'documents' })}>
            Hujjatlar bo'limiga o'tish
          </Anchor>
        </Alert>
      )}

      <Tabs value={tab} onChange={(t) => setParams(t === 'overview' ? {} : { tab: t ?? 'overview' })} keepMounted={false}>
        <Tabs.List mb="md">
          <Tabs.Tab value="overview">Umumiy</Tabs.Tab>
          <Tabs.Tab value="segments">Uchastkalar ({line.segments?.length ?? 0})</Tabs.Tab>
          <Tabs.Tab value="map">Xarita</Tabs.Tab>
          <Tabs.Tab value="metal">Rangli metall</Tabs.Tab>
          <Tabs.Tab value="documents">Hujjatlar</Tabs.Tab>
          <Tabs.Tab value="history">Inventarizatsiya va demontaj tarixi</Tabs.Tab>
          {can('audit.view') && <Tabs.Tab value="audit">Audit</Tabs.Tab>}
        </Tabs.List>

        <Tabs.Panel value="overview">
          <SimpleGrid cols={{ base: 1, md: 2 }} spacing="md">
            <Paper p="md">
              <Text fw={600} mb="sm">
                Asosiy ma'lumotlar
              </Text>
              <InfoGrid
                rows={[
                  ['Kabel ID', line.cable_code],
                  ['Kabel turi', line.cable_type_ref?.name ?? line.cable_type],
                  ['Markasi', line.brand],
                  ['Tomirlar soni', line.core_count?.toString()],
                  ['Yotqizilgan yil', line.laid_year_label],
                  ['Balans saqlovchi', line.balance_holder?.name],
                  ["Mas'ul MGQB", line.organization?.name],
                  ['Magistral gaz quvuri', line.gas_pipeline?.name],
                  ['Obyekt', line.object_name],
                  ['Boshlanish nuqtasi', line.start_point],
                  ['Tugash nuqtasi', line.end_point],
                ]}
              />
            </Paper>
            <Stack gap="md">
              <Paper p="md">
                <Text fw={600} mb="sm">
                  Uzunlik va holat
                </Text>
                <InfoGrid
                  rows={[
                    ["Loyiha bo'yicha uzunlik", formatLength(line.design_length_m)],
                    ['Aniqlangan uzunlik', formatLength(line.measured_length_m)],
                  ]}
                />
                <Group gap={6} mt="sm">
                  <StatusBadge enumKey="technical_condition" value={line.technical_condition} />
                  <StatusBadge enumKey="usage_status" value={line.usage_status} />
                  <StatusBadge enumKey="risk_level" value={line.risk_level} />
                  <StatusBadge enumKey="segment_inspection_status" value={line.inspection_status} />
                </Group>
                <Text size="xs" c="dimmed" mt="xs">
                  Liniya holatlari uchastkalar holatidan avtomatik hisoblanadi.
                </Text>
              </Paper>
              {line.initial_cost !== undefined && (
                <Paper p="md">
                  <Text fw={600} mb="sm">
                    Moliyaviy ma'lumotlar
                  </Text>
                  <InfoGrid
                    rows={[
                      ['Inventar raqami', line.inventory_number ?? null],
                      ["Boshlang'ich qiymat", formatMoney(line.initial_cost)],
                      ["Jamg'arilgan amortizatsiya", formatMoney(line.accumulated_depreciation)],
                      ['Qoldiq qiymat', formatMoney(line.residual_value)],
                    ]}
                  />
                </Paper>
              )}
              <Paper p="md">
                <InfoGrid
                  rows={[
                    ['Kiritgan', `${line.created_by ?? '—'}, ${formatDateTime(line.created_at)}`],
                    ["Oxirgi o'zgartirgan", `${line.updated_by ?? '—'}, ${formatDateTime(line.updated_at)}`],
                  ]}
                />
                {line.notes && (
                  <Text size="sm" mt="sm">
                    {line.notes}
                  </Text>
                )}
              </Paper>
            </Stack>
          </SimpleGrid>
        </Tabs.Panel>

        <Tabs.Panel value="segments">
          <Paper p={0}>
            <Group justify="space-between" p="sm">
              <Text fw={600}>Uchastkalar</Text>
              {line.can?.update && (
                <Button size="xs" leftSection={<IconPlus size={14} />} onClick={() => setSegmentModal({ open: true, segment: null })}>
                  Uchastka qo'shish
                </Button>
              )}
            </Group>
            <Table.ScrollContainer minWidth={900}>
              <Table fz="sm">
                <Table.Thead>
                  <Table.Tr>
                    <Table.Th>Kod</Table.Th>
                    <Table.Th>Nomi / joylashuv</Table.Th>
                    <Table.Th>Yotqizish</Table.Th>
                    <Table.Th ta="right">Loyiha</Table.Th>
                    <Table.Th ta="right">Aniqlangan</Table.Th>
                    <Table.Th>Holat</Table.Th>
                    <Table.Th>Geometriya</Table.Th>
                    <Table.Th />
                  </Table.Tr>
                </Table.Thead>
                <Table.Tbody>
                  {line.segments?.map((s) => (
                    <Table.Tr key={s.id}>
                      <Table.Td fw={500}>{s.code}</Table.Td>
                      <Table.Td>
                        {s.name ?? '—'}
                        <Text size="xs" c="dimmed">
                          {s.location_description}
                        </Text>
                      </Table.Td>
                      <Table.Td>
                        {laying.label(s.laying_type)}
                        {s.depth_m && (
                          <Text size="xs" c="dimmed">
                            ~{s.depth_m} m chuqurlikda
                          </Text>
                        )}
                      </Table.Td>
                      <Table.Td ta="right">{formatLength(s.design_length_m)}</Table.Td>
                      <Table.Td ta="right">{formatLength(s.measured_length_m)}</Table.Td>
                      <Table.Td>
                        <Stack gap={4} align="flex-start">
                          <StatusBadge enumKey="dismantling_status" value={s.dismantling_status} size="sm" />
                          <StatusBadge enumKey="segment_inspection_status" value={s.inspection_status} size="sm" />
                          {s.is_complex && <StatusBadge enumKey="risk_level" value={s.risk_level} size="sm" />}
                        </Stack>
                      </Table.Td>
                      <Table.Td>
                        {s.geometry ? (
                          <Text size="xs">
                            {formatLength(s.geometry_length_m)}
                            {s.geometry_source === 'demo' && (
                              <Badge size="xs" color="orange" ml={4}>
                                DEMO
                              </Badge>
                            )}
                          </Text>
                        ) : (
                          <Group gap={4} c="dimmed">
                            <IconMapPinOff size={14} />
                            <Text size="xs">Kiritilmagan</Text>
                          </Group>
                        )}
                      </Table.Td>
                      <Table.Td>
                        <Group gap={4} wrap="nowrap" justify="flex-end">
                          {line.can?.edit_geometry && (
                            <Tooltip label="Xaritada chizish / tahrirlash">
                              <ActionIcon variant="subtle" component={Link} to={`/map?selected=${line.id}&segment=${s.id}&edit=1`} aria-label={`${s.code} geometriyasini tahrirlash`}>
                                <IconMap2 size={16} />
                              </ActionIcon>
                            </Tooltip>
                          )}
                          {line.can?.update && (
                            <Tooltip label="Tahrirlash">
                              <ActionIcon variant="subtle" onClick={() => setSegmentModal({ open: true, segment: s })} aria-label={`${s.code} tahrirlash`}>
                                <IconEdit size={16} />
                              </ActionIcon>
                            </Tooltip>
                          )}
                          {line.can?.update && (line.segments?.length ?? 0) > 1 && (
                            <Tooltip label="O'chirish">
                              <ActionIcon variant="subtle" color="red" onClick={() => setDeletingSegment(s)} aria-label={`${s.code} o'chirish`}>
                                <IconTrash size={16} />
                              </ActionIcon>
                            </Tooltip>
                          )}
                        </Group>
                      </Table.Td>
                    </Table.Tr>
                  ))}
                </Table.Tbody>
              </Table>
            </Table.ScrollContainer>
          </Paper>
        </Tabs.Panel>

        <Tabs.Panel value="map">
          <LineMap line={line} />
        </Tabs.Panel>

        <Tabs.Panel value="metal">
          <LineMetalTab line={line} />
        </Tabs.Panel>

        <Tabs.Panel value="documents">
          <Paper p="md">
            <DocumentsPanel
              documentableType="cable_line"
              documentableId={line.id}
              canUpload={!!line.can?.update && can('documents.upload')}
              suggestedTypes={['technical_passport', 'old_scheme', 'uztransgaz_order', 'company_order', 'photo']}
              onChanged={refresh}
            />
          </Paper>
        </Tabs.Panel>

        <Tabs.Panel value="history">
          <LineHistory lineId={line.id} />
        </Tabs.Panel>

        {can('audit.view') && (
          <Tabs.Panel value="audit">
            <LineAudit lineId={line.id} />
          </Tabs.Panel>
        )}
      </Tabs>

      {segmentModal.open && (
        <SegmentFormModal
          opened
          onClose={() => setSegmentModal({ open: false, segment: null })}
          lineId={line.id}
          segment={segmentModal.segment}
        />
      )}

      <ReasonModal
        opened={deletingSegment !== null}
        onClose={() => setDeletingSegment(null)}
        title={`${deletingSegment?.code ?? ''} uchastkasini o'chirish`}
        description="Faqat xato kiritilgan, tarixi yo'q uchastka o'chiriladi. Amal audit jurnaliga yoziladi."
        confirmLabel="O'chirish"
        color="red"
        onConfirm={async (reason) => {
          await api.delete(`/cable-segments/${deletingSegment!.id}`, { data: { reason } })
          notifySuccess("Uchastka o'chirildi")
          refresh()
        }}
      />
      <ReasonModal
        opened={deletingLine}
        onClose={() => setDeletingLine(false)}
        title={`${line.registry_number} liniyasini o'chirish`}
        description="Inventarizatsiya yoki demontaj tarixi bo'lgan liniya o'chirilmaydi. Amal audit jurnaliga yoziladi."
        confirmLabel="O'chirish"
        color="red"
        onConfirm={async (reason) => {
          await api.delete(`/cable-lines/${line.id}`, { data: { reason } })
          notifySuccess("Liniya o'chirildi")
          void queryClient.invalidateQueries({ queryKey: ['cable-lines'] })
          navigate('/registry')
        }}
      />
    </>
  )
}

function InfoGrid({ rows }: { rows: [string, string | null | undefined][] }) {
  return (
    <Stack gap={6}>
      {rows.map(([label, value]) => (
        <Group key={label} justify="space-between" gap="md" wrap="nowrap" align="flex-start">
          <Text size="sm" c="dimmed">
            {label}
          </Text>
          <Text size="sm" ta="right">
            {value || '—'}
          </Text>
        </Group>
      ))}
    </Stack>
  )
}

function LineMap({ line }: { line: CableLine }) {
  const features = useMapFeatures({ line_id: line.id })
  const withoutGeometry = (line.segments ?? []).filter((s) => !s.geometry)

  return (
    <Stack gap="sm">
      {withoutGeometry.length > 0 && (
        <Alert color="gray" icon={<IconMapPinOff size={18} />}>
          Geometriya kiritilmagan uchastkalar: {withoutGeometry.map((s) => s.code).join(', ')}. Ular xaritada ko'rsatilmaydi — joylashuv taxminiy
          koordinata bilan to'ldirilmaydi.
        </Alert>
      )}
      <Paper p={0} style={{ overflow: 'hidden' }}>
        <CableMap features={features.data?.features ?? []} colorBy="dismantling_status" selectedLineId={line.id} fitKey={features.data ? line.id : null} height={480} loading={features.isFetching} />
      </Paper>
    </Stack>
  )
}

interface TimelineEntry {
  kind: 'inspection' | 'dismantling'
  date: string | null
  reference: string
  reference_id: number
  status: string | null
  event_type?: string
  segment: string | null
  details: string | null
  user?: string | null
}

function LineHistory({ lineId }: { lineId: number }) {
  const eventTypes = useEnum('dismantling_event_type')
  const orderStatus = useEnum('dismantling_order_status')
  const inspectionStatus = useEnum('inspection_status')
  const timeline = useQuery({
    queryKey: ['cable-line-timeline', lineId],
    queryFn: async () => (await api.get<{ data: TimelineEntry[] }>(`/cable-lines/${lineId}/timeline`)).data.data,
  })
  const orders = useQuery({
    queryKey: ['dismantling-orders', { cable_line_id: lineId }],
    queryFn: async () => (await api.get<Paginated<DismantlingOrder>>('/dismantling-orders', { params: { cable_line_id: lineId, per_page: 50 } })).data,
  })

  if (timeline.isLoading) return <PageSkeleton />
  const entries = timeline.data ?? []

  return (
    <SimpleGrid cols={{ base: 1, lg: 2 }} spacing="md">
      <Paper p="md">
        <Text fw={600} mb="md">
          Hodisalar tarixi
        </Text>
        {entries.length === 0 ? (
          <EmptyState title="Hali tarix yo'q" description="Inventarizatsiya yoki demontaj boshlanganda bu yerda ko'rinadi." />
        ) : (
          <Timeline bulletSize={22} lineWidth={2}>
            {entries.map((e, i) => (
              <Timeline.Item
                key={i}
                bullet={e.kind === 'inspection' ? <IconClipboardCheck size={12} /> : <IconTool size={12} />}
                title={
                  <Anchor component={Link} to={e.kind === 'inspection' ? `/inspections/${e.reference_id}` : `/dismantling/${e.reference_id}`} size="sm" fw={600}>
                    {e.reference}
                  </Anchor>
                }
              >
                <Text size="sm">
                  {e.kind === 'inspection'
                    ? `${inspectionStatus.label(e.status)}${e.segment ? `, ${e.segment}` : ''}`
                    : `${eventTypes.label(e.event_type)}${e.status ? `: ${orderStatus.label(e.status)}` : ''}${e.segment ? `, ${e.segment}` : ''}`}
                </Text>
                {e.details && (
                  <Text size="xs" c="dimmed">
                    {e.details}
                  </Text>
                )}
                <Text size="xs" c="dimmed">
                  {formatDateTime(e.date)}
                  {e.user ? `, ${e.user}` : ''}
                </Text>
              </Timeline.Item>
            ))}
          </Timeline>
        )}
      </Paper>
      <Paper p="md">
        <Text fw={600} mb="md">
          Demontaj topshiriqlari
        </Text>
        {(orders.data?.data ?? []).length === 0 ? (
          <EmptyState title="Demontaj topshirig'i yo'q" />
        ) : (
          <Table fz="sm">
            <Table.Tbody>
              {orders.data!.data.map((o) => (
                <Table.Tr key={o.id}>
                  <Table.Td>
                    <Anchor component={Link} to={`/dismantling/${o.id}`} size="sm" fw={600}>
                      {o.number}
                    </Anchor>
                    <Text size="xs" c="dimmed">
                      Reja: {formatDate(o.planned_start)} — {formatDate(o.planned_end)}
                    </Text>
                  </Table.Td>
                  <Table.Td>
                    <StatusBadge enumKey="dismantling_order_status" value={o.status} size="sm" />
                  </Table.Td>
                  <Table.Td ta="right">
                    {formatLength(o.actual_length_m)} / {formatLength(o.planned_length_m)}
                  </Table.Td>
                </Table.Tr>
              ))}
            </Table.Tbody>
          </Table>
        )}
      </Paper>
    </SimpleGrid>
  )
}

function LineAudit({ lineId }: { lineId: number }) {
  const { data, isLoading } = useQuery({
    queryKey: ['audit-logs', { auditable_type: 'cable_line', auditable_id: lineId }],
    queryFn: async () => (await api.get<Paginated<AuditLogEntry>>('/audit-logs', { params: { auditable_type: 'cable_line', auditable_id: lineId, per_page: 100 } })).data,
  })

  return <AuditTable entries={data?.data} loading={isLoading} />
}
