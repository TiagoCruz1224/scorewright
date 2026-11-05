// WCAG 2.2 AA contrast of the design tokens in src/index.css, in both themes: 4.5:1 for text, 3:1 for borders and
// the focus ring. Adapted from the designer's check (equipa/design/evalforge/wireframes/check-contrast.mjs).
import { readFileSync } from 'node:fs';

const css = readFileSync(new URL('../src/index.css', import.meta.url), 'utf8');
const block = (start, end) =>
  Object.fromEntries([...css.slice(css.indexOf(start), css.indexOf(end)).matchAll(/--([\w-]+):\s*(#[0-9a-f]{6})/gi)].map((m) => [m[1], m[2]]));
const themes = { light: block('/* light */', '/* dark */'), dark: block('/* dark */', '/* end of tokens */') };

const luminance = (hex) => {
  const [r, g, b] = [1, 3, 5]
    .map((i) => parseInt(hex.slice(i, i + 2), 16) / 255)
    .map((v) => (v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4));
  return 0.2126 * r + 0.7152 * g + 0.0722 * b;
};
const ratio = (a, b) => {
  const [high, low] = [luminance(a), luminance(b)].sort((x, y) => y - x);
  return (high + 0.05) / (low + 0.05);
};

const pairs = [];
for (const bg of ['bg', 'surface', 'surface-2']) {
  for (const fg of ['text', 'text-muted', 'primary', 'run-a', 'run-b', 'success', 'danger', 'warning']) pairs.push([fg, bg, 4.5]);
}
pairs.push(['on-primary', 'primary', 4.5], ['border-strong', 'bg', 3], ['border-strong', 'surface', 3], ['focus', 'bg', 3], ['focus', 'surface', 3]);

let failures = 0;
for (const [name, tokens] of Object.entries(themes)) {
  for (const [fg, bg, min] of pairs) {
    if (!tokens[fg] || !tokens[bg]) throw new Error(`Token missing in the ${name} theme: ${tokens[fg] ? bg : fg}`);
    const value = ratio(tokens[fg], tokens[bg]);
    if (value < min) {
      failures++;
      console.log(`FAIL ${name} ${fg} on ${bg}: ${value.toFixed(2)} (min ${min})`);
    }
  }
}
console.log(failures ? `${failures} failing pairs` : `contrast: all ${pairs.length * 2} pairs pass`);
process.exitCode = failures ? 1 : 0;
