import { Button, Checkbox, Grid, Group, NavLink, Paper, Select, Stack, Text, Title } from '@mantine/core'
import { DatePickerInput } from '@mantine/dates'
import { IconDownload } from '@tabler/icons-react'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { useSearchParams } from 'react-router'
import { api, downloadFile } from '@/api/client'
import { useOrganizationOptions } from '@/api/queries'
import type { ReportColumn, ReportDefinition } from '@/api/types'
import { useAuth } from '@/auth/AuthContext'
import { DataTable, type Column } from '@/components/common/DataTable'
import { notifyError } from '@/components/common/notify'
import { PageHeader } from '@/components/common/PageHeader'
import { EnumCell } from '@/components/common/EnumCell'
import { KgPerMetreValue, MassValue } from '@/components/metal/MetalValue'
import { ErrorState, PageSkeleton } from '@/components/common/States'
import { cleanParams } from '@/components/map/useMapFeatures'
import { formatDate, formatLength, formatMoney, formatNumber, formatPercent } from '@/lib/format'
import { useEnum } from '@/lib/meta'

type Row = Record<string, unknown>

function renderCell(column: ReportColumn, value: unknown) {
  if (column.type === 'mass') return <MassValue kg={value === '' ? null : (value as string | null)} />
  if (column.type === 'kg_per_m') {
    return value !== null && value !== undefined && Number.isNaN(Number(value)) ? String(value) : <KgPerMetreValue value={value as string | null} />
  }
  if (value === null || value === undefined || value === '') return '—'
  if (column.type.startsWith('enum:')) return <EnumCell enumKey={column.type.slice(5)} value={String(value)} />
  switch (column.type) {
    case 'length':
      return formatLength(value as string)
    case 'money':
      return formatMoney(value as string)
    case 'percent':
      return formatPercent(value as number)
    case 'number':
      return formatNumber(value as string, Number.isInteger(Number(value)) ? 0 : 2)
    case 'date':
      return formatDate(String(value))
    case 'bool':
      return value ? 'Ha' : "Yo'q"
    default:
      return String(value)
  }
}

