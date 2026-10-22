import { Badge, Button, CloseButton, Divider, Group, Paper, ScrollArea, SimpleGrid, Skeleton, Stack, Text } from '@mantine/core'
import { IconEdit, IconFileText, IconHistory, IconListDetails, IconPencil } from '@tabler/icons-react'
import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router'
import { api } from '@/api/client'
import type { CableLine } from '@/api/types'
import { StatusBadge } from '@/components/common/StatusBadge'
import { formatLength } from '@/lib/format'
import { useEnum } from '@/lib/meta'

interface SelectedLinePanelProps {
  lineId: number
  segmentId?: number | null
  onClose: () => void
  onEditGeometry?: (segmentId: number) => void
  onSelectSegment?: (segmentId: number) => void
}

/** Short summary of the selected line with the actions required by the spec. */
export function SelectedLinePanel({ lineId, segmentId, onClose, onEditGeometry, onSelectSegment }: SelectedLinePanelProps) {
  const laying = useEnum('laying_type')
  const flags = useEnum('complexity_flag')
  const { data: line, isLoading } = useQuery({
    queryKey: ['cable-line', lineId],
    queryFn: async () => (await api.get<{ data: CableLine }>(`/cable-lines/${lineId}`)).data.data,
  })

  const segment = line?.segments?.find((s) => s.id === segmentId) ?? null

  return (
    <Paper p="md" shadow="sm" h="100%" style={{ display: 'flex', flexDirection: 'column' }} aria-label="Tanlangan kabel liniyasi">
      <Group justify="space-between" align="flex-start" wrap="nowrap" mb="xs">
        <div style={{ minWidth: 0 }}>
          <Text size="xs" c="dimmed">
            {line?.registry_number ?? '…'}
          </Text>
          <Text fw={600} lineClamp={2}>
            {line?.name ?? 'Yuklanmoqda'}
          </Text>
        </div>
        <CloseButton onClick={onClose} aria-label="Panelni yopish" />
      </Group>

      {isLoading || !line ? (
        <Stack gap="xs">
          <Skeleton h={14} />
          <Skeleton h={14} w="70%" />
          <Skeleton h={80} />
        </Stack>
      ) : (
        <ScrollArea style={{ flex: 1 }} offsetScrollbars>
          <Stack gap="sm">
            <Group gap={6}>
              {line.is_demo && <Badge color="orange">DEMO</Badge>}
              <StatusBadge enumKey="dismantling_status" value={line.dismantling_status} />
              <StatusBadge enumKey="usage_status" value={line.usage_status} />
            </Group>
            <SimpleGrid cols={2} spacing="xs" verticalSpacing={6}>
              <Info label="MGQB" value={line.organization?.short_name ?? line.organization?.name} />
              <Info label="Gaz quvuri" value={line.gas_pipeline?.name} />
              <Info label="Markasi" value={line.brand} />
              <Info label="Yotqizilgan" value={line.laid_year_label} />
              <Info label="Loyiha bo'yicha" value={formatLength(line.design_length_m)} />
              <Info label="Aniqlangan" value={formatLength(line.measured_length_m)} />
            </SimpleGrid>

            <Divider label="Uchastkalar" labelPosition="left" />
            <Stack gap={6}>
              {line.segments?.map((s) => (
                <Paper
                  key={s.id}
                  p="xs"
                  withBorder
                  style={{
                    borderColor: s.id === segmentId ? 'var(--app-signal)' : undefined,
                    cursor: onSelectSegment ? 'pointer' : undefined,
                  }}
                  onClick={() => onSelectSegment?.(s.id)}
                >
                  <Group justify="space-between" gap="xs" wrap="nowrap">
                    <Text size="sm" fw={500}>
                      {s.code}
                    </Text>
                    {!s.geometry && (
                      <Badge size="xs" color="gray" variant="outline">
                        Geometriya kiritilmagan
                      </Badge>
                    )}
                  </Group>
                  <Text size="xs" c="dimmed">
                    {[s.name, laying.label(s.laying_type), formatLength(s.measured_length_m ?? s.design_length_m)].filter(Boolean).join(', ')}
                  </Text>
                  {s.complexity_flags.length > 0 && (
                    <Text size="xs" c="orange.8">
                      {s.complexity_flags.map((f) => flags.label(f)).join(', ')}
                    </Text>
                  )}
                </Paper>
              ))}
            </Stack>
            {segment && (
              <Text size="xs" c="dimmed">
                Tanlangan uchastka: {segment.code}
                {segment.geometry_source ? `, manba: ${segment.geometry_source}` : ''}
              </Text>
            )}
          </Stack>
        </ScrollArea>
      )}

      {line && (
        <Stack gap={6} mt="sm">
          <Group grow gap={6}>
            <Button size="xs" component={Link} to={`/registry/${line.id}`} leftSection={<IconListDetails size={14} />}>
              Batafsil
            </Button>
            {line.can?.update && (
              <Button size="xs" variant="default" component={Link} to={`/registry/${line.id}/edit`} leftSection={<IconEdit size={14} />}>
                Tahrirlash
              </Button>
            )}
          </Group>
          <Group grow gap={6}>
            <Button size="xs" variant="default" component={Link} to={`/registry/${line.id}?tab=documents`} leftSection={<IconFileText size={14} />}>
              Hujjatlar
            </Button>
            <Button size="xs" variant="default" component={Link} to={`/registry/${line.id}?tab=history`} leftSection={<IconHistory size={14} />}>
              Demontaj tarixi
            </Button>
          </Group>
          {onEditGeometry && line.can?.edit_geometry && (
            <Button
              size="xs"
              variant="light"
              color="signal.8"
              leftSection={<IconPencil size={14} />}
              disabled={!segmentId}
              onClick={() => segmentId && onEditGeometry(segmentId)}
            >
              {segmentId ? 'Uchastka geometriyasini tahrirlash' : 'Tahrirlash uchun uchastkani tanlang'}
            </Button>
          )}
        </Stack>
      )}
    </Paper>
  )
}

function Info({ label, value }: { label: string; value: string | null | undefined }) {
  return (
    <div>
      <Text size="xs" c="dimmed">
        {label}
      </Text>
      <Text size="sm">{value || '—'}</Text>
    </div>
  )
}
