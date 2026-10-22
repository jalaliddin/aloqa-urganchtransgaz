import {
  Alert, AppShell, Avatar, Badge, Box, Breadcrumbs, Burger, Group, Indicator, Menu, NavLink, Popover, ScrollArea,
  Stack, Text, UnstyledButton, ActionIcon, Anchor,
} from '@mantine/core'
import { useDisclosure } from '@mantine/hooks'
import { IconBell, IconChevronDown, IconInfoCircle, IconLogout } from '@tabler/icons-react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Suspense } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, NavLink as RouterNavLink, Outlet, useLocation, useMatches, useNavigate } from 'react-router'
import { api } from '@/api/client'
import { useAuth } from '@/auth/AuthContext'
import { formatDateTime } from '@/lib/format'
import { PageSkeleton } from '../common/States'
import { NAV_ITEMS } from './navigation'

export interface RouteHandle {
  crumb?: string
}

function AppBrand() {
  const { t } = useTranslation()
  return (
    <Group gap="sm" wrap="nowrap">
      <svg width="30" height="30" viewBox="0 0 32 32" aria-hidden>
        <rect width="32" height="32" rx="6" fill="#1D3B53" />
        <path d="M6 22c5 0 6-12 10-12s5 12 10 12" fill="none" stroke="#E3B505" strokeWidth="3" strokeLinecap="round" />
        <circle cx="6" cy="22" r="2.5" fill="#fff" />
        <circle cx="26" cy="22" r="2.5" fill="#fff" />
      </svg>
      <Box visibleFrom="xs">
        <Text fw={600} size="sm" lh={1.2}>
          {t('app.name')}
        </Text>
        <Text size="xs" c="dimmed" lh={1.2}>
          {t('app.company')}
        </Text>
      </Box>
    </Group>
  )
}