export default function ReportsPage() {
  const { can } = useAuth()
  const [params, setParams] = useSearchParams()
  const organizations = useOrganizationOptions()
  const definitions = useQuery({
    queryKey: ['reports'],
    queryFn: async () => (await api.get<{ data: ReportDefinition[] }>('/reports')).data.data,
    staleTime: Infinity,
  })
  const key = params.get('report') ?? definitions.data?.[0]?.key ?? null
  const report = definitions.data?.find((r) => r.key === key)
  const statusEnum = useEnum(report?.status_filter ?? '')
  const [organization, setOrganization] = useState<string | null>(null)
  const [status, setStatus] = useState<string | null>(null)
  const [range, setRange] = useState<[string | null, string | null]>([null, null])
  const [onlyDiscrepancies, setOnlyDiscrepancies] = useState(false)
  const [page, setPage] = useState(1)

  const filters = cleanParams({
    organization_id: organization,
    status,
    date_from: range[0],
    date_to: range[1],
    only_discrepancies: key === 'inventory_results' && onlyDiscrepancies ? 1 : null,
  })

  const rows = useQuery({
    queryKey: ['report', key, filters, page],
    queryFn: async () =>
      (
        await api.get<{ data: Row[]; meta: { total: number }; totals: { values: Record<string, string>; incomplete: string[] } | null }>(`/reports/${key}`, {
          params: { ...filters, page, per_page: 25 },
        })
      ).data,
    enabled: !!key,
    placeholderData: keepPreviousData,
  })

  if (definitions.isLoading) return <PageSkeleton />
  if (definitions.error) return <ErrorState error={definitions.error} />

  const columns: Column<Row>[] = (report?.columns ?? []).map((c) => ({
    key: c.key,
    header: c.label,
    align: ['length', 'money', 'percent', 'number'].includes(c.type) ? 'right' : undefined,
    render: (row: Row) => renderCell(c, row[c.key]),
  }))

  const exportReport = async (format: 'xlsx' | 'csv') => {
    try {
      await downloadFile(`/reports/${key}/export`, { ...filters, format })
    } catch (e) {
      notifyError(e, 'Eksport bajarilmadi')
    }
  }

  const select = (next: string) => {
    setParams({ report: next })
    setStatus(null)
    setPage(1)
  }

  return (
    <>
      <PageHeader
        title="Hisobotlar"
        description="Ko'rinish va eksportga faqat sizning vakolatingiz doirasidagi ma'lumotlar kiradi. Eksport audit jurnaliga yoziladi."
        actions={
          can('reports.export') &&
          report && (
            <Group gap="xs">
              <Button variant="default" leftSection={<IconDownload size={16} />} onClick={() => exportReport('csv')}>
                CSV
              </Button>
              <Button leftSection={<IconDownload size={16} />} onClick={() => exportReport('xlsx')}>
                Excel
              </Button>
            </Group>
          )
        }
      />
      <Grid gap="md">
        <Grid.Col span={{ base: 12, md: 3 }}>
          <Paper p="xs">
            <Stack gap={2} component="nav" aria-label="Hisobotlar ro'yxati">
              {definitions.data?.map((r) => (
                <NavLink key={r.key} label={r.title} active={r.key === key} onClick={() => select(r.key)} />
              ))}
            </Stack>
          </Paper>
        </Grid.Col>
        <Grid.Col span={{ base: 12, md: 9 }}>
          {report && (
            <Stack gap="sm">
              <Title order={2}>{report.title}</Title>
              <DataTable
                columns={columns}
                rows={rows.data?.data}
                rowKey={(row) => JSON.stringify(row).slice(0, 200)}
                loading={rows.isFetching}
                error={rows.error}
                total={rows.data?.meta.total}
                page={page}
                perPage={25}
                onPageChange={setPage}
                storageKey={`report-${report.key}`}
                minWidth={Math.max(720, report.columns.length * 140)}
                emptyTitle="Tanlangan filtrlar bo'yicha yozuv yo'q"
                toolbar={
                  <>
                    {organizations.length > 1 && (
                      <Select size="sm" aria-label="MGQB" placeholder="MGQB" data={organizations} value={organization} onChange={(v) => { setOrganization(v); setPage(1) }} clearable />
                    )}
                    {report.status_filter && (
                      <Select size="sm" aria-label="Holat" placeholder="Holat" data={statusEnum.selectData} value={status} onChange={(v) => { setStatus(v); setPage(1) }} clearable />
                    )}
                    {report.date_label && (
                      <DatePickerInput
                        size="sm"
                        type="range"
                        aria-label={report.date_label}
                        placeholder={report.date_label}
                        valueFormat="DD.MM.YYYY"
                        clearable
                        value={range}
                        onChange={(v) => {
                          setRange(v as [string | null, string | null])
                          setPage(1)
                        }}
                        w={260}
                      />
                    )}
                    {report.key === 'inventory_results' && (
                      <Checkbox label="Faqat farqlar" checked={onlyDiscrepancies} onChange={(e) => setOnlyDiscrepancies(e.currentTarget.checked)} />
                    )}
                  </>
                }
              />
              {rows.data?.totals && (
                <Paper p="sm" aria-label="Jami">
                  <Text fw={600} size="sm" mb={6}>
                    Jami (barcha sahifalar bo'yicha)
                  </Text>
                  <Group gap="xl" wrap="wrap">
                    {Object.entries(rows.data.totals.values).map(([column, value]) => {
                      const def = report.columns.find((c) => c.key === column)
                      const incomplete = rows.data!.totals!.incomplete.includes(column)
                      return (
                        <div key={column}>
                          <Text size="xs" c="dimmed">
                            {def?.label ?? column}
                          </Text>
                          {def?.type === 'mass' ? (
                            <MassValue kg={incomplete ? null : value} incomplete={incomplete} knownPart={value} />
                          ) : (
                            <Text size="sm" c={incomplete ? 'orange.8' : undefined}>
                              {def ? renderCell(def, value) : value}
                              {incomplete ? " (to'liq emas)" : ''}
                            </Text>
                          )}
                        </div>
                      )
                    })}
                  </Group>
                </Paper>
              )}
              <Text size="xs" c="dimmed">
                Rangli metall: "noma'lum" — tarkib ma'lumoti yo'q (0 deb olinmaydi); "to'liq emas" — jami faqat ma'lum qismdan iborat.{' '}
                Uzunliklar bazada metrda saqlanadi; jadvalda 1000 m dan ortiq qiymatlar km da ko'rsatiladi, eksportda metrda.
              </Text>
            </Stack>
          )}
        </Grid.Col>
      </Grid>
    </>
  )
}
