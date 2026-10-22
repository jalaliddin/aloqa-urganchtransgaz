import { Anchor, Badge, Button, Group, Progress, Select, Text, TextInput } from '@mantine/core'
import { useDebouncedValue } from '@mantine/hooks'
import { IconPlus, IconSearch } from '@tabler/icons-react'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { Link, useNavigate } from 'react-router'
import { api } from '@/api/client'
import { useOrganizationOptions } from '@/api/queries'
import type { Inspection, Paginated } from '@/api/types'
import { useAuth } from '@/auth/AuthContext'
import { DataTable, type Column } from '@/components/common/DataTable'
import { PageHeader } from '@/components/common/PageHeader'
import { StatusBadge } from '@/components/common/StatusBadge'
import { cleanParams } from '@/components/map/useMapFeatures'
import { formatDate } from '@/lib/format'
import { useEnum } from '@/lib/meta'

export default function InspectionsPage() {
  const { can } = useAuth()
  const navigate = useNavigate()
  const statuses = useEnum('inspection_status')
  const organizations = useOrganizationOptions()
  const [status, setStatus] = useState<string | null>(null)
  const [organization, setOrganization] = useState<string | null>(null)
  const [search, setSearch] = useState('')
  const [q] = useDebouncedValue(search, 350)
  const [page, setPage] = useState(1)

  const params = cleanParams({ status, organization_id: organization, q, page, per_page: 20 })
  const { data, isFetching, error, refetch } = useQuery({
    queryKey: ['inspections', params],
    queryFn: async () => (await api.get<Paginated<Inspection>>('/inspections', { params })).data,
    placeholderData: keepPreviousData,
  })

  const columns: Column<Inspection>[] = [
    {
      key: 'number',
      header: 'Raqam',
      alwaysVisible: true,
      render: (i) => (
        <Anchor component={Link} to={`/inspections/${i.id}`} fw={600} size="sm" onClick={(e) => e.stopPropagation()}>
          {i.number}
        </Anchor>
      ),
    },
    {
      key: 'title',
      header: 'Topshiriq',
      render: (i) => (
        <Group gap={6} wrap="nowrap">
          <Text size="sm" lineClamp={1}>
            {i.title}
          </Text>
          {i.is_demo && (
            <Badge size="xs" color="orange">
              DEMO
            </Badge>
          )}
        </Group>
      ),
    },
    { key: 'organization', header: 'MGQB', render: (i) => i.organization ?? '—' },
    { key: 'planned', header: 'Muddat', render: (i) => `${formatDate(i.planned_start)} — ${formatDate(i.planned_end)}` },
    {
      key: 'progress',
      header: 'Natijalar',
      width: 160,
      render: (i) => {
        const total = i.items_count ?? 0
        const done = i.recorded_items_count ?? 0
        return (
          <div>
            <Text size="xs">
              {done} / {total} uchastka
            </Text>
            <Progress value={total ? (done / total) * 100 : 0} size="sm" aria-label={`${done} / ${total}`} />
          </div>
        )
      },
    },
    { key: 'status', header: 'Holat', render: (i) => <StatusBadge enumKey="inspection_status" value={i.status} /> },
  ]

  return (
    <>
      <PageHeader
        title="Inventarizatsiya va texnik ko'rik"
        description="Topshiriq yaratiladi, komissiya natijalarni kiritadi, boshqa vakolatli shaxs tasdiqlagandan so'ng reyestr yangilanadi."
        actions={
          can('inspections.manage') && (
            <Button component={Link} to="/inspections/new" leftSection={<IconPlus size={16} />}>
              Yangi topshiriq
            </Button>
          )
        }
      />
      <DataTable
        columns={columns}
        rows={data?.data}
        rowKey={(i) => i.id}
        loading={isFetching}
        error={error}
        onRetry={() => void refetch()}
        total={data?.meta.total}
        page={page}
        perPage={20}
        onPageChange={setPage}
        onRowClick={(i) => navigate(`/inspections/${i.id}`)}
        storageKey="inspections"
        emptyTitle="Inventarizatsiya topshirig'i yo'q"
        toolbar={
          <>
            <TextInput
              size="sm"
              aria-label="Qidiruv"
              placeholder="Raqam yoki nom"
              leftSection={<IconSearch size={16} />}
              value={search}
              onChange={(e) => {
                setSearch(e.currentTarget.value)
                setPage(1)
              }}
            />
            <Select size="sm" aria-label="Holat" placeholder="Holat" data={statuses.selectData} value={status} onChange={(v) => { setStatus(v); setPage(1) }} clearable />
            {organizations.length > 1 && (
              <Select size="sm" aria-label="MGQB" placeholder="MGQB" data={organizations} value={organization} onChange={(v) => { setOrganization(v); setPage(1) }} clearable />
            )}
          </>
        }
      />
    </>
  )
}
