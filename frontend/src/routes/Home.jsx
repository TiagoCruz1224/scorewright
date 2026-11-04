import { useLoaderData } from 'react-router';

export async function loader() {
  const response = await fetch('/api/health').catch(() => null);

  return { apiAvailable: response?.ok === true };
}

export default function Home() {
  const { apiAvailable } = useLoaderData();

  return (
    <main className="mx-auto max-w-2xl px-6 py-16">
      <h1 className="text-3xl font-semibold text-slate-900">{import.meta.env.VITE_APP_NAME}</h1>
      <p className="mt-2 text-slate-600">Evaluate the answers of AI systems against explicit criteria.</p>
      <p className="mt-8 text-sm text-slate-700" role="status">
        API: <strong>{apiAvailable ? 'available' : 'unavailable'}</strong>
      </p>
    </main>
  );
}
