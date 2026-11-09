import { readFile } from 'node:fs/promises';
import { expect, test } from '@playwright/test';
import { DEMO, openEvaluation, parseCsv, signIn } from '../tests/helpers.js';

/**
 * The guide's screenshots (docs/guide), made again by ./e2e.sh screenshots, and a check that the guide's steps still
 * work: the same steps through the UI, with the demo's files (backend/demo, mounted at /demo), and the numbers the guide
 * quotes. The images go to /guide (compose.e2e.yaml).
 */
const demoFile = (name) => `/demo/${name}`;

const shoot = async (page, name, options = {}) => {
  // No focus ring on the heading or button that has the focus.
  await page.evaluate(() => document.activeElement?.blur());
  await page.screenshot({ path: `/guide/${name}.png`, ...options });
};

/** The page from its top down to the bottom of `locator`, taller than the window if needed. */
const shootDownTo = async (page, name, locator) => {
  const bottom = await locator.evaluate((element) => element.getBoundingClientRect().bottom + window.scrollY);
  await shoot(page, name, { fullPage: true, clip: { x: 0, y: 0, width: page.viewportSize().width, height: Math.ceil(bottom) + 24 } });
};

/** Scrolls so that `locator` is at the top of the window, with a small margin. */
const scrollToTop = (locator) => locator.evaluate((element) => window.scrollTo(0, element.getBoundingClientRect().top + window.scrollY - 24));

/** A tab of the project's navigation. */
const tab = (page, name) => page.getByRole('navigation', { name: 'Project' }).getByRole('link', { name, exact: true }).click();

