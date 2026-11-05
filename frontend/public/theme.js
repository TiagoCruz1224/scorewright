// The saved theme, else the system's, set before the first paint. A file, not an inline script: the API's
// Content-Security-Policy allows scripts from the same origin only.
(() => {
  let saved = null;
  try {
    saved = localStorage.getItem('sw-theme');
  } catch {
    // Storage blocked: follow the system.
  }
  const dark = saved ? saved === 'dark' : matchMedia('(prefers-color-scheme: dark)').matches;
  document.documentElement.dataset.theme = dark ? 'dark' : 'light';
})();
