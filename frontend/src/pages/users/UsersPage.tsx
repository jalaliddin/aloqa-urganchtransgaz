import {
  Badge, Button, Checkbox, Group, Modal, MultiSelect, Paper, PasswordInput, ScrollArea, Select, SimpleGrid, Stack, Switch, Table, Tabs, Text,
  TextInput, Tooltip,
} from '@mantine/core'
import { useForm } from '@mantine/form'
import { useDebouncedValue } from '@mantine/hooks'
import { IconPlus, IconSearch } from '@tabler/icons-react'
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useSearchParams } from 'react-router'
import { api, ApiError } from '@/api/client'
import { useOrganizationOptions } from '@/api/queries'
import type { Paginated, UserRow } from '@/api/types'
import { useAuth } from '@/auth/AuthContext'
import { DataTable, type Column } from '@/components/common/DataTable'
import { notifySuccess } from '@/components/common/notify'
import { PageHeader } from '@/components/common/PageHeader'
import { ReasonModal } from '@/components/common/ReasonModal'
import { ApiErrorAlert, PageSkeleton } from '@/components/common/States'
import { cleanParams } from '@/components/map/useMapFeatures'
import { formatDateTime } from '@/lib/format'
import { useMeta } from '@/lib/meta'

export default function UsersPage() {
  const [params, setParams] = useSearchParams()
  const tab = params.get('tab') ?? 'users'

  return (
    <>
      <PageHeader title="Foydalanuvchilar va rollar" description="Ruxsatlar serverda tekshiriladi; tashkilot bo'yicha ajratish foydalanuvchining MGQBsiga bog'liq." />
      <Tabs value={tab} onChange={(t) => setParams({ tab: t ?? 'users' })} keepMounted={false}>
        <Tabs.List mb="md">
          <Tabs.Tab value="users">Foydalanuvchilar</Tabs.Tab>
          <Tabs.Tab value="roles">Rollar va ruxsatlar</Tabs.Tab>
        </Tabs.List>
        <Tabs.Panel value="users">
          <Users />
        </Tabs.Panel>
        <Tabs.Panel value="roles">
          <Roles />
        </Tabs.Panel>
      </Tabs>
    </>
  )
}

function Users() {
  const { data: meta } = useMeta()
  const organizations = useOrganizationOptions()
  const [search, setSearch] = useState('')
  const [q] = useDebouncedValue(search, 350)
  const [role, setRole] = useState<string | null>(null)
  const [page, setPage] = useState(1)
  const [editing, setEditing] = useState<UserRow | 'new' | null>(null)

  const query = cleanParams({ q, role, page, per_page: 25 })
  const { data, isFetching, error } = useQuery({
    queryKey: ['users', query],
    queryFn: async () => (await api.get<Paginated<UserRow>>('/users', { params: query })).data,
    placeholderData: keepPreviousData,
  })

  const columns: Column<UserRow>[] = [
    {
      key: 'name',
      header: 'F.I.Sh.',
      alwaysVisible: true,
      render: (u) => (
        <div>
          <Text size="sm" fw={500}>
            {u.name}
          </Text>
          <Text size="xs" c="dimmed">
            {u.email}
          </Text>
        </div>
      ),
    },
    { key: 'position', header: 'Lavozimi', render: (u) => u.position ?? '—' },
    { key: 'organization', header: 'Tashkilot', render: (u) => u.organization?.short_name ?? u.organization?.name ?? '—' },
    {
      key: 'roles',
      header: 'Rollar',
      render: (u) => (
        <Group gap={4}>
          {u.roles.map((r) => (
            <Badge key={r.name} size="sm" variant="outline">
              {r.label}
            </Badge>
          ))}
        </Group>
      ),
    },
    { key: 'last_login', header: 'Oxirgi kirish', render: (u) => formatDateTime(u.last_login_at) },
    { key: 'active', header: 'Holat', render: (u) => (u.is_active ? <Badge color="teal">Faol</Badge> : <Badge color="gray">Bloklangan</Badge>) },
  ]

  return (
    <>
      <DataTable
        columns={columns}
        rows={data?.data}
        rowKey={(u) => u.id}
        loading={isFetching}
        error={error}
        total={data?.meta.total}
        page={page}
        perPage={25}
        onPageChange={setPage}
        onRowClick={(u) => setEditing(u)}
        storageKey="users"
        toolbar={
          <>
            <TextInput size="sm" aria-label="Qidiruv" placeholder="Ism yoki e-pochta" leftSection={<IconSearch size={16} />} value={search} onChange={(e) => { setSearch(e.currentTarget.value); setPage(1) }} />
            <Select size="sm" aria-label="Rol" placeholder="Rol" data={meta?.roles ?? []} value={role} onChange={(v) => { setRole(v); setPage(1) }} clearable />
            <Button size="sm" leftSection={<IconPlus size={16} />} onClick={() => setEditing('new')}>
              Foydalanuvchi qo'shish
            </Button>
          </>
        }
      />
      {editing && <UserModal user={editing === 'new' ? null : editing} organizations={organizations} onClose={() => setEditing(null)} />}
    </>
  )
}

