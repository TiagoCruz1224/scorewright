import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { createBrowserRouter } from 'react-router';
import { RouterProvider } from 'react-router/dom';
import './index.css';
import Home, { HydrateFallback as HomeFallback, loader as homeLoader } from './routes/Home.jsx';

const router = createBrowserRouter([{ path: '/', Component: Home, loader: homeLoader, HydrateFallback: HomeFallback }]);

createRoot(document.getElementById('root')).render(
  <StrictMode>
    <RouterProvider router={router} />
  </StrictMode>,
);
