import { redirect } from 'react-router';
import { ErrorPage, Loading, RootLayout, rootLoader } from './components/layout.jsx';
import { Register, registerAction, SignIn, signInAction, signOutAction } from './routes/auth.jsx';
import {
  Dataset,
  datasetAction,
  datasetLoader,
  Datasets,
  datasetsLoader,
  ImportVersion,
  importVersionAction,
  importVersionLoader,
  NewDataset,
  newDatasetAction,
} from './routes/datasets.jsx';
import {
  Evaluation,
  evaluationAction,
  evaluationLoader,
  Evaluations,
  evaluationsLoader,
  NewEvaluation,
  newEvaluationAction,
  newEvaluationLoader,
  Results,
  resultsLoader,
} from './routes/evaluations.jsx';
import { ImportFormat } from './routes/importFormat.jsx';
import { Members, membersAction, membersLoader } from './routes/members.jsx';
import { EditRubric, editRubricAction, editRubricLoader, NewRubric, newRubricAction, Rubric, rubricAction, rubricLoader, Rubrics, rubricsLoader } from './routes/rubrics.jsx';
import { ImportRun, importRunAction, importRunLoader, Run, runAction, runLoader, Runs, runsLoader } from './routes/runs.jsx';
import { NewProject, newProjectAction, Overview, overviewLoader, ProjectLayout, projectLoader, Projects, projectsLoader } from './routes/projects.jsx';
import { Scoring, scoringLoader, scoringStartLoader } from './routes/scoring.jsx';
import { Settings, settingsAction } from './routes/settings.jsx';

/**
 * The page map (UX §2). Pages render inside the layout, and so does their error page; data comes from loaders and
 * actions, which call the API with its shared client (src/api/client.js).
 */
export const routes = [
  {
    id: 'root',
    path: '/',
    loader: rootLoader,
    Component: RootLayout,
    ErrorBoundary: ErrorPage,
    HydrateFallback: Loading,
    children: [
      {
        ErrorBoundary: ErrorPage,
        children: [
          // The loader always redirects; the empty Component keeps React Router from warning about a route without one.
          { index: true, loader: () => redirect('/projects'), Component: () => null },
          { path: 'sign-in', action: signInAction, Component: SignIn },
          { path: 'register', action: registerAction, Component: Register },
          { path: 'sign-out', action: signOutAction },
          { path: 'projects', loader: projectsLoader, Component: Projects },
          { path: 'projects/new', action: newProjectAction, Component: NewProject },
          { path: 'import-format', Component: ImportFormat },
          {
            id: 'project',
            path: 'projects/:projectId',
            loader: projectLoader,
            Component: ProjectLayout,
            children: [
              { index: true, loader: overviewLoader, Component: Overview },
              { path: 'datasets', loader: datasetsLoader, Component: Datasets },
              { path: 'datasets/new', action: newDatasetAction, Component: NewDataset },
              { path: 'datasets/:datasetId', loader: datasetLoader, action: datasetAction, Component: Dataset },
              { path: 'datasets/:datasetId/import', loader: importVersionLoader, action: importVersionAction, Component: ImportVersion },
              { path: 'runs', loader: runsLoader, Component: Runs },
              { path: 'runs/new', loader: importRunLoader, action: importRunAction, Component: ImportRun },
              { path: 'runs/:runId', loader: runLoader, action: runAction, Component: Run },
              { path: 'rubrics', loader: rubricsLoader, Component: Rubrics },
              { path: 'rubrics/new', action: newRubricAction, Component: NewRubric },
              { path: 'rubrics/:rubricId', loader: rubricLoader, action: rubricAction, Component: Rubric },
              { path: 'rubrics/:rubricId/edit', loader: editRubricLoader, action: editRubricAction, Component: EditRubric },
              { path: 'evaluations', loader: evaluationsLoader, Component: Evaluations },
              { path: 'evaluations/new', loader: newEvaluationLoader, action: newEvaluationAction, Component: NewEvaluation },
              { path: 'evaluations/:evaluationId', loader: evaluationLoader, action: evaluationAction, Component: Evaluation },
              { path: 'evaluations/:evaluationId/results', loader: resultsLoader, Component: Results },
              // Scoring stays on the page as it saves (no revalidation): only navigating loads another answer.
              { path: 'evaluations/:evaluationId/score', loader: scoringStartLoader, Component: Scoring },
              { path: 'evaluations/:evaluationId/score/:itemId', loader: scoringLoader, Component: Scoring },
              { path: 'members', loader: membersLoader, action: membersAction, Component: Members },
              { path: 'settings', action: settingsAction, Component: Settings },
            ],
          },
        ],
      },
    ],
  },
];
