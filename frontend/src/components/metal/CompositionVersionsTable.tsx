import { Badge, Button, Group, Stack, Table, Text } from '@mantine/core'
import { modals } from '@mantine/modals'
import { useState } from 'react'
import { api } from '@/api/client'
import type { MetalComposition } from '@/api/types'
import { notifyError, notifySuccess } from '@/components/common/notify'
import { ReasonModal } from '@/components/common/ReasonModal'
import { EmptyState } from '@/components/common/States'
import { StatusBadge } from '@/components/common/StatusBadge'
import { formatDateTime } from '@/lib/format'
import { useEnum } from '@/lib/meta'
import { KgPerMetreValue } from './MetalValue'

const SCOPE_LABEL = { cable_type: 'Kabel turi', cable_line: 'Liniya', cable_segment: 'Uchastka' } as const

/** Every version of kg/m values (never deleted), with approve / reject for authorised users. */
export function CompositionVersionsTable({ versions, onChanged, showScope = true }: { versions: MetalComposition[]; onChanged: () => void; showScope?: boolean }) {
  const sources = useEnum('composition_source_type')
  const [rejecting, setRejecting] = useState<MetalComposition | null>(null)

  if (versions.length === 0) {
    return <EmptyState title="Qiymat kiritilmagan" description="Kabel turi yoki uchastka uchun kg/m qiymati kiritilmaguncha nazariy massa noma'lum." />
  }

  const approve = (version: MetalComposition) =>
    modals.openConfirmModal({
      title: 'Qiymatni tasdiqlash',
      children: (
        <Text size="sm">
          Tasdiqlangandan so'ng bu qiymat amalga kiradi, oldingi versiya eskirgan deb saqlanadi va bog'liq uchastkalar uchun nazariy massa qayta
          hisoblanib, tarixga yoziladi.
        </Text>
      ),
      labels: { confirm: 'Tasdiqlash', cancel: 'Bekor qilish' },
      confirmProps: { color: 'teal' },
      onConfirm: async () => {
        try {
          await api.post(`/metal-compositions/${version.id}/approve`)
          notifySuccess('Qiymat tasdiqlandi')
          onChanged()
        } catch (e) {
          notifyError(e, 'Tasdiqlab bo\'lmadi')
        }
      },
    })

  return (
    <>
      <Table.ScrollContainer minWidth={980}>
        <Table fz="sm">
          <Table.Thead>
            <Table.Tr>
              {showScope && <Table.Th>Daraja</Table.Th>}
              <Table.Th>Alyuminiy</Table.Th>
              <Table.Th>Qo'rg'oshin</Table.Th>
              <Table.Th>Mis</Table.Th>
              <Table.Th>Manba</Table.Th>
              <Table.Th>Kiritgan / qaror</Table.Th>
              <Table.Th>Holat</Table.Th>
            </Table.Tr>
          </Table.Thead>
          <Table.Tbody>
            {versions.map((v) => (
              <Table.Tr key={v.id} style={{ opacity: ['superseded', 'rejected'].includes(v.status) ? 0.6 : 1 }}>
                {showScope && (
                  <Table.Td>
                    <Text size="sm">{SCOPE_LABEL[v.scope_type]}</Text>
                    <Text size="xs" c="dimmed">
                      {v.scope_label}
                    </Text>
                  </Table.Td>
                )}
                <Table.Td>
                  <KgPerMetreValue value={v.aluminium_kg_per_m} />
                </Table.Td>
                <Table.Td>
                  <KgPerMetreValue value={v.lead_kg_per_m} />
                </Table.Td>
                <Table.Td>
                  <KgPerMetreValue value={v.copper_kg_per_m} />
                </Table.Td>
                <Table.Td maw={260}>
                  <Text size="sm">{v.source_reference}</Text>
                  <Text size="xs" c="dimmed">
                    {sources.label(v.source_type)}
                    {v.document_title ? `, ${v.document_title}` : ''}
                  </Text>
                  {v.is_demo && (
                    <Badge size="xs" color="orange">
                      DEMO
                    </Badge>
                  )}
                </Table.Td>
                <Table.Td>
                  <Text size="xs">
                    {v.entered_by}, {formatDateTime(v.entered_at)}
                  </Text>
                  {v.decided_at && (
                    <Text size="xs" c="dimmed">
                      {v.decided_by}, {formatDateTime(v.decided_at)}
                      {v.decision_comment ? ` — ${v.decision_comment}` : ''}
                    </Text>
                  )}
                </Table.Td>
                <Table.Td>
                  <Stack gap={6} align="flex-start">
                    <StatusBadge enumKey="composition_status" value={v.status} size="sm" />
                    {v.can_decide && (
                      <Group gap={4} wrap="nowrap">
                        <Button size="compact-xs" color="teal" onClick={() => approve(v)}>
                          Tasdiqlash
                        </Button>
                        <Button size="compact-xs" variant="light" color="red" onClick={() => setRejecting(v)}>
                          Rad etish
                        </Button>
                      </Group>
                    )}
                  </Stack>
                </Table.Td>
              </Table.Tr>
            ))}
          </Table.Tbody>
        </Table>
      </Table.ScrollContainer>
      <ReasonModal
        opened={rejecting !== null}
        onClose={() => setRejecting(null)}
        title="Qiymatni rad etish"
        confirmLabel="Rad etish"
        color="red"
        onConfirm={async (reason) => {
          await api.post(`/metal-compositions/${rejecting!.id}/reject`, { reason })
          notifySuccess('Qiymat rad etildi')
          onChanged()
        }}
      />
    </>
  )
}