function UserModal({ user, organizations, onClose }: { user: UserRow | null; organizations: { value: string; label: string }[]; onClose: () => void }) {
  const queryClient = useQueryClient()
  const { data: meta } = useMeta()
  const { user: me } = useAuth()
  const roleOptions = (meta?.roles ?? []).filter((r) => r.value !== 'super_admin' || me?.roles.some((x) => x.name === 'super_admin'))

  const form = useForm({
    initialValues: {
      name: user?.name ?? '',
      email: user?.email ?? '',
      phone: user?.phone ?? '',
      position: user?.position ?? '',
      organization_id: user?.organization_id ? String(user.organization_id) : '',
      roles: user?.roles.map((r) => r.name) ?? [],
      password: '',
      is_active: user?.is_active ?? true,
    },
    validate: {
      name: (v) => (v.trim().length < 3 ? 'F.I.Sh. kiriting' : null),
      email: (v) => (/^\S+@\S+\.\S+$/.test(v) ? null : "E-pochtani to'g'ri kiriting"),
      organization_id: (v) => (!v ? 'Tashkilotni tanlang' : null),
      roles: (v) => (v.length === 0 ? 'Kamida bitta rol tanlang' : null),
      password: (v) => (!user && v.length < 10 ? 'Parol kamida 10 belgi (harf va raqam)' : v && v.length < 10 ? 'Parol kamida 10 belgi' : null),
    },
  })

  const save = useMutation({
    mutationFn: async (values: typeof form.values) => {
      const payload = { ...values, organization_id: Number(values.organization_id), password: values.password || undefined, phone: values.phone || null, position: values.position || null }
      return user ? api.put(`/users/${user.id}`, payload) : api.post('/users', payload)
    },
    onSuccess: () => {
      notifySuccess(user ? 'Foydalanuvchi saqlandi' : "Foydalanuvchi qo'shildi")
      void queryClient.invalidateQueries({ queryKey: ['users'] })
      onClose()
    },
    onError: (e) => {
      if (e instanceof ApiError) form.setErrors(Object.fromEntries(Object.entries(e.fieldErrors).map(([k, v]) => [k.split('.')[0], v[0]])))
    },
  })

  return (
    <Modal opened onClose={onClose} title={<Text fw={600}>{user ? user.name : 'Yangi foydalanuvchi'}</Text>} size="lg">
      <form onSubmit={form.onSubmit((values) => save.mutate(values))}>
        <Stack gap="sm">
          <SimpleGrid cols={{ base: 1, sm: 2 }}>
            <TextInput label="F.I.Sh." required {...form.getInputProps('name')} />
            <TextInput label="E-pochta (login)" required {...form.getInputProps('email')} />
            <TextInput label="Telefon" {...form.getInputProps('phone')} />
            <TextInput label="Lavozimi" {...form.getInputProps('position')} />
            <Select label="Tashkilot" required searchable data={organizations} {...form.getInputProps('organization_id')} />
            <PasswordInput
              label={user ? 'Yangi parol' : 'Parol'}
              description={user ? "O'zgartirmaslik uchun bo'sh qoldiring" : 'Kamida 10 belgi, harf va raqam'}
              required={!user}
              autoComplete="new-password"
              {...form.getInputProps('password')}
            />
          </SimpleGrid>
          <MultiSelect label="Rollar" required data={roleOptions} {...form.getInputProps('roles')} />
          <Switch label="Faol (tizimga kira oladi)" {...form.getInputProps('is_active', { type: 'checkbox' })} />
          <ApiErrorAlert error={save.error} />
          <Group justify="flex-end">
            <Button variant="default" onClick={onClose}>
              Bekor qilish
            </Button>
            <Button type="submit" loading={save.isPending}>
              Saqlash
            </Button>
          </Group>
        </Stack>
      </form>
    </Modal>
  )
}

