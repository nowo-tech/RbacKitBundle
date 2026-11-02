import { test, expect } from '@playwright/test';
import { as, confirmAction, ensureReportingSet, rows } from './helpers';

/** REQ-DEMO-013 — happy path of the RBAC Kit demo: the voter per project, the request guard and the admin UI. */
test.describe('Permissions of alice (viewer of both projects, triage in Billing)', () => {
  test.use(as('alice'));

  test('the matrix is the union of her sets in each project', async ({ page }) => {
    // The home page is public: /sign-in (access_control) makes the browser authenticate, then redirects home.
    await page.goto('/sign-in');
    const billing = page.locator('[data-testid="demo-matrix"] tr[data-project="billing"]');
    const mobile = page.locator('[data-testid="demo-matrix"] tr[data-project="mobile"]');
    await expect(billing.locator('td').nth(1)).toHaveText('✓');
    await expect(mobile.locator('td').nth(1)).toHaveText('—');
    await expect(page.getByTestId('demo-nav-reports')).toBeVisible();
    await expect(page.getByTestId('demo-nav-admin')).toHaveCount(0);
  });

  test('the request guard refuses the settings of a project she only views', async ({ page }) => {
    expect((await page.goto('/projects/billing'))?.status()).toBe(200);
    expect((await page.goto('/projects/billing/settings'))?.status()).toBe(403);
    expect((await page.goto('/admin/rbac'))?.status()).toBe(403);
  });
});

test.describe('Permissions of bob (global auditor)', () => {
  test.use(as('bob'));

  test('a global set opens the general pages but no project', async ({ page }) => {
    await page.goto('/reports');
    await expect(page.getByTestId('demo-export')).toBeVisible();
    expect((await page.goto('/projects/mobile'))?.status()).toBe(403);
  });
});

test.describe('Permissions of carol (owner of Mobile only)', () => {
  test.use(as('carol'));

  test('owning a project grants no platform-wide permission', async ({ page }) => {
    await page.goto('/sign-in');
    const general = page.getByTestId('demo-general');
    await expect(general).toContainText('Export reports: no');
    await expect(general).toContainText('View reports: no');
    const mobile = page.locator('[data-testid="demo-matrix"] tr[data-project="mobile"]');
    await expect(mobile.locator('td').nth(3)).toHaveText('✓');
    expect((await page.goto('/reports'))?.status()).toBe(403);
    expect((await page.goto('/projects/mobile/settings'))?.status()).toBe(200);
    expect((await page.goto('/projects/billing'))?.status()).toBe(403);
  });
});

test.describe('Admin UI', () => {
  test.use(as('admin'));

  test('a custom set is created, listed in the catalogue and deleted', async ({ page }) => {
    await ensureReportingSet(page);
    await page.goto('/admin/rbac');
    const row = rows(page, 'rbac-sets').filter({ hasText: 'reporting' });
    await expect(row).toHaveCount(1);
    await expect(row.getByTestId('rbac-kind')).toHaveText('Custom');

    await page.goto('/admin/rbac/catalogue');
    await expect(rows(page, 'rbac-catalogue').filter({ hasText: 'reports.export' })).toContainText('reporting');

    await page.goto('/admin/rbac');
    await confirmAction(page, rows(page, 'rbac-sets').filter({ hasText: 'reporting' }).getByTestId('rbac-delete'));
    await expect(page.getByText('Permission set "reporting" deleted.')).toBeVisible();
    await expect(rows(page, 'rbac-sets').filter({ hasText: 'reporting' })).toHaveCount(0);
  });

  test('a system set is edited over its configured default', async ({ page }) => {
    await page.goto('/admin/rbac/viewer/edit');
    await expect(page.getByTestId('rbac-form')).toBeVisible();
    await expect(page.getByLabel('View reports')).toBeChecked();
    await page.getByRole('button', { name: 'Save' }).click();
    await expect(page.getByText('Permission set "viewer" saved.')).toBeVisible();
    await expect(rows(page, 'rbac-sets').filter({ hasText: 'viewer' }).getByTestId('rbac-kind')).toHaveText('System (edited)');
  });
});
