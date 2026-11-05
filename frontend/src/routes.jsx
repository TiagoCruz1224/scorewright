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
import { ImportFormat } from './routes/importFormat.jsx';
import { Members, membersAction, membersLoader } from './routes/members.jsx';
import { NewProject, newProjectAction, Overview, overviewLoader, ProjectLayout, projectLoader, Projects, projectsLoader } from './routes/projects.jsx';
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
          { index: true, loader: () => redirect('/projects') },
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
              { path: 'members', loader: membersLoader, action: membersAction, Component: Members },
              { path: 'settings', action: settingsAction, Component: Settings },
            ],
          },
        ],
      },
    ],
  },
];
