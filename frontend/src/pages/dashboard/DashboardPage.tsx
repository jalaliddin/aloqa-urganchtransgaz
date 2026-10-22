import { BarChart } from '@mantine/charts'
import { Anchor, Badge, Grid, Group, Paper, Progress, SimpleGrid, Stack, Table, Text, Title } from '@mantine/core'
import {
  IconAlertTriangle, IconBuildingWarehouse, IconClipboardCheck, IconFileAlert, IconListDetails, IconMapOff, IconRuler2,
  IconTool, IconTruckLoading,
} from '@tabler/icons-react'
import { useQuery } from '@tanstack/react-query'
import { api } from '@/api/client'
import { METALS, type DashboardData, type MetalKey } from '@/api/types'
import { MassValue } from '@/components/metal/MetalValue'
import { Link } from 'react-router'
import { useAuth } from '@/auth/AuthContext'
import { KpiTile } from '@/components/common/KpiTile'
import { PageHeader } from '@/components/common/PageHeader'
import { ErrorState, PageSkeleton } from '@/components/common/States'
import { SERIES, SINGLE_SERIES } from '@/lib/chartPalette'
import { formatKm, formatMonth, formatNumber, formatPercent, formatQuantity } from '@/lib/format'
import { useEnum } from '@/lib/meta'

const METAL_LABEL: Record<MetalKey, string> = { aluminium: 'Alyuminiy', lead: "Qo'rg'oshin", copper: 'Mis' }

const km = (metres: string) => Math.round((Number(metres) / 1000) * 100) / 100

