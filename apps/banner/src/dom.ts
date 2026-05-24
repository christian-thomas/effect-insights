import { applyNonce } from './csp';
import type { BannerCopy, ConsentCategories, CookieCategory } from './types';
import { cls } from './styles';

const CATEGORIES: CookieCategory[] = ['necessary', 'preferences', 'statistics', 'marketing'];

interface RenderOptions {
  copy: BannerCopy;
  initial: ConsentCategories;
  styleText: string;
  // Host page's CSP nonce (read once at boot). Stamped on the <style>
  // element so strict-CSP sites don't block our banner styles.
  cspNonce: string | null;
  onAcceptAll: () => void;
  onRejectAll: () => void;
  onSavePreferences: (categories: ConsentCategories) => void;
}

interface RenderHandles {
  root: HTMLElement;
  destroy: () => void;
}

/**
 * Constructs the banner DOM as a detached element. Caller mounts it
 * (typically `document.body.appendChild`). Returns a destroy() callback
 * that pulls the element out of the DOM after consent is recorded.
 *
 * Modal layout only in iteration 4. Bar / corner variants land in v5.
 */
export const renderBanner = (options: RenderOptions): RenderHandles => {
  const { copy } = options;
  const categories: ConsentCategories = { ...options.initial };
  let customising = false;

  const root = document.createElement('div');
  root.setAttribute('role', 'dialog');
  root.setAttribute('aria-modal', 'true');
  root.setAttribute('aria-labelledby', `${cls('title')}`);

  const style = document.createElement('style');
  style.textContent = options.styleText;
  applyNonce(style, options.cspNonce);
  root.appendChild(style);

  const shell = document.createElement('div');
  shell.className = cls('shell');

  const card = document.createElement('div');
  card.className = cls('card');
  shell.appendChild(card);

  const title = document.createElement('h2');
  title.id = cls('title');
  title.className = cls('title');
  title.textContent = copy.title;
  card.appendChild(title);

  const body = document.createElement('p');
  body.className = cls('body');
  body.textContent = copy.body;
  card.appendChild(body);

  const categoriesContainer = document.createElement('div');
  categoriesContainer.className = cls('categories');
  categoriesContainer.hidden = true;
  card.appendChild(categoriesContainer);

  CATEGORIES.forEach((category) => {
    const row = document.createElement('div');
    row.className = cls('category');

    const meta = document.createElement('div');
    meta.className = cls('category-meta');

    const label = document.createElement('p');
    label.className = cls('category-label');
    label.textContent = category;
    meta.appendChild(label);

    const desc = document.createElement('p');
    desc.className = cls('category-desc');
    desc.textContent = copy.categoryDescriptions[category];
    meta.appendChild(desc);

    const toggle = document.createElement('div');
    toggle.className = cls('switch');
    toggle.setAttribute('role', 'switch');
    toggle.setAttribute('aria-label', `${category} cookies`);
    toggle.dataset['on'] = String(categories[category]);
    if (category === 'necessary') {
      toggle.dataset['disabled'] = 'true';
      toggle.setAttribute('aria-disabled', 'true');
    } else {
      toggle.addEventListener('click', () => {
        categories[category] = !categories[category];
        toggle.dataset['on'] = String(categories[category]);
      });
      toggle.addEventListener('keydown', (event: KeyboardEvent) => {
        if (event.key === ' ' || event.key === 'Enter') {
          event.preventDefault();
          categories[category] = !categories[category];
          toggle.dataset['on'] = String(categories[category]);
        }
      });
      toggle.tabIndex = 0;
    }

    row.appendChild(meta);
    row.appendChild(toggle);
    categoriesContainer.appendChild(row);
  });

  const actions = document.createElement('div');
  actions.className = cls('actions');
  card.appendChild(actions);

  const rejectButton = document.createElement('button');
  rejectButton.type = 'button';
  rejectButton.className = `${cls('btn')} ${cls('btn-secondary')}`;
  rejectButton.textContent = copy.rejectAllLabel;
  rejectButton.addEventListener('click', () => {
    options.onRejectAll();
  });
  actions.appendChild(rejectButton);

  const customiseButton = document.createElement('button');
  customiseButton.type = 'button';
  customiseButton.className = `${cls('btn')} ${cls('btn-link')}`;
  customiseButton.textContent = copy.customiseLabel;
  actions.appendChild(customiseButton);

  const acceptButton = document.createElement('button');
  acceptButton.type = 'button';
  acceptButton.className = `${cls('btn')} ${cls('btn-primary')}`;
  acceptButton.textContent = copy.acceptAllLabel;
  acceptButton.addEventListener('click', () => {
    options.onAcceptAll();
  });
  actions.appendChild(acceptButton);

  const saveButton = document.createElement('button');
  saveButton.type = 'button';
  saveButton.className = `${cls('btn')} ${cls('btn-primary')}`;
  saveButton.textContent = copy.savePreferencesLabel;
  saveButton.hidden = true;
  saveButton.addEventListener('click', () => {
    options.onSavePreferences(categories);
  });
  actions.appendChild(saveButton);

  customiseButton.addEventListener('click', () => {
    customising = !customising;
    categoriesContainer.hidden = !customising;
    saveButton.hidden = !customising;
    acceptButton.textContent = customising ? copy.acceptAllLabel : copy.acceptAllLabel;
  });

  const onKey = (event: KeyboardEvent): void => {
    if (event.key === 'Escape' && customising) {
      customising = false;
      categoriesContainer.hidden = true;
      saveButton.hidden = true;
    }
  };
  document.addEventListener('keydown', onKey);

  root.appendChild(shell);

  const destroy = (): void => {
    document.removeEventListener('keydown', onKey);
    root.remove();
  };

  return { root, destroy };
};
