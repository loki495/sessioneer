const { test, expect } = require('@playwright/test');

const live = process.env.SESSIONEER_PLAYWRIGHT_LIVE === '1';
const workdir = process.env.SESSIONEER_CODEX_E2E_WORKDIR;

test.describe('live Codex browser lifecycle', () => {
  test.skip(!live, 'Run with npm run test:codex-live against a live Sessioneer instance.');
  test.skip(!workdir, 'SESSIONEER_CODEX_E2E_WORKDIR must be an absolute directory on the host.');

  test('create, refresh, reopen, and send a Codex message without errors', async ({ page }) => {
    test.setTimeout(120_000);

    const seriousErrors = [];
    const failedRequests = [];
    const serverErrors = [];
    let createdSession = null;

    page.on('pageerror', error => seriousErrors.push(`pageerror: ${error.message}`));
    page.on('console', message => {
      if (message.type() === 'error' && !message.text().includes('Failed to load resource')) {
        seriousErrors.push(`console: ${message.text()}`);
      }
    });
    page.on('requestfailed', request => {
      const failure = request.failure();
      if (!failure || !failure.errorText.includes('ERR_ABORTED')) {
        failedRequests.push(`${request.method()} ${request.url()}: ${failure ? failure.errorText : 'unknown failure'}`);
      }
    });
    page.on('response', response => {
      if (response.status() >= 500) {
        serverErrors.push(`${response.status()} ${response.url()}`);
      }
    });

    try {
      await page.goto('/');
      await expect(page).toHaveTitle(/Sessioneer/);

      await page.locator('#new-session-summary').click();
      await expect(page.locator('#new-session-agent')).toBeVisible();
      const existingSessions = new Set(await page.locator('a[href^="/session?session="]').evaluateAll(
        links => links.map(link => new URL(link.href).searchParams.get('session'))
      ));
      await page.locator('#new-session-agent').selectOption('codex');

      // The folder browser is itself loaded and exercised in-browser. The
      // live regression uses an explicit known-safe workspace so it does
      // not depend on how many parent/subdirectory clicks are needed on a
      // particular host.
      await expect(page.locator('#browser_path')).not.toHaveText('Loading…');
      await page.locator('#workdir_value').evaluate((input, value) => { input.value = value; }, workdir);
      await page.locator('#new-session-submit').evaluate(button => { button.disabled = false; });

      await Promise.all([
        page.waitForURL(url => url.pathname === '/'),
        page.locator('#new-session-submit').click(),
      ]);

      await expect(page.getByText('Created session', { exact: true })).toBeVisible();
      const currentSessions = await page.locator('a[href^="/session?session="]').evaluateAll(
        links => links.map(link => new URL(link.href).searchParams.get('session'))
      );
      createdSession = currentSessions.find(id => id && !existingSessions.has(id));
      expect(createdSession).toMatch(/^[0-9a-f-]{36}$/);

      // Refresh the dashboard first, proving the new sidecar/session row is
      // durable and the successful creation did not leave an error flash.
      await page.reload({ waitUntil: 'domcontentloaded' });
      const sessionLink = page.locator(`a[href="/session?session=${createdSession}"]`).first();
      await expect(sessionLink).toBeVisible({ timeout: 30_000 });
      await sessionLink.click();
      await expect(page).toHaveURL(new RegExp(`session=${createdSession}$`));
      await expect(page.locator('#history-list')).toBeVisible();
      await expect(page.getByText('Session not found.', { exact: true })).toHaveCount(0);
      await expect(page.getByText(/includeTurns is unavailable/i)).toHaveCount(0);
      await expect(page.locator('#compose-textarea')).toBeVisible();

      // A hard session-page refresh exercises the unmaterialized empty
      // thread detail/history lifecycle that originally failed.
      await page.reload({ waitUntil: 'domcontentloaded' });
      await expect(page.locator('#history-list')).toBeVisible();
      await expect(page.getByText('Session not found.', { exact: true })).toHaveCount(0);
      await expect(page.getByText(/includeTurns is unavailable/i)).toHaveCount(0);
      await expect(page.locator('#compose-textarea')).toBeVisible();

      const message = `Sessioneer Codex browser E2E ${Date.now()}: reply with exactly SESSIONEER_CODEX_E2E_OK.`;
      await page.locator('#compose-textarea').fill(message);
      const sendResponse = page.waitForResponse(response => response.url().includes('/session_send.php'));
      await page.locator('#compose-send-btn').click();
      const response = await sendResponse;
      expect(response.status()).toBe(200);
      expect(await response.json()).toMatchObject({ ok: true });
      await expect(page.locator('#compose-status')).toBeHidden();
      await expect(page.locator('#compose-textarea')).toHaveValue('');

      expect(seriousErrors).toEqual([]);
      expect(failedRequests).toEqual([]);
      expect(serverErrors).toEqual([]);
    } finally {
      // Archive the test-created thread through the same browser UI after
      // its first message has materialized a rollout. Dialog acceptance is
      // scoped to cleanup so unexpected dialogs in the tested flow fail.
      if (createdSession) {
        await page.goto('/').catch(() => {});
        page.once('dialog', dialog => dialog.accept());
        const rowLink = page.locator(`a[href="/session?session=${createdSession}"]`).first();
        const row = rowLink.locator('xpath=ancestor::li[1]');
        const kill = row.getByRole('button', { name: 'Kill' });
        if (await kill.count()) {
          await kill.click().catch(() => {});
          await expect(rowLink).toHaveCount(0, { timeout: 30_000 });

          // Closing a Codex session archives it natively. Verify the lazy
          // archive endpoint discovers that durable catalog entry and the
          // browser renders a usable archived-session link for it.
          await page.locator('#show-archived-btn').click();
          const archivedLink = page.locator(`a[href="/archived-session?agent_session_id=${createdSession}"]`).first();
          await expect(archivedLink).toBeVisible({ timeout: 30_000 });
          await archivedLink.click();
          await expect(page.getByRole('heading', { name: 'History (read-only)' })).toBeVisible();
          await expect(page.getByText('Session not found.', { exact: true })).toHaveCount(0);
          await expect(page.getByText('Transcript file not found', { exact: false })).toHaveCount(0);
        }
      }
    }
  });
});
