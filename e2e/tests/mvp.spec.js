import { readFile } from 'node:fs/promises';
import { expect, test } from '@playwright/test';
import { api, expectNoAxeViolations, fixture, newUser, parseCsv } from './helpers.js';

/**
 * MVP criterion 1 (requirements §7), only through the UI: a new user registers, creates a project, imports a dataset
 * and two runs, builds a rubric with a manual and an automatic criterion, starts a blind evaluation, waits for the
 * checks, scores every answer with the keyboard, closes it, reads the results and the comparison, opens a case and
 * exports the CSV. axe-core checks five of the pages on the way.
 */
test('a new user goes from registering to the comparison and the CSV, through the UI', async ({ page }) => {
  const owner = newUser('owner');

  await test.step('register', async () => {
    await page.goto('/register');
    await page.getByLabel('Name').fill(owner.name);
    await page.getByLabel('Email').fill(owner.email);
    await page.getByLabel('Password', { exact: true }).fill(owner.password);
    await page.getByRole('button', { name: 'Create account' }).click();
    await expect(page.getByRole('heading', { level: 1, name: 'Projects' })).toBeVisible();
  });

  const projectPath = await test.step('create the project', async () => {
    await page.getByRole('link', { name: 'Create project' }).first().click();
    await page.getByLabel('Name').fill('Support assistant (e2e)');
    await page.getByRole('button', { name: 'Create project' }).click();
    await expect(page.getByRole('heading', { level: 1, name: 'Support assistant (e2e)' })).toBeVisible();
    const path = new URL(page.url()).pathname;

    await page.goto('/projects');
    await expect(page.getByRole('link', { name: 'Support assistant (e2e)' })).toBeVisible();
    await expectNoAxeViolations(page, 'Projects');

    return path;
  });

  await test.step('import the dataset', async () => {
    await page.goto(`${projectPath}/datasets/new`);
    await page.getByLabel('Name').fill('Support cases');
    await page.locator('#field-file').setInputFiles(fixture('cases.csv'));
    await page.getByRole('button', { name: 'Check and import' }).click();
    await expect(page.getByText('Version v1 created: 4 cases.')).toBeVisible();
    await expectNoAxeViolations(page, 'Dataset');
  });

  for (const [label, file] of [['baseline-v1', 'run-baseline.csv'], ['tuned-v2', 'run-tuned.csv']]) {
    await test.step(`import the run ${label}`, async () => {
      await page.goto(`${projectPath}/runs/new`);
      await page.getByLabel('Run label').fill(label);
      await page.locator('#field-file').setInputFiles(fixture(file));
      await page.getByRole('button', { name: 'Check and import' }).click();
      await expect(page.getByText('4 of 4 cases answered')).toBeVisible();
    });
  }

  await test.step('build a rubric with a manual and an automatic criterion', async () => {
    await page.goto(`${projectPath}/rubrics/new`);
    await page.locator('#field-name').fill('Support quality');
    await page.getByRole('button', { name: 'Add criterion' }).click();
    await page.locator('input[name="criteria.c1.kind"][value="scale_1_5"]').check();
    await page.locator('#field-criteria\\.c1\\.name').fill('Tone');
    await page.locator('#field-criteria\\.c1\\.weight').fill('2');
    await page.locator('#field-criteria\\.c1\\.guidance').fill('1: rude or cold. 5: warm and clear, without filler.');
    await page.getByRole('button', { name: 'Add criterion' }).click();
    await page.locator('input[name="criteria.c2.kind"][value="automatic"]').check();
    await page.locator('#field-criteria\\.c2\\.name').fill('Mentions a refund');
    await page.locator('#field-criteria\\.c2\\.checkType').selectOption('contains');
    await page.locator('#field-criteria\\.c2\\.checkParams\\.text').fill('refund');
    await expectNoAxeViolations(page, 'Rubric editor');
    await page.getByRole('button', { name: 'Save rubric' }).click();
    await expect(page.getByText('1 manual')).toBeVisible();
  });

  const evaluationPath = await test.step('start a blind evaluation and wait for the checks', async () => {
    await page.goto(`${projectPath}/evaluations/new`);
    await page.getByLabel('Name').fill('Baseline vs tuned');
    for (const checkbox of await page.locator('input[name="runs"]').all()) {
      await checkbox.check();
    }
    await expect(page.locator('input[name="blind"]')).toBeChecked();
    await page.getByRole('button', { name: 'Start evaluation' }).click();
    await expect(page.getByText('The evaluation started.')).toBeVisible();
    // The page polls every 5 s while the worker runs the checks.
    await expect(page.getByText('8 of 8 · done')).toBeVisible({ timeout: 60_000 });

    return new URL(page.url()).pathname;
  });

  await test.step('score every answer with the keyboard', async () => {
    await page.getByRole('link', { name: 'Start scoring' }).first().click();
    const scorebar = page.locator('.scorebar');
    for (let scored = 1; scored <= 8; scored++) {
      // The focus goes to the case on every answer; the run is hidden.
      await expect(page.locator('.case-title')).toBeFocused();
      if (scored === 1) {
        await expectNoAxeViolations(page, 'Scoring');
      }
      const answer = await page.locator('.textblock.is-answer').innerText();
      await page.keyboard.press(answer.length > 60 ? '5' : '2');
      await expect(scorebar).toContainText(`${scored} of 8 scored`);
      // J waits for the save, then opens the next answer with missing scores (the last one, the end page).
      const url = page.url();
      await page.keyboard.press('j');
      await page.waitForURL((next) => next.href !== url);
    }
    await expect(page.getByText('All 8 answers scored')).toBeVisible();
  });

  await test.step('close the evaluation', async () => {
    await page.goto(evaluationPath);
    await page.getByRole('button', { name: 'Close evaluation' }).click();
    await page.locator('form.confirm').getByRole('button', { name: 'Close evaluation' }).click();
    await expect(page.getByText(/^Closed/).first()).toBeVisible();
  });

  await test.step('read the results', async () => {
    await page.getByRole('navigation', { name: 'Evaluation' }).getByRole('link', { name: 'Results' }).click();
    await expect(page.getByText('The evaluation is closed: these results no longer change.')).toBeVisible();
    const byCriterion = page.getByRole('region', { name: 'By criterion', exact: true });
    await expect(byCriterion.getByRole('row', { name: /^Tone/ })).toContainText('2.0');
    await expect(byCriterion.getByRole('row', { name: /^Tone/ })).toContainText('5.0');
  });

  await test.step('compare the runs and open a case', async () => {
    await page.getByRole('navigation', { name: 'Evaluation' }).getByRole('link', { name: 'Compare' }).click();
    await expect(page.getByText('▲ B is 3.0 points higher than A')).toBeVisible();
    await expect(page.getByText('▲ B is 50 percentage points higher than A')).toBeVisible();
    await expectNoAxeViolations(page, 'Compare');

    await page.getByRole('region', { name: 'Largest disagreements' }).getByRole('link').first().click();
    await expect(page.getByRole('heading', { level: 1, name: /^Case / })).toBeVisible();
    await expect(page.getByText(/^Largest disagreement 1 of \d+ on /)).toBeVisible();
    await expect(page.getByRole('article')).toHaveCount(2);
  });

  await test.step('export the CSV', async () => {
    await page.goto(`${evaluationPath}/results`);
    const [download] = await Promise.all([page.waitForEvent('download'), page.getByRole('link', { name: 'Export results (CSV)' }).click()]);
    expect(download.suggestedFilename()).toBe('baseline-vs-tuned-results.csv');
    const csv = await readFile(await download.path(), 'utf8');

    expect(csv.startsWith('﻿')).toBe(true);
    const [header, ...rows] = parseCsv(csv.slice(1));
    expect(header).toEqual(['case_key', 'tags', 'input', 'reference', 'run_letter', 'run_label', 'output', 'Tone', 'Mentions a refund', 'comment', 'comment_by']);
    expect(rows.map((row) => `${row[0]} ${row[4]}`)).toEqual(['refund-1 A', 'refund-1 B', 'refund-2 A', 'refund-2 B', 'delivery-1 A', 'delivery-1 B', 'formula-1 A', 'formula-1 B']);
    // Every answer scored: 2 for the short ones (A), 5 for B; the check as pass or fail.
    expect(rows.map((row) => row[7])).toEqual(['2', '5', '2', '5', '2', '5', '2', '5']);
    expect(rows.map((row) => row[8])).toEqual(['fail', 'pass', 'pass', 'pass', 'fail', 'fail', 'fail', 'pass']);
    // The input that a spreadsheet would run as a formula is text.
    expect(rows[6][2]).toBe("'=SUM(1,2) is what I paid, right?");
  });
});

