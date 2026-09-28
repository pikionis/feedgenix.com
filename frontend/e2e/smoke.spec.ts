import { expect, test } from '@playwright/test'

test('the pre-rendered home page shows the brand promise', async ({ page }) => {
  await page.goto('/')

  await expect(page).toHaveTitle(/FeedGenix/)
  await expect(page.getByRole('heading', { level: 1 })).toContainText('every language your buyers shop in')
  await expect(page.getByText(/pass through untouched/i)).toBeVisible()
})

test('the workspace sends a visitor without a session to sign in, remembering the page', async ({ page }) => {
  await page.goto('/app/settings')

  await expect(page).toHaveURL(/\/login\?next=%2Fapp%2Fsettings$/)
  await expect(page.getByRole('heading', { name: 'Sign in' })).toBeVisible()
})

test('the health endpoint answers through the same origin', async ({ request }) => {
  const response = await request.get('/api/health')

  expect(response.status()).toBe(200)
  expect(await response.json()).toEqual({ status: 'ok', database: 'ok' })
})

test('paths outside the build are never served', async ({ request }) => {
  const response = await request.get('/..%2f..%2f.env')

  expect(response.status()).not.toBe(200)
})
