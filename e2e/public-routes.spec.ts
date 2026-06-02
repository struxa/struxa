import { test, expect, type Page } from '@playwright/test';

const errorPatterns = [/Slim Application Error/i, /PHP Fatal error/i, /Uncaught Exception/i];

async function assertHealthyRoute(page: Page, path: string, contentPattern: RegExp): Promise<void> {
  const res = await page.goto(path, { waitUntil: 'domcontentloaded' });
  expect(res, `No response for ${path}`).not.toBeNull();
  const status = res?.status() ?? 0;
  expect(status, `${path} should not 5xx`).toBeLessThan(500);
  expect(status, `${path} should return 2xx or 3xx`).toBeGreaterThanOrEqual(200);
  expect(status, `${path} should not 404 on staging`).toBeLessThan(400);

  const body = await page.locator('body').innerText();
  for (const pattern of errorPatterns) {
    expect(body, `${path} should not show application errors`).not.toMatch(pattern);
  }
  await expect(page.locator('body')).toBeVisible();
  expect(body, `${path} should include expected markup`).toMatch(contentPattern);
}

test.describe('Public storefront smoke', () => {
  test('homepage /', async ({ page }) => {
    await assertHealthyRoute(page, '/', /./);
  });

  test('knowledge base /kb', async ({ page }) => {
    await assertHealthyRoute(page, '/kb', /kb|knowledge|article|wiki/i);
  });

  test('forum /forum', async ({ page }) => {
    await assertHealthyRoute(page, '/forum', /forum|thread|discussion/i);
  });

  test('shop /shop', async ({ page }) => {
    await assertHealthyRoute(page, '/shop', /shop|product|cart|commerce|store/i);
  });
});
