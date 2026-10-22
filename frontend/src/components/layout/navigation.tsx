import {
  IconBuildingWarehouse, IconChartBar, IconClipboardCheck, IconFileText, IconGauge, IconHistory, IconListDetails,
  IconMap2, IconSettings, IconTool, IconUsers,
} from '@tabler/icons-react'
import type { ReactNode } from 'react'

export interface NavItem {
  to: string
  labelKey: string
  icon: ReactNode
  /** Any of these permissions shows the item */
  permissions: string[]
}

export const NAV_ITEMS: NavItem[] = [
  { to: '/', labelKey: 'nav.dashboard', icon: <IconGauge size={18} />, permissions: ['dashboard.view'] },
  { to: '/registry', labelKey: 'nav.registry', icon: <IconListDetails size={18} />, permissions: ['cable_lines.view'] },
  { to: '/map', labelKey: 'nav.map', icon: <IconMap2 size={18} />, permissions: ['cable_lines.view'] },
  { to: '/inspections', labelKey: 'nav.inspections', icon: <IconClipboardCheck size={18} />, permissions: ['inspections.view'] },
  { to: '/dismantling', labelKey: 'nav.dismantling', icon: <IconTool size={18} />, permissions: ['dismantling.view'] },
  { to: '/materials', labelKey: 'nav.materials', icon: <IconBuildingWarehouse size={18} />, permissions: ['materials.view'] },
  { to: '/documents', labelKey: 'nav.documents', icon: <IconFileText size={18} />, permissions: ['documents.view'] },
  { to: '/reports', labelKey: 'nav.reports', icon: <IconChartBar size={18} />, permissions: ['reports.view'] },
  { to: '/users', labelKey: 'nav.users', icon: <IconUsers size={18} />, permissions: ['users.manage'] },
  { to: '/audit', labelKey: 'nav.audit', icon: <IconHistory size={18} />, permissions: ['audit.view', 'alerts.view'] },
  { to: '/settings', labelKey: 'nav.settings', icon: <IconSettings size={18} />, permissions: ['settings.manage'] },
]
