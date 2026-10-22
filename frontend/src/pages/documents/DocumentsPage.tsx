import { ActionIcon, Anchor, Checkbox, Select, Text, TextInput, Tooltip } from '@mantine/core'
import { useDebouncedValue } from '@mantine/hooks'
import { IconDownload, IconSearch } from '@tabler/icons-react'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { Link } from 'react-router'
import { api, downloadFile } from '@/api/client'
import type { DocumentItem, Paginated } from '@/api/types'
import { DataTable, type Column } from '@/components/common/DataTable'
import { notifyError } from '@/components/common/notify'
import { PageHeader } from '@/components/common/PageHeader'
import { cleanParams } from '@/components/map/useMapFeatures'
import { formatDate, formatFileSize } from '@/lib/format'
import { useEnum } from '@/lib/meta'

const OWNER_LABELS: Record<string, { label: string; link?: (id: number) => string }> = {
  cable_line: { label: 'Kabel liniyasi', link: (id) => `/registry/${id}` },
  cable_segment: { label: 'Uchastka' },
  inspection: { label: 'Inventarizatsiya', link: (id) => `/inspections/${id}` },
  inspection_item: { label: 'Ko\'rik natijasi' },
  dismantling_order: { label: 'Demontaj', link: (id) => `/dismantling/${id}` },
  material_receipt: { label: 'Ombor kirimi', link: (id) => `/materials/${id}` },
  warehouse_transaction: { label: 'Ombor harakati' },
}

export default function DocumentsPage() {
  const types = useEnum('document_type')
  const [type, setType] = useState<string | null>(null)
  const [owner, setOwner] = useState<string | null>(null)
  const [voided, setVoided] = useState(false)
  const [search, setSearch] = useState('')
  const [q] = useDebouncedValue(search, 350)
  const [page, setPage] = useState(1)

  const params = cleanParams({ type, documentable_type: owner, include_voided: voided ? 1 : null, q, page, per_page: 25 })
  const { data, isFetching, error, refetch } = useQuery({
    queryKey: ['documents-all', params],
    queryFn: async () => (await api.get<Paginated<DocumentItem>>('/documents', { params })).data,
    placeholderData: keepPreviousData,
  })

  const columns: Column<DocumentItem>[] = [
    {
      key: 'title',
      header: 'Hujjat',
      alwaysVisible: true,
      render: (d) => (
        <div>
          <Text size="sm" fw={500} td={d.voided_at ? 'line-through' : undefined}>
            {d.title}
          </Text>
          <Text size="xs" c="dimmed">
            {d.original_name}, {formatFileSize(d.size_bytes)}
          </Text>
        </div>
      ),
    },
    { key: 'type', header: 'Turi', render: (d) => types.label(d.type) },
    { key: 'number', header: 'Raqam, sana', render: (d) => `${d.number ?? '—'}${d.document_date ? `, ${formatDate(d.document_date)}` : ''}` },
    {
      key: 'owner',
      header: 'Tegishli obyekt',
      render: (d) => {
        const meta = OWNER_LABELS[d.documentable_type]
        const label = `${meta?.label ?? d.documentable_type} #${d.documentable_id}`
        return meta?.link ? (
          <Anchor component={Link} to={meta.link(d.documentable_id)} size="sm">
            {label}
          </Anchor>
        ) : (
          label
        )
      },
    },
    { key: 'uploaded', header: 'Yuklagan', render: (d) => `${d.uploaded_by ?? '—'}, ${formatDate(d.created_at)}` },
    { key: 'hash', header: 'SHA-256', hiddenByDefault: true, render: (d) => <Text size="xs" ff="monospace">{d.sha256.slice(0, 16)}…</Text> },
    {
      key: 'actions',
      header: '',
      alwaysVisible: true,
      render: (d) =>
        d.voided_at ? (
          <Text size="xs" c="red">
            Bekor qilingan
          </Text>
        ) : (
          <Tooltip label="Yuklab olish">
            <ActionIcon variant="subtle" aria-label={`${d.title} — yuklab olish`} onClick={() => downloadFile(`/documents/${d.id}/download`).catch((e) => notifyError(e))}>
              <IconDownload size={16} />
            </ActionIcon>
          </Tooltip>
        ),
    },
  ]

  return (
    <>
      <PageHeader
        title="Hujjatlar va dalolatnomalar"
        description="Fayllar himoyalangan omborda saqlanadi va faqat ruxsat tekshiruvi orqali yuklab olinadi. Hujjat yuklash tegishli obyekt sahifasida bajariladi."
      />
      <DataTable
        columns={columns}
        rows={data?.data}
        rowKey={(d) => d.id}
        loading={isFetching}
        error={error}
        onRetry={() => void refetch()}
        total={data?.meta.total}
        page={page}
        perPage={25}
        onPageChange={setPage}
        storageKey="documents"
        emptyTitle="Hujjat topilmadi"
        toolbar={
          <>
            <TextInput size="sm" aria-label="Qidiruv" placeholder="Sarlavha, raqam, fayl nomi" leftSection={<IconSearch size={16} />} value={search} onChange={(e) => { setSearch(e.currentTarget.value); setPage(1) }} />
            <Select size="sm" aria-label="Hujjat turi" placeholder="Hujjat turi" data={types.selectData} value={type} onChange={(v) => { setType(v); setPage(1) }} clearable searchable />
            <Select
              size="sm"
              aria-label="Obyekt turi"
              placeholder="Obyekt"
              data={Object.entries(OWNER_LABELS).map(([value, { label }]) => ({ value, label }))}
              value={owner}
              onChange={(v) => { setOwner(v); setPage(1) }}
              clearable
            />
            <Checkbox label="Bekor qilinganlar ham" checked={voided} onChange={(e) => setVoided(e.currentTarget.checked)} />
          </>
        }
      />
    </>
  )
}
