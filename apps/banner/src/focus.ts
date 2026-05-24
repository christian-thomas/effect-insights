/**
 * Minimal focus trap for modal dialogs.
 *
 * Keeps Tab / Shift+Tab cycling inside `container`. The browser will
 * happily let a user Tab their way out of a `role="dialog"` element
 * straight into the host page underneath — that's an immediate WCAG
 * 2.4.3 Focus Order failure for modal dialogs.
 *
 * Listens for Tab on the container itself (since focus is trapped
 * inside, keydown always bubbles up here) and wraps the focus around
 * when it hits either edge of the focusable list.
 *
 * Returns a teardown function that removes the listener.
 */

const FOCUSABLE_SELECTOR = [
  'button:not([disabled])',
  'a[href]',
  'input:not([disabled])',
  'select:not([disabled])',
  'textarea:not([disabled])',
  '[tabindex]:not([tabindex="-1"])',
].join(', ');

const focusableElementsIn = (container: HTMLElement): HTMLElement[] =>
  Array.from(container.querySelectorAll<HTMLElement>(FOCUSABLE_SELECTOR)).filter(
    (element: HTMLElement): boolean => {
      // Ignore visually-hidden / display:none nodes — they're not actually
      // reachable so trapping focus on them would dead-end Tab.
      if (element.hasAttribute('hidden')) return false;
      if (element.getAttribute('aria-hidden') === 'true') return false;
      return true;
    },
  );

export const createFocusTrap = (container: HTMLElement): (() => void) => {
  const onKeyDown = (event: KeyboardEvent): void => {
    if (event.key !== 'Tab') return;

    const focusables = focusableElementsIn(container);
    if (focusables.length === 0) {
      event.preventDefault();
      return;
    }

    const first = focusables[0];
    const last = focusables[focusables.length - 1];
    const active = document.activeElement;

    if (event.shiftKey && active === first) {
      event.preventDefault();
      last?.focus();
    } else if (!event.shiftKey && active === last) {
      event.preventDefault();
      first?.focus();
    }
  };

  container.addEventListener('keydown', onKeyDown);
  return (): void => container.removeEventListener('keydown', onKeyDown);
};

/**
 * Records the currently-focused element (if any) and returns a function
 * that restores focus to it. Used to return focus to the page when the
 * banner is dismissed — WCAG 2.4.3.
 */
export const captureFocusRestorer = (): (() => void) => {
  const previous = document.activeElement;
  return (): void => {
    if (previous instanceof HTMLElement && typeof previous.focus === 'function') {
      previous.focus();
    }
  };
};
