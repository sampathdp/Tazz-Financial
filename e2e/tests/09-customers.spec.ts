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

/** The Add Customer form is a full page (not a modal). Fields are <label><span>NAME</span>control</label>. */
const pfield = (page: Page, label: string) =>
  page.locator(`label:has(span:text-is("${label}"))`).locator('input, select, textarea').first()

test.describe('Customer Management', () => {
  test('create → verify auto-code → delete', async ({ page }) => {
    await nav(page, 'customers')
    const surname = 'E2ESurname' + stamp()

    await page.getByRole('button', { name: 'Add Customer' }).click()
    await expect(page.getByRole('heading', { level: 1, name: 'Add Customer' })).toBeVisible()
    await pfield(page, 'Title').selectOption({ index: 1 })
    await pfield(page, 'Surname').fill(surname)
    await pfield(page, 'Initial').fill('A.B.')
    await pfield(page, 'Branch').selectOption({ label: 'Head Office' })
    await pfield(page, 'NIC Number').fill('91123' + stamp().slice(-4) + 'V')

    const [resp] = await Promise.all([
      page.waitForResponse((r) => r.url().includes('/api/customers') && r.request().method() === 'POST'),
      page.getByRole('button', { name: 'Create Customer' }).click(),
    ])
    expect(resp.status()).toBe(201)
    const body = await resp.json()
    expect(body.code).toMatch(/^CUS-\d{6}$/)
    await expect(page.getByRole('heading', { level: 1, name: 'Customer Management' })).toBeVisible()
    await expect(page.getByText(surname).first()).toBeVisible()

    await page.getByRole('row', { name: new RegExp(surname) }).getByTitle('Delete').click()
    await confirmDelete(page).click()
    await expect(page.getByText(surname)).toHaveCount(0)
  })

  test('required fields are enforced on the form', async ({ page }) => {
    await nav(page, 'customers')
    await page.getByRole('button', { name: 'Add Customer' }).click()
    await page.getByRole('button', { name: 'Create Customer' }).click()
    await expect(page.getByText('Title, Surname and Initial are required.')).toBeVisible()
  })

  test('occupation type toggles employer/business fields', async ({ page }) => {
    await nav(page, 'customers')
    await page.getByRole('button', { name: 'Add Customer' }).click()
    await expect(page.getByRole('heading', { level: 1, name: 'Add Customer' })).toBeVisible()

    await expect(page.getByText('Employer Name', { exact: true })).toHaveCount(0)
    await expect(page.getByText('Business Name', { exact: true })).toHaveCount(0)

    await pfield(page, 'Occupation Type').selectOption({ label: 'Employed' })
    await expect(page.getByText('Employer Name', { exact: true })).toBeVisible()
    await expect(page.getByText('Business Name', { exact: true })).toHaveCount(0)

    await pfield(page, 'Occupation Type').selectOption({ label: 'Business Owner' })
    await expect(page.getByText('Business Name', { exact: true })).toBeVisible()
    await expect(page.getByText('Employer Name', { exact: true })).toHaveCount(0)

    await page.getByRole('button', { name: 'Cancel' }).click()
  })
})
