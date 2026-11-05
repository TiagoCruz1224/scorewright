import { useEffect, useRef, useState } from 'react';
import { flushSync } from 'react-dom';
import { Form } from 'react-router';
import { ApiError } from '../api/client.js';

/**
 * Runs an action's API calls. An API error becomes `{ problem }` for the form to show (on top of `extra`, which says
 * which form it belongs to); anything else, such as the redirect of a 401, goes on up.
 */
export async function attempt(call, extra = {}) {
  try {
    return await call();
  } catch (error) {
    if (error instanceof ApiError) {
      return { ...extra, problem: error.problem };
    }
    throw error;
  }
}

/** "You can try again in 12 minutes." from a 429's Retry-After, in seconds. */
export function retryAfterText(seconds) {
  if (!seconds) {
    return 'Try again later.';
  }
  const unit = (count, word) => `${count} ${word}${count === 1 ? '' : 's'}`;
  if (seconds < 60) {
    return `You can try again in ${unit(seconds, 'second')}.`;
  }
  const minutes = Math.ceil(seconds / 60);

  return minutes < 60 ? `You can try again in ${unit(minutes, 'minute')}.` : `You can try again in about ${unit(Math.ceil(minutes / 60), 'hour')}.`;
}

/**
 * The problem of the last submission, on top of the form (UX §3.1, WCAG 3.3.1): one link per field error, which moves
 * to the field, or the problem's title. It takes the focus when it appears, so the error is read out after submitting.
 */
export function ErrorSummary({ problem, idPrefix = 'field' }) {
  const ref = useRef(null);
  useEffect(() => {
    if (problem) {
      ref.current?.focus();
    }
  }, [problem]);

  if (!problem) {
    return null;
  }

  const headingId = `${idPrefix}-error-summary`;

  return (
    <div ref={ref} tabIndex={-1} className="error-summary" aria-labelledby={headingId}>
      <h2 id={headingId}>There is a problem</h2>
      {problem.errors.length > 0 ? (
        <ul>
          {problem.errors.map((error, index) => (
            <li key={index}>
              <a
                href={`#${idPrefix}-${error.field}`}
                onClick={(event) => {
                  const field = document.getElementById(`${idPrefix}-${error.field}`);
                  if (field) {
                    event.preventDefault();
                    field.focus();
                  }
                }}
              >
                {error.message}
              </a>
            </li>
          ))}
        </ul>
      ) : (
        <p>{problem.title}</p>
      )}
      {problem.status === 429 && <p>{retryAfterText(problem.retryAfter)}</p>}
    </div>
  );
}

/**
 * A labelled control with its hint and its errors from the API's `errors[]` (the field path is the control's name).
 * `as` is "input" (the default), "textarea" or "select".
 */
export function Field({ name, label, problem, hint, idPrefix = 'field', as: Control = 'input', children, ...props }) {
  const id = `${idPrefix}-${name}`;
  const errors = (problem?.errors ?? []).filter((error) => error.field === name);
  const describedBy = [hint && `${id}-hint`, errors.length > 0 && `${id}-error`].filter(Boolean).join(' ');

  return (
    <div className="field">
      <label htmlFor={id}>{label}</label>
      {hint && (
        <p id={`${id}-hint`} className="hint">
          {hint}
        </p>
      )}
      {errors.length > 0 && (
        <p id={`${id}-error`} className="field-error">
          <span className="sr-only">Error: </span>
          {errors.map((error) => error.message).join(' ')}
        </p>
      )}
      <Control id={id} name={name} aria-invalid={errors.length > 0 || undefined} aria-describedby={describedBy || undefined} {...props}>
        {children}
      </Control>
    </div>
  );
}

/** A status message (WCAG 4.1.3): always rendered, so screen readers announce the text when it changes. */
export function StatusMessage({ message }) {
  return (
    <p role="status" className={message ? 'font-semibold text-success' : 'sr-only'}>
      {message ?? ''}
    </p>
  );
}

/**
 * A delete that asks first, on the page and not in a browser dialog (like removing a member): the button opens the
 * question with Delete (focused) and Cancel (back to the button). The form posts intent=delete to the page's action.
 */
export function ConfirmDelete({ heading, button, question, problem }) {
  const [confirming, setConfirming] = useState(false);

  return (
    <section className="max-w-xl">
      <h2>{heading}</h2>
      <ErrorSummary problem={problem} idPrefix="delete" />
      {confirming ? (
        <Form method="post" className="flex flex-wrap items-center gap-3">
          <input type="hidden" name="intent" value="delete" />
          <p className="mb-0">{question}</p>
          <button type="submit" className="btn btn-danger" autoFocus>
            Delete
          </button>
          <button
            type="button"
            className="btn"
            onClick={() => {
              flushSync(() => setConfirming(false));
              document.getElementById('delete-start')?.focus();
            }}
          >
            Cancel
          </button>
        </Form>
      ) : (
        <button id="delete-start" type="button" className="btn btn-danger" onClick={() => setConfirming(true)}>
          {button}
        </button>
      )}
    </section>
  );
}
