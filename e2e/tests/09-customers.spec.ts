import { test, expect, Page } from '@playwright/test'
import { nav } from './helpers'

const stamp = () => Date.now().toString().slice(-6)
const modal = (page: Page) => page.locator('.mms-modal-overlay')
const mfield = (page: Page, label: string) =>
  modal(page).locator(`label:has(span:text-is("${label}")) `).locator('input, select, textarea').first()
const confirmDelete = (page: Page) => page.locator('button.mms-btn').filter({ hasText: /^Delete$/ })

test.beforeEach(async ({ page }) => {
  await page.goto('/')
  await expect(page.getByRole('heading', { level: 1, name: 'Dashboard' })).toBeVisible()
})

test.describe('Customer Management', () => {
  test('create → verify auto-code → delete', async ({ page }) => {
    await nav(page, 'customers')
    const name = 'E2E Customer ' + stamp()

    await page.getByRole('button', { name: 'Add Customer' }).click()
    await expect(modal(page).getByRole('heading', { name: 'Add Customer' })).toBeVisible()
    await mfield(page, 'Full Name').fill(name)
    await mfield(page, 'Branch').selectOption({ label: 'Head Office' })
    await mfield(page, 'NIC Number').fill('E2E' + stamp())

    const [resp] = await Promise.all([
      page.waitForResponse((r) => r.url().includes('/api/customers') && r.request().method() === 'POST'),
      modal(page).getByRole('button', { name: 'Create Customer' }).click(),
    ])
    expect(resp.status()).toBe(201)
    const body = await resp.json()
    expect(body.code).toMatch(/^CUS-\d{6}$/)
    await expect(page.getByRole('cell', { name })).toBeVisible()

    await page.getByRole('row', { name: new RegExp(name) }).getByTitle('Delete').click()
    await confirmDelete(page).click()
    await expect(page.getByRole('cell', { name })).toHaveCount(0)
  })

  test('occupation type toggles employer/business fields', async ({ page }) => {
    await nav(page, 'customers')
    await page.getByRole('button', { name: 'Add Customer' }).click()
    await expect(modal(page).getByRole('heading', { name: 'Add Customer' })).toBeVisible()

    await expect(modal(page).getByText('Employer Name')).toHaveCount(0)
    await expect(modal(page).getByText('Business Name')).toHaveCount(0)

    await mfield(page, 'Occupation Type').selectOption({ label: 'Employed' })
    await expect(modal(page).getByText('Employer Name')).toBeVisible()
    await expect(modal(page).getByText('Business Name')).toHaveCount(0)

    await mfield(page, 'Occupation Type').selectOption({ label: 'Business Owner' })
    await expect(modal(page).getByText('Business Name')).toBeVisible()
    await expect(modal(page).getByText('Employer Name')).toHaveCount(0)

    await modal(page).getByRole('button', { name: 'Cancel' }).click()
  })
})
