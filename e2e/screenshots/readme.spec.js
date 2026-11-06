import { expect, test } from '@playwright/test';
import { DEMO, openEvaluation, signIn } from '../tests/helpers.js';

/**
 * The README's screenshots (docs/screenshots), made again from the demo data: ./e2e.sh screenshots. The folder is
 * mounted at /screenshots (compose.e2e.yaml).
 */
const shoot = async (page, name) => {
  // No focus ring on the heading that took the focus when the page opened.
  await page.evaluate(() => document.activeElement?.blur());
  await page.screenshot({ path: `/screenshots/${name}.png` });
};

test('Scoring in blind mode, as the evaluator', async ({ page }) => {
  await signIn(page, DEMO.evaluator, DEMO.password);
  await openEvaluation(page, 'Support assistant (demo)', 'Baseline vs tuned');
  // The worker runs this evaluation's checks after app:demo: wait for them, so the panel shows their results.
  await expect(page.getByText(/· done/)).toBeVisible({ timeout: 60_000 });
  await page.getByRole('link', { name: 'Continue scoring' }).first().click();
  await expect(page.locator('.case-title')).toBeFocused();
  await shoot(page, 'scoring-blind');
});

test('Results and Compare, as the owner, light and dark', async ({ page }) => {
  await signIn(page, DEMO.owner, DEMO.password);
  await openEvaluation(page, 'Support assistant (demo)', 'Release check');
  const subnav = page.getByRole('navigation', { name: 'Evaluation' });

  await subnav.getByRole('link', { name: 'Results' }).click();
  await expect(page.getByRole('region', { name: 'By criterion', exact: true })).toBeVisible();
  await shoot(page, 'results');

  await subnav.getByRole('link', { name: 'Compare' }).click();
  await expect(page.getByRole('region', { name: 'Largest disagreements' })).toBeVisible();
  await shoot(page, 'compare');

  // The app's own switch: it sets the theme when the page loads, so emulating the media query later changes nothing.
  await page.getByRole('button', { name: 'Dark theme' }).click();
  await subnav.getByRole('link', { name: 'Results' }).click();
  // Compare has a "By criterion" table too: wait for the Results page itself.
  await page.waitForURL(/\/results$/);
  await expect(page.getByRole('figure', { name: /how often each score was given/ })).toBeVisible();
  await shoot(page, 'results-dark');
});
