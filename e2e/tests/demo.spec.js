import { expect, test } from '@playwright/test';
import { DEMO, expectNoAxeViolations, openEvaluation, signIn } from './helpers.js';

/**
 * MVP criterion 4: the demo data (app:demo, which ./e2e.sh runs as the README says) is there to look around in. The
 * owner signs in and opens the closed evaluation's results and its comparison, with numbers from the start.
 */
test('the demo owner opens the results and the comparison of the closed evaluation', async ({ page }) => {
  await signIn(page, DEMO.owner, DEMO.password);
  await openEvaluation(page, 'Support assistant (demo)', 'Release check');

  const subnav = page.getByRole('navigation', { name: 'Evaluation' });
  await subnav.getByRole('link', { name: 'Results' }).click();
  await expect(page.getByText('The evaluation is closed: these results no longer change.')).toBeVisible();
  const byCriterion = page.getByRole('region', { name: 'By criterion', exact: true });
  await expect(byCriterion.getByRole('row', { name: /^Overall score/ })).toContainText(/\d+\.\d/);
  await expect(page.getByRole('link', { name: 'Export results (CSV)' })).toBeVisible();

  await subnav.getByRole('link', { name: 'Compare' }).click();
  // The story of the demo: the tuned run (B) is kinder than the baseline (A).
  await expect(page.locator('.stat').filter({ hasText: 'Tone' }).locator('.diff')).toHaveText(/^▲ B is [\d.]+ points higher than A$/);
  await expect(page.getByRole('region', { name: 'Largest disagreements' }).getByRole('link').first()).toBeVisible();
  await expectNoAxeViolations(page, 'Compare (demo)');
});
