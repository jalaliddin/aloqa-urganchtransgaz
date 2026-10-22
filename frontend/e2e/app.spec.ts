import { expect, test } from '@playwright/test'
import { login } from './helpers.ts'

test('wrong password is rejected with a clear message', async ({ page }) => {
  await page.goto('/login')
  await page.getByLabel('Elektron pochta').fill('at@demo.aloqa.local')
  await page.getByLabel('Parol').fill('notthepassword')
  await page.getByRole('button', { name: 'Kirish' }).click()
  await expect(page.getByRole('alert')).toContainText("Login yoki parol noto'g'ri")
})

test('dashboard shows KPIs computed from data and flags demo records', async ({ page }) => {
  await login(page, 'rahbar')
  await expect(page.getByRole('heading', { name: 'Dashboard' })).toBeVisible()
  await expect(page.getByText('Kabel liniyalari', { exact: true })).toBeVisible()
  await expect(page.getByText(/DEMO ma'lumotlar: tizimda namunaviy yozuvlar bor/)).toBeVisible()
  await expect(page.getByText('MGQBlar kesimida bajarilish')).toBeVisible()
})

test('registry row and map route select the same record', async ({ page }) => {
  await login(page, 'at')
  await page.goto('/registry?view=split')
  const row = page.getByRole('row').filter({ hasText: 'KL-2026-00002' })
  await row.click()
  await expect(row).toHaveAttribute('aria-selected', 'true')
  await expect(page.getByLabel('Tanlangan kabel liniyasi')).toContainText('KL-2026-00002')

  // Clicking a route peg on the map selects that line in the table. Narrow the list first so the
  // map zooms to the line and its pegs are not merged into a cluster.
  await page.getByRole('textbox', { name: 'Qidiruv' }).fill('KL-2026-00005')
  const target = page.getByRole('row').filter({ hasText: 'KL-2026-00005' })
  await expect(target).toHaveAttribute('aria-selected', 'false')
  await page.locator('.route-peg[title^="KL-2026-00005"]').first().click()
  await expect(target).toHaveAttribute('aria-selected', 'true')
  await expect(page.getByLabel('Tanlangan kabel liniyasi')).toContainText('KL-2026-00005')
})

test('MGQB user only sees own organisation data, also via URL', async ({ page }) => {
  await login(page, 'mgqb2')
  await page.goto('/registry')
  const rows = page.getByRole('row').filter({ hasText: 'KL-2026-' })
  await expect(rows.first()).toBeVisible()
  for (const text of await rows.allTextContents()) {
    expect(text).toContain('DEMO MGQB-2')
  }

  // Line 1 belongs to MGQB-1: opening it directly is refused by the server
  await page.goto('/registry/1')
  await expect(page.getByText("Ruxsat yo'q")).toBeVisible()
})

test('satellite imagery can be chosen as the base map and is remembered', async ({ page }) => {
  await login(page, 'at')
  await page.goto('/map')
  const imagery = page.locator('img.leaflet-tile[src*="World_Imagery"]')
  const labels = page.locator('img.leaflet-tile[src*="World_Boundaries_and_Places"]')
  await expect(page.locator('img.leaflet-tile').first()).toBeAttached()
  await expect(imagery).toHaveCount(0)

  const control = page.locator('.leaflet-control-layers')
  await control.hover()
  await control.getByLabel("Sun'iy yo'ldosh").check()
  await expect(imagery.first()).toBeAttached()
  await expect(labels).toHaveCount(0)

  await control.getByLabel('Gibrid').check()
  await expect(labels.first()).toBeAttached()

  // The choice follows the user to other maps and survives a reload
  await page.goto('/registry?view=split')
  await expect(labels.first()).toBeAttached()
  await expect(page.locator('.leaflet-control-layers').getByLabel('Gibrid')).toBeChecked()
})

test('geometry can be drawn on the map and is saved to the registry', async ({ page }) => {
  await login(page, 'at')
  await page.goto('/map')
  await page.getByRole('button', { name: /Geometriya kiritilmagan/ }).click()
  const drawer = page.getByRole('dialog', { name: 'Geometriya kiritilmagan uchastkalar' })
  const firstCode = (await drawer.locator('.mantine-Paper-root p').first().textContent())?.trim()
  await drawer.getByRole('button', { name: 'Chizish' }).first().click()
  // Wait until the drawer overlay is gone and the editor is active before clicking on the map
  await expect(drawer).toBeHidden()
  await expect(page.getByText(`Tahrirlash: ${firstCode}`)).toBeVisible()

  const map = page.locator('.leaflet-container')
  const box = (await map.boundingBox())!
  const cx = box.x + box.width / 2
  const cy = box.y + box.height / 2
  await page.mouse.click(cx - 120, cy)
  await page.mouse.click(cx, cy - 60)
  await page.mouse.click(cx + 120, cy)
  await page.mouse.click(cx + 120, cy) // finish on the last vertex
  await expect(page.getByText(/Uzunlik \(taxminiy\)/)).toBeVisible()

  await page.getByRole('button', { name: 'Saqlash', exact: true }).first().click()
  const dialog = page.getByRole('dialog', { name: /Geometriyani saqlash/ })
  await dialog.getByLabel('Manba izohi').fill('E2E test chizmasi')
  await dialog.getByRole('button', { name: 'Saqlash' }).click()
  await expect(page.getByText(`${firstCode} geometriyasi saqlandi`)).toBeVisible()

  // Clean up through the API (audited, with a reason) so the test can run again on the same data
  const baseURL = test.info().project.use.baseURL!
  const xsrf = decodeURIComponent((await page.context().cookies()).find((c) => c.name === 'XSRF-TOKEN')!.value)
  const headers = { Accept: 'application/json', Referer: `${baseURL}/`, 'X-XSRF-TOKEN': xsrf }
  const found = await page.request.get(`/api/cable-segments?q=${firstCode}`, { headers })
  const segmentId = (await found.json()).data[0].id
  const cleared = await page.request.delete(`/api/cable-segments/${segmentId}/geometry`, { headers, data: { reason: 'E2E test chizmasini tozalash' } })
  expect(cleared.status()).toBe(200)
})
