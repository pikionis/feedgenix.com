import { expect, test, type Page } from '@playwright/test'

// A phone-width screen: no page may be wider than the viewport (found in M1: the app header
// pushed the workspace to ~540px on a 390px phone).
test.use({ viewport: { width: 390, height: 844 } })

async function expectNoHorizontalScroll(page: Page, path: string): Promise<void> {
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth)

  expect(overflow, `${path} is ${overflow}px wider than the screen`).toBeLessThanOrEqual(0)
}

for (const path of ['/', '/login', '/register', '/forgot-password']) {
  test(`${path} fits a phone`, async ({ page }) => {
    await page.goto(path)
    await page.getByRole('heading', { level: 1 }).waitFor()

    await expectNoHorizontalScroll(page, path)
  })
}

test('the workspace fits a phone, even with a long name', async ({ page }) => {
  await page.goto('/register')
  await page.getByLabel('Name').fill('Konstantina Papadopoulou-Georgiou')
  await page.getByLabel('Email').fill(`phone-${Date.now()}@example.com`)
  await page.getByLabel('Password', { exact: true }).fill('correct horse battery')
  await page.getByLabel('Repeat the password').fill('correct horse battery')
  await page.getByRole('button', { name: 'Create account' }).click()
  await page.waitForURL(/\/app$/)
  await page.getByRole('heading', { name: /Welcome/ }).waitFor()
  await expectNoHorizontalScroll(page, '/app')

  await page.goto('/app/settings')
  await page.getByText('Most popular').waitFor()
  await expectNoHorizontalScroll(page, '/app/settings')
})
