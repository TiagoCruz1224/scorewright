/** The project roles (requirements §4), as the API names them. */
export const ROLES = {
  owner: { label: 'Owner', article: 'an owner', description: 'Manages the project: its members, settings and data.' },
  evaluator: { label: 'Evaluator', article: 'an evaluator', description: 'Scores and comments on the answers in evaluations.' },
  viewer: { label: 'Viewer', article: 'a viewer', description: 'Reads the project and its results.' },
};

export function RoleOptions() {
  return Object.entries(ROLES).map(([value, role]) => (
    <option key={value} value={value}>
      {role.label}
    </option>
  ));
}
