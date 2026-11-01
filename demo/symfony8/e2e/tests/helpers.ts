import { type Page, type Locator } from '@playwright/test';

/** Demo users (DEMO ONLY: the password is the user name; the demo binds to 127.0.0.1). */
export function as(user: string): { httpCredentials: { username: string; password: string; send: 'always' } } {
  return { httpCredentials: { username: user, password: user, send: 'always' } };
}

/** Wait until the Symfony Web Debug Toolbar has finished loading (optional outside dev). */
export async function waitForWebProfiler(page: Page): Promise<void> {
  await page.waitForSelector('.sf-toolbar', { state: 'visible', timeout: 20000 }).catch(() => undefined);
  await page
    .waitForFunction(
      () => {
        const bar = document.querySelector('.sf-toolbar') as HTMLElement | null;
        if (!bar || bar.offsetParent === null) {
          return true;
        }
        return !!document.querySelector('.sf-toolbar-block');
      },
      { timeout: 25000, polling: 250 },
    )
    .catch(() => undefined);
  await new Promise((r) => setTimeout(r, 400));
}

/** Rows of a list page (`data-testid` of the page section). */
export function rows(page: Page, testId: string): Locator {
  return page.locator(`[data-testid="${testId}"] tbody tr`);
}

/** Opens the UiKit confirm dialog of an action button and confirms it. */
export async function confirmAction(page: Page, button: Locator): Promise<void> {
  await button.click();
  const dialog = page.locator('dialog[open]');
  await dialog.locator('.nowo-ui-confirm__submit').click();
}

/** Creates the custom set `reporting` (reports.view + reports.export) unless it exists. */
export async function ensureReportingSet(page: Page): Promise<void> {
  await page.goto('/admin/rbac');
  if ((await rows(page, 'rbac-sets').filter({ hasText: 'reporting' }).count()) > 0) {
    return;
  }
  await page.goto('/admin/rbac/new');
  await page.getByTestId('rbac-code').fill('reporting');
  await page.getByTestId('rbac-name').fill('Reporting');
  await page.getByLabel('View reports').check();
  await page.getByLabel('Export reports').check();
  await page.getByRole('button', { name: 'Create' }).click();
}
