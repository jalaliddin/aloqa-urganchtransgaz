import { Badge, Code, Collapse, Group, Paper, Stack, Table, Text, UnstyledButton } from '@mantine/core'
import { IconChevronDown, IconChevronRight } from '@tabler/icons-react'
import { Fragment, useState } from 'react'
import type { AuditLogEntry } from '@/api/types'
import { EmptyState } from '@/components/common/States'
import { formatDateTime } from '@/lib/format'

export const EVENT_LABELS: Record<string, string> = {
  created: 'Yaratildi',
  updated: "O'zgartirildi",
  deleted: "O'chirildi",
  soft_deleted: "O'chirildi (arxiv)",
  restored: 'Tiklandi',
  login: 'Tizimga kirdi',
  login_failed: 'Muvaffaqiyatsiz kirish',
  login_locked: 'Kirish bloklandi',
  login_inactive: 'Faol bo\'lmagan foydalanuvchi kirishi',
  logout: 'Tizimdan chiqdi',
  status_changed: "Holat o'zgardi",
  approval_recorded: 'Kelishuv qayd etildi',
  approval_revoked: 'Kelishuv bekor qilindi',
  inspection_started: 'Ko\'rik boshlandi',
  inspection_submitted: 'Tasdiqlashga yuborildi',
  inspection_approved: 'Natija tasdiqlandi',
  inspection_rejected: 'Natija qaytarildi',
  inspection_cancelled: 'Topshiriq bekor qilindi',
  geometry_updated: 'Geometriya yangilandi',
  geometry_cleared: "Geometriya o'chirildi",
  geojson_imported: 'GeoJSON import',
  map_exported: 'Xarita eksporti',
  document_downloaded: 'Hujjat yuklab olindi',
  document_voided: 'Hujjat bekor qilindi',
  report_exported: 'Hisobot eksporti',
  pdf_generated: 'PDF shakllantirildi',
  material_discrepancy_decided: 'Material farqi bo\'yicha qaror',
  warehouse_adjustment_decided: 'Ombor tuzatishi bo\'yicha qaror',
  alert_resolved: 'Nomuvofiqlik yopildi',
  line_deleted: "Liniya o'chirildi",
  segment_deleted: "Uchastka o'chirildi",
  roles_assigned: 'Rollar biriktirildi',
  role_permissions_changed: "Rol ruxsatlari o'zgardi",
  settings_updated: 'Sozlamalar yangilandi',
}

export const TYPE_LABELS: Record<string, string> = {
  cable_line: 'Kabel liniyasi',
  cable_segment: 'Uchastka',
  inspection: 'Inventarizatsiya',
  inspection_item: "Ko'rik natijasi",
  dismantling_order: 'Demontaj',
  approval: 'Kelishuv',
  material_receipt: 'Ombor kirimi',
  material_item: 'Material',
  warehouse_transaction: 'Ombor harakati',
  document: 'Hujjat',
  user: 'Foydalanuvchi',
  organization: 'Tashkilot',
  gas_pipeline_asset: 'Gaz quvuri',
  alert: 'Nomuvofiqlik',
}

const severe = ['login_failed', 'login_locked', 'deleted', 'soft_deleted', 'line_deleted', 'segment_deleted', 'document_voided', 'approval_revoked']

function stringify(value: unknown): string {
  if (value === null || value === undefined) return '—'
  return typeof value === 'object' ? JSON.stringify(value) : String(value)
}

