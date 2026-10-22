import {
  ActionIcon, Anchor, Button, Divider, Group, NumberInput, Paper, Select, SimpleGrid, Stack, Text, Textarea, TextInput, Title, Tooltip,
} from '@mantine/core'
import { useForm } from '@mantine/form'
import { IconPlus, IconTrash } from '@tabler/icons-react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useEffect } from 'react'
import { Link, useNavigate, useParams } from 'react-router'
import { api, ApiError } from '@/api/client'
import { useCableTypes, useOrganizationOptions, usePipelines } from '@/api/queries'
import type { CableLine } from '@/api/types'
import { useAuth } from '@/auth/AuthContext'
import { notifySuccess } from '@/components/common/notify'
import { PageHeader } from '@/components/common/PageHeader'
import { ApiErrorAlert, PageSkeleton } from '@/components/common/States'
import { useEnum } from '@/lib/meta'

interface SegmentDraft {
  name: string
  location_description: string
  laying_type: string
  design_length_m: number | ''
}

const emptyNumber = '' as number | ''

export default function CableLineFormPage() {
  const { id } = useParams()
  const editing = id !== undefined
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { can, user } = useAuth()
  const organizations = useOrganizationOptions()
  const { data: pipelines = [] } = usePipelines()
  const { data: cableTypes = [] } = useCableTypes(true)
  const precision = useEnum('laid_year_precision')
  const laying = useEnum('laying_type')

  const existing = useQuery({
    queryKey: ['cable-line', Number(id)],
    queryFn: async () => (await api.get<{ data: CableLine }>(`/cable-lines/${id}`)).data.data,
    enabled: editing,
  })

  const financialOnly = editing && !!existing.data && !existing.data.can?.update && !!existing.data.can?.update_financial
  const canFinancial = can('cable_lines.update_financial')

  const form = useForm({
    initialValues: {
      name: '',
      cable_code: '',
      cable_type_id: null as string | null,
      core_count: emptyNumber,
      laid_year: emptyNumber,
      laid_year_precision: 'unknown',
      organization_id: user?.organization_id ? String(user.organization_id) : '',
      balance_holder_id: user?.organization_id ? String(user.organization_id) : '',
      gas_pipeline_asset_id: '' as string | null,
      object_name: '',
      start_point: '',
      end_point: '',
      design_length_m: emptyNumber,
      inventory_number: '',
      initial_cost: emptyNumber,
      accumulated_depreciation: emptyNumber,
      residual_value: emptyNumber,
      notes: '',
      segments: [] as SegmentDraft[],
    },
    validate: (values) =>
      financialOnly
        ? {}
        : {
            name: values.name.trim().length < 3 ? 'Liniya nomini kiriting' : null,
            cable_type_id: !values.cable_type_id ? "Kabel turini ma'lumotnomadan tanlang" : null,
            organization_id: !values.organization_id ? "Mas'ul MGQBni tanlang" : null,
            balance_holder_id: !values.balance_holder_id ? 'Balans saqlovchini tanlang' : null,
            laid_year:
              values.laid_year_precision === 'exact' && values.laid_year === '' ? "Aniq yil tanlangan bo'lsa, yilni kiriting" : null,
            accumulated_depreciation:
              values.initial_cost !== '' && values.accumulated_depreciation !== '' && values.accumulated_depreciation > values.initial_cost
                ? "Amortizatsiya boshlang'ich qiymatdan oshmasligi kerak"
                : null,
          },
  })

  // Fill the form once the record has loaded
  useEffect(() => {
    const line = existing.data
    if (!line) return
    form.setValues({
      name: line.name,
      cable_code: line.cable_code ?? '',
      cable_type_id: line.cable_type_id ? String(line.cable_type_id) : null,
      core_count: line.core_count ?? '',
      laid_year: line.laid_year ?? '',
      laid_year_precision: line.laid_year_precision,
      organization_id: String(line.organization_id),
      balance_holder_id: String(line.balance_holder_id),
      gas_pipeline_asset_id: line.gas_pipeline_asset_id ? String(line.gas_pipeline_asset_id) : null,
      object_name: line.object_name ?? '',
      start_point: line.start_point ?? '',
      end_point: line.end_point ?? '',
      design_length_m: line.design_length_m ? Number(line.design_length_m) : '',
      inventory_number: line.inventory_number ?? '',
      initial_cost: line.initial_cost ? Number(line.initial_cost) : '',
      accumulated_depreciation: line.accumulated_depreciation ? Number(line.accumulated_depreciation) : '',
      residual_value: line.residual_value ? Number(line.residual_value) : '',
      notes: line.notes ?? '',
      segments: [],
    })
    form.resetDirty()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [existing.data])

  const save = useMutation({
    mutationFn: async (values: typeof form.values) => {
      const nullable = (v: string | number | null) => (v === '' ? null : v)
      const financial = canFinancial
        ? {
            inventory_number: nullable(values.inventory_number),
            initial_cost: nullable(values.initial_cost),
            accumulated_depreciation: nullable(values.accumulated_depreciation),
            residual_value: nullable(values.residual_value),
          }
        : {}

      if (financialOnly) {
        return (await api.put<{ data: CableLine }>(`/cable-lines/${id}`, financial)).data.data
      }

      const payload = {
        name: values.name.trim(),
        cable_code: nullable(values.cable_code),
        cable_type_id: Number(values.cable_type_id),
        core_count: nullable(values.core_count),
        laid_year: nullable(values.laid_year),
        laid_year_precision: values.laid_year_precision,
        organization_id: Number(values.organization_id),
        balance_holder_id: Number(values.balance_holder_id),
        gas_pipeline_asset_id: values.gas_pipeline_asset_id ? Number(values.gas_pipeline_asset_id) : null,
        object_name: nullable(values.object_name),
        start_point: nullable(values.start_point),
        end_point: nullable(values.end_point),
        design_length_m: nullable(values.design_length_m),
        notes: nullable(values.notes),
        ...financial,
        ...(editing ? {} : { segments: values.segments.map((s) => ({ ...s, design_length_m: nullable(s.design_length_m) })) }),
      }

      return editing
        ? (await api.put<{ data: CableLine }>(`/cable-lines/${id}`, payload)).data.data
        : (await api.post<{ data: CableLine }>('/cable-lines', payload)).data.data
    },
    onSuccess: (line) => {
      notifySuccess(editing ? "O'zgarishlar saqlandi" : `${line.registry_number} reyestrga qo'shildi`)
      void queryClient.invalidateQueries({ queryKey: ['cable-lines'] })
      void queryClient.invalidateQueries({ queryKey: ['cable-line', line.id] })
      navigate(`/registry/${line.id}`)
    },
    onError: (error) => {
      if (error instanceof ApiError) {
        form.setErrors(Object.fromEntries(Object.entries(error.fieldErrors).map(([k, v]) => [k, v[0]])))
      }
    },
  })

  if (editing && existing.isLoading) return <PageSkeleton />

  // Only active types are offered; a line keeps its current type even after that type was deactivated
  const current = existing.data?.cable_type_ref
  const typeOptions = cableTypes.map((t) => ({ value: String(t.id), label: t.name }))
  if (current && !cableTypes.some((t) => t.id === current.id)) typeOptions.push({ value: String(current.id), label: `${current.name} (nofaol)` })
  const pickedType = cableTypes.find((t) => String(t.id) === form.values.cable_type_id)

  const pipelineOptions = pipelines
    .filter((p) => !form.values.organization_id || String(p.organization_id) === form.values.organization_id)
    .map((p) => ({ value: String(p.id), label: `${p.code} — ${p.name}` }))

  return (
    <>
      <PageHeader
        title={editing ? `${existing.data?.registry_number ?? ''} — tahrirlash` : 'Yangi kabel liniyasi'}
        description={
          editing
            ? 'Holatlar (texnik, foydalanish, demontaj) uchastkalar va ish oqimlari orqali yangilanadi.'
            : "Reyestr raqami avtomatik beriladi. Uchastkalar kiritilmasa, bitta uchastka yaratiladi."
        }
      />
      <form onSubmit={form.onSubmit((values) => save.mutate(values))} noValidate>
        <Stack gap="md" maw={1100}>
          {!financialOnly && (
            <Paper p="md">
              <Title order={3} mb="md">
                Asosiy ma'lumotlar
              </Title>
              <SimpleGrid cols={{ base: 1, sm: 2, lg: 3 }}>
                <TextInput label="Kabel nomi" required {...form.getInputProps('name')} />
                <TextInput label="Kabel ID" description="Mavjud bo'lsa, eski hisob raqami" {...form.getInputProps('cable_code')} />
                <Stack gap={4}>
                  <Select
                    label="Kabel turi va markasi"
                    description={
                      pickedType
                        ? [pickedType.brand && `Markasi: ${pickedType.brand}`, pickedType.core_count && `${pickedType.core_count} tomir`].filter(Boolean).join(', ') ||
                          "Rangli metall tarkibi (kg/m) shu turdan olinadi"
                        : "Rangli metall tarkibi (kg/m) shu turdan olinadi"
                    }
                    placeholder="Ma'lumotnomadan tanlang"
                    required
                    searchable
                    nothingFoundMessage="Bunday kabel turi yo'q"
                    data={typeOptions}
                    {...form.getInputProps('cable_type_id')}
                    onChange={(v) => {
                      form.setFieldValue('cable_type_id', v)
                      const picked = cableTypes.find((t) => String(t.id) === v)
                      if (picked?.core_count && form.values.core_count === '') form.setFieldValue('core_count', picked.core_count)
                    }}
                  />
                  {can('metal_composition.manage') && (
                    <Anchor component={Link} to="/registry/cable-types" size="xs">
                      Ro'yxatda yo'q bo'lsa, kabel turini qo'shing
                    </Anchor>
                  )}
                </Stack>
                <NumberInput label="Tomirlar soni" min={1} max={2000} allowDecimal={false} {...form.getInputProps('core_count')} />
                <Group grow align="flex-start">
                  <NumberInput label="Yotqizilgan yil" min={1900} max={new Date().getFullYear()} allowDecimal={false} {...form.getInputProps('laid_year')} />
                  <Select label="Aniqligi" data={precision.selectData} allowDeselect={false} {...form.getInputProps('laid_year_precision')} />
                </Group>
                <Select label="Mas'ul MGQB" required searchable data={organizations} {...form.getInputProps('organization_id')} />
                <Select label="Balans saqlovchi tashkilot" required searchable data={organizations} {...form.getInputProps('balance_holder_id')} />
                <Select label="Magistral gaz quvuri" searchable clearable data={pipelineOptions} {...form.getInputProps('gas_pipeline_asset_id')} />
                <TextInput label="Obyekt nomi" {...form.getInputProps('object_name')} />
                <TextInput label="Boshlanish nuqtasi" {...form.getInputProps('start_point')} />
                <TextInput label="Tugash nuqtasi" {...form.getInputProps('end_point')} />
                <NumberInput
                  label="Loyiha bo'yicha uzunlik, m"
                  description="Bazada metrda saqlanadi"
                  min={0}
                  decimalScale={2}
                  thousandSeparator=" "
                  {...form.getInputProps('design_length_m')}
                />
              </SimpleGrid>
              <Textarea label="Izoh" mt="sm" autosize minRows={2} {...form.getInputProps('notes')} />
            </Paper>
          )}

          {canFinancial && (
            <Paper p="md">
              <Title order={3} mb="md">
                Moliyaviy ma'lumotlar
              </Title>
              <SimpleGrid cols={{ base: 1, sm: 2, lg: 4 }}>
                <TextInput label="Inventar raqami" {...form.getInputProps('inventory_number')} />
                <NumberInput label="Boshlang'ich qiymat, so'm" min={0} decimalScale={2} thousandSeparator=" " {...form.getInputProps('initial_cost')} />
                <NumberInput label="Jamg'arilgan amortizatsiya, so'm" min={0} decimalScale={2} thousandSeparator=" " {...form.getInputProps('accumulated_depreciation')} />
                <NumberInput label="Qoldiq qiymat, so'm" min={0} decimalScale={2} thousandSeparator=" " {...form.getInputProps('residual_value')} />
              </SimpleGrid>
            </Paper>
          )}

          {!editing && (
            <Paper p="md">
              <Group justify="space-between" mb="sm">
                <div>
                  <Title order={3}>Uchastkalar</Title>
                  <Text size="sm" c="dimmed">
                    Geometriyani saqlagandan so'ng xaritada chizish mumkin.
                  </Text>
                </div>
                <Button
                  variant="light"
                  size="xs"
                  leftSection={<IconPlus size={14} />}
                  onClick={() => form.insertListItem('segments', { name: `${form.values.segments.length + 1}-uchastka`, location_description: '', laying_type: 'underground', design_length_m: '' })}
                >
                  Uchastka qo'shish
                </Button>
              </Group>
              {form.values.segments.length === 0 ? (
                <Text size="sm" c="dimmed">
                  Uchastka qo'shilmagan — liniya bitta uchastka sifatida yaratiladi.
                </Text>
              ) : (
                <Stack gap="xs">
                  {form.values.segments.map((_, index) => (
                    <Group key={index} align="flex-end" gap="xs" wrap="wrap">
                      <TextInput label={index === 0 ? 'Nomi' : undefined} aria-label="Uchastka nomi" w={180} {...form.getInputProps(`segments.${index}.name`)} />
                      <TextInput label={index === 0 ? 'Joylashuv' : undefined} aria-label="Joylashuv" style={{ flex: 1, minWidth: 180 }} {...form.getInputProps(`segments.${index}.location_description`)} />
                      <Select label={index === 0 ? 'Yotqizish turi' : undefined} aria-label="Yotqizish turi" w={200} data={laying.selectData} allowDeselect={false} {...form.getInputProps(`segments.${index}.laying_type`)} />
                      <NumberInput label={index === 0 ? 'Uzunlik, m' : undefined} aria-label="Uzunlik, m" w={130} min={0} decimalScale={2} {...form.getInputProps(`segments.${index}.design_length_m`)} />
                      <Tooltip label="Olib tashlash">
                        <ActionIcon variant="subtle" color="red" size="lg" onClick={() => form.removeListItem('segments', index)} aria-label="Uchastkani olib tashlash">
                          <IconTrash size={16} />
                        </ActionIcon>
                      </Tooltip>
                    </Group>
                  ))}
                </Stack>
              )}
            </Paper>
          )}

          <ApiErrorAlert error={save.error} />
          <Divider />
          <Group justify="flex-end">
            <Button variant="default" onClick={() => navigate(-1)}>
              Bekor qilish
            </Button>
            <Button type="submit" loading={save.isPending}>
              {editing ? "O'zgarishlarni saqlash" : "Reyestrga qo'shish"}
            </Button>
          </Group>
        </Stack>
      </form>
    </>
  )
}