interface RoleRow {
  name: string
  label: string
  users_count: number
  editable: boolean
  permissions: string[]
}

function Roles() {
  const queryClient = useQueryClient()
  const { user } = useAuth()
  const isSuperAdmin = !!user?.roles.some((r) => r.name === 'super_admin')
  const { data, isLoading } = useQuery({
    queryKey: ['roles'],
    queryFn: async () => (await api.get<{ data: RoleRow[]; permissions: { name: string; label: string }[] }>('/roles')).data,
  })
  const [draft, setDraft] = useState<Record<string, string[]>>({})
  const [saving, setSaving] = useState<string | null>(null)

  if (isLoading || !data) return <PageSkeleton />

  const current = (role: RoleRow) => draft[role.name] ?? role.permissions
  const toggle = (role: RoleRow, permission: string) => {
    const set = new Set(current(role))
    if (set.has(permission)) set.delete(permission)
    else set.add(permission)
    setDraft({ ...draft, [role.name]: Array.from(set) })
  }
  const dirtyRoles = data.data.filter((r) => draft[r.name] && JSON.stringify([...draft[r.name]].sort()) !== JSON.stringify([...r.permissions].sort()))

  return (
    <Stack gap="sm">
      <Text size="sm" c="dimmed">
        {isSuperAdmin ? "Ruxsatlarni faqat Super Admin o'zgartiradi; har bir o'zgarish sabab bilan audit jurnaliga yoziladi." : "Faqat ko'rish rejimi."}
      </Text>
      {dirtyRoles.length > 0 && (
        <Group>
          {dirtyRoles.map((r) => (
            <Button key={r.name} size="xs" onClick={() => setSaving(r.name)}>
              «{r.label}» o'zgarishlarini saqlash
            </Button>
          ))}
          <Button size="xs" variant="subtle" onClick={() => setDraft({})}>
            Bekor qilish
          </Button>
        </Group>
      )}
      <Paper p={0}>
        <ScrollArea type="auto">
          <Table fz="xs" miw={1200} stickyHeader withColumnBorders>
            <Table.Thead>
              <Table.Tr>
                <Table.Th miw={260}>Ruxsat</Table.Th>
                {data.data.map((r) => (
                  <Table.Th key={r.name} ta="center" style={{ verticalAlign: 'bottom' }}>
                    <Tooltip label={`${r.users_count} foydalanuvchi`}>
                      <Text size="xs" fw={600}>
                        {r.label}
                      </Text>
                    </Tooltip>
                  </Table.Th>
                ))}
              </Table.Tr>
            </Table.Thead>
            <Table.Tbody>
              {data.permissions.map((p) => (
                <Table.Tr key={p.name}>
                  <Table.Td>
                    <Text size="xs">{p.label}</Text>
                    <Text size="xs" c="dimmed">
                      {p.name}
                    </Text>
                  </Table.Td>
                  {data.data.map((r) => (
                    <Table.Td key={r.name} ta="center">
                      <Checkbox
                        size="xs"
                        display="inline-block"
                        aria-label={`${r.label}: ${p.label}`}
                        checked={current(r).includes(p.name)}
                        disabled={!isSuperAdmin || !r.editable}
                        onChange={() => toggle(r, p.name)}
                      />
                    </Table.Td>
                  ))}
                </Table.Tr>
              ))}
            </Table.Tbody>
          </Table>
        </ScrollArea>
      </Paper>
      <ReasonModal
        opened={saving !== null}
        onClose={() => setSaving(null)}
        title="Rol ruxsatlarini o'zgartirish"
        description="O'zgarish shu roldagi barcha foydalanuvchilarga darhol ta'sir qiladi."
        confirmLabel="Saqlash"
        onConfirm={async (reason) => {
          await api.put(`/roles/${saving}/permissions`, { permissions: draft[saving!], reason })
          notifySuccess('Rol ruxsatlari yangilandi')
          const { [saving!]: _, ...rest } = draft
          setDraft(rest)
          void queryClient.invalidateQueries({ queryKey: ['roles'] })
        }}
      />
    </Stack>
  )
}