export default function DashboardPage() {
  const { user } = useAuth()
  const statuses = useEnum('dismantling_status')
  const units = useEnum('material_unit')
  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ['dashboard'],
    queryFn: async () => (await api.get<{ data: DashboardData }>('/dashboard')).data.data,
  })

  if (isLoading) return <PageSkeleton />
  if (error || !data) return <ErrorState error={error} onRetry={() => void refetch()} />

  const k = data.kpis
  const total = Number(k.total_length_m)
  const inventoryShare = total > 0 ? (Number(k.inventoried_length_m) / total) * 100 : null
  const scope = user?.can_view_all_organizations ? 'Barcha MGQBlar' : (user?.organization?.short_name ?? user?.organization?.name)

  const statusRows = data.status_distribution
    .filter((row) => row.segments > 0)
    .map((row) => ({ status: statuses.label(row.status), km: km(row.length_m), segments: row.segments }))

  const orgRows = data.by_organization.map((row) => ({
    name: row.name,
    Reyestr: km(row.total_length_m),
    Inventarizatsiya: km(row.inventoried_length_m),
    'Demontaj qilingan': km(row.dismantled_length_m),
  }))

  const monthly = data.monthly_dismantling.map((row) => ({ month: formatMonth(row.month), km: km(row.length_m) }))
  const hasMonthly = monthly.some((m) => m.km > 0)

  return (
    <>
      <PageHeader
        title="Dashboard"
        description={`Ko'rsatkichlar bazadagi yozuvlardan hisoblanadi. Ko'rish doirasi: ${scope}.`}
        badges={data.has_demo_data ? <Badge color="orange">DEMO ma'lumotlar mavjud</Badge> : undefined}
      />

      <SimpleGrid cols={{ base: 1, xs: 2, lg: 4 }} spacing="md" mb="md">
        <KpiTile label="Kabel liniyalari" value={formatNumber(k.lines_count)} hint={`${formatNumber(k.segments_count)} ta uchastka`} icon={<IconListDetails size={20} />} to="/registry" />
        <KpiTile label="Reyestrdagi uzunlik" value={formatKm(k.total_length_m)} hint="Loyiha bo'yicha jami" icon={<IconRuler2 size={20} />} />
        <KpiTile
          label="Inventarizatsiya qilingan"
          value={formatKm(k.inventoried_length_m)}
          hint={`Reyestrning ${formatPercent(inventoryShare)}`}
          icon={<IconClipboardCheck size={20} />}
          to="/inspections"
        />
        <KpiTile label="Demontaj rejasi" value={formatKm(k.planned_dismantling_length_m)} hint="Faol va yakunlangan topshiriqlar" icon={<IconTool size={20} />} to="/dismantling" />
        <KpiTile
          label="Demontaj qilingan"
          value={formatKm(k.dismantled_length_m)}
          hint={`Rejaning ${formatPercent(k.dismantling_percent)}`}
          icon={<IconTruckLoading size={20} />}
          to="/dismantling"
        />
        <KpiTile
          label="Omborga qabul qilingan"
          value={k.materials_received.length === 0 ? '—' : k.materials_received.map((m) => formatQuantity(m.quantity, units.label(m.unit))).join(', ')}
          hint="Barcha kirim hujjatlari bo'yicha"
          icon={<IconBuildingWarehouse size={20} />}
          to="/materials"
        />
        <KpiTile
          label="Tekshiruv talab qiladi"
          value={`${formatNumber(k.segments_requiring_inspection)} uchastka`}
          hint={`${formatNumber(k.segments_without_geometry)} tasida geometriya kiritilmagan`}
          icon={<IconMapOff size={20} />}
          tone={k.segments_requiring_inspection > 0 ? 'attention' : 'neutral'}
          to="/registry?inspection_status=not_inspected,requires_reinspection,scheduled"
        />
        <KpiTile
          label="Hujjatlari to'liq emas"
          value={`${formatNumber(k.incomplete_documents)} yozuv`}
          hint={`Liniya: ${k.incomplete_documents_breakdown.cable_lines ?? 0}, demontaj: ${(k.incomplete_documents_breakdown.completed_orders ?? 0) + (k.incomplete_documents_breakdown.left_in_place_orders ?? 0)}, ombor: ${k.incomplete_documents_breakdown.material_receipts ?? 0}`}
          icon={<IconFileAlert size={20} />}
          tone={k.incomplete_documents > 0 ? 'attention' : 'neutral'}
          to="/reports?report=incomplete_documents"
        />
        <KpiTile
          label="Xavfli / murakkab uchastkalar"
          value={formatNumber(k.hazardous_segments)}
          hint="Yuqori xavf yoki murakkablik belgisi bor"
          icon={<IconAlertTriangle size={20} />}
          tone={k.hazardous_segments > 0 ? 'risk' : 'neutral'}
          to="/reports?report=hazardous_segments"
        />
        <KpiTile
          label="Ochiq nomuvofiqliklar"
          value={formatNumber(k.open_alerts)}
          hint="Tekshiruv talab qiladigan holatlar"
          icon={<IconAlertTriangle size={20} />}
          tone={k.open_alerts > 0 ? 'attention' : 'neutral'}
          to="/audit?tab=alerts"
        />
      </SimpleGrid>

      <Paper p="md" mb="md">
        <Group justify="space-between" align="flex-start" wrap="wrap" mb="xs">
          <div>
            <Title order={3}>Rangli metall</Title>
            <Text size="sm" c="dimmed">
              Uch ko'rsatkich alohida: reyestrdagi kabellar tarkibi (uzunlik × kg/m), demontaj dalolatnomalari va omborga qabul qilingan massa.
            </Text>
          </div>
          <Anchor component={Link} to="/reports?report=metal_balance" size="sm">
            Metall balansi hisoboti
          </Anchor>
        </Group>
        <Table.ScrollContainer minWidth={560}>
          <Table fz="sm">
            <Table.Thead>
              <Table.Tr>
                <Table.Th>Metall</Table.Th>
                <Table.Th>Reyestrda (nazariy)</Table.Th>
                <Table.Th>Dalolatnoma bo'yicha</Table.Th>
                <Table.Th>Omborga qabul qilingan</Table.Th>
              </Table.Tr>
            </Table.Thead>
            <Table.Tbody>
              {METALS.map((m) => {
                const row = data.metals[m]
                return (
                  <Table.Tr key={m}>
                    <Table.Td fw={500}>{METAL_LABEL[m]}</Table.Td>
                    <Table.Td>
                      <MassValue kg={row.theoretical_complete ? row.theoretical_kg : null} incomplete={!row.theoretical_complete} knownPart={row.theoretical_kg} />
                      {!row.theoretical_complete && (
                        <Text size="xs" c="dimmed">
                          {row.unknown_segments} ta uchastkada tarkib noma'lum
                        </Text>
                      )}
                    </Table.Td>
                    <Table.Td>
                      <MassValue kg={row.act_kg} />
                    </Table.Td>
                    <Table.Td>
                      <MassValue kg={row.warehouse_kg} />
                    </Table.Td>
                  </Table.Tr>
                )
              })}
            </Table.Tbody>
          </Table>
        </Table.ScrollContainer>
      </Paper>

      <Grid gap="md" mb="md">
        <Grid.Col span={{ base: 12, lg: 5 }}>
          <Paper p="md" h="100%">
            <Title order={3} mb={4}>
              Demontaj holatlari bo'yicha taqsimot
            </Title>
            <Text size="sm" c="dimmed" mb="md">
              Uchastkalar uzunligi, km (aniqlangan, bo'lmasa loyiha bo'yicha)
            </Text>
            {statusRows.length === 0 ? (
              <Text c="dimmed" size="sm">
                Hali uchastkalar yo'q.
              </Text>
            ) : (
              <BarChart
                h={Math.max(160, statusRows.length * 38)}
                data={statusRows}
                dataKey="status"
                orientation="vertical"
                series={[{ name: 'km', label: 'Uzunlik, km', color: SINGLE_SERIES }]}
                withBarValueLabel
                gridAxis="none"
                yAxisProps={{ width: 170 }}
                // Headroom so the value label of the longest bar is not clipped
                xAxisProps={{ domain: [0, (max: number) => Math.ceil(max * 1.25)] }}
                valueFormatter={(v) => `${formatNumber(v, 2)} km`}
                barProps={{ radius: [0, 4, 4, 0] }}
              />
            )}
          </Paper>
        </Grid.Col>
        <Grid.Col span={{ base: 12, lg: 7 }}>
          <Paper p="md" h="100%">
            <Title order={3} mb={4}>
              Oylar bo'yicha demontaj dinamikasi
            </Title>
            <Text size="sm" c="dimmed" mb="md">
              Kiritilgan bajarilgan ishlar bo'yicha chiqarilgan kabel, km (so'nggi 12 oy)
            </Text>
            {hasMonthly ? (
              <BarChart
                h={260}
                data={monthly}
                dataKey="month"
                series={[{ name: 'km', label: 'Chiqarilgan, km', color: SINGLE_SERIES }]}
                gridAxis="y"
                valueFormatter={(v) => `${formatNumber(v, 2)} km`}
                barProps={{ radius: [4, 4, 0, 0] }}
              />
            ) : (
              <Text c="dimmed" size="sm">
                So'nggi 12 oyda bajarilgan demontaj ishlari qayd etilmagan.
              </Text>
            )}
          </Paper>
        </Grid.Col>
      </Grid>

      <Paper p="md">
        <Title order={3} mb={4}>
          MGQBlar kesimida bajarilish
        </Title>
        <Text size="sm" c="dimmed" mb="md">
          Reyestr, inventarizatsiya va demontaj uzunligi, km
        </Text>
        {orgRows.length > 0 && (
          <BarChart
            h={Math.max(200, orgRows.length * 70)}
            data={orgRows}
            dataKey="name"
            orientation="vertical"
            withLegend
            legendProps={{ verticalAlign: 'top' }}
            series={[
              { name: 'Reyestr', color: SERIES.registry },
              { name: 'Inventarizatsiya', color: SERIES.inventoried },
              { name: 'Demontaj qilingan', color: SERIES.dismantled },
            ]}
            yAxisProps={{ width: 130 }}
            gridAxis="x"
            valueFormatter={(v) => `${formatNumber(v, 2)} km`}
            barProps={{ radius: [0, 4, 4, 0] }}
            mb="lg"
          />
        )}
        <Table.ScrollContainer minWidth={640}>
          <Table fz="sm">
            <Table.Thead>
              <Table.Tr>
                <Table.Th>MGQB</Table.Th>
                <Table.Th ta="right">Liniyalar</Table.Th>
                <Table.Th ta="right">Reyestr</Table.Th>
                <Table.Th w={200}>Inventarizatsiya</Table.Th>
                <Table.Th ta="right">Demontaj rejasi</Table.Th>
                <Table.Th w={200}>Demontaj bajarilishi</Table.Th>
              </Table.Tr>
            </Table.Thead>
            <Table.Tbody>
              {data.by_organization.map((row) => (
                <Table.Tr key={row.organization_id}>
                  <Table.Td>
                    <Group gap="xs">
                      {row.name}
                      {row.is_demo && (
                        <Badge size="xs" color="orange">
                          DEMO
                        </Badge>
                      )}
                    </Group>
                  </Table.Td>
                  <Table.Td ta="right">{row.lines_count}</Table.Td>
                  <Table.Td ta="right">{formatKm(row.total_length_m)}</Table.Td>
                  <Table.Td>
                    <PercentBar value={row.inventory_percent} label={formatKm(row.inventoried_length_m)} color={SERIES.inventoried} />
                  </Table.Td>
                  <Table.Td ta="right">{formatKm(row.planned_length_m)}</Table.Td>
                  <Table.Td>
                    <PercentBar value={row.dismantling_percent} label={formatKm(row.dismantled_length_m)} color={SERIES.dismantled} />
                  </Table.Td>
                </Table.Tr>
              ))}
            </Table.Tbody>
          </Table>
        </Table.ScrollContainer>
      </Paper>
    </>
  )
}

function PercentBar({ value, label, color }: { value: number | null; label: string; color: string }) {
  return (
    <Stack gap={2}>
      <Group justify="space-between" gap="xs">
        <Text size="xs">{label}</Text>
        <Text size="xs" fw={600}>
          {formatPercent(value)}
        </Text>
      </Group>
      <Progress value={Math.min(100, value ?? 0)} color={color} size="sm" aria-label={`${formatPercent(value)}`} />
    </Stack>
  )
}
