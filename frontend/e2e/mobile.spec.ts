import { expect, test } from '@playwright/test'
import { login } from './helpers.ts'

test('navigation collapses behind a burger on phones', async ({ page }) => {
  await login(page, 'at')
  const burger = page.getByRole('button', { name: 'Menyuni ochish' })
  await expect(burger).toBeVisible()
  await expect(page.getByRole('link', { name: 'Interaktiv xarita' })).not.toBeInViewport()

  await burger.click()
  await expect(page.getByRole('link', { name: 'Interaktiv xarita' })).toBeInViewport()
  await page.getByRole('link', { name: 'Interaktiv xarita' }).click()
  await expect(page).toHaveURL(/\/map/)
})

test('map page uses drawers on phones and has no horizontal scroll', async ({ page }) => {
  await login(page, 'at')
  await page.goto('/map')
  await expect(page.getByRole('button', { name: 'Filtr va qidiruv' })).toBeVisible()
  await page.getByRole('button', { name: 'Filtr va qidiruv' }).click()
  await expect(page.getByRole('dialog', { name: 'Filtr va qidiruv' })).toBeVisible()

  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)
  expect(overflow).toBeLessThanOrEqual(1)
})

test('registry table scrolls inside its container on phones', async ({ page }) => {
  await login(page, 'at')
  await page.goto('/registry')
  await expect(page.getByRole('row').filter({ hasText: 'KL-2026-00001' })).toBeVisible()
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)
  expect(overflow).toBeLessThanOrEqual(1)
})
