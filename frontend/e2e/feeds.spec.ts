import { readFileSync } from 'node:fs'
import { createServer, type Server } from 'node:http'
import type { AddressInfo } from 'node:net'
import { resolve } from 'node:path'
import { expect, test, type Page } from '@playwright/test'

const fixtures = resolve(import.meta.dirname, 'fixtures')

// The shop's feed URL: a local server the backend fetches from (allowed in end-to-end runs only).
let shop: Server
let feedUrl: string

test.beforeAll(async () => {
  const xml = readFileSync(resolve(fixtures, 'google.xml'))
  shop = createServer((request, response) => {
    if (request.url !== '/google.xml') return response.writeHead(404).end()
    response.writeHead(200, { 'content-type': 'application/xml; charset=utf-8' }).end(xml)
  })
  await new Promise<void>((done) => shop.listen(0, '127.0.0.1', done))
  feedUrl = `http://127.0.0.1:${(shop.address() as AddressInfo).port}/google.xml`
})

test.afterAll(async () => {
  await new Promise((done) => shop.close(done))
})

async function signUp(page: Page): Promise<void> {
  const password = 'correct horse battery'
  await page.goto('/register')
  await page.getByLabel('Name').fill('Fay Feeds')
  await page.getByLabel('Email').fill(`e2e-${Date.now()}-${Math.random().toString(36).slice(2, 8)}@example.com`)
  await page.getByLabel('Password', { exact: true }).fill(password)
  await page.getByLabel('Repeat the password').fill(password)
  await page.getByRole('button', { name: 'Create account' }).click()
  await expect(page).toHaveURL(/\/app$/)
}

async function addFeed(page: Page): Promise<void> {
  await page.getByRole('navigation', { name: 'Workspace' }).getByRole('link', { name: 'Feeds' }).click()
  await page.getByRole('button', { name: 'Add a feed', exact: true }).click()
}

test('an Excel upload becomes a column profile', async ({ page }) => {
  await signUp(page)
  await addFeed(page)

  const dialog = page.getByRole('dialog', { name: 'Add a feed' })
  await dialog.getByLabel('Name').fill('Tools export')
  await dialog.getByLabel('A file').check()
  await dialog.getByLabel('File', { exact: true }).setInputFiles(resolve(fixtures, 'tools.xlsx'))
  await dialog.getByRole('button', { name: 'Add feed' }).click()

  await expect(page).toHaveURL(/\/app\/feeds\/\d+$/)
  await expect(page.getByRole('heading', { name: 'Tools export' })).toBeVisible()
  await expect(page.getByRole('heading', { name: 'What we read' })).toBeVisible()

  const columns = page.getByRole('table', { name: 'Columns' })
  await expect(columns.getByRole('row', { name: /^SKU/ })).toContainText('Code')
  await expect(columns.getByRole('row', { name: /^EAN/ })).toContainText('GTIN')
  await expect(columns.getByRole('row', { name: /^Price/ })).toContainText('Decimal')
  await expect(columns.getByRole('row', { name: /^Colour/ })).toContainText('75%')
  await expect(page.getByRole('table', { name: 'Sample products' })).toContainText('Claw hammer')
  await expect(page.getByText(/SKU · filled and unique in every row/)).toBeVisible()
})

test('a Google Shopping URL becomes a column profile, and the Free plan stops at one feed', async ({ page }) => {
  await signUp(page)
  await addFeed(page)

  const dialog = page.getByRole('dialog', { name: 'Add a feed' })
  await dialog.getByLabel('Name').fill('Alpine Google feed')
  await dialog.getByLabel('Feed URL').fill(feedUrl)
  await dialog.getByRole('button', { name: 'Add feed' }).click()

  await expect(page).toHaveURL(/\/app\/feeds\/\d+$/)
  await expect(page.getByText('Fetched from 127.0.0.1')).toBeVisible()
  await expect(page.getByRole('heading', { name: 'What we read' })).toBeVisible()
  await expect(page.getByText('rss/channel/item')).toBeVisible()

  const columns = page.getByRole('table', { name: 'Columns' })
  await expect(columns.getByRole('row', { name: /^g:id/ })).toContainText('Code')
  await expect(columns.getByRole('row', { name: /^g:gtin/ })).toContainText('GTIN')
  await expect(columns.getByRole('row', { name: /^description/ })).toContainText('HTML')
  await expect(page.getByRole('table', { name: 'Sample products' })).toContainText('Merino beanie')

  // A second feed is over the Free plan: the upgrade dialog explains it.
  await addFeed(page)
  const second = page.getByRole('dialog', { name: 'Add a feed' })
  await second.getByLabel('Name').fill('Second feed')
  await second.getByLabel('Feed URL').fill(feedUrl)
  await second.getByRole('button', { name: 'Add feed' }).click()

  await expect(page.getByRole('dialog')).toContainText('Your Free plan includes 1 feed.')
})
