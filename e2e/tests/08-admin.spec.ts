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
  test('Branch Management: create → verify → delete', async ({ page }) => {
    await nav(page, 'ad/branches')
    const code = 'E2E' + stamp()

    await page.getByRole('button', { name: 'Add Branch' }).click()
    await expect(modal(page).getByRole('heading', { name: 'Add Branch' })).toBeVisible()
    await mfield(page, 'Branch Name').fill('E2E Branch ' + stamp())
    await mfield(page, 'Code').fill(code)

    const [resp] = await Promise.all([
      page.waitForResponse((r) => r.url().includes('/api/branches') && r.request().method() === 'POST'),
      modal(page).getByRole('button', { name: 'Create Branch' }).click(),
    ])
    expect(resp.status()).toBe(201)
    await expect(page.getByRole('cell', { name: code })).toBeVisible()

    await page.getByRole('row', { name: new RegExp(code) }).getByTitle('Delete').click()
    await confirmDelete(page).click()
    await expect(page.getByRole('cell', { name: code })).toHaveCount(0)
  })

  test('Role Management: create role, edit its permission matrix', async ({ page }) => {
    await nav(page, 'ad/roles')
    const name = 'E2E Role ' + stamp()

    await page.getByRole('button', { name: 'Add Role' }).click()
    await expect(modal(page).getByRole('heading', { name: 'Add Role' })).toBeVisible()
    await mfield(page, 'Role Name').fill(name)

    const [resp] = await Promise.all([
      page.waitForResponse((r) => r.url().includes('/api/roles') && r.request().method() === 'POST'),
      modal(page).getByRole('button', { name: 'Create Role' }).click(),
    ])
    expect(resp.status()).toBe(201)
    await page.locator('[data-testid="role-row"]', { hasText: name }).click()

    // Toggle the Dashboard:View cell on for the new role and save.
    await expect(page.getByRole('columnheader', { name: 'Module' })).toBeVisible()
    await page.locator('tbody tr', { hasText: 'Dashboard' }).locator('td').nth(1).locator('button').click()

    const [saveResp] = await Promise.all([
      page.waitForResponse((r) => r.url().includes('/permissions') && r.request().method() === 'PUT'),
      page.getByRole('button', { name: 'Save Permissions' }).click(),
    ])
    expect(saveResp.ok()).toBeTruthy()
  })

  test('User Management: create → verify → delete', async ({ page }) => {
    await nav(page, 'ad/users')
    const email = `e2e.user.${stamp()}@example.lk`

    await page.getByRole('button', { name: 'Add User' }).click()
    await expect(modal(page).getByRole('heading', { name: 'Add System User' })).toBeVisible()
    await mfield(page, 'Full Name').fill('E2E User')
    await mfield(page, 'Email').fill(email)
    await mfield(page, 'Password').fill('password123')
    await mfield(page, 'Role').selectOption({ label: 'Branch Manager' })
    await mfield(page, 'Branch').selectOption({ label: 'Head Office' })

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
