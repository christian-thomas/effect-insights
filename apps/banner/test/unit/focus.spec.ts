import { beforeEach, describe, expect, it } from 'vitest';
import { captureFocusRestorer, createFocusTrap } from '../../src/focus';

const resetDom = (): void => {
  document.documentElement.innerHTML = '<head></head><body></body>';
};

describe('focus.createFocusTrap', () => {
  beforeEach(resetDom);

  it('wraps Shift+Tab from the first focusable to the last', () => {
    const container = document.createElement('div');
    container.innerHTML = `
      <button id="a">A</button>
      <button id="b">B</button>
      <button id="c">C</button>
    `;
    document.body.appendChild(container);
    const release = createFocusTrap(container);

    const first = container.querySelector<HTMLButtonElement>('#a');
    const last = container.querySelector<HTMLButtonElement>('#c');

    first?.focus();
    expect(document.activeElement).toBe(first);

    const event = new KeyboardEvent('keydown', {
      key: 'Tab',
      shiftKey: true,
      bubbles: true,
      cancelable: true,
    });
    first?.dispatchEvent(event);

    expect(document.activeElement).toBe(last);
    expect(event.defaultPrevented).toBe(true);

    release();
  });

  it('wraps Tab from the last focusable back to the first', () => {
    const container = document.createElement('div');
    container.innerHTML = `
      <button id="a">A</button>
      <button id="b">B</button>
    `;
    document.body.appendChild(container);
    const release = createFocusTrap(container);

    const first = container.querySelector<HTMLButtonElement>('#a');
    const last = container.querySelector<HTMLButtonElement>('#b');

    last?.focus();
    const event = new KeyboardEvent('keydown', {
      key: 'Tab',
      bubbles: true,
      cancelable: true,
    });
    last?.dispatchEvent(event);

    expect(document.activeElement).toBe(first);
    expect(event.defaultPrevented).toBe(true);

    release();
  });

  it('skips disabled buttons', () => {
    const container = document.createElement('div');
    container.innerHTML = `
      <button id="a">A</button>
      <button id="b" disabled>B</button>
      <button id="c">C</button>
    `;
    document.body.appendChild(container);
    const release = createFocusTrap(container);

    const first = container.querySelector<HTMLButtonElement>('#a');
    const last = container.querySelector<HTMLButtonElement>('#c');

    last?.focus();
    last?.dispatchEvent(
      new KeyboardEvent('keydown', { key: 'Tab', bubbles: true, cancelable: true }),
    );

    expect(document.activeElement).toBe(first);
    release();
  });

  it('does not interfere with non-Tab keys', () => {
    const container = document.createElement('div');
    container.innerHTML = `<button id="a">A</button>`;
    document.body.appendChild(container);
    const release = createFocusTrap(container);

    const button = container.querySelector<HTMLButtonElement>('#a');
    button?.focus();

    const event = new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true });
    button?.dispatchEvent(event);

    expect(event.defaultPrevented).toBe(false);
    release();
  });

  it('release() stops trapping', () => {
    const container = document.createElement('div');
    container.innerHTML = `<button id="a">A</button><button id="b">B</button>`;
    document.body.appendChild(container);
    const release = createFocusTrap(container);
    release();

    const first = container.querySelector<HTMLButtonElement>('#a');
    first?.focus();

    const event = new KeyboardEvent('keydown', {
      key: 'Tab',
      shiftKey: true,
      bubbles: true,
      cancelable: true,
    });
    first?.dispatchEvent(event);

    expect(event.defaultPrevented).toBe(false);
  });
});

describe('focus.captureFocusRestorer', () => {
  beforeEach(resetDom);

  it('returns focus to whichever element had it at capture time', () => {
    const button = document.createElement('button');
    button.id = 'host';
    document.body.appendChild(button);
    button.focus();

    const restore = captureFocusRestorer();

    const other = document.createElement('button');
    document.body.appendChild(other);
    other.focus();
    expect(document.activeElement).toBe(other);

    restore();
    expect(document.activeElement).toBe(button);
  });

  it('is a no-op when nothing was focused at capture time', () => {
    // Reset to body which has no tabindex.
    document.body.focus();
    const restore = captureFocusRestorer();

    const button = document.createElement('button');
    document.body.appendChild(button);
    button.focus();

    expect(() => restore()).not.toThrow();
  });
});