function Diff({ entry }: { entry: AuditLogEntry }) {
  const keys = Array.from(new Set([...Object.keys(entry.old_values ?? {}), ...Object.keys(entry.new_values ?? {})]))
  if (keys.length === 0) return <Text size="sm" c="dimmed">Qiymatlar o'zgarishi qayd etilmagan.</Text>

  return (
    <Table fz="xs" withTableBorder>
      <Table.Thead>
        <Table.Tr>
          <Table.Th>Maydon</Table.Th>
          <Table.Th>Eski qiymat</Table.Th>
          <Table.Th>Yangi qiymat</Table.Th>
        </Table.Tr>
      </Table.Thead>
      <Table.Tbody>
        {keys.map((key) => (
          <Table.Tr key={key}>
            <Table.Td>
              <Code>{key}</Code>
            </Table.Td>
            <Table.Td style={{ wordBreak: 'break-word' }}>{stringify(entry.old_values?.[key])}</Table.Td>
            <Table.Td style={{ wordBreak: 'break-word' }}>{stringify(entry.new_values?.[key])}</Table.Td>
          </Table.Tr>
        ))}
      </Table.Tbody>
    </Table>
  )
}

/** Read-only audit table with expandable old/new values. */
export function AuditTable({ entries, loading }: { entries: AuditLogEntry[] | undefined; loading?: boolean }) {
  const [open, setOpen] = useState<number | null>(null)

  if (!loading && (!entries || entries.length === 0)) return <EmptyState title="Audit yozuvlari yo'q" />

  return (
    <Paper p={0}>
      <Table.ScrollContainer minWidth={860}>
        <Table fz="sm">
          <Table.Thead>
            <Table.Tr>
              <Table.Th w={30} />
              <Table.Th>Vaqt</Table.Th>
              <Table.Th>Foydalanuvchi</Table.Th>
              <Table.Th>Amal</Table.Th>
              <Table.Th>Obyekt</Table.Th>
              <Table.Th>Sabab</Table.Th>
              <Table.Th>IP</Table.Th>
            </Table.Tr>
          </Table.Thead>
          <Table.Tbody>
            {(entries ?? []).map((entry) => (
              <Fragment key={entry.id}>
                <Table.Tr>
                  <Table.Td>
                    <UnstyledButton onClick={() => setOpen(open === entry.id ? null : entry.id)} aria-expanded={open === entry.id} aria-label="Tafsilotlar">
                      {open === entry.id ? <IconChevronDown size={14} /> : <IconChevronRight size={14} />}
                    </UnstyledButton>
                  </Table.Td>
                  <Table.Td style={{ whiteSpace: 'nowrap' }}>{formatDateTime(entry.created_at)}</Table.Td>
                  <Table.Td>{entry.user_name ?? 'Tizim'}</Table.Td>
                  <Table.Td>
                    <Badge size="sm" variant="light" color={severe.includes(entry.event) ? 'red' : 'steel'}>
                      {EVENT_LABELS[entry.event] ?? entry.event}
                    </Badge>
                  </Table.Td>
                  <Table.Td>
                    {entry.auditable_type ? (
                      <Text size="sm">
                        {TYPE_LABELS[entry.auditable_type] ?? entry.auditable_type}: {entry.auditable_label ?? `#${entry.auditable_id}`}
                      </Text>
                    ) : (
                      '—'
                    )}
                  </Table.Td>
                  <Table.Td>
                    <Text size="sm" lineClamp={2}>
                      {entry.reason ?? '—'}
                    </Text>
                  </Table.Td>
                  <Table.Td>{entry.ip_address ?? '—'}</Table.Td>
                </Table.Tr>
                <Table.Tr style={{ display: open === entry.id ? undefined : 'none' }}>
                  <Table.Td colSpan={7} bg="gray.0">
                    <Collapse expanded={open === entry.id}>
                      <Stack gap="xs" p="xs">
                        <Diff entry={entry} />
                        <Group gap="lg">
                          <Text size="xs" c="dimmed">
                            {entry.method} /{entry.url}
                          </Text>
                          <Text size="xs" c="dimmed" lineClamp={1}>
                            {entry.user_agent}
                          </Text>
                        </Group>
                      </Stack>
                    </Collapse>
                  </Table.Td>
                </Table.Tr>
              </Fragment>
            ))}
          </Table.Tbody>
        </Table>
      </Table.ScrollContainer>
    </Paper>
  )
}
