import {
  ActionIcon, Checkbox, Group, Menu, Pagination, Paper, ScrollArea, Select, Skeleton, Table, Text, Tooltip, UnstyledButton,
} from '@mantine/core'
import { IconArrowDown, IconArrowUp, IconArrowsSort, IconColumns3 } from '@tabler/icons-react'
import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import { EmptyState, ErrorState } from './States'

export interface Column<T> {
  key: string
  header: string
  render: (row: T) => ReactNode
  /** Backend sort key; omit for unsortable columns */
  sortKey?: string
  hiddenByDefault?: boolean
  /** Cannot be hidden from the column menu */
  alwaysVisible?: boolean
  width?: number | string
  align?: 'left' | 'right' | 'center'
}

export interface SortState {
  key: string
  direction: 'asc' | 'desc'
}

interface DataTableProps<T> {
  columns: Column<T>[]
  rows: T[] | undefined
  rowKey: (row: T) => number | string
  loading?: boolean
  error?: unknown
  onRetry?: () => void
  total?: number
  page?: number
  perPage?: number
  onPageChange?: (page: number) => void
  onPerPageChange?: (perPage: number) => void
  sort?: SortState | null
  onSortChange?: (sort: SortState) => void
  selectedKey?: number | string | null
  onRowClick?: (row: T) => void
  /** Columns visibility is remembered per table in localStorage */
  storageKey?: string
  emptyTitle?: string
  emptyDescription?: ReactNode
  emptyAction?: ReactNode
  toolbar?: ReactNode
  minWidth?: number
  caption?: string
}

function readHidden(storageKey: string | undefined, fallback: string[]): string[] {
  if (!storageKey) return fallback
  try {
    const raw = window.localStorage.getItem(`table-columns:${storageKey}`)
    return raw ? (JSON.parse(raw) as string[]) : fallback
  } catch {
    return fallback
  }
}

