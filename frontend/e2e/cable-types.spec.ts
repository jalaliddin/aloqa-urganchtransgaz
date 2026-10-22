import { expect, test } from '@playwright/test'
import { login } from './helpers.ts'

test('a new line takes its cable type from the dictionary', async ({ page, browser }) => {
  await login(page, 'mgqb1')
  await page.goto('/registry/new')
  await page.getByLabel('Kabel nomi').fill('E2E kabel turi tekshiruvi')
  await page.getByRole('button', { name: "Reyestrga qo'shish" }).click()
  await expect(page.getByText("Kabel turini ma'lumotnomadan tanlang")).toBeVisible()
  // Type and brand are no longer typed in by hand
  await expect(page.getByLabel('Markasi', { exact: true })).toHaveCount(0)
  await expect(page.getByLabel('Kabel turi (tavsif)')).toHaveCount(0)

  await page.getByRole('combobox', { name: 'Kabel turi va markasi' }).click()
  const option = page.getByRole('option').first()
  const typeName = (await option.textContent())!.trim()
  await option.click()
  await page.getByRole('button', { name: "Reyestrga qo'shish" }).click()
  await expect(page.getByText(/reyestrga qo'shildi/)).toBeVisible()
  await expect(page.getByText(typeName).first()).toBeVisible()
  const lineId = page.url().match(/\/registry\/(\d+)/)![1]

  // Clean up as the administrator (audited, with a reason) so the test can run again on the same data
  const baseURL = test.info().project.use.baseURL!
  const admin = await browser.newContext({ baseURL })
  const adminPage = await admin.newPage()
  await login(adminPage, 'admin')
  const xsrf = decodeURIComponent((await admin.cookies()).find((c) => c.name === 'XSRF-TOKEN')!.value)
  const deleted = await adminPage.request.delete(`/api/cable-lines/${lineId}`, {
    headers: { Accept: 'application/json', Referer: `${baseURL}/`, 'X-XSRF-TOKEN': xsrf },
    data: { reason: 'E2E test liniyasini tozalash' },
  })
  expect(deleted.status()).toBe(200)
  await admin.close()
})

test('statistics per cable type lead to the matching registry lines', async ({ page }) => {
  await login(page, 'rahbar')
  const section = page.getByRole('heading', { name: 'Kabel turlari kesimida' }).locator('xpath=ancestor::div[contains(@class, "mantine-Paper-root")][1]')
  await expect(section.getByRole('columnheader', { name: 'Reyestrdagi ulushi' })).toBeVisible()

  const typeLink = section.getByRole('row').nth(1).getByRole('link')
  const typeName = (await typeLink.textContent())!.trim()
  await typeLink.click()
  await expect(page).toHaveURL(/cable_type_id=\d+/)
  const rows = page.getByRole('row').filter({ hasText: 'KL-' })
  await expect(rows.first()).toBeVisible()
  for (const text of await rows.allTextContents()) {
    expect(text).toContain(typeName)
  }

  await page.goto('/registry/cable-types')
  await expect(page.getByRole('columnheader', { name: 'Reyestrda' })).toBeVisible()
  await expect(page.getByRole('button', { name: 'Tarix va hujjatlar' }).first()).toBeInViewport()
  await page.getByRole('link', { name: 'Kabel turlari hisoboti' }).click()
  await expect(page.getByRole('heading', { name: /Kabel turlari kesimida uzunlik/ })).toBeVisible()
})
