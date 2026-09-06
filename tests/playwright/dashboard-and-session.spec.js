const { test, expect } = require('@playwright/test');

test('dashboard renders OpenCode usage without browser errors', async ({ page }) => {
  const errors = [];
  page.on('console', message => {
    if (message.type() === 'error' && !message.text().startsWith('Failed to load resource:')) errors.push(message.text());
  });
  page.on('pageerror', error => errors.push(error.message));
  page.on('response', response => { if (response.status() >= 500) errors.push(`${response.status()} ${response.url()}`); });

  await page.goto('/');
  await expect(page).toHaveTitle(/Sessioneer/);
  await page.locator('#quota-toggle-btn').click();

  const quota = page.locator('#quota-info');
  await expect(quota).toContainText('OpenCode');
  await expect(quota).toContainText('61%');
  await expect(quota).toContainText('45%');
  await expect(quota).toContainText('22%');
  await expect(quota).toContainText('Cost $12.34');
  await expect(quota).toContainText('In 12,345');
  await expect(quota).toContainText('Out 678');
  await expect(quota).toContainText('4 sessions');
  expect(errors.filter(error => !error.includes('Failed to load resource'))).toEqual([]);
});

test('session page renders the Claude transcript and controls on desktop', async ({ page }) => {
  const errors = [];
  page.on('console', message => {
    if (message.type() === 'error' && !message.text().startsWith('Failed to load resource:')) errors.push(message.text());
  });
  page.on('pageerror', error => errors.push(error.message));
  page.on('response', response => { if (response.status() >= 500) errors.push(`${response.status()} ${response.url()}`); });

  await page.goto('/session.php?session=cc-20260101-1200');
  await expect(page.locator('#header-title')).toHaveText('Fix the login redirect bug');
  await expect(page.locator('#history-list')).toContainText('Looking into it now.');
  await expect(page.locator('#compose-bar')).toBeVisible();
  await expect(page.locator('#blocked-prompt-section')).toContainText('Do you want to proceed?');
  expect(errors.filter(error => !error.includes('Failed to load resource'))).toEqual([]);
});

test('session page keeps its controls usable at a mobile viewport', async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto('/session.php?session=cc-20260101-1600');

  await expect(page.locator('#compose-textarea')).toBeVisible();
  await expect(page.locator('#sidebar-toggle-btn')).toBeVisible();
  // The control is intentionally hidden while already at the bottom; this
  // smoke test only needs to prove the mobile page rendered the control.
  await expect(page.locator('#go-to-bottom-btn')).toBeAttached();
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
});