/** An evaluator of a blind evaluation that is still open sees the locked results, with no numbers and no export. */
test('an evaluator of a blind, open evaluation sees the results locked', async ({ page, playwright, baseURL }) => {
  const [owner, evaluator] = [newUser('owner'), newUser('evaluator')];
  const ownerRequest = await playwright.request.newContext({ baseURL });
  const evaluatorRequest = await playwright.request.newContext({ baseURL });
  const asOwner = api(ownerRequest);

  // Set up through the API: the UI of all this is the scenario above.
  for (const [user, request] of [[owner, ownerRequest], [evaluator, evaluatorRequest]]) {
    await api(request).post('/api/auth/register', { email: user.email, password: user.password, displayName: user.name });
  }
  await asOwner.post('/api/auth/login', { email: owner.email, password: owner.password });
  const project = await asOwner.post('/api/projects', { name: 'Blind check (e2e)' });
  const base = `/api/projects/${project.id}`;
  const file = async (name) => ({ name, mimeType: 'text/csv', buffer: await readFile(fixture(name)) });
  const dataset = await asOwner.upload(`${base}/datasets`, { name: 'Cases', file: await file('cases.csv') });
  const versionId = dataset.versions[0].id;
  const runs = [];
  for (const [label, name] of [['baseline-v1', 'run-baseline.csv'], ['tuned-v2', 'run-tuned.csv']]) {
    runs.push((await asOwner.upload(`${base}/runs`, { datasetVersion: versionId, label, file: await file(name) })).id);
  }
  const rubric = await asOwner.post(`${base}/rubrics`, { name: 'Quality', criteria: [{ kind: 'scale_1_5', name: 'Tone', guidance: '1: rude. 5: warm.' }] });
  const evaluation = await asOwner.post(`${base}/evaluations`, { name: 'Blind and open', datasetVersion: versionId, rubric: rubric.id, runs, blind: true });
  await asOwner.post(`${base}/members`, { email: evaluator.email, role: 'evaluator' });
  await Promise.all([ownerRequest.dispose(), evaluatorRequest.dispose()]);

  await page.goto(`/sign-in?next=${encodeURIComponent(`/projects/${project.id}/evaluations/${evaluation.id}/results`)}`);
  await page.getByLabel('Email').fill(evaluator.email);
  await page.getByLabel('Password', { exact: true }).fill(evaluator.password);
  await page.getByRole('button', { name: 'Sign in' }).click();

  await expect(page.getByRole('heading', { name: 'Results unlock when the owner closes this evaluation' })).toBeVisible();
  await expect(page.getByRole('table')).toHaveCount(0);
  await expect(page.getByRole('link', { name: 'Export results (CSV)' })).toHaveCount(0);
  await expect(page.getByRole('link', { name: 'Continue scoring' }).or(page.getByRole('link', { name: 'Start scoring' }))).toBeVisible();
});
