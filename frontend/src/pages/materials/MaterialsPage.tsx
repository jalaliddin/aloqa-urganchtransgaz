import { Anchor, Badge, Button, Group, Select, Text, TextInput } from '@mantine/core'
import { useDebouncedValue } from '@mantine/hooks'
import { IconPlus, IconSearch } from '@tabler/icons-react'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { Link, useNavigate } from 'react-router'
import { api } from '@/api/client'
import type { MaterialReceipt, Paginated } from '@/api/types'
import { useAuth } from '@/auth/AuthContext'
import { DataTable, type Column } from '@/components/common/DataTable'
import { PageHeader } from '@/components/common/PageHeader'
import { StatusBadge } from '@/components/common/StatusBadge'
import { cleanParams } from '@/components/map/useMapFeatures'
import { formatDate, formatQuantity } from '@/lib/format'
import { useEnum } from '@/lib/meta'

export default function MaterialsPage() {
  const { can } = useAuth()
  const navigate = useNavigate()
  const statuses = useEnum('material_receipt_status')
  const units = useEnum('material_unit')
  const [status, setStatus] = useState<string | null>(null)
  const [search, setSearch] = useState('')
  const [q] = useDebouncedValue(search, 350)
  const [page, setPage] = useState(1)

  const params = cleanParams({ status, q, page, per_page: 20 })
  const { data, isFetching, error, refetch } = useQuery({
    queryKey: ['material-receipts', params],
    queryFn: async () => (await api.get<Paginated<MaterialReceipt>>('/material-receipts', { params })).data,
    placeholderData: keepPreviousData,
  })

  const columns: Column<MaterialReceipt>[] = [
    {
      key: 'number',
      header: 'Kirim hujjati',
      alwaysVisible: true,
      render: (r) => (
        <Anchor component={Link} to={`/materials/${r.id}`} fw={600} size="sm" onClick={(e) => e.stopPropagation()}>
          {r.number}
        </Anchor>
      ),
    },
    { key: 'date', header: 'Sana', render: (r) => formatDate(r.receipt_date) },
    { key: 'order', header: 'Demontaj topshirig\'i', render: (r) => r.dismantling_order?.number ?? '—' },
    { key: 'warehouse', header: 'Ombor', render: (r) => r.warehouse_name },
    {
      key: 'accepted',
      header: 'Qabul qilingan',
      render: (r) => (
        <Text size="sm">
          {(r.items ?? []).map((i) => formatQuantity(i.accepted_quantity, units.label(i.unit))).join(', ') || '—'}
        </Text>
      ),
    },
    { key: 'people', header: 'Topshirdi / qabul qildi', hiddenByDefault: true, render: (r) => `${r.handed_over_by ?? '—'} / ${r.received_by ?? '—'}` },
    { key: 'organization', header: 'MGQB', hiddenByDefault: true, render: (r) => r.organization ?? '—' },
    {
      key: 'status',
      header: 'Holat',
      render: (r) => (
        <Group gap={6} wrap="nowrap">
          <StatusBadge enumKey="material_receipt_status" value={r.status} />
          {r.is_demo && (
            <Badge size="xs" color="orange">
              DEMO
            </Badge>
          )}
        </Group>
      ),
    },
  ]

  return (
    <>
      <PageHeader
        title="Materiallar va ombor"
        description="Demontajdan olingan materiallar alohida hisobga olinadi. Miqdorlar tenglashtirilmaydi — farqlar ko'rsatiladi va tasdiqlanadi."
        actions={
          can('materials.receive') && (
            <Button component={Link} to="/materials/new" leftSection={<IconPlus size={16} />}>
              Omborga kirim
            </Button>
          )
        }
      />
      <DataTable
        columns={columns}
        rows={data?.data}
        rowKey={(r) => r.id}
        loading={isFetching}
        error={error}
        onRetry={() => void refetch()}
        total={data?.meta.total}
        page={page}
        perPage={20}
        onPageChange={setPage}
        onRowClick={(r) => navigate(`/materials/${r.id}`)}
        storageKey="materials"
        emptyTitle="Ombor kirimi yo'q"
        toolbar={
          <>
            <TextInput size="sm" aria-label="Qidiruv" placeholder="Hujjat raqami yoki ombor" leftSection={<IconSearch size={16} />} value={search} onChange={(e) => { setSearch(e.currentTarget.value); setPage(1) }} />
            <Select size="sm" aria-label="Holat" placeholder="Holat" data={statuses.selectData} value={status} onChange={(v) => { setStatus(v); setPage(1) }} clearable w={300} />
          </>
        }
      />
    </>
  )
}
