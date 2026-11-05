import { Icon } from './components/icons.jsx';

/** The project roles (requirements §4), as the API names them. */
export const ROLES = {
  owner: {
    label: 'Owner',
    article: 'an owner',
    description: 'Manages the project: its members, settings and data.',
    detail: 'Owners import datasets and runs, create rubrics and evaluations, and close evaluations.',
  },
  evaluator: {
    label: 'Evaluator',
    article: 'an evaluator',
    description: 'Scores and comments on the answers in evaluations.',
    detail: 'You can read the datasets, runs and rubrics. The owner imports them and manages the project.',
  },
  viewer: {
    label: 'Viewer',
    article: 'a viewer',
    description: 'Reads the project and its results.',
    detail: 'You can read the datasets, runs, rubrics and results. The owner imports them and manages the project.',
  },
};

export function RoleOptions() {
  return Object.entries(ROLES).map(([value, role]) => (
    <option key={value} value={value}>
      {role.label}
    </option>
  ));
}

const BADGES = { owner: ['role role-owner', 'key'], evaluator: ['role', 'pen'], viewer: ['role role-viewer', 'eye'] };

/** The role as a badge: the icon and the word, never the colour alone. */
export function RoleBadge({ role }) {
  const [className, icon] = BADGES[role];

  return (
    <span className={className}>
      <Icon name={icon} />
      {ROLES[role].label}
    </span>
  );
}
