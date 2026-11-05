import { Form, Link, redirect, useActionData, useNavigation, useSearchParams } from 'react-router';
import { api, safeNext } from '../api/client.js';
import { attempt, ErrorSummary, Field } from '../components/forms.jsx';
import { usePageTitle } from '../components/layout.jsx';

export async function signInAction({ request }) {
  const form = await request.formData();

  return attempt(async () => {
    await api('/api/auth/login', { method: 'POST', body: { email: form.get('email'), password: form.get('password') }, request, redirectOn401: false });

    return redirect(safeNext(new URL(request.url).searchParams.get('next')));
  });
}

export function SignIn() {
  const result = useActionData();
  const [searchParams] = useSearchParams();
  const submitting = useNavigation().state === 'submitting';
  usePageTitle('Sign in');

  return (
    <div className="auth">
      <Form method="post" noValidate className="auth-card">
        <div>
          <h1 className="auth-title">Sign in</h1>
          {searchParams.has('next') && !result && <p className="muted mt-1">Sign in to continue to that page.</p>}
        </div>
        <ErrorSummary problem={result?.problem} />
        <Field name="email" label="Email" type="email" autoComplete="email" required problem={result?.problem} />
        <Field name="password" label="Password" type="password" autoComplete="current-password" required problem={result?.problem} />
        <button type="submit" className="btn btn-primary btn-block" disabled={submitting}>
          Sign in
        </button>
      </Form>
      <p className="auth-alt">
        No account yet? <Link to="/register">Register</Link>
      </p>
    </div>
  );
}

/** Registering does not sign in (API), so the action signs in right after, with the same credentials. */
export async function registerAction({ request }) {
  const form = await request.formData();
  const credentials = { email: form.get('email'), password: form.get('password') };

  return attempt(async () => {
    await api('/api/auth/register', { method: 'POST', body: { ...credentials, displayName: form.get('displayName') }, request });
    await api('/api/auth/login', { method: 'POST', body: credentials, request, redirectOn401: false });

    return redirect('/projects');
  });
}

export function Register() {
  const result = useActionData();
  const submitting = useNavigation().state === 'submitting';
  usePageTitle('Register');

  return (
    <div className="auth">
      <Form method="post" noValidate className="auth-card">
        <h1 className="auth-title">Create an account</h1>
        <ErrorSummary problem={result?.problem} />
        <Field name="displayName" label="Name" autoComplete="name" required hint="Shown to the other members of your projects." problem={result?.problem} />
        <Field name="email" label="Email" type="email" autoComplete="email" required problem={result?.problem} />
        <Field
          name="password"
          label="Password"
          type="password"
          autoComplete="new-password"
          required
          hint="At least 12 characters. A few random words are easy to remember and hard to guess."
          problem={result?.problem}
        />
        <button type="submit" className="btn btn-primary btn-block" disabled={submitting}>
          Create account
        </button>
      </Form>
      <p className="auth-alt">
        Already have an account? <Link to="/sign-in">Sign in</Link>
      </p>
    </div>
  );
}

export async function signOutAction({ request }) {
  await api('/api/auth/logout', { method: 'POST', request, redirectOn401: false });

  return redirect('/sign-in');
}
