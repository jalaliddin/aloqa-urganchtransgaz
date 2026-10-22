import { Anchor, Badge, Button, Group, MultiSelect, Progress, Select, Stack, Text, TextInput } from '@mantine/core'
import { useDebouncedValue } from '@mantine/hooks'
import { IconPlus, IconSearch } from '@tabler/icons-react'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { Link, useNavigate } from 'react-router'
import { api } from '@/api/client'
import { useOrganizationOptions } from '@/api/queries'
import type { DismantlingOrder, Paginated } from '@/api/types'
import { useAuth } from '@/auth/AuthContext'
import { DataTable, type Column } from '@/components/common/DataTable'
import { PageHeader } from '@/components/common/PageHeader'
import { StatusBadge } from '@/components/common/StatusBadge'
import { cleanParams } from '@/components/map/useMapFeatures'
import { formatDate, formatLength, formatPercent } from '@/lib/format'
import { useEnum } from '@/lib/meta'

export default function DismantlingPage() {
  const { can } = useAuth()
  const navigate = useNavigate()
  const statuses = useEnum('dismantling_order_status')
  const organizations = useOrganizationOptions()
  const [status, setStatus] = useState<string[]>([])
  const [organization, setOrganization] = useState<string | null>(null)
  const [complex, setComplex] = useState<string | null>(null)
  const [search, setSearch] = useState('')
  const [q] = useDebouncedValue(search, 350)
  const [page, setPage] = useState(1)

  const params = cleanParams({ status, organization_id: organization, is_complex: complex, q, page, per_page: 20 })
  const { data, isFetching, error, refetch } = useQuery({
    queryKey: ['dismantling-orders', params],
    queryFn: async () => (await api.get<Paginated<DismantlingOrder>>('/dismantling-orders', { params })).data,
    placeholderData: keepPreviousData,
  })

  const columns: Column<DismantlingOrder>[] = [
    {
      key: 'number',
      header: 'Topshiriq',
      alwaysVisible: true,
      render: (o) => (
        <Stack gap={0}>
          <Anchor component={Link} to={`/dismantling/${o.id}`} fw={600} size="sm" onClick={(e) => e.stopPropagation()}>
            {o.number}
          </Anchor>
          <Text size="xs" c="dimmed">
            {[o.work_plan_number && `IR ${o.work_plan_number}`, o.order_number && `Buyruq ${o.order_number}`].filter(Boolean).join(', ')}
          </Text>
        </Stack>
      ),
    },
    {
      key: 'line',
      header: 'Liniya',
      render: (o) => (
        <Group gap={6} wrap="nowrap">
          <Text size="sm" lineClamp={1}>
            {o.line?.name}
          </Text>
          {o.is_complex && (
            <Badge size="xs" color="orange" variant="outline">
              Murakkab
            </Badge>
          )}
          {o.is_demo && (
            <Badge size="xs" color="orange">
              DEMO
            </Badge>
          )}
        </Group>
      ),
    },
    { key: 'organization', header: 'MGQB', render: (o) => o.organization ?? '—' },
    { key: 'planned', header: 'Reja muddati', render: (o) => `${formatDate(o.planned_start)} — ${formatDate(o.planned_end)}` },
    { key: 'brigade', header: 'Brigada', hiddenByDefault: true, render: (o) => o.brigade_name ?? '—' },
    {
      key: 'progress',
      header: 'Reja / fakt',
      width: 190,
      render: (o) => (
        <div>
          <Text size="xs">
            {formatLength(o.actual_length_m)} / {formatLength(o.planned_length_m)} ({formatPercent(o.percent)})
          </Text>
          <Progress value={Math.min(100, o.percent ?? 0)} size="sm" color="#d08a0c" aria-label={formatPercent(o.percent)} />
        </div>
      ),
    },
    { key: 'status', header: 'Holat', render: (o) => <StatusBadge enumKey="dismantling_order_status" value={o.status} /> },
  ]

  return (
    <>
      <PageHeader
        title="Demontaj topshiriqlari"
        description="Ishga ruxsat faqat vakolatli shaxslar qayd etgan kelishuvlar bilan beriladi. Dastur xavfsizlik ruxsatini o'zi bermaydi."
        actions={
          can('dismantling.manage') && (
            <Button component={Link} to="/dismantling/new" leftSection={<IconPlus size={16} />}>
              Yangi topshiriq
            </Button>
          )
        }
      />
      <DataTable
        columns={columns}
        rows={data?.data}
        rowKey={(o) => o.id}
        loading={isFetching}
        error={error}
        onRetry={() => void refetch()}
        total={data?.meta.total}
        page={page}
        perPage={20}
        onPageChange={setPage}
        onRowClick={(o) => navigate(`/dismantling/${o.id}`)}
        storageKey="dismantling"
        emptyTitle="Demontaj topshirig'i topilmadi"
        toolbar={
          <>
            <TextInput size="sm" aria-label="Qidiruv" placeholder="Raqam, ish reja, buyruq" leftSection={<IconSearch size={16} />} value={search} onChange={(e) => { setSearch(e.currentTarget.value); setPage(1) }} />
            <MultiSelect size="sm" aria-label="Holat" placeholder={status.length ? undefined : 'Holat'} data={statuses.selectData} value={status} onChange={(v) => { setStatus(v); setPage(1) }} clearable w={240} />
            {organizations.length > 1 && (
              <Select size="sm" aria-label="MGQB" placeholder="MGQB" data={organizations} value={organization} onChange={(v) => { setOrganization(v); setPage(1) }} clearable />
            )}
            <Select
              size="sm"
              aria-label="Murakkablik"
              placeholder="Murakkablik"
              data={[
                { value: '1', label: 'Murakkab uchastkali' },
                { value: '0', label: 'Oddiy' },
              ]}
              value={complex}
              onChange={(v) => { setComplex(v); setPage(1) }}
              clearable
            />
          </>
        }
      />
    </>
  )
}
