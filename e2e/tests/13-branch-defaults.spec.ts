import { test, expect, Page } from '@playwright/test'
import { nav } from './helpers'

/** A branch created through the UI comes with its main till and ledger account; deleting the untouched branch removes them. */
const API = process.env.E2E_API_URL || 'http://127.0.0.1:8000/api'
const modal = (page: Page) => page.locator('.mms-modal-overlay')
const mfield = (page: Page, label: string) => modal(page).locator(`label:has(span:text-is("${label}")) `).locator('input, select, textarea').first()
const confirmDelete = (page: Page) => page.locator('button.mms-btn').filter({ hasText: /^Delete$/ })

test.beforeEach(async ({ page }) => {
  await page.goto('/')
  await expect(page.getByRole('heading', { level: 1, name: 'Dashboard' })).toBeVisible()
})

test('new branch gets its main till and ledger account automatically', async ({ page }) => {
  const code = 'DF' + Date.now().toString().slice(-6)
  await nav(page, 'ad/branches')
  await page.getByRole('button', { name: 'Add Branch' }).click()
  await mfield(page, 'Branch Name').fill('Default Accounts ' + code)
  await mfield(page, 'Code').fill(code)
  const [resp] = await Promise.all([
    page.waitForResponse((r) => r.url().includes('/api/branches') && r.request().method() === 'POST'),
    modal(page).getByRole('button', { name: 'Create Branch' }).click(),
  ])
  expect(resp.status()).toBe(201)
  const branchId = (await resp.json()).id

  const token = await page.evaluate(() => localStorage.getItem('mms-token') || localStorage.getItem('token') || '')
  const login = await page.request.post(`${API}/login`, { data: { email: 'admin@absl.local', password: 'password' }, headers: { Accept: 'application/json' } })
  const bearer = (await login.json()).token || token
  const tills = await (await page.request.get(`${API}/cash-accounts`, { headers: { Authorization: `Bearer ${bearer}`, Accept: 'application/json' } })).json()
  const mine = tills.filter((t: any) => t.branchId === branchId || t.branch_id === branchId)
  expect(mine).toHaveLength(1)
  expect(mine[0].purpose).toBe('General')
  expect(mine[0].account_type || mine[0].accountType).toBe('BranchMain')
  expect(mine[0].chart_of_account_id).toBeTruthy()

  // Open the Cashbook accounts screen and see the till listed for the new branch.
  await page.goto('/cash/book')
  await page.getByRole('button', { name: 'Manage Accounts' }).click()
  await expect(modal(page).getByText('Default Accounts ' + code + ' - Main Till')).toBeVisible()
  await page.screenshot({ path: `../audit/evidence/BRANCH-DEFAULTS-${process.env.EVIDENCE_TAG || 'after'}-cashbook-accounts.png` })

  // Clean up through the UI: the untouched branch and its default till are deleted together.
  await nav(page, 'ad/branches')
  await page.getByRole('row', { name: new RegExp(code) }).getByTitle('Delete').click()
  await confirmDelete(page).click()
  await expect(page.getByRole('cell', { name: code })).toHaveCount(0)
})