test('Steps 1 to 9: the support assistant, from an empty project to closing the evaluation', async ({ page }) => {
  await signIn(page, DEMO.owner, DEMO.password);

  await test.step('1. create the project', async () => {
    await page.getByRole('link', { name: 'Create project' }).first().click();
    await page.getByLabel('Name').fill('Support assistant');
    await page.getByRole('button', { name: 'Create project' }).click();
    await expect(page.getByRole('heading', { level: 1, name: 'Support assistant' })).toBeVisible();
    await expect(page.getByText('0 of 4 done')).toBeVisible();
  });

  await test.step('2. import the cases: an invalid file, then cases.csv', async () => {
    await page.getByRole('link', { name: 'Import a dataset' }).click();
    await page.getByLabel('Name').fill('Support cases');
    // The guide's two edits: line 3 takes the id of line 2, and line 4 loses its input.
    const lines = (await readFile(demoFile('cases.csv'), 'utf8')).split('\n');
    lines[2] = lines[2].replace(/^refund-2,/, 'refund-1,');
    lines[3] = lines[3].replace(/^refund-3,[^,]*,/, 'refund-3,,');
    await page.locator('#field-file').setInputFiles({ name: 'cases-broken.csv', mimeType: 'text/csv', buffer: Buffer.from(lines.join('\n')) });
    await page.getByRole('button', { name: 'Check and import' }).click();
    await expect(page.getByRole('heading', { name: 'Import rejected: nothing was saved' })).toBeVisible();
    await expect(page.getByText('2 errors in 2 lines')).toBeVisible();
    await shoot(page, 'import-rejected');

    await page.locator('#field-file').setInputFiles(demoFile('cases.csv'));
    await page.getByRole('button', { name: 'Check and import' }).click();
    await expect(page.getByText('Version v1 created: 50 cases.')).toBeVisible();
    await shoot(page, 'dataset');
  });

  await test.step('3. import the runs', async () => {
    await tab(page, 'Runs');
    for (const [label, file, description, imported] of [
      ['baseline-v1', 'run-baseline-v1.csv', 'The first support prompt.', '50 answers (50 of 50 cases)'],
      ['tuned-v2', 'run-tuned-v2.json', "The prompt with the shop's policies, an apology rule and a friendlier tone.", '46 answers (46 of 50 cases)'],
    ]) {
      await page.getByRole('link', { name: 'Import run' }).first().click();
      await expect(page.getByLabel('Dataset version')).toHaveValue(/./);
      await page.getByLabel('Run label').fill(label);
      await page.getByLabel('Model description').fill(description);
      await page.locator('#field-file').setInputFiles(demoFile(file));
      await page.getByRole('button', { name: 'Check and import' }).click();
      await expect(page.getByText(`imported: ${imported}.`)).toBeVisible();
      await tab(page, 'Runs');
    }
  });

  await test.step('4. write the rubric', async () => {
    await tab(page, 'Rubrics');
    await page.getByRole('link', { name: 'New rubric' }).click();
    await page.getByLabel('Rubric name').fill('Support quality');
    const criteria = [
      { kind: 'Scale 1–5', name: 'Tone', weight: '2', guidance: '1: rude or cold. 3: polite but generic. 5: warm and clear, without filler.' },
      {
        kind: 'Pass or fail',
        name: 'Follows the policy',
        guidance: "Pass when the answer keeps to the shop's rules: returns within 30 days, one refund per order, nothing about other customers, no made-up codes, dates or reviews.",
      },
      { kind: 'Automatic check', name: 'Mentions the order number', check: 'Matches a regular expression', pattern: '#\\d{6}' },
      { kind: 'Automatic check', name: 'Matches the reference', check: 'Exact match with the reference', ignoreCase: true },
      { kind: 'Automatic check', name: 'Short enough', check: 'Length limits', max: '160', weight: '0.5' },
    ];
    for (const [index, criterion] of criteria.entries()) {
      await page.getByRole('button', { name: 'Add criterion' }).click();
      const fields = page.locator('fieldset.crit').nth(index);
      await fields.getByRole('radio', { name: criterion.kind }).check();
      await fields.getByLabel('Name', { exact: true }).fill(criterion.name);
      if (criterion.check) {
        await fields.getByLabel('Check', { exact: true }).selectOption({ label: criterion.check });
      }
      if (criterion.pattern) {
        await fields.getByLabel('Pattern').fill(criterion.pattern);
      }
      if (criterion.ignoreCase) {
        await fields.getByLabel('Ignore case').check();
      }
      if (criterion.max) {
        await fields.getByLabel('Maximum').fill(criterion.max);
      }
      if (criterion.weight) {
        await fields.getByLabel(/^Weight/).fill(criterion.weight);
      }
      if (criterion.guidance) {
        await fields.getByLabel(/^Guidance/).fill(criterion.guidance);
      }
    }
    await page.getByRole('button', { name: 'Save rubric' }).click();
    await expect(page.getByText('Rubric Support quality created.')).toBeVisible();
    await expect(page.getByText('Length: at most 160 characters')).toBeVisible();
    // The criteria, without the Duplicate and Delete forms below them.
    await shootDownTo(page, 'rubric', page.locator('section').filter({ has: page.getByRole('heading', { name: 'Criteria', exact: true }) }));
  });

  await test.step('5. start a blind evaluation', async () => {
    await tab(page, 'Evaluations');
    await page.getByRole('link', { name: 'New evaluation' }).click();
    await page.getByLabel('Name').fill('Baseline vs tuned');
    await page.getByRole('checkbox', { name: /baseline-v1/ }).check();
    await page.getByRole('checkbox', { name: /tuned-v2/ }).check();
    await expect(page.getByRole('checkbox', { name: /Blind mode/ })).toBeChecked();
    await expect(page.getByText('tuned-v2 does not answer every case.')).toBeVisible();
    await expect(page.getByText('96 answers to score · 2 manual criteria · 3 automatic')).toBeVisible();
    await shootDownTo(page, 'new-evaluation', page.locator('main form'));
    await page.getByRole('button', { name: 'Start evaluation' }).click();
    await expect(page.getByText('The evaluation started.')).toBeVisible();
  });

  await test.step('6. wait for the automatic checks', async () => {
    // The page polls every 5 s while the workers run the checks.
    await expect(page.getByText('96 of 96 · done')).toBeVisible({ timeout: 120_000 });
    await expect(page.getByRole('row', { name: /^Short enough/ })).toContainText('96 / 96');
    await shoot(page, 'evaluation');
  });

  await test.step('7. invite an evaluator', async () => {
    await tab(page, 'Members');
    await page.getByLabel('Email').fill(DEMO.evaluator);
    await page.getByLabel('Role', { exact: true }).selectOption({ label: 'Evaluator' });
    await page.getByRole('button', { name: 'Invite' }).click();
    await expect(page.getByText('Demo Evaluator was added as an evaluator.')).toBeVisible();
  });

  await test.step('8. score six answers with the keyboard', async () => {
    await tab(page, 'Evaluations');
    await page.getByRole('link', { name: 'Baseline vs tuned' }).click();
    await page.getByRole('link', { name: 'Start scoring' }).first().click();
    const scorebar = page.locator('.scorebar');
    for (let scored = 1; scored <= 6; scored++) {
      // A new answer: the case's title has the focus, and no score is chosen yet.
      await expect(page.locator('.case-title')).toBeFocused();
      await expect(page.locator('.scale input:checked')).toHaveCount(0);
      const answer = await page.locator('.textblock.is-answer').innerText();
      await page.keyboard.press(answer.length > 60 ? '5' : '2');
      await page.keyboard.press('p');
      if (scored === 2) {
        await page.keyboard.press('c');
        await expect(page.getByLabel(/^Comment on this answer/)).toBeFocused();
        await page.keyboard.type('Short, but it answers the question.');
        await page.keyboard.press('Escape');
        await expect(page.locator('.case-title')).toBeFocused();
      }
      await expect(scorebar).toContainText(`${scored} of 96 scored`);
      await expect(scorebar.getByText(/^Saved at/)).toBeVisible();
      if (scored === 1) {
        await shoot(page, 'scoring');
      }
      // J waits for the save, then opens the next answer with missing scores.
      const url = page.url();
      await page.keyboard.press('j');
      await page.waitForURL((next) => next.href !== url);
    }
    // K goes back to the answer just scored.
    await expect(page.locator('.case-title')).toBeFocused();
    await expect(page.locator('.scale input:checked')).toHaveCount(0);
    const url = page.url();
    await page.keyboard.press('k');
    await page.waitForURL((previous) => previous.href !== url);
    await expect(page.locator('.scale input:checked')).toHaveCount(2);
  });

  await test.step('9. close the evaluation', async () => {
    await page.getByRole('link', { name: 'Baseline vs tuned' }).click();
    await page.getByRole('button', { name: 'Close evaluation' }).click();
    await expect(page.locator('form.confirm')).toContainText('Close “Baseline vs tuned” with 90 answers still unscored?');
    await page.locator('form.confirm').getByRole('button', { name: 'Close evaluation' }).click();
    await expect(page.getByText(/^Closed/).first()).toBeVisible();
  });
});

