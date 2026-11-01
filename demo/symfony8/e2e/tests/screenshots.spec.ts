import { test, expect } from '@playwright/test';
import { mkdirSync } from 'node:fs';
import { resolve } from 'node:path';
import { as, ensureReportingSet, waitForWebProfiler } from './helpers';

/** REQ-DEMO-013 — README screenshots (captured as PNG, converted to WebP by scripts/to-webp.mjs). */
const outDir = process.env.SCREENSHOT_DIR
  ? resolve(process.env.SCREENSHOT_DIR)
  : resolve(__dirname, '../../../../docs/images/demo');

test.beforeAll(() => {
  mkdirSync(outDir, { recursive: true });
});

test.describe('RBAC Kit admin screenshots', () => {
  test.use({ viewport: { width: 1280, height: 1000 }, ...as('admin') });

  test('permission-sets — system, edited and custom sets with their permissions and assignments', async ({ page }) => {
    await ensureReportingSet(page);
    await page.goto('/admin/rbac');
    await expect(page.getByTestId('rbac-sets')).toBeVisible();
    await waitForWebProfiler(page);
    await page.screenshot({ path: resolve(outDir, 'permission-sets.png'), fullPage: true });
  });

  test('permission-set-form — a set is a collection of catalogued permissions', async ({ page }) => {
    await page.goto('/admin/rbac/triage/edit');
    await expect(page.getByTestId('rbac-form')).toBeVisible();
    await waitForWebProfiler(page);
    await page.screenshot({ path: resolve(outDir, 'permission-set-form.png'), fullPage: true });
  });
});

test.describe('Voter screenshot', () => {
  test.use({ viewport: { width: 1280, height: 800 }, ...as('alice') });

  test('access-matrix — rbac_can() per project for a user holding two incomparable sets', async ({ page }) => {
    await page.goto('/');
    await expect(page.getByTestId('demo-matrix')).toBeVisible();
    await waitForWebProfiler(page);
    await page.screenshot({ path: resolve(outDir, 'access-matrix.png'), fullPage: true });
  });
});
