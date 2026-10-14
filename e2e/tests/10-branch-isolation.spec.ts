import { test, expect, request as pwRequest } from '@playwright/test'
import { dismissWhatsNew, ADMIN } from './helpers'

/**
 * BUG-002 / BUG-003 (audit): a branch-confined user must not see, or create, another branch's records.
 * Uses a Horana (branch 4) Branch Manager against a record created in Head Office (branch 1).
 * The manager's password is set on the isolated test database before the run (see audit/09).
 */
const MANAGER = { email: 'yapa@tazz.local', password: process.env.E2E_MANAGER_PASSWORD || 'Zq7-mV3-kLp9-x' }
const API = process.env.E2E_API_URL || 'http://127.0.0.1:8000/api'

async function token(email: string, password: string) {
  const ctx = await pwRequest.newContext()
  const res = await ctx.post(`${API}/login`, { data: { email, password }, headers: { Accept: 'application/json' } })
  expect(res.ok()).toBeTruthy()
  return { ctx, token: (await res.json()).token as string }
}

test.use({ storageState: { cookies: [], origins: [] } })

test.describe('Branch isolation (UI + API)', () => {
  const marker = 'ISO' + Date.now().toString().slice(-7)

  test('manager of one branch cannot see or create other-branch expenses', async ({ page }) => {
    // Arrange: an expense in Head Office, created by the admin through the API.
    const admin = await token(ADMIN.email, ADMIN.password)
    const auth = { Authorization: `Bearer ${admin.token}`, Accept: 'application/json' }
    const cats = await (await admin.ctx.get(`${API}/expense-categories`, { headers: auth })).json()
    const created = await admin.ctx.post(`${API}/expenses`, {
      headers: auth,
      data: { branch_id: 1, expense_category_id: cats[0].id, amount: 123, expense_date: new Date().toISOString().slice(0, 10), description: `HO only ${marker}` },
    })
    expect(created.status()).toBe(201)

    // Act: log in through the real login form as the Horana manager and open Expenses.
    await page.goto('/')
    await page.getByPlaceholder('you@company.com').fill(MANAGER.email)
    await page.getByPlaceholder('••••••••').fill(MANAGER.password)
    await page.getByRole('button', { name: 'Sign in' }).click()
    await dismissWhatsNew(page)
    await expect(page.getByRole('heading', { level: 1, name: 'Dashboard' })).toBeVisible()
    await page.goto('/exp/expenses')
    await expect(page.getByRole('heading', { level: 1, name: /Expenses/ })).toBeVisible()
    await page.waitForLoadState('networkidle')

    // Assert (read): the Head Office expense is not listed.
    await expect(page.getByText(marker)).toHaveCount(0)
    await page.screenshot({ path: `../audit/evidence/BUG-002-${process.env.EVIDENCE_TAG || 'after'}-manager-expenses.png` })

    // Assert (write): creating an expense for Head Office is refused with 403.
    const mgr = await token(MANAGER.email, MANAGER.password)
    const mauth = { Authorization: `Bearer ${mgr.token}`, Accept: 'application/json' }
    const crossWrite = await mgr.ctx.post(`${API}/expenses`, {
      headers: mauth,
      data: { branch_id: 1, expense_category_id: cats[0].id, amount: 5, expense_date: new Date().toISOString().slice(0, 10), description: `cross ${marker}` },
    })
    expect(crossWrite.status()).toBe(403)

    // Direct API read: the list for the manager must not contain the marker either.
    const list = await (await mgr.ctx.get(`${API}/expenses`, { headers: mauth })).text()
    expect(list).not.toContain(marker)
  })
})
