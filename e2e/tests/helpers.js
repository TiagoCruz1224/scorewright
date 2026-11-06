import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';
import { expect } from '@playwright/test';

const require = createRequire(import.meta.url);
const axeScript = require.resolve('axe-core/axe.min.js');

/** A file in e2e/fixtures. */
export const fixture = (name) => fileURLToPath(new URL(`../fixtures/${name}`, import.meta.url));

/** A user nobody has used before, and a password made for this run only. */
export const newUser = (role) => {
  const id = `${Date.now().toString(36)}${Math.random().toString(36).slice(2, 8)}`;

  return { name: `${role} ${id}`, email: `${role}.${id}@example.com`, password: `e2e-${id}-${Math.random().toString(36).slice(2)}` };
};

/** axe-core (the frontend's version) on the page as it is now: no violation, or the list of what to fix. */
export async function expectNoAxeViolations(page, name) {
  await page.addScriptTag({ path: axeScript });
  const violations = await page.evaluate(async () => {
    const { violations } = await window.axe.run(document, { resultTypes: ['violations'] });

    return violations.map((violation) => `${violation.id}: ${violation.nodes.map((node) => node.target.join(' ')).join(', ')}`);
  });
  expect(violations, `axe-core on ${name}`).toEqual([]);
}

/** RFC 4180: rows of cells, with quoted cells that may hold commas, doubled quotes and line breaks. */
export function parseCsv(text) {
  const rows = [];
  let row = [];
  let cell = '';
  let quoted = false;
  for (let i = 0; i < text.length; i++) {
    const char = text[i];
    if (quoted) {
      if (char === '"' && text[i + 1] === '"') {
        cell += '"';
        i++;
      } else if (char === '"') {
        quoted = false;
      } else {
        cell += char;
      }
    } else if (char === '"') {
      quoted = true;
    } else if (char === ',') {
      row.push(cell);
      cell = '';
    } else if (char === '\r' && text[i + 1] === '\n') {
      row.push(cell);
      rows.push(row);
      row = [];
      cell = '';
      i++;
    } else {
      cell += char;
    }
  }

  return rows;
}

/**
 * The API as the SPA calls it (JSON, the csrf-token header), for the set-up of a scenario: `request` keeps the
 * session cookie of the user it signed in.
 */
export const api = (request) => {
  const headers = { 'csrf-token': 'e2e-csrf-token-0123456789abcdef' };
  const check = async (response) => {
    expect(response.ok(), `${response.url()}: ${await response.text()}`).toBe(true);

    return response.status() === 204 ? null : response.json();
  };

  return {
    post: async (path, data) => check(await request.post(path, { headers, data })),
    upload: async (path, multipart) => check(await request.post(path, { headers, multipart })),
  };
};
