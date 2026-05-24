import { beforeEach, describe, expect, it, vi } from 'vitest';
import { renderBanner } from '../../src/dom';
import type { BannerCopy, ConsentCategories } from '../../src/types';

const copy: BannerCopy = {
  title: 'We use cookies',
  body: 'Cookie body text',
  acceptAllLabel: 'Accept all',
  rejectAllLabel: 'Reject all',
  customiseLabel: 'Customise',
  savePreferencesLabel: 'Save preferences',
  categoryDescriptions: {
    necessary: 'n',
    preferences: 'p',
    statistics: 's',
    marketing: 'm',
  },
};

const initial: ConsentCategories = {
  necessary: true,
  preferences: false,
  statistics: false,
  marketing: false,
};

const resetDom = (): void => {
  document.documentElement.innerHTML = '<head></head><body></body>';
};

const noopHandlers = {
  onAcceptAll: vi.fn(),
  onRejectAll: vi.fn(),
  onSavePreferences: vi.fn(),
};

describe('renderBanner granularity', () => {
  beforeEach(() => {
    resetDom();
    vi.clearAllMocks();
  });

  it('renders the customise button in categorised mode (default)', () => {
    const handles = renderBanner({
      copy,
      initial,
      styleText: '',
      cspNonce: null,
      ...noopHandlers,
    });
    document.body.appendChild(handles.root);

    const buttons = Array.from(handles.root.querySelectorAll<HTMLButtonElement>('button'));
    const labels = buttons.map((button) => button.textContent);
    expect(labels).toContain('Customise');
    expect(labels).toContain('Save preferences');

    handles.destroy();
  });

  it('omits the customise button in basic mode', () => {
    const handles = renderBanner({
      copy,
      initial,
      styleText: '',
      granularity: 'basic',
      cspNonce: null,
      ...noopHandlers,
    });
    document.body.appendChild(handles.root);

    const buttons = Array.from(handles.root.querySelectorAll<HTMLButtonElement>('button'));
    const visibleLabels = buttons
      .filter((button) => !button.hidden && button.offsetParent !== null)
      .map((button) => button.textContent);

    expect(visibleLabels).not.toContain('Customise');
    expect(visibleLabels).toContain('Accept all');
    expect(visibleLabels).toContain('Reject all');

    handles.destroy();
  });

  it('still routes acceptAll / rejectAll in basic mode', () => {
    const handles = renderBanner({
      copy,
      initial,
      styleText: '',
      granularity: 'basic',
      cspNonce: null,
      ...noopHandlers,
    });
    document.body.appendChild(handles.root);

    const buttons = Array.from(handles.root.querySelectorAll<HTMLButtonElement>('button'));
    const accept = buttons.find((button) => button.textContent === 'Accept all');
    const reject = buttons.find((button) => button.textContent === 'Reject all');

    accept?.click();
    expect(noopHandlers.onAcceptAll).toHaveBeenCalledTimes(1);

    reject?.click();
    expect(noopHandlers.onRejectAll).toHaveBeenCalledTimes(1);

    handles.destroy();
  });
});