export function DataTable<T>({
  columns, rows, rowKey, loading, error, onRetry, total, page = 1, perPage = 25, onPageChange, onPerPageChange,
  sort, onSortChange, selectedKey, onRowClick, storageKey, emptyTitle = "Ma'lumot topilmadi", emptyDescription,
  emptyAction, toolbar, minWidth = 720, caption,
}: DataTableProps<T>) {
  const [hidden, setHidden] = useState<string[]>(() =>
    readHidden(storageKey, columns.filter((c) => c.hiddenByDefault).map((c) => c.key)),
  )
  const selectedRef = useRef<HTMLTableRowElement | null>(null)

  useEffect(() => {
    if (!storageKey) return
    try {
      window.localStorage.setItem(`table-columns:${storageKey}`, JSON.stringify(hidden))
    } catch {
      // Storage may be unavailable (private mode) — visibility simply is not remembered
    }
  }, [hidden, storageKey])

  // Keep the selected row in view when selection comes from elsewhere (e.g. the map)
  useEffect(() => {
    selectedRef.current?.scrollIntoView?.({ block: 'nearest' })
  }, [selectedKey])

  const visible = useMemo(() => columns.filter((c) => c.alwaysVisible || !hidden.includes(c.key)), [columns, hidden])
  const lastPage = total !== undefined ? Math.max(1, Math.ceil(total / perPage)) : 1

  const toggleSort = (column: Column<T>) => {
    if (!column.sortKey || !onSortChange) return
    const direction = sort?.key === column.sortKey && sort.direction === 'asc' ? 'desc' : 'asc'
    onSortChange({ key: column.sortKey, direction })
  }

  const header = (
    <Table.Tr>
      {visible.map((column) => {
        const active = sort?.key === column.sortKey
        const ariaSort = active ? (sort?.direction === 'asc' ? 'ascending' : 'descending') : undefined
        return (
          <Table.Th key={column.key} style={{ width: column.width, textAlign: column.align, whiteSpace: 'nowrap' }} aria-sort={ariaSort}>
            {column.sortKey && onSortChange ? (
              <UnstyledButton onClick={() => toggleSort(column)} aria-label={`${column.header} bo'yicha saralash`}>
                <Group gap={4} wrap="nowrap">
                  <Text fw={600} size="sm">
                    {column.header}
                  </Text>
                  {active ? (
                    sort?.direction === 'asc' ? <IconArrowUp size={14} /> : <IconArrowDown size={14} />
                  ) : (
                    <IconArrowsSort size={14} opacity={0.4} />
                  )}
                </Group>
              </UnstyledButton>
            ) : (
              <Text fw={600} size="sm">
                {column.header}
              </Text>
            )}
          </Table.Th>
        )
      })}
    </Table.Tr>
  )

  let body: ReactNode
  if (error) {
    body = (
      <Table.Tr>
        <Table.Td colSpan={visible.length}>
          <ErrorState error={error} onRetry={onRetry} />
        </Table.Td>
      </Table.Tr>
    )
  } else if (loading && !rows) {
    body = Array.from({ length: 6 }, (_, i) => (
      <Table.Tr key={`s${i}`}>
        {visible.map((c) => (
          <Table.Td key={c.key}>
            <Skeleton height={14} radius="xs" />
          </Table.Td>
        ))}
      </Table.Tr>
    ))
  } else if (!rows || rows.length === 0) {
    body = (
      <Table.Tr>
        <Table.Td colSpan={visible.length}>
          <EmptyState title={emptyTitle} description={emptyDescription} action={emptyAction} />
        </Table.Td>
      </Table.Tr>
    )
  } else {
    body = rows.map((row) => {
      const key = rowKey(row)
      const selected = selectedKey !== undefined && selectedKey !== null && key === selectedKey
      return (
        <Table.Tr
          key={key}
          ref={selected ? selectedRef : undefined}
          data-selected={selected || undefined}
          aria-selected={onRowClick ? selected : undefined}
          tabIndex={onRowClick ? 0 : undefined}
          onClick={onRowClick ? () => onRowClick(row) : undefined}
          onKeyDown={
            onRowClick
              ? (e) => {
                  if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault()
                    onRowClick(row)
                  }
                }
              : undefined
          }
          style={{
            cursor: onRowClick ? 'pointer' : undefined,
            background: selected ? 'var(--mantine-color-signal-0)' : undefined,
            boxShadow: selected ? 'inset 3px 0 0 var(--app-signal)' : undefined,
            opacity: loading ? 0.6 : 1,
          }}
        >
          {visible.map((column) => (
            <Table.Td key={column.key} style={{ textAlign: column.align }}>
              {column.render(row)}
            </Table.Td>
          ))}
        </Table.Tr>
      )
    })
  }

  return (
    <Paper p={0}>
      <Group justify="space-between" px="sm" py="xs" gap="sm" wrap="wrap" style={{ borderBottom: '1px solid var(--mantine-color-gray-3)' }}>
        <Group gap="sm" wrap="wrap" style={{ flex: 1 }}>
          {toolbar}
        </Group>
        <Group gap="xs">
          {total !== undefined && (
            <Text size="sm" c="dimmed" aria-live="polite">
              Jami: {total}
            </Text>
          )}
          <Menu closeOnItemClick={false} position="bottom-end" withinPortal>
            <Menu.Target>
              <Tooltip label="Ustunlarni tanlash">
                <ActionIcon variant="default" aria-label="Ustunlarni tanlash">
                  <IconColumns3 size={16} />
                </ActionIcon>
              </Tooltip>
            </Menu.Target>
            <Menu.Dropdown>
              <Menu.Label>Ko'rinadigan ustunlar</Menu.Label>
              {columns.filter((c) => !c.alwaysVisible).map((column) => (
                <Menu.Item key={column.key}>
                  <Checkbox
                    size="xs"
                    label={column.header}
                    checked={!hidden.includes(column.key)}
                    onChange={(e) => {
                      const checked = e.currentTarget.checked
                      setHidden((current) => (checked ? current.filter((k) => k !== column.key) : [...current, column.key]))
                    }}
                  />
                </Menu.Item>
              ))}
            </Menu.Dropdown>
          </Menu>
        </Group>
      </Group>

      <ScrollArea type="auto">
        <Table miw={minWidth} stickyHeader verticalSpacing="xs" fz="sm">
          {caption && <Table.Caption style={{ display: 'none' }}>{caption}</Table.Caption>}
          <Table.Thead>{header}</Table.Thead>
          <Table.Tbody>{body}</Table.Tbody>
        </Table>
      </ScrollArea>

      {onPageChange && total !== undefined && total > 0 && (
        <Group justify="space-between" px="sm" py="xs" style={{ borderTop: '1px solid var(--mantine-color-gray-3)' }} wrap="wrap">
          {onPerPageChange ? (
            <Group gap="xs">
              <Text size="sm" c="dimmed">
                Sahifada:
              </Text>
              <Select
                size="xs"
                w={80}
                aria-label="Sahifadagi yozuvlar soni"
                data={['10', '25', '50', '100']}
                value={String(perPage)}
                onChange={(value) => value && onPerPageChange(Number(value))}
                allowDeselect={false}
              />
            </Group>
          ) : (
            <span />
          )}
          <Pagination size="sm" total={lastPage} value={page} onChange={onPageChange} siblings={1} />
        </Group>
      )}
    </Paper>
  )
}
