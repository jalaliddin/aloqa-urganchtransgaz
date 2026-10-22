import { Badge, Button, Group, MultiSelect, Pagination, Paper, Select, Stack, Tabs, Text, TextInput } from '@mantine/core'
import { DatePickerInput } from '@mantine/dates'
import { useDebouncedValue } from '@mantine/hooks'
import { IconSearch } from '@tabler/icons-react'
import { keepPreviousData, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { Link, useSearchParams } from 'react-router'
import { api } from '@/api/client'
import type { AlertItem, AuditLogEntry, Paginated } from '@/api/types'
import { useAuth } from '@/auth/AuthContext'
import { notifySuccess } from '@/components/common/notify'
import { PageHeader } from '@/components/common/PageHeader'
import { ReasonModal } from '@/components/common/ReasonModal'
import { EmptyState, ErrorState } from '@/components/common/States'
import { StatusBadge } from '@/components/common/StatusBadge'
import { cleanParams } from '@/components/map/useMapFeatures'
import { formatDateTime } from '@/lib/format'
import { useEnum } from '@/lib/meta'
import { AuditTable, EVENT_LABELS, TYPE_LABELS } from './AuditTable'

const SUBJECT_LINKS: Record<string, (id: number) => string> = {
  cable_line: (id) => `/registry/${id}`,
  inspection: (id) => `/inspections/${id}`,
  dismantling_order: (id) => `/dismantling/${id}`,
  material_receipt: (id) => `/materials/${id}`,
}

export default function AuditPage() {
  const { can } = useAuth()
  const [params, setParams] = useSearchParams()
  const tab = params.get('tab') ?? (can('audit.view') ? 'journal' : 'alerts')

  return (
    <>
      <PageHeader
        title="Audit jurnali va nomuvofiqliklar"
        description="Jurnal faqat qo'shiladi: yozuvlarni hech kim o'zgartira yoki o'chira olmaydi. Nomuvofiqlik — ayblov emas, tekshiruv talab qiladigan holat."
      />
      <Tabs value={tab} onChange={(t) => setParams({ tab: t ?? 'journal' })} keepMounted={false}>
        <Tabs.List mb="md">
          {can('audit.view') && <Tabs.Tab value="journal">Audit jurnali</Tabs.Tab>}
          {can('alerts.view') && <Tabs.Tab value="alerts">Nomuvofiqliklar</Tabs.Tab>}
        </Tabs.List>
        <Tabs.Panel value="journal">
          <Journal />
        </Tabs.Panel>
        <Tabs.Panel value="alerts">
          <Alerts />
        </Tabs.Panel>
      </Tabs>
    </>
  )
}

function Journal() {
  const [event, setEvent] = useState<string | null>(null)
  const [type, setType] = useState<string | null>(null)
  const [range, setRange] = useState<[string | null, string | null]>([null, null])
  const [search, setSearch] = useState('')
  const [q] = useDebouncedValue(search, 350)
  const [page, setPage] = useState(1)

  const events = useQuery({
    queryKey: ['audit-events'],
    queryFn: async () => (await api.get<{ data: string[] }>('/audit-logs/events')).data.data,
  })
  const query = cleanParams({ event, auditable_type: type, date_from: range[0], date_to: range[1], q, page, per_page: 50 })
  const { data, isFetching, error } = useQuery({
    queryKey: ['audit-logs', query],
    queryFn: async () => (await api.get<Paginated<AuditLogEntry>>('/audit-logs', { params: query })).data,
    placeholderData: keepPreviousData,
  })

  return (
    <Stack gap="sm">
      <Group gap="xs" wrap="wrap">
        <TextInput size="sm" aria-label="Qidiruv" placeholder="Obyekt, foydalanuvchi, sabab" leftSection={<IconSearch size={16} />} value={search} onChange={(e) => { setSearch(e.currentTarget.value); setPage(1) }} />
        <Select
          size="sm"
          aria-label="Amal"
          placeholder="Amal"
          searchable
          clearable
          data={(events.data ?? []).map((e) => ({ value: e, label: EVENT_LABELS[e] ?? e }))}
          value={event}
          onChange={(v) => { setEvent(v); setPage(1) }}
        />
        <Select
          size="sm"
          aria-label="Obyekt turi"
          placeholder="Obyekt turi"
          clearable
          data={Object.entries(TYPE_LABELS).map(([value, label]) => ({ value, label }))}
          value={type}
          onChange={(v) => { setType(v); setPage(1) }}
        />
        <DatePickerInput size="sm" type="range" aria-label="Davr" placeholder="Davr" valueFormat="DD.MM.YYYY" clearable value={range} onChange={(v) => { setRange(v as [string | null, string | null]); setPage(1) }} w={240} />
      </Group>
      {error ? <ErrorState error={error} /> : <AuditTable entries={data?.data} loading={isFetching} />}
      {data && data.meta.last_page > 1 && (
        <Group justify="flex-end">
          <Pagination size="sm" total={data.meta.last_page} value={page} onChange={setPage} />
        </Group>
      )}
    </Stack>
  )
}

function Alerts() {
  const queryClient = useQueryClient()
  const types = useEnum('alert_type')
  const statuses = useEnum('alert_status')
  const severities = useEnum('alert_severity')
  const [status, setStatus] = useState<string[]>(['open', 'acknowledged'])
  const [type, setType] = useState<string | null>(null)
  const [page, setPage] = useState(1)
  const [action, setAction] = useState<{ alert: AlertItem; kind: 'acknowledge' | 'resolve' } | null>(null)

  const query = cleanParams({ status, type, page, per_page: 20 })
  const { data, error } = useQuery({
    queryKey: ['alerts', query],
    queryFn: async () => (await api.get<Paginated<AlertItem>>('/alerts', { params: query })).data,
    placeholderData: keepPreviousData,
  })

  return (
    <Stack gap="sm">
      <Group gap="xs">
        <MultiSelect size="sm" aria-label="Holat" data={statuses.selectData} value={status} onChange={(v) => { setStatus(v); setPage(1) }} w={300} clearable placeholder={status.length ? undefined : 'Holat'} />
        <Select size="sm" aria-label="Turi" placeholder="Turi" data={types.selectData} value={type} onChange={(v) => { setType(v); setPage(1) }} clearable w={300} />
      </Group>
      {error && <ErrorState error={error} />}
      {data && data.data.length === 0 && <EmptyState title="Nomuvofiqlik yo'q" description="Tanlangan filtrlar bo'yicha tekshiruv talab qiladigan holat topilmadi." />}
      <Stack gap="xs">
        {data?.data.map((alert) => {
          const link = alert.subject_type ? SUBJECT_LINKS[alert.subject_type]?.(alert.subject_id!) : undefined
          return (
            <Paper key={alert.id} p="md" style={{ borderLeft: `3px solid var(--mantine-color-${severities.color(alert.severity)}-6)` }}>
              <Group justify="space-between" align="flex-start" wrap="wrap" gap="sm">
                <Stack gap={4} style={{ flex: '1 1 420px' }}>
                  <Group gap={6}>
                    <StatusBadge enumKey="alert_severity" value={alert.severity} size="sm" />
                    <Text fw={600} size="sm">
                      {types.label(alert.type)}
                    </Text>
                    <StatusBadge enumKey="alert_status" value={alert.status} size="sm" />
                  </Group>
                  <Text size="sm">{alert.message}</Text>
                  <Text size="xs" c="dimmed">
                    {formatDateTime(alert.created_at)}
                    {alert.organization ? `, ${alert.organization}` : ''}
                    {alert.triggered_by ? `, amal bajargan: ${alert.triggered_by}` : ''}
                    {link && (
                      <>
                        {', '}
                        <Link to={link}>obyektni ochish</Link>
                      </>
                    )}
                  </Text>
                  {alert.resolution_note && (
                    <Text size="xs">
                      <Badge size="xs" variant="outline" mr={4}>
                        Izoh
                      </Badge>
                      {alert.resolution_note}
                      {alert.resolved_by ? ` (${alert.resolved_by}, ${formatDateTime(alert.resolved_at)})` : ''}
                    </Text>
                  )}
                </Stack>
                {alert.can_resolve && alert.status !== 'resolved' && (
                  <Group gap="xs">
                    {alert.status === 'open' && (
                      <Button size="xs" variant="default" onClick={() => setAction({ alert, kind: 'acknowledge' })}>
                        Ko'rib chiqishga olish
                      </Button>
                    )}
                    <Button size="xs" onClick={() => setAction({ alert, kind: 'resolve' })}>
                      Yopish
                    </Button>
                  </Group>
                )}
              </Group>
            </Paper>
          )
        })}
      </Stack>
      {data && data.meta.last_page > 1 && (
        <Group justify="flex-end">
          <Pagination size="sm" total={data.meta.last_page} value={page} onChange={setPage} />
        </Group>
      )}
      <ReasonModal
        opened={action !== null}
        onClose={() => setAction(null)}
        title={action?.kind === 'resolve' ? 'Nomuvofiqlikni yopish' : "Ko'rib chiqishga olish"}
        description={action?.kind === 'resolve' ? 'Tekshiruv natijasi va qabul qilingan chora qisqacha yozilsin.' : undefined}
        reasonLabel="Izoh"
        reasonRequired={action?.kind === 'resolve'}
        confirmLabel={action?.kind === 'resolve' ? 'Yopish' : 'Saqlash'}
        onConfirm={async (note) => {
          await api.post(`/alerts/${action!.alert.id}/${action!.kind}`, { note: note || null })
          notifySuccess(action!.kind === 'resolve' ? 'Nomuvofiqlik yopildi' : "Ko'rib chiqishga olindi")
          void queryClient.invalidateQueries({ queryKey: ['alerts'] })
          void queryClient.invalidateQueries({ queryKey: ['dashboard'] })
        }}
      />
    </Stack>
  )
}
