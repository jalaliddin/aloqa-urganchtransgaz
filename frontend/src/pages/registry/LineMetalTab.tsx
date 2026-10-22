import { Alert, Anchor, Button, Group, Paper, Stack, Table, Text, Title } from '@mantine/core'
import { IconAlertTriangle, IconPlus } from '@tabler/icons-react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { Link } from 'react-router'
import { api } from '@/api/client'
import { METALS, type CableLine, type LineMetalContent, type MetalComposition } from '@/api/types'
import { ErrorState, PageSkeleton } from '@/components/common/States'
import { CompositionFormModal, type CompositionScope } from '@/components/metal/CompositionFormModal'
import { CompositionVersionsTable } from '@/components/metal/CompositionVersionsTable'
import { KgPerMetreValue, MassValue } from '@/components/metal/MetalValue'
import { formatDateTime, formatLength, formatMass } from '@/lib/format'
import { useEnum } from '@/lib/meta'

const SCOPE_LABEL = { cable_type: 'Kabel turi', cable_line: 'Liniya', cable_segment: 'Uchastka' } as const
const METAL_LABEL = { aluminium: 'Alyuminiy', lead: "Qo'rg'oshin", copper: 'Mis' } as const

/** Theoretical non-ferrous metal mass of a line: length_m × kg/m per segment, never guessing unknowns. */
export function LineMetalTab({ line }: { line: CableLine }) {
  const queryClient = useQueryClient()
  const triggers = useEnum('metal_calculation_trigger')
  const [composing, setComposing] = useState<{ scope: CompositionScope; current?: Partial<MetalComposition> | null } | null>(null)
  const key = ['line-metal', line.id]
  const { data, isLoading, error } = useQuery({
    queryKey: key,
    queryFn: async () => (await api.get<{ data: LineMetalContent }>(`/cable-lines/${line.id}/metal-content`)).data.data,
  })

  if (isLoading) return <PageSkeleton />
  if (error || !data) return <ErrorState error={error} />

  const refresh = () => {
    void queryClient.invalidateQueries({ queryKey: key })
    void queryClient.invalidateQueries({ queryKey: ['metal-compositions'] })
  }
  const effective = (row: (typeof data.segments)[number]) => ({
    aluminium_kg_per_m: row.metals.aluminium.kg_per_m,
    lead_kg_per_m: row.metals.lead.kg_per_m,
    copper_kg_per_m: row.metals.copper.kg_per_m,
  })

  return (
    <Stack gap="md">
      <Paper p="md">
        <Group justify="space-between" align="flex-start" mb="sm" wrap="wrap">
          <div>
            <Title order={3}>Nazariy massa</Title>
            <Text size="sm" c="dimmed">
              Uchastka hisob uzunligi (aniqlangan, bo'lmasa loyiha bo'yicha) × 1 metrdagi metall miqdori. Kabel turi:{' '}
              {data.cable_type ? (
                <Anchor component={Link} to="/registry/cable-types" size="sm">
                  {data.cable_type.name}
                </Anchor>
              ) : (
                <Text span c="orange.8" size="sm">
                  tanlanmagan
                </Text>
              )}
            </Text>
          </div>
          {data.can.submit && (
            <Button
              size="xs"
              variant="light"
              leftSection={<IconPlus size={14} />}
              onClick={() => setComposing({ scope: { type: 'cable_line', id: line.id, label: line.registry_number }, current: data.segments[0] ? effective(data.segments[0]) : null })}
            >
              Liniya uchun qiymat kiritish
            </Button>
          )}
        </Group>

        {!data.complete && (
          <Alert color="orange" variant="light" icon={<IconAlertTriangle size={18} />} mb="sm">
            Ayrim uchastkalarda metall tarkibi noma'lum. Ular 0 deb hisoblanmaydi: jami faqat ma'lum qismning yig'indisi bo'lib, ishonchli
            hisoblangan sifatida ko'rsatilmaydi.
          </Alert>
        )}

        <Table.ScrollContainer minWidth={1000}>
          <Table fz="sm">
            <Table.Thead>
              <Table.Tr>
                <Table.Th>Uchastka</Table.Th>
                <Table.Th ta="right">Hisob uzunligi</Table.Th>
                <Table.Th>Qiymat manbai</Table.Th>
                {METALS.map((m) => (
                  <Table.Th key={m}>{METAL_LABEL[m]}</Table.Th>
                ))}
                <Table.Th />
              </Table.Tr>
            </Table.Thead>
            <Table.Tbody>
              {data.segments.map((row) => (
                <Table.Tr key={row.segment_id}>
                  <Table.Td fw={500}>{row.code}</Table.Td>
                  <Table.Td ta="right">{formatLength(row.length_m)}</Table.Td>
                  <Table.Td>{row.scope ? SCOPE_LABEL[row.scope] : <Text size="sm" c="orange.8">yo'q</Text>}</Table.Td>
                  {METALS.map((m) => (
                    <Table.Td key={m}>
                      <Stack gap={2}>
                        <MassValue kg={row.metals[m].theoretical_kg} />
                        <Text size="xs" c="dimmed" span>
                          <KgPerMetreValue value={row.metals[m].kg_per_m} />
                        </Text>
                      </Stack>
                    </Table.Td>
                  ))}
                  <Table.Td>
                    {data.can.submit && (
                      <Button
                        size="compact-xs"
                        variant="subtle"
                        onClick={() => setComposing({ scope: { type: 'cable_segment', id: row.segment_id, label: row.code, lineId: line.id }, current: effective(row) })}
                      >
                        Uchastka uchun qiymat
                      </Button>
                    )}
                  </Table.Td>
                </Table.Tr>
              ))}
            </Table.Tbody>
            <Table.Tfoot>
              <Table.Tr>
                <Table.Th colSpan={3}>Jami</Table.Th>
                {METALS.map((m) => (
                  <Table.Th key={m}>
                    <MassValue
                      kg={data.totals[m].complete ? data.totals[m].known_kg : null}
                      incomplete={!data.totals[m].complete}
                      knownPart={data.totals[m].known_kg}
                    />
                    {!data.totals[m].complete && (
                      <Text size="xs" c="dimmed">
                        Noma'lum: {data.totals[m].unknown_segments.join(', ')}
                      </Text>
                    )}
                  </Table.Th>
                ))}
                <Table.Th />
              </Table.Tr>
            </Table.Tfoot>
          </Table>
        </Table.ScrollContainer>
      </Paper>

      <Paper p="md">
        <Title order={3} mb="sm">
          Qiymatlar va tasdiqlar
        </Title>
        <CompositionVersionsTable versions={data.versions} onChanged={refresh} />
      </Paper>

      <Paper p="md">
        <Title order={3} mb={4}>
          Hisob-kitob tarixi
        </Title>
        <Text size="sm" c="dimmed" mb="sm">
          Tarkib tasdiqlanganda yoki uzunlik o'zgarganda nazariy massa qayta hisoblanadi; oldingi natijalar shu yerda saqlanadi.
        </Text>
        {data.history.length === 0 ? (
          <Text size="sm" c="dimmed">
            Hali hisob-kitob yozuvi yo'q.
          </Text>
        ) : (
          <Table.ScrollContainer minWidth={860}>
            <Table fz="sm">
              <Table.Thead>
                <Table.Tr>
                  <Table.Th>Vaqt</Table.Th>
                  <Table.Th>Uchastka</Table.Th>
                  <Table.Th>Sabab</Table.Th>
                  <Table.Th ta="right">Uzunlik</Table.Th>
                  {METALS.map((m) => (
                    <Table.Th key={m} ta="right">
                      {METAL_LABEL[m]}
                    </Table.Th>
                  ))}
                </Table.Tr>
              </Table.Thead>
              <Table.Tbody>
                {data.history.map((h) => (
                  <Table.Tr key={h.id}>
                    <Table.Td>
                      {formatDateTime(h.created_at)}
                      <Text size="xs" c="dimmed">
                        {h.calculated_by ?? 'Tizim'}
                      </Text>
                    </Table.Td>
                    <Table.Td>{h.segment_code}</Table.Td>
                    <Table.Td>{triggers.label(h.trigger)}</Table.Td>
                    <Table.Td ta="right">{formatLength(h.length_m)}</Table.Td>
                    <Table.Td ta="right">{formatMass(h.aluminium_kg)}</Table.Td>
                    <Table.Td ta="right">{formatMass(h.lead_kg)}</Table.Td>
                    <Table.Td ta="right">{formatMass(h.copper_kg)}</Table.Td>
                  </Table.Tr>
                ))}
              </Table.Tbody>
            </Table>
          </Table.ScrollContainer>
        )}
      </Paper>

      {composing && <CompositionFormModal scope={composing.scope} current={composing.current as MetalComposition | null} onClose={() => setComposing(null)} onSaved={refresh} />}
    </Stack>
  )
}
