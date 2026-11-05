// Scorewright · Graphite: the interface icons (equipa/design/scorewright/spec.md §8).
// Line icons on a 24 px grid, drawn inline (no icon library): 16 px, stroke 1.75 (the .ic class sets size and stroke).
// Decorative by default (aria-hidden): the button or link around it carries the words.
const PATHS = {
  plus: (
    <>
      <path d="M12 5v14M5 12h14"/>
    </>
  ),
  upload: (
    <>
      <path d="M12 15V4M7 9l5-5 5 5M5 20h14"/>
    </>
  ),
  download: (
    <>
      <path d="M12 4v11M7 10l5 5 5-5M5 20h14"/>
    </>
  ),
  "chev-down": (
    <>
      <path d="m6 9 6 6 6-6"/>
    </>
  ),
  "chev-right": (
    <>
      <path d="m9 6 6 6-6 6"/>
    </>
  ),
  "chev-left": (
    <>
      <path d="m15 6-6 6 6 6"/>
    </>
  ),
  "arrow-up": (
    <>
      <path d="M12 19V5M6 11l6-6 6 6"/>
    </>
  ),
  "arrow-down": (
    <>
      <path d="M12 5v14M18 13l-6 6-6-6"/>
    </>
  ),
  "arrow-right": (
    <>
      <path d="M5 12h14M13 6l6 6-6 6"/>
    </>
  ),
  trash: (
    <>
      <path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/>
    </>
  ),
  search: (
    <>
      <circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>
    </>
  ),
  moon: (
    <>
      <path d="M20 14.5A8 8 0 0 1 9.5 4a8 8 0 1 0 10.5 10.5Z"/>
    </>
  ),
  alert: (
    <>
      <circle cx="12" cy="12" r="9"/><path d="M12 7.5v5.5M12 16.5v.01"/>
    </>
  ),
  info: (
    <>
      <circle cx="12" cy="12" r="9"/><path d="M12 11v5.5M12 7.5v.01"/>
    </>
  ),
  check: (
    <>
      <path d="m5 12.5 4.5 4.5L19 7"/>
    </>
  ),
  x: (
    <>
      <path d="M6 6l12 12M18 6 6 18"/>
    </>
  ),
  clock: (
    <>
      <circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>
    </>
  ),
  key: (
    <>
      <circle cx="8" cy="15" r="4"/><path d="m11 12 9-9M16 7l3 3"/>
    </>
  ),
  pen: (
    <>
      <path d="M4 20h4L19 9l-4-4L4 16v4ZM14 6l4 4"/>
    </>
  ),
  eye: (
    <>
      <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/>
    </>
  ),
  "eye-off": (
    <>
      <path d="M3 3l18 18M10.6 5.1A10.6 10.6 0 0 1 12 5c6.5 0 10 7 10 7a17.6 17.6 0 0 1-3.2 4.2M6.6 6.6C3.9 8.4 2 12 2 12s3.5 7 10 7c1.8 0 3.4-.5 4.7-1.3M9.9 9.9a3 3 0 0 0 4.2 4.2"/>
    </>
  ),
  folder: (
    <>
      <path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2Z"/>
    </>
  ),
  file: (
    <>
      <path d="M6 3h8l4 4v14H6zM14 3v4h4"/>
    </>
  ),
  archive: (
    <>
      <rect x="3" y="4" width="18" height="4" rx="1"/><path d="M5 8v11h14V8M10 12h4"/>
    </>
  ),
  lock: (
    <>
      <rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/>
    </>
  ),
  play: (
    <>
      <path d="M8 5v14l11-7Z"/>
    </>
  ),
  refresh: (
    <>
      <path d="M20 12a8 8 0 1 1-2.3-5.7M20 4v5h-5"/>
    </>
  ),
  keyboard: (
    <>
      <rect x="2.5" y="6" width="19" height="12" rx="2"/><path d="M6 10h.01M10 10h.01M14 10h.01M18 10h.01M7 14h10"/>
    </>
  ),
  copy: (
    <>
      <rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V5a1 1 0 0 0-1-1H5a1 1 0 0 0-1 1v10a1 1 0 0 0 1 1h3"/>
    </>
  ),
  mail: (
    <>
      <rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>
    </>
  ),
  layers: (
    <>
      <path d="m12 3 9 5-9 5-9-5 9-5ZM3 13l9 5 9-5"/>
    </>
  ),
  sun: (
    <>
      <circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>
    </>
  ),
};

/** <Icon name="plus" />, <Icon name="alert" size="lg" /> (20 px). */
export function Icon({ name, size }) {
  return (
    <svg className={size === 'lg' ? 'ic ic-lg' : 'ic'} viewBox="0 0 24 24" aria-hidden="true">
      {PATHS[name]}
    </svg>
  );
}

/** The wordmark: an ink square with three rising bars, then the name. */
export function BrandMark() {
  return (
    <span className="brand-mark">
      <svg viewBox="0 0 24 24" aria-hidden="true">
        <path d="M6 17v-4M12 17V8M18 17V5" />
      </svg>
    </span>
  );
}
