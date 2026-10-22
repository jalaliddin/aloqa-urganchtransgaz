import {
  ActionIcon, Button, Checkbox, Group, Paper, ScrollArea, Select, SimpleGrid, Stack, Table, Text, Textarea, TextInput, Title,
} from '@mantine/core'
import { DatePickerInput } from '@mantine/dates'
import { useForm } from '@mantine/form'
import { useDebouncedValue } from '@mantine/hooks'
import { IconPlus, IconSearch, IconTrash } from '@tabler/icons-react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useNavigate } from 'react-router'
import { api } from '@/api/client'
import { useOrganizationOptions, useUserDirectory } from '@/api/queries'
import type { CableSegment, Inspection, Paginated } from '@/api/types'
import { useAuth } from '@/auth/AuthContext'
import { notifySuccess } from '@/components/common/notify'
import { PageHeader } from '@/components/common/PageHeader'
import { ApiErrorAlert } from '@/components/common/States'
import { StatusBadge } from '@/components/common/StatusBadge'
import { formatLength } from '@/lib/format'
import { useEnum } from '@/lib/meta'

interface MemberDraft {
  user_id: string | null
  full_name: string
  position: string
  role: string
}

export default function InspectionFormPage() {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { user } = useAuth()
  const organizations = useOrganizationOptions(true)
  const roles = useEnum('inspection_member_role')
  const [search, setSearch] = useState('')
  const [q] = useDebouncedValue(search, 300)

  const form = useForm({
    initialValues: {
      organization_id: user?.organization_id ? String(user.organization_id) : '',
      title: '',
      description: '',
      planned_start: null as string | null,
      planned_end: null as string | null,
      segment_ids: [] as number[],
      members: [{ user_id: null, full_name: '', position: '', role: 'chair' }] as MemberDraft[],
    },
    validate: {
      organization_id: (v) => (!v ? 'MGQBni tanlang' : null),
      title: (v) => (v.trim().length < 3 ? 'Topshiriq nomini kiriting' : null),
      segment_ids: (v) => (v.length === 0 ? 'Kamida bitta uchastkani tanlang' : null),
      members: {
        full_name: (v) => (v.trim().length < 3 ? 'F.I.Sh. kiriting' : null),
      },
    },
  })

  const orgId = form.values.organization_id
  const people = useUserDirectory(orgId ? Number(orgId) : null)
  const segments = useQuery({
    queryKey: ['segments-picker', orgId, q],
    queryFn: async () =>
      (await api.get<Paginated<CableSegment>>('/cable-segments', { params: { organization_id: orgId, q: q || undefined, per_page: 300 } })).data.data,
    enabled: !!orgId,
  })

  const create = useMutation({
    mutationFn: async (values: typeof form.values) =>
      (
        await api.post<{ data: Inspection }>('/inspections', {
          ...values,
          organization_id: Number(values.organization_id),
          members: values.members.map((m) => ({ ...m, user_id: m.user_id ? Number(m.user_id) : null, position: m.position || null })),
        })
      ).data.data,
    onSuccess: (inspection) => {
      notifySuccess(`${inspection.number} topshirig'i yaratildi`)
      void queryClient.invalidateQueries({ queryKey: ['inspections'] })
      navigate(`/inspections/${inspection.id}`)
    },
  })

  const selectable = (segments.data ?? []).filter((s) => s.inspection_status !== 'scheduled')
  const toggle = (id: number) =>
    form.setFieldValue('segment_ids', form.values.segment_ids.includes(id) ? form.values.segment_ids.filter((x) => x !== id) : [...form.values.segment_ids, id])

  return (
    <>
      <PageHeader title="Yangi inventarizatsiya topshirig'i" description="Uchastkalardagi hisobdagi uzunlik topshiriq yaratilganda qayd etiladi — keyin joyida o'lchangan qiymat bilan solishtiriladi." />
      <form onSubmit={form.onSubmit((values) => create.mutate(values))} noValidate>
        <Stack gap="md" maw={1100}>
          <Paper p="md">
            <SimpleGrid cols={{ base: 1, sm: 2 }}>
              <Select
                label="MGQB"
                required
                data={organizations}
                {...form.getInputProps('organization_id')}
                onChange={(v) => {
                  form.setFieldValue('organization_id', v ?? '')
                  form.setFieldValue('segment_ids', [])
                }}
              />
              <TextInput label="Topshiriq nomi" required {...form.getInputProps('title')} />
              <DatePickerInput label="Boshlanish" valueFormat="DD.MM.YYYY" clearable {...form.getInputProps('planned_start')} />
              <DatePickerInput label="Tugash" valueFormat="DD.MM.YYYY" clearable minDate={form.values.planned_start ?? undefined} {...form.getInputProps('planned_end')} />
            </SimpleGrid>
            <Textarea label="Tavsif" mt="sm" autosize minRows={2} {...form.getInputProps('description')} />
          </Paper>

          <Paper p="md">
            <Group justify="space-between" mb="sm">
              <div>
                <Title order={3}>Uchastkalar</Title>
                <Text size="sm" c="dimmed">
                  Tanlangan: {form.values.segment_ids.length}. Boshqa ochiq topshiriqdagi uchastkalar ko'rsatilmaydi.
                </Text>
              </div>
              <TextInput size="sm" aria-label="Uchastka qidirish" placeholder="Kod, liniya, joylashuv" leftSection={<IconSearch size={16} />} value={search} onChange={(e) => setSearch(e.currentTarget.value)} />
            </Group>
            {form.errors.segment_ids && (
              <Text c="red" size="sm" mb="xs">
                {form.errors.segment_ids}
              </Text>
            )}
            <ScrollArea.Autosize mah={360}>
              <Table fz="sm" highlightOnHover>
                <Table.Thead>
                  <Table.Tr>
                    <Table.Th w={40}>
                      <Checkbox
                        aria-label="Hammasini tanlash"
                        checked={selectable.length > 0 && selectable.every((s) => form.values.segment_ids.includes(s.id))}
                        onChange={(e) => form.setFieldValue('segment_ids', e.currentTarget.checked ? selectable.map((s) => s.id) : [])}
                      />
                    </Table.Th>
                    <Table.Th>Uchastka</Table.Th>
                    <Table.Th>Liniya</Table.Th>
                    <Table.Th ta="right">Hisobdagi</Table.Th>
                    <Table.Th>Tekshiruv</Table.Th>
                  </Table.Tr>
                </Table.Thead>
                <Table.Tbody>
                  {!orgId && (
                    <Table.Tr>
                      <Table.Td colSpan={5}>
                        <Text size="sm" c="dimmed">
                          Avval MGQBni tanlang.
                        </Text>
                      </Table.Td>
                    </Table.Tr>
                  )}
                  {selectable.map((s) => (
                    <Table.Tr key={s.id} onClick={() => toggle(s.id)} style={{ cursor: 'pointer' }}>
                      <Table.Td>
                        <Checkbox aria-label={s.code} checked={form.values.segment_ids.includes(s.id)} onChange={() => toggle(s.id)} onClick={(e) => e.stopPropagation()} />
                      </Table.Td>
                      <Table.Td fw={500}>{s.code}</Table.Td>
                      <Table.Td>{s.line?.name}</Table.Td>
                      <Table.Td ta="right">{formatLength(s.measured_length_m ?? s.design_length_m)}</Table.Td>
                      <Table.Td>
                        <StatusBadge enumKey="segment_inspection_status" value={s.inspection_status} size="sm" />
                      </Table.Td>
                    </Table.Tr>
                  ))}
                </Table.Tbody>
              </Table>
            </ScrollArea.Autosize>
          </Paper>

          <Paper p="md">
            <Group justify="space-between" mb="sm">
              <Title order={3}>Komissiya tarkibi</Title>
              <Button
                size="xs"
                variant="light"
                leftSection={<IconPlus size={14} />}
                onClick={() => form.insertListItem('members', { user_id: null, full_name: '', position: '', role: 'member' })}
              >
                A'zo qo'shish
              </Button>
            </Group>
            <Stack gap="xs">
              {form.values.members.map((member, index) => (
                <Group key={index} align="flex-end" gap="xs" wrap="wrap">
                  <Select
                    label={index === 0 ? 'Tizim foydalanuvchisi' : undefined}
                    aria-label="Tizim foydalanuvchisi"
                    placeholder="Tanlash (ixtiyoriy)"
                    w={240}
                    searchable
                    clearable
                    data={(people.data ?? []).map((p) => ({ value: String(p.id), label: p.name }))}
                    value={member.user_id}
                    onChange={(v) => {
                      const person = people.data?.find((p) => String(p.id) === v)
                      form.setFieldValue(`members.${index}.user_id`, v)
                      if (person) {
                        form.setFieldValue(`members.${index}.full_name`, person.name)
                        form.setFieldValue(`members.${index}.position`, person.position ?? '')
                      }
                    }}
                  />
                  <TextInput label={index === 0 ? 'F.I.Sh.' : undefined} aria-label="F.I.Sh." required w={240} {...form.getInputProps(`members.${index}.full_name`)} />
                  <TextInput label={index === 0 ? 'Lavozimi' : undefined} aria-label="Lavozimi" w={200} {...form.getInputProps(`members.${index}.position`)} />
                  <Select label={index === 0 ? 'Roli' : undefined} aria-label="Komissiyadagi roli" w={170} data={roles.selectData} allowDeselect={false} {...form.getInputProps(`members.${index}.role`)} />
                  {form.values.members.length > 1 && (
                    <ActionIcon variant="subtle" color="red" size="lg" onClick={() => form.removeListItem('members', index)} aria-label="A'zoni olib tashlash">
                      <IconTrash size={16} />
                    </ActionIcon>
                  )}
                </Group>
              ))}
            </Stack>
          </Paper>

          <ApiErrorAlert error={create.error} />
          <Group justify="flex-end">
            <Button variant="default" onClick={() => navigate(-1)}>
              Bekor qilish
            </Button>
            <Button type="submit" loading={create.isPending}>
              Topshiriqni yaratish
            </Button>
          </Group>
        </Stack>
      </form>
    </>
  )
}