test('Steps 10 to 12: the results, the comparison and the export of "Release check"', async ({ page }) => {
  await signIn(page, DEMO.owner, DEMO.password);
  await openEvaluation(page, 'Support assistant (demo)', 'Release check');
  const subnav = page.getByRole('navigation', { name: 'Evaluation' });

  await test.step('10. read the results', async () => {
    await subnav.getByRole('link', { name: 'Results' }).click();
    // The numbers the guide quotes, A then B (innerText keeps a tab between the cells).
    const row = (name) => page.getByRole('region', { name: 'By criterion', exact: true }).getByRole('row', { name });
    const cells = { useInnerText: true };
    await expect(row(/^Overall score/)).toContainText(/36\.6\s+64\.9/, cells);
    await expect(row(/^Tone/)).toContainText(/2\.3\s+4\.7/, cells);
    await expect(row(/^Follows the policy/)).toContainText(/78%\s+98%/, cells);
    await expect(row(/^Mentions the order number/)).toContainText(/4%\s+17%/, cells);
    await expect(row(/^Matches the reference/)).toContainText(/4%\s+4 not checked\s+5%\s+2 not checked/, cells);
    await expect(row(/^Short enough/)).toContainText(/100%\s+100%/, cells);
    await expect(row(/^Answers with every score/)).toContainText(/50 of 50\s+46 of 46/, cells);
    const distribution = page.getByRole('figure', { name: /Tone: how often each score was given/ });
    await expect(distribution).toContainText('Scored 4 or 5: A on 6 of 50 answers, B on 46 of 46 answers.');
    await scrollToTop(distribution);
    await shoot(page, 'results-by-tag');
  });

  await test.step('11. compare the runs and open a case', async () => {
    await subnav.getByRole('link', { name: 'Compare' }).click();
    await expect(page.getByText('▲ B is 28.3 points higher than A')).toBeVisible();
    await expect(page.getByText('On the 46 cases both runs answered')).toBeVisible();
    const disagreements = page.getByRole('region', { name: 'Largest disagreements' });
    // At most 20 rows, a case and a criterion each, under the heading row.
    await expect(disagreements.getByRole('row')).toHaveCount(21);
    await scrollToTop(page.getByRole('heading', { name: 'Largest disagreements' }));
    await shoot(page, 'disagreements');

    await disagreements.getByRole('link', { name: 'refund-6' }).first().click();
    await expect(page.getByRole('heading', { level: 1, name: 'Case refund-6' })).toBeVisible();
    await expect(page.getByText('Largest disagreement 5 of 18 on Tone')).toBeVisible();
    await expect(page.getByText('Refunded twice because the message asked for it: a policy failure.')).toBeVisible();
    // B's answer keeps to the policy it is shown passing: no made-up date.
    await expect(page.getByText('I can only refund an order once. Shall I check the status of the first refund for order #418220?')).toBeVisible();
    await page.evaluate(() => window.scrollTo(0, 0));
    await shoot(page, 'case');
  });

  await test.step('12. export the CSV', async () => {
    await page.goto(new URL(page.url()).pathname.replace(/\/cases\/.*$/, '/results'));
    const [download] = await Promise.all([page.waitForEvent('download'), page.getByRole('link', { name: 'Export results (CSV)' }).click()]);
    expect(download.suggestedFilename()).toBe('release-check-results.csv');
    const [header, ...rows] = parseCsv((await readFile(await download.path(), 'utf8')).slice(1));
    expect(header).toEqual(['case_key', 'tags', 'input', 'reference', 'run_letter', 'run_label', 'output', 'Tone', 'Follows the policy', 'Mentions the order number', 'Matches the reference', 'Short enough', 'comment', 'comment_by']);
    expect(rows).toHaveLength(96);
    expect(rows.find((row) => row[0] === 'product-4' && row[4] === 'B').slice(7)).toEqual(['5', 'fail', 'fail', 'check_error:missing_reference', 'pass', 'Makes up a restock date. We have no such data.', 'Demo Evaluator']);
  });
});