function NotificationsMenu() {
  const queryClient = useQueryClient()
  const { data } = useQuery({
    queryKey: ['notifications'],
    queryFn: async () =>
      (await api.get<{ data: { id: string; data: { title: string; message: string }; read_at: string | null; created_at: string }[]; unread: number }>('/notifications')).data,
    refetchInterval: 60_000,
  })

  const markRead = async () => {
    await api.post('/notifications/read-all')
    void queryClient.invalidateQueries({ queryKey: ['notifications'] })
  }

  return (
    <Popover width={360} position="bottom-end" shadow="md" withinPortal>
      <Popover.Target>
        <Indicator disabled={!data?.unread} label={data?.unread} size={16} color="red" offset={4}>
          <ActionIcon variant="subtle" size="lg" aria-label={`Bildirishnomalar${data?.unread ? `, ${data.unread} ta o'qilmagan` : ''}`}>
            <IconBell size={20} />
          </ActionIcon>
        </Indicator>
      </Popover.Target>
      <Popover.Dropdown p={0}>
        <Group justify="space-between" px="md" py="xs" style={{ borderBottom: '1px solid var(--mantine-color-gray-3)' }}>
          <Text fw={600} size="sm">
            Bildirishnomalar
          </Text>
          {!!data?.unread && (
            <Anchor component="button" size="xs" onClick={markRead}>
              Hammasini o'qilgan deb belgilash
            </Anchor>
          )}
        </Group>
        <ScrollArea.Autosize mah={360}>
          {(data?.data ?? []).length === 0 ? (
            <Text size="sm" c="dimmed" p="md">
              Yangi bildirishnoma yo'q.
            </Text>
          ) : (
            data!.data.map((n) => (
              <Box key={n.id} px="md" py="sm" style={{ borderBottom: '1px solid var(--mantine-color-gray-2)', background: n.read_at ? undefined : 'var(--mantine-color-steel-0)' }}>
                <Text size="sm" fw={500}>
                  {n.data.title}
                </Text>
                <Text size="xs" c="dimmed" lineClamp={3}>
                  {n.data.message}
                </Text>
                <Text size="xs" c="dimmed" mt={2}>
                  {formatDateTime(n.created_at)}
                </Text>
              </Box>
            ))
          )}
        </ScrollArea.Autosize>
        <Box px="md" py="xs">
          <Anchor component={Link} to="/audit?tab=alerts" size="sm">
            Barcha nomuvofiqliklar
          </Anchor>
        </Box>
      </Popover.Dropdown>
    </Popover>
  )
}

function useCrumbs() {
  const matches = useMatches()
  return matches
    .filter((m) => (m.handle as RouteHandle | undefined)?.crumb)
    .map((m) => ({ to: m.pathname, label: (m.handle as RouteHandle).crumb! }))
}

export function AppLayout() {
  const [opened, { toggle, close }] = useDisclosure(false)
  const { user, can, logout } = useAuth()
  const { t } = useTranslation()
  const navigate = useNavigate()
  const location = useLocation()
  const crumbs = useCrumbs()
  const fullBleed = location.pathname === '/map'

  const items = NAV_ITEMS.filter((item) => item.permissions.some(can))

  return (
    <AppShell
      header={{ height: 56 }}
      navbar={{ width: 264, breakpoint: 'md', collapsed: { mobile: !opened } }}
      padding={fullBleed ? 0 : { base: 'sm', sm: 'lg' }}
    >
      <AppShell.Header>
        <Group h="100%" px="md" justify="space-between" wrap="nowrap">
          <Group gap="sm" wrap="nowrap">
            <Burger opened={opened} onClick={toggle} hiddenFrom="md" size="sm" aria-label="Menyuni ochish" aria-expanded={opened} aria-controls="app-navigation" />
            <AppBrand />
            {crumbs.length > 0 && (
              <Breadcrumbs ml="lg" visibleFrom="lg" separator="/" fz="sm">
                {crumbs.map((c, i) =>
                  i === crumbs.length - 1 ? (
                    <Text key={c.to} size="sm" c="dimmed">
                      {c.label}
                    </Text>
                  ) : (
                    <Anchor key={c.to} component={Link} to={c.to} size="sm">
                      {c.label}
                    </Anchor>
                  ),
                )}
              </Breadcrumbs>
            )}
          </Group>
          <Group gap="xs" wrap="nowrap">
            {can('alerts.view') && <NotificationsMenu />}
            <Menu position="bottom-end" withinPortal>
              <Menu.Target>
                <UnstyledButton aria-label="Profil menyusi">
                  <Group gap="xs" wrap="nowrap">
                    <Avatar color="steel" radius="xl" size={32}>
                      {user?.name.slice(0, 2).toUpperCase()}
                    </Avatar>
                    <Box visibleFrom="sm" maw={200}>
                      <Text size="sm" fw={500} truncate>
                        {user?.name}
                      </Text>
                      <Text size="xs" c="dimmed" truncate>
                        {user?.roles.map((r) => r.label).join(', ')}
                      </Text>
                    </Box>
                    <IconChevronDown size={14} />
                  </Group>
                </UnstyledButton>
              </Menu.Target>
              <Menu.Dropdown>
                <Menu.Label>{user?.organization?.name ?? 'Tashkilot biriktirilmagan'}</Menu.Label>
                <Menu.Item disabled>{user?.email}</Menu.Item>
                <Menu.Divider />
                <Menu.Item
                  color="red"
                  leftSection={<IconLogout size={16} />}
                  onClick={async () => {
                    await logout()
                    navigate('/login', { replace: true })
                  }}
                >
                  {t('common.logout')}
                </Menu.Item>
              </Menu.Dropdown>
            </Menu>
          </Group>
        </Group>
      </AppShell.Header>

      <AppShell.Navbar id="app-navigation" p="sm" bg="var(--app-navy)" style={{ borderRight: "none" }}>
        <AppShell.Section grow component={ScrollArea} scrollbarSize={6}>
          <Stack gap={2} component="nav" aria-label="Asosiy menyu">
            {items.map((item) => (
              <NavLink
                key={item.to}
                component={RouterNavLink}
                to={item.to}
                end={item.to === '/'}
                label={t(item.labelKey)}
                leftSection={item.icon}
                onClick={close}
                color="signal"
                styles={{
                  root: { borderRadius: 4, color: 'rgba(255,255,255,0.82)' },
                  label: { fontSize: 14 },
                }}
                className="app-nav-link"
              />
            ))}
          </Stack>
        </AppShell.Section>
        <AppShell.Section>
          <Text size="xs" c="rgba(255,255,255,0.55)" px="sm" pt="sm">
            {user?.organization?.short_name ?? user?.organization?.name}
          </Text>
        </AppShell.Section>
      </AppShell.Navbar>

      <AppShell.Main
        style={
          fullBleed
            ? { display: 'flex', flexDirection: 'column', height: '100dvh', minHeight: 0 }
            : undefined
        }
      >
        {user?.demo_data_present && (
          <Alert
            color="orange"
            variant="light"
            radius={0}
            icon={<IconInfoCircle size={18} />}
            py={6}
            mb={fullBleed ? 0 : 'md'}
            mx={fullBleed ? 0 : undefined}
          >
            <Group gap="xs">
              <Badge color="orange" variant="filled" size="sm">
                DEMO
              </Badge>
              <Text size="sm">{t('app.demoBanner')}</Text>
            </Group>
          </Alert>
        )}
        <Suspense fallback={<PageSkeleton />}>
          {fullBleed ? (
            <Box style={{ flex: 1, minHeight: 0 }}>
              <Outlet />
            </Box>
          ) : (
            <Outlet />
          )}
        </Suspense>
      </AppShell.Main>
    </AppShell>
  )
}
