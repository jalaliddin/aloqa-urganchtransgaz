import { expect, test } from '@playwright/test'
import { login } from './helpers.ts'

test('line shows theoretical metal mass from cable type kg/m', async ({ page }) => {
  await login(page, 'rahbar')
  await page.goto('/registry/1?tab=metal')
  await expect(page.getByRole('heading', { name: 'Nazariy massa' })).toBeVisible()
  await expect(page.getByText('Jami', { exact: true })).toBeVisible()
  await expect(page.getByText('Kabel turi').first()).toBeVisible()
  await expect(page.getByText('0,421 kg/m').first()).toBeVisible()
})

test('unknown composition is shown as unknown, never as zero', async ({ page }) => {
  await login(page, 'rahbar')
  // Line 3 is of a cable type without an approved composition
  await page.goto('/registry/3?tab=metal')
  await expect(page.getByText("Ayrim uchastkalarda metall tarkibi noma'lum")).toBeVisible()
  await expect(page.getByText("noma'lum").first()).toBeVisible()
})

test('dismantling order shows theoretical, act and warehouse masses separately', async ({ page }) => {
  await login(page, 'rahbar')
  await page.goto('/dismantling/1')
  const card = page.getByLabel('Rangli metall balansi')
  await expect(card.getByRole('columnheader', { name: 'Nazariy', exact: true })).toBeVisible()
  await expect(card.getByRole('columnheader', { name: "Dalolatnoma bo'yicha" })).toBeVisible()
  await expect(card.getByRole('columnheader', { name: 'Omborga qabul qilingan' })).toBeVisible()
  // Aluminium accepted ~4 % below the act: flagged, not equalised
  await expect(card.getByLabel('Chegaradan oshgan').first()).toBeVisible()
})

test('pending kg/m value waits for a second person on the cable types page', async ({ page }) => {
  await login(page, 'rahbar')
  await page.goto('/registry/cable-types')
  await expect(page.getByRole('heading', { name: /Tasdiq kutilayotgan qiymatlar/ })).toBeVisible()
  await expect(page.getByRole('button', { name: 'Tasdiqlash' }).first()).toBeVisible()
})
