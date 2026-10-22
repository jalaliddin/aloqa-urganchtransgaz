import { lazy, type ReactNode } from 'react'
import { createBrowserRouter } from 'react-router'
import { RequireAuth, RequirePermission } from './auth/guards'
import { AppLayout, type RouteHandle } from './components/layout/AppLayout'
import RouteErrorPage from './pages/RouteErrorPage'

const LoginPage = lazy(() => import('./pages/auth/LoginPage'))
const DashboardPage = lazy(() => import('./pages/dashboard/DashboardPage'))
const RegistryPage = lazy(() => import('./pages/registry/RegistryPage'))
const CableLineDetailPage = lazy(() => import('./pages/registry/CableLineDetailPage'))
const CableLineFormPage = lazy(() => import('./pages/registry/CableLineFormPage'))
const CableTypesPage = lazy(() => import('./pages/registry/CableTypesPage'))
const MapPage = lazy(() => import('./pages/map/MapPage'))
const InspectionsPage = lazy(() => import('./pages/inspections/InspectionsPage'))
const InspectionFormPage = lazy(() => import('./pages/inspections/InspectionFormPage'))
const InspectionDetailPage = lazy(() => import('./pages/inspections/InspectionDetailPage'))
const DismantlingPage = lazy(() => import('./pages/dismantling/DismantlingPage'))
const DismantlingFormPage = lazy(() => import('./pages/dismantling/DismantlingFormPage'))
const DismantlingDetailPage = lazy(() => import('./pages/dismantling/DismantlingDetailPage'))
const MaterialsPage = lazy(() => import('./pages/materials/MaterialsPage'))
const ReceiptFormPage = lazy(() => import('./pages/materials/ReceiptFormPage'))
const ReceiptDetailPage = lazy(() => import('./pages/materials/ReceiptDetailPage'))
const DocumentsPage = lazy(() => import('./pages/documents/DocumentsPage'))
const ReportsPage = lazy(() => import('./pages/reports/ReportsPage'))
const UsersPage = lazy(() => import('./pages/users/UsersPage'))
const AuditPage = lazy(() => import('./pages/audit/AuditPage'))
const SettingsPage = lazy(() => import('./pages/settings/SettingsPage'))
const NotFoundPage = lazy(() => import('./pages/NotFoundPage'))

const guard = (anyOf: string[], element: ReactNode) => <RequirePermission anyOf={anyOf}>{element}</RequirePermission>
const crumb = (label: string): RouteHandle => ({ crumb: label })

export const router = createBrowserRouter([
  { path: '/login', element: <LoginPage />, errorElement: <RouteErrorPage /> },
  {
    path: '/',
    element: (
      <RequireAuth>
        <AppLayout />
      </RequireAuth>
    ),
    errorElement: <RouteErrorPage />,
    children: [
      { index: true, element: guard(['dashboard.view'], <DashboardPage />) },
      {
        path: 'registry',
        handle: crumb('Reyestr'),
        children: [
          { index: true, element: guard(['cable_lines.view'], <RegistryPage />) },
          { path: 'new', handle: crumb('Yangi liniya'), element: guard(['cable_lines.create'], <CableLineFormPage />) },
          { path: 'cable-types', handle: crumb('Kabel turlari va metall tarkibi'), element: guard(['cable_lines.view'], <CableTypesPage />) },
          { path: ':id', handle: crumb('Liniya'), element: guard(['cable_lines.view'], <CableLineDetailPage />) },
          { path: ':id/edit', handle: crumb('Tahrirlash'), element: guard(['cable_lines.update', 'cable_lines.update_financial'], <CableLineFormPage />) },
        ],
      },
      { path: 'map', handle: crumb('Xarita'), element: guard(['cable_lines.view'], <MapPage />) },
      {
        path: 'inspections',
        handle: crumb('Inventarizatsiya'),
        children: [
          { index: true, element: guard(['inspections.view'], <InspectionsPage />) },
          { path: 'new', handle: crumb("Yangi topshiriq"), element: guard(['inspections.manage'], <InspectionFormPage />) },
          { path: ':id', handle: crumb('Topshiriq'), element: guard(['inspections.view'], <InspectionDetailPage />) },
        ],
      },
      {
        path: 'dismantling',
        handle: crumb('Demontaj'),
        children: [
          { index: true, element: guard(['dismantling.view'], <DismantlingPage />) },
          { path: 'new', handle: crumb('Yangi topshiriq'), element: guard(['dismantling.manage'], <DismantlingFormPage />) },
          { path: ':id', handle: crumb('Topshiriq'), element: guard(['dismantling.view'], <DismantlingDetailPage />) },
        ],
      },
      {
        path: 'materials',
        handle: crumb('Materiallar'),
        children: [
          { index: true, element: guard(['materials.view'], <MaterialsPage />) },
          { path: 'new', handle: crumb('Ombor kirimi'), element: guard(['materials.receive'], <ReceiptFormPage />) },
          { path: ':id', handle: crumb('Kirim'), element: guard(['materials.view'], <ReceiptDetailPage />) },
        ],
      },
      { path: 'documents', handle: crumb('Hujjatlar'), element: guard(['documents.view'], <DocumentsPage />) },
      { path: 'reports', handle: crumb('Hisobotlar'), element: guard(['reports.view'], <ReportsPage />) },
      { path: 'users', handle: crumb('Foydalanuvchilar'), element: guard(['users.manage'], <UsersPage />) },
      { path: 'audit', handle: crumb('Audit'), element: guard(['audit.view', 'alerts.view'], <AuditPage />) },
      { path: 'settings', handle: crumb('Sozlamalar'), element: guard(['settings.manage'], <SettingsPage />) },
      { path: '*', element: <NotFoundPage /> },
    ],
  },
])
