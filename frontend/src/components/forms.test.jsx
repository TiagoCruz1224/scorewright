import { fireEvent, render, screen, within } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { ErrorSummary, Field, retryAfterText } from './forms.jsx';

describe('ErrorSummary and Field', () => {
  const problem = {
    status: 422,
    code: 'validation_failed',
    title: 'The request is not valid',
    errors: [
      { field: 'email', code: 'invalid_format', message: 'This value is not a valid email address.' },
      { field: 'password', code: 'too_short', message: 'This value is too short. It should have 12 characters or more.' },
    ],
  };

  it('lists every field error on top, takes the focus, and each link moves to its field', () => {
    render(
      <>
        <ErrorSummary problem={problem} />
        <Field name="email" label="Email" problem={problem} />
        <Field name="password" label="Password" hint="At least 12 characters." problem={problem} />
      </>,
    );

    // The alert box takes the focus; the heading and the links are in its body.
    const summary = screen.getByRole('heading', { name: 'There is a problem' }).closest('.alert');
    expect(document.activeElement).toBe(summary);
    const links = within(summary).getAllByRole('link');
    expect(links.map((link) => link.textContent)).toEqual(problem.errors.map((error) => error.message));

    fireEvent.click(links[1]);
    expect(document.activeElement).toBe(screen.getByLabelText('Password'));
  });

  it('shows each error next to its field, linked with aria-describedby: the error first, then the hint', () => {
    render(<Field name="password" label="Password" hint="At least 12 characters." problem={problem} />);

    const field = screen.getByLabelText('Password');
    expect(field.getAttribute('aria-invalid')).toBe('true');
    const descriptions = field
      .getAttribute('aria-describedby')
      .split(' ')
      .map((id) => document.getElementById(id).textContent);
    expect(descriptions).toEqual(['Error: This value is too short. It should have 12 characters or more.', 'At least 12 characters.']);
    // In the page too: label, control, error, hint (Graphite, proposal 3).
    const order = [...field.closest('.field').children].map((child) => child.tagName + (child.className ? `.${child.className}` : ''));
    expect(order).toEqual(['LABEL.label', 'INPUT', 'P.field-error', 'P.hint']);
  });

  it('leaves a field without errors alone', () => {
    render(<Field name="name" label="Name" problem={problem} />);

    const field = screen.getByLabelText('Name');
    expect(field.hasAttribute('aria-invalid')).toBe(false);
    expect(field.hasAttribute('aria-describedby')).toBe(false);
  });

  it('shows the title of a problem without field errors, and when a 429 can be retried', () => {
    render(<ErrorSummary problem={{ status: 429, code: 'too_many_invites', title: 'Too many invites: try again later', errors: [], retryAfter: 125 }} />);

    screen.getByText('Too many invites: try again later');
    screen.getByText('You can try again in 3 minutes.');
  });

  it('renders nothing without a problem', () => {
    const { container } = render(<ErrorSummary problem={undefined} />);
    expect(container.innerHTML).toBe('');
  });
});

describe('retryAfterText', () => {
  it('says the wait in words', () => {
    expect(retryAfterText(1)).toBe('You can try again in 1 second.');
    expect(retryAfterText(59)).toBe('You can try again in 59 seconds.');
    expect(retryAfterText(60)).toBe('You can try again in 1 minute.');
    expect(retryAfterText(3_601)).toBe('You can try again in about 2 hours.');
    expect(retryAfterText(undefined)).toBe('Try again later.');
  });
});
