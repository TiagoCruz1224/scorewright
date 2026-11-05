// WCAG 2.2 AA contrast of the design tokens in src/index.css, in both themes: 4.5:1 for text, 3:1 for borders, the
// focus ring and the run colours as fills. It is the Graphite design's check (equipa/design/scorewright/tools),
// reading src/index.css, with the new tokens too (link, primary-hover, the *-bg tints).
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
  for (const fg of ['text', 'text-muted', 'link', 'run-a', 'run-b', 'success', 'danger', 'warning']) pairs.push([fg, bg, 4.5]);
  pairs.push(['border-strong', bg, 3], ['focus', bg, 3], ['primary', bg, 4.5]);
}
for (const tone of ['success', 'danger', 'warning']) pairs.push([tone, `${tone}-bg`, 4.5], ['text', `${tone}-bg`, 4.5], [`border-strong`, `${tone}-bg`, 3]);
if (css.includes('--tint:')) pairs.push(['primary', 'tint', 4.5], ['text', 'tint', 4.5], ['text-muted', 'tint', 4.5]);
pairs.push(['on-primary', 'primary', 4.5], ['on-primary', 'primary-hover', 4.5], ['run-a', 'run-b', 1]);

let failures = 0;
let lowest = { text: [99], other: [99] };
for (const [name, tokens] of Object.entries(themes)) {
  for (const [fg, bg, min] of pairs) {
    if (!tokens[fg] || !tokens[bg]) throw new Error(`Token missing in the ${name} theme: ${tokens[fg] ? bg : fg}`);
    const value = ratio(tokens[fg], tokens[bg]);
    const kind = min >= 4.5 ? 'text' : 'other';
    if (min > 1 && value < lowest[kind][0]) lowest[kind] = [value, `${name} ${fg} on ${bg}`];
    if (value < min) {
      failures++;
      console.log(`FAIL ${name} ${fg} on ${bg}: ${value.toFixed(2)} (min ${min})`);
    }
  }
  // Run A and B must also differ in lightness, not only in hue (they always carry a letter and a pattern too).
  const l = (t) => luminance(tokens[t]);
  console.log(`${name}: run-a vs run-b lightness ratio ${ratio(tokens['run-a'], tokens['run-b']).toFixed(2)} (L ${l('run-a').toFixed(3)} / ${l('run-b').toFixed(3)})`);
}
console.log(`lowest text pair: ${lowest.text[0].toFixed(2)} (${lowest.text[1]}); lowest non-text: ${lowest.other[0].toFixed(2)} (${lowest.other[1]})`);
console.log(failures ? `${failures} failing pairs` : `contrast: all ${pairs.length * 2} pairs pass`);
process.exitCode = failures ? 1 : 0;
