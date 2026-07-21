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

test.describe('Administration', () => {
  test('User Management: create → verify → delete', async ({ page }) => {
    await nav(page, 'ad/users')
    const email = `e2e.user.${stamp()}@example.lk`

    await page.getByRole('button', { name: 'Add User' }).click()
    await expect(modal(page).getByRole('heading', { name: 'Add System User' })).toBeVisible()
    await mfield(page, 'Full Name').fill('E2E User')
    await mfield(page, 'Email').fill(email)
    await mfield(page, 'Password').fill('password123')
    await mfield(page, 'Role').selectOption('manager')

    const [resp] = await Promise.all([
      page.waitForResponse((r) => r.url().includes('/api/users') && r.request().method() === 'POST'),
      modal(page).getByRole('button', { name: 'Create User' }).click(),
    ])
    expect(resp.status()).toBe(201)
    await expect(page.getByRole('cell', { name: email })).toBeVisible()

    // Delete via the custom confirm dialog
    await page.getByRole('row', { name: new RegExp(email) }).getByTitle('Delete').click()
    await confirmDelete(page).click()
    await expect(page.getByRole('cell', { name: email })).toHaveCount(0)
  })

  test('Company Profile: update tagline and save', async ({ page }) => {
    await nav(page, 'ad/company')
    const tagline = 'E2E tagline ' + stamp()
    await page.locator('label:has(span:text-is("Tagline")) input').fill(tagline)

    const [resp] = await Promise.all([
      page.waitForResponse((r) => r.url().includes('/api/company-profile') && r.request().method() === 'PUT'),
      page.getByRole('button', { name: 'Save Changes' }).click(),
    ])
    expect(resp.ok()).toBeTruthy()
  })

  test('Sales Reps: renders seeded reps and creates a new rep', async ({ page }) => {
    await nav(page, 'ad/reps')
    await expect(page.getByRole('cell', { name: 'R. Fernando' })).toBeVisible()

    const name = 'E2E Rep ' + stamp()
    await page.getByRole('button', { name: 'Add Sales Rep' }).click()
    await expect(modal(page).getByRole('heading', { name: 'Add Sales Rep' })).toBeVisible()
    await mfield(page, 'Full Name').fill(name)
    await mfield(page, 'Sales Zone').fill('E2E Zone')

    const [resp] = await Promise.all([
      page.waitForResponse((r) => r.url().includes('/api/sales-reps') && r.request().method() === 'POST'),
      modal(page).getByRole('button', { name: 'Create Rep & Login' }).click(),
    ])
    expect(resp.status()).toBe(201)
    // Dismiss the generated-credentials dialog, then confirm the rep is listed
    await page.getByRole('button', { name: 'Done' }).click()
    await expect(page.getByRole('cell', { name })).toBeVisible()
  })

  test('User Permission: matrix loads for a user', async ({ page }) => {
    await nav(page, 'ad/perm')
    // The user selector and the permission matrix (Module / All columns) render
    await expect(page.getByText('User:')).toBeVisible()
    await expect(page.getByRole('columnheader', { name: 'Module' })).toBeVisible()
    await expect(page.getByRole('columnheader', { name: 'All', exact: true })).toBeVisible()
    // At least one module row is present
    await expect(page.locator('tbody tr').first()).toBeVisible()
  })
})
