import { applyNonce } from './csp';
import { captureFocusRestorer, createFocusTrap } from './focus';
import { cls } from './styles';
import type { BannerCopy, BannerGranularity, ConsentCategories, CookieCategory } from './types';

const CATEGORIES: CookieCategory[] = ['necessary', 'preferences', 'statistics', 'marketing'];

interface RenderOptions {
  copy: BannerCopy;
  initial: ConsentCategories;
  styleText: string;
  // 'basic' collapses the UI to Accept-all / Reject-all — no Customise
  // button, no per-category toggles, no Save-preferences. Operator
  // setting; defaults to 'categorised' to preserve existing behaviour.
  granularity?: BannerGranularity;
  // Host page's CSP nonce (read once at boot). Stamped on the <style>
  // element so strict-CSP sites don't block our banner styles.
  cspNonce: string | null;
  // When provided, Esc on the dialog + clicking the shell backdrop (not
  // the card) records consent as denied + closes the dialog. Used to
  // honour the operator's `behaviour.autoDenyOnDismiss` flag. When
  // undefined (the default), Esc only collapses the customise pane and
  // backdrop clicks do nothing — the visitor must make an explicit
  // accept/reject/save choice.
  onDismiss?: () => void;
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
 * A11y notes:
 *  - root has role=dialog + aria-modal + aria-labelledby + aria-describedby
 *  - focus is trapped inside the dialog until destroy()
 *  - initial focus lands on the reject button so screen-reader users hear
 *    a refusal action first (refusal as easy as acceptance, WCAG-friendly)
 *  - on destroy() focus returns to whatever the host page had focused
 *  - each category toggle is a <button role="switch"> with aria-checked,
 *    so native Enter/Space + screen-reader announcements work for free
 *  - Esc inside the customise pane returns to the simple view
 *
 * Modal layout only in iteration 4. Bar / corner variants land in v5.
 */
export const renderBanner = (options: RenderOptions): RenderHandles => {
  const { copy } = options;
  const granularity: BannerGranularity = options.granularity ?? 'categorised';
  const categories: ConsentCategories = { ...options.initial };
  let customising = false;

  const restoreFocus = captureFocusRestorer();

  const root = document.createElement('div');
  root.setAttribute('role', 'dialog');
  root.setAttribute('aria-modal', 'true');
  root.setAttribute('aria-labelledby', cls('title'));
  root.setAttribute('aria-describedby', cls('body'));

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
  body.id = cls('body');
  body.className = cls('body');
  body.textContent = copy.body;
  card.appendChild(body);

  const categoriesContainer = document.createElement('div');
  categoriesContainer.id = cls('categories');
  categoriesContainer.className = cls('categories');
  categoriesContainer.hidden = true;
  // role=group with a label means screen readers announce "Cookie categories,
  // group" when focus enters and group items together coherently.
  categoriesContainer.setAttribute('role', 'group');
  categoriesContainer.setAttribute('aria-label', 'Cookie categories');
  card.appendChild(categoriesContainer);

  CATEGORIES.forEach((category) => {
    const row = document.createElement('div');
    row.className = cls('category');

    const meta = document.createElement('div');
    meta.className = cls('category-meta');

    const labelId = cls(`category-label-${category}`);
    const descId = cls(`category-desc-${category}`);

    const label = document.createElement('p');
    label.id = labelId;
    label.className = cls('category-label');
    label.textContent = category;
    meta.appendChild(label);

    const desc = document.createElement('p');
    desc.id = descId;
    desc.className = cls('category-desc');
    desc.textContent = copy.categoryDescriptions[category];
    meta.appendChild(desc);

    // Native <button role="switch"> gives us Enter/Space, focus ring,
    // disabled handling, and screen-reader semantics for free.
    const toggle = document.createElement('button');
    toggle.type = 'button';
    toggle.className = cls('switch');
    toggle.setAttribute('role', 'switch');
    toggle.setAttribute('aria-checked', String(categories[category]));
    toggle.setAttribute('aria-labelledby', labelId);
    toggle.setAttribute('aria-describedby', descId);
    toggle.dataset['on'] = String(categories[category]);

    if (category === 'necessary') {
      toggle.disabled = true;
      toggle.setAttribute('aria-disabled', 'true');
    } else {
      toggle.addEventListener('click', () => {
        const next = !categories[category];
        categories[category] = next;
        toggle.dataset['on'] = String(next);
        toggle.setAttribute('aria-checked', String(next));
      });
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
  // aria-expanded reflects whether the customise pane is open so screen
  // readers can announce the toggle state.
  customiseButton.setAttribute('aria-expanded', 'false');
  customiseButton.setAttribute('aria-controls', categoriesContainer.id);
  if (granularity === 'basic') {
    // Basic mode = binary Accept/Reject; no customise affordance.
    customiseButton.hidden = true;
  } else {
    actions.appendChild(customiseButton);
  }

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
    customiseButton.setAttribute('aria-expanded', String(customising));
  });

  const onKey = (event: KeyboardEvent): void => {
    if (event.key !== 'Escape') return;
    if (customising) {
      // Collapse the customise pane back to the simple Accept/Reject view
      // rather than closing the whole banner.
      customising = false;
      categoriesContainer.hidden = true;
      saveButton.hidden = true;
      customiseButton.setAttribute('aria-expanded', 'false');
      customiseButton.focus();
      event.stopPropagation();
      return;
    }
    if (options.onDismiss) {
      // Operator has opted in to dismiss-as-denial. Esc records denied
      // consent and closes the banner.
      event.stopPropagation();
      options.onDismiss();
    }
    // Without onDismiss, Escape on the dialog (outside the customise pane)
    // is a deliberate no-op — closing the modal without an explicit
    // consent choice would be a GDPR foot-gun.
  };
  root.addEventListener('keydown', onKey);

  // Backdrop dismissal — only when the operator opted in. Click on the
  // shell wrapper (which sits behind the card) records denial. Clicks
  // inside the card itself shouldn't dismiss; we stopPropagation on the
  // card to be sure.
  if (options.onDismiss) {
    shell.addEventListener('click', (event: MouseEvent): void => {
      if (event.target === shell) {
        options.onDismiss?.();
      }
    });
  }

  root.appendChild(shell);

  const releaseFocusTrap = createFocusTrap(root);

  const destroy = (): void => {
    releaseFocusTrap();
    root.removeEventListener('keydown', onKey);
    root.remove();
    restoreFocus();
  };

  // Move focus into the dialog. Reject-first sets a refusal-friendly
  // default, but we use queueMicrotask so the caller can append the root
  // to the DOM before we try to focus an element inside it.
  queueMicrotask((): void => {
    if (root.isConnected) {
      rejectButton.focus();
    }
  });

  return { root, destroy };
};
