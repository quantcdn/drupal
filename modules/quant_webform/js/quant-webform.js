/**
 * @file
 * Runs a Webform on a static Quant page.
 *
 * The export render shows every wizard page and marks it with
 * data-quant-wizard-page. This script turns the pages into steps, checks each
 * step in the browser, builds the review step, and sends the form to Quant
 * Forms once. Without JavaScript every page shows and the form posts as one
 * page; Quant then shows its message in #quant-form-result.
 */
((Drupal, drupalSettings, once) => {
  'use strict';

  const FIELDS = 'input, select, textarea';
  const SKIP_TYPES = ['hidden', 'submit', 'button', 'reset', 'image'];

  /**
   * Whether an element takes space on the page.
   */
  function isVisible(el) {
    return !!(el.offsetWidth || el.offsetHeight || el.getClientRects().length);
  }

  /**
   * Whether Drupal #states hid a wizard page (not this script).
   */
  function isStatesHidden(page) {
    return page.style.display === 'none' || page.classList.contains('js-webform-states-hidden');
  }

  /**
   * The checkbox groups in a container that must have one box checked.
   */
  function requiredGroups(container) {
    return Array.from(container.querySelectorAll('.js-webform-type-checkboxes, .js-form-type-checkboxes, fieldset.form-checkboxes'))
      .filter((group) => group.querySelector('.form-required, .js-form-required, [required]') && isVisible(group));
  }

  /**
   * Checks the visible fields of a container; shows the first problem.
   */
  function validate(container, labels) {
    const fields = Array.from(container.querySelectorAll(FIELDS))
      .filter((el) => !el.disabled && !SKIP_TYPES.includes(el.type) && isVisible(el));
    for (const el of fields) {
      el.setCustomValidity('');
      if (!el.checkValidity()) {
        el.reportValidity();
        return false;
      }
    }
    for (const group of requiredGroups(container)) {
      const boxes = Array.from(group.querySelectorAll('input[type="checkbox"]'));
      if (boxes.length && !boxes.some((box) => box.checked)) {
        boxes[0].setCustomValidity(labels.required);
        boxes[0].reportValidity();
        boxes.forEach((box) => box.addEventListener('change', () => boxes[0].setCustomValidity(''), { once: true }));
        return false;
      }
    }
    return true;
  }

  /**
   * The label text of a field, for the review step.
   */
  function labelOf(el, form) {
    const group = el.closest('fieldset');
    if ((el.type === 'radio' || (el.type === 'checkbox' && group && group.querySelectorAll('input[type="checkbox"]').length > 1)) && group) {
      const legend = group.querySelector('legend');
      return legend ? legend.textContent.trim() : el.name;
    }
    const label = el.id ? form.querySelector(`label[for="${CSS.escape(el.id)}"]`) : null;
    return label ? label.textContent.trim() : el.name;
  }

  /**
   * The display value of a field, or an empty string.
   */
  function valueOf(el, form) {
    if (el.type === 'radio' || el.type === 'checkbox') {
      if (!el.checked) {
        return '';
      }
      const group = el.closest('fieldset');
      const inGroup = group && group.querySelectorAll(`input[type="${el.type}"]`).length > 1;
      if (el.type === 'checkbox' && !inGroup) {
        return Drupal.t('Yes');
      }
      const own = el.id ? form.querySelector(`label[for="${CSS.escape(el.id)}"]`) : null;
      return own ? own.textContent.trim() : el.value;
    }
    if (el.type === 'file') {
      return Array.from(el.files || []).map((file) => file.name).join(', ');
    }
    if (el.tagName === 'SELECT') {
      return Array.from(el.selectedOptions).filter((o) => o.value !== '').map((o) => o.textContent.trim()).join(', ');
    }
    return el.value.trim();
  }

  /**
   * The answers of one page: [label, value] pairs, in page order.
   */
  function answers(page, form) {
    const rows = new Map();
    page.querySelectorAll(FIELDS).forEach((el) => {
      if (el.disabled || SKIP_TYPES.includes(el.type) || !isVisible(el)) {
        return;
      }
      const value = valueOf(el, form);
      if (value === '') {
        return;
      }
      const label = labelOf(el, form);
      rows.set(label, rows.has(label) ? `${rows.get(label)}, ${value}` : value);
    });
    return Array.from(rows.entries());
  }

  /**
   * The request body, with the field names the Quant edge keeps.
   *
   * The edge keeps the last value of a repeated multipart name, so repeated
   * names become name, name_1, ... Without a file the body is urlencoded and
   * the edge groups name[] itself.
   */
  function bodyOf(form) {
    const data = new FormData(form);
    if (!form.querySelector('input[type="file"]')) {
      return new URLSearchParams(data);
    }
    const body = new FormData();
    const seen = {};
    data.forEach((value, key) => {
      // files[attachments][] is Drupal's upload name; the edge wants one
      // name per value.
      const name0 = key.replace(/^files\[([^\]]+)\](\[\])?$/, '$1').replace(/\[\]$/, '');
      let name = name0;
      if (seen[name] !== undefined) {
        seen[name] += 1;
        name = `${name}_${seen[name]}`;
      }
      else {
        seen[name] = 0;
      }
      if (value instanceof File && value.name === '' && value.size === 0) {
        return;
      }
      body.append(name, value);
    });
    return body;
  }

  /**
   * One static form.
   */
  class QuantForm {

    constructor(form, settings) {
      this.form = form;
      this.settings = settings;
      this.labels = settings.labels;
      this.pages = settings.wizard ? Array.from(form.querySelectorAll('[data-quant-wizard-page]')) : [];
      this.submits = Array.from(form.querySelectorAll('[data-quant-submit]'));
      this.index = 0;
      this.reviewing = false;
      this.status = document.createElement('div');
      this.status.className = 'quant-webform-status';
      form.prepend(this.status);
      form.classList.add('quant-webform-js');
      form.noValidate = true;
      form.addEventListener('submit', (event) => this.onSubmit(event));
      if (this.pages.length > 1) {
        this.buildSteps();
        this.show(0);
      }
    }

    /**
     * Pages that apply now: #states may skip a page.
     */
    activePages() {
      return this.pages.filter((page) => !isStatesHidden(page));
    }

    buildSteps() {
      this.nav = document.createElement('div');
      this.nav.className = 'quant-webform-steps';
      this.prev = this.button(this.labels.prev, () => this.back());
      this.next = this.button(this.labels.next, () => this.forward());
      this.nav.append(this.prev, this.next);
      const actions = this.form.querySelector('.form-actions, [data-drupal-selector="edit-actions"]');
      (actions || this.form.lastElementChild).before(this.nav);
      this.review = document.createElement('section');
      this.review.className = 'quant-webform-review';
      this.review.hidden = true;
      this.nav.before(this.review);
    }

    button(label, handler) {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'button';
      button.textContent = label;
      button.addEventListener('click', handler);
      return button;
    }

    show(index) {
      const active = this.activePages();
      this.index = Math.max(0, Math.min(index, active.length - 1));
      this.reviewing = false;
      this.pages.forEach((page) => { page.hidden = page !== active[this.index]; });
      this.review.hidden = true;
      this.updateControls(active);
      this.updateProgress(active[this.index].dataset.quantWizardPage);
    }

    updateControls(active) {
      const last = this.index === active.length - 1;
      this.prev.hidden = this.index === 0 && !this.reviewing;
      this.prev.textContent = this.reviewing ? this.labels.edit : this.labels.prev;
      this.next.hidden = this.reviewing || (last && !this.settings.preview);
      this.next.textContent = last ? this.labels.review : this.labels.next;
      const canSubmit = this.reviewing || (last && !this.settings.preview);
      this.submits.forEach((submit) => { submit.hidden = !canSubmit; });
    }

    updateProgress(key) {
      let reached = false;
      this.form.querySelectorAll('.webform-progress [data-webform-page]').forEach((step) => {
        const current = step.dataset.webformPage === key;
        reached = reached || current;
        step.classList.toggle('is-active', current);
        step.classList.toggle('is-complete', !reached);
        if (current) {
          step.setAttribute('aria-current', 'step');
        }
        else {
          step.removeAttribute('aria-current');
        }
      });
    }

    forward() {
      const active = this.activePages();
      if (!validate(active[this.index], this.labels)) {
        return;
      }
      if (this.index < active.length - 1) {
        this.show(this.index + 1);
        this.focusTop();
        return;
      }
      if (this.settings.preview) {
        this.showReview(active);
      }
    }

    back() {
      if (this.reviewing) {
        this.show(this.activePages().length - 1);
      }
      else {
        this.show(this.index - 1);
      }
      this.focusTop();
    }

    showReview(active) {
      this.review.replaceChildren();
      const heading = document.createElement('h2');
      heading.textContent = this.labels.previewTitle;
      this.review.append(heading);
      active.forEach((page) => {
        // Read the values while the page is visible.
        page.hidden = false;
        const rows = answers(page, this.form);
        page.hidden = true;
        if (!rows.length) {
          return;
        }
        const title = document.createElement('h3');
        title.textContent = page.dataset.quantWizardTitle;
        const list = document.createElement('dl');
        rows.forEach(([label, value]) => {
          const dt = document.createElement('dt');
          dt.textContent = label;
          const dd = document.createElement('dd');
          dd.textContent = value;
          list.append(dt, dd);
        });
        this.review.append(title, list);
      });
      this.reviewing = true;
      this.review.hidden = false;
      this.updateControls(active);
      this.updateProgress('webform_preview');
      this.focusTop();
    }

    focusTop() {
      this.form.scrollIntoView({ block: 'start', behavior: 'smooth' });
    }

    onSubmit(event) {
      event.preventDefault();
      if (this.pages.length > 1 && !this.reviewing) {
        const active = this.activePages();
        if (this.index < active.length - 1 || this.settings.preview) {
          this.forward();
          return;
        }
      }
      const scope = this.pages.length > 1 && !this.reviewing ? this.activePages()[this.index] : this.form;
      if (!this.reviewing && !validate(scope, this.labels)) {
        return;
      }
      this.send();
    }

    async send() {
      this.setSending(true);
      try {
        const response = await fetch(this.form.getAttribute('action') || window.location.pathname, {
          method: 'POST',
          body: bodyOf(this.form),
          headers: { Accept: 'application/json' },
          credentials: 'same-origin',
        });
        const result = await response.json();
        if (result && result.ok) {
          this.done();
          return;
        }
        this.fail(result && result.msg ? result.msg : this.labels.error);
      }
      catch (e) {
        this.fail(this.labels.error);
      }
    }

    setSending(sending) {
      this.submits.forEach((submit) => {
        submit.disabled = sending;
        if (sending) {
          submit.dataset.quantLabel = submit.value;
          submit.value = this.labels.sending;
        }
        else if (submit.dataset.quantLabel) {
          submit.value = submit.dataset.quantLabel;
        }
      });
    }

    fail(message) {
      this.setSending(false);
      this.status.setAttribute('role', 'alert');
      this.status.className = 'quant-webform-status messages messages--error';
      // Quant messages are admin-set text: show them as text.
      this.status.textContent = message;
      this.status.scrollIntoView({ block: 'center' });
    }

    done() {
      const confirmation = this.settings.confirmation;
      if (confirmation.type === 'url' && confirmation.url) {
        window.location.assign(confirmation.url);
        return;
      }
      const box = document.createElement('div');
      box.className = 'quant-webform-confirmation webform-confirmation';
      box.setAttribute('role', 'status');
      box.tabIndex = -1;
      if (confirmation.title) {
        const title = document.createElement('h2');
        title.textContent = confirmation.title;
        box.append(title);
      }
      const message = document.createElement('div');
      // Filtered on the server with Xss::filterAdmin().
      message.innerHTML = confirmation.message;
      box.append(message);
      this.form.replaceWith(box);
      box.focus();
    }

  }

  Drupal.behaviors.quantWebform = {
    attach(context) {
      once('quant-webform', 'form[data-quant-webform]', context).forEach((form) => {
        const settings = (drupalSettings.quantWebform || {})[form.dataset.quantWebform];
        if (settings) {
          form.quantForm = new QuantForm(form, settings);
        }
      });
    },
  };

  // For tests in Node.
  if (typeof module !== 'undefined') {
    module.exports = { bodyOf, validate, answers, isVisible };
  }

})(Drupal, drupalSettings, once);
