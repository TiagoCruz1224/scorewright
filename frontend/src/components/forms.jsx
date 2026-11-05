import { useEffect, useRef, useState } from 'react';
import { flushSync } from 'react-dom';
import { Form } from 'react-router';
import { ApiError } from '../api/client.js';
import { Icon } from './icons.jsx';

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
    <div ref={ref} tabIndex={-1} className="alert mb-6" aria-labelledby={headingId}>
      <Icon name="alert" size="lg" />
      <div className="alert-body">
        <h2 id={headingId} className="alert-title">
          There is a problem
        </h2>
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
    </div>
  );
}

/**
 * A labelled control with its errors from the API's `errors[]` (the field path is the control's name) and its hint, in
 * that order: label, control, error, hint (Graphite, proposal 3). `as` is "input" (the default), "textarea" or
 * "select"; `className` goes on the field, `controlClassName` on the control (such as "input mono"), and `icon` puts an
 * icon inside a text input (such as "search").
 */
export function Field({ name, label, problem, hint, idPrefix = 'field', as: Control = 'input', className, controlClassName, icon, children, ...props }) {
  const id = `${idPrefix}-${name}`;
  const errors = (problem?.errors ?? []).filter((error) => error.field === name);
  const describedBy = [errors.length > 0 && `${id}-error`, hint && `${id}-hint`].filter(Boolean).join(' ');

  return (
    <div className={className ? `field ${className}` : 'field'}>
      <label className="label" htmlFor={id}>
        {label}
      </label>
      {icon ? (
        <div className="input-icon">
          <Icon name={icon} />
          <Control id={id} name={name} className={controlClassName ?? 'input'} aria-invalid={errors.length > 0 || undefined} aria-describedby={describedBy || undefined} {...props} />
        </div>
      ) : (
        <Control id={id} name={name} className={controlClassName} aria-invalid={errors.length > 0 || undefined} aria-describedby={describedBy || undefined} {...props}>
          {children}
        </Control>
      )}
      {errors.length > 0 && (
        <p id={`${id}-error`} className="field-error">
          <Icon name="alert" />
          <span>
            <span className="sr-only">Error: </span>
            {errors.map((error) => error.message).join(' ')}
          </span>
        </p>
      )}
      {hint && (
        <p id={`${id}-hint`} className="hint">
          {hint}
        </p>
      )}
    </div>
  );
}

/** A status message (WCAG 4.1.3): always rendered, so screen readers announce the text when it changes. */
export function StatusMessage({ message }) {
  return (
    <p role="status" className={message ? 'status-ok mb-4' : 'sr-only'}>
      {message && <Icon name="check" />}
      {message ?? ''}
    </p>
  );
}

/**
 * A delete that asks first, on the page and not in a browser dialog (like removing a member): the button opens the
 * question with Delete (focused) and Cancel (back to the button). The form posts intent=delete to the page's action.
 * `note` says when it is possible, such as "Possible only while no evaluation uses it.".
 */
export function ConfirmDelete({ heading, button, question, note, problem }) {
  const [confirming, setConfirming] = useState(false);

  return (
    <section className="card section">
      <div className="card-body stack" style={{ gap: 12 }}>
        <ErrorSummary problem={problem} idPrefix="delete" />
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div>
            <h2 className="card-title">{heading}</h2>
            {note && <p className="small muted">{note}</p>}
          </div>
          {!confirming && (
            <button id="delete-start" type="button" className="btn btn-danger" onClick={() => setConfirming(true)}>
              <Icon name="trash" />
              {button}
            </button>
          )}
        </div>
        {confirming && (
          <Form method="post" className="confirm">
            <input type="hidden" name="intent" value="delete" />
            <p>{question}</p>
            <button type="submit" className="btn btn-danger btn-sm" autoFocus>
              Delete
            </button>
            <button
              type="button"
              className="btn btn-sm"
              onClick={() => {
                flushSync(() => setConfirming(false));
                document.getElementById('delete-start')?.focus();
              }}
            >
              Cancel
            </button>
          </Form>
        )}
      </div>
    </section>
  );
}
