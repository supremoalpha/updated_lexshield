(() => {
  const root = document.documentElement;
  const themeStorageKey = 'lex-theme';
  const themeToggle = document.getElementById('themeToggle');
  const moonIcon = '&#9681;';
  const sunIcon = '&#9728;';

  const getPreferredTheme = () => {
    const storedTheme = localStorage.getItem(themeStorageKey);
    if (storedTheme === 'dark' || storedTheme === 'light') {
      return storedTheme;
    }
    return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  };

  const applyTheme = (theme) => {
    const normalizedTheme = theme === 'dark' ? 'dark' : 'light';
    root.dataset.theme = normalizedTheme;
    localStorage.setItem(themeStorageKey, normalizedTheme);

    if (themeToggle) {
      const isDark = normalizedTheme === 'dark';
      themeToggle.innerHTML = isDark ? sunIcon : moonIcon;
      themeToggle.setAttribute('aria-label', isDark ? 'Switch to light mode' : 'Switch to dark mode');
      themeToggle.setAttribute('title', isDark ? 'Switch to light mode' : 'Switch to dark mode');
      themeToggle.setAttribute('aria-pressed', String(isDark));
    }
  };

  applyTheme(getPreferredTheme());

  const sidebar = document.getElementById('sidebar');
  const toggle = document.getElementById('sidebarToggle');
  if (toggle && sidebar) {
    const closeSidebar = () => {
      sidebar.classList.remove('open');
      toggle.setAttribute('aria-expanded', 'false');
      document.body.classList.remove('sidebar-is-open');
    };

    const openSidebar = () => {
      sidebar.classList.add('open');
      toggle.setAttribute('aria-expanded', 'true');
      document.body.classList.add('sidebar-is-open');
    };

    toggle.setAttribute('aria-expanded', sidebar.classList.contains('open') ? 'true' : 'false');
    toggle.setAttribute('aria-controls', sidebar.id);
    if (!sidebar.classList.contains('open')) {
      document.body.classList.remove('sidebar-is-open');
    }

    toggle.addEventListener('click', () => {
      if (sidebar.classList.contains('open')) {
        closeSidebar();
      } else {
        openSidebar();
      }
    });

    document.addEventListener('click', (event) => {
      if (!sidebar.classList.contains('open')) return;
      if (sidebar.contains(event.target) || toggle.contains(event.target)) return;
      closeSidebar();
    });

    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && sidebar.classList.contains('open')) {
        closeSidebar();
        toggle.focus();
      }
    });

    sidebar.querySelectorAll('a').forEach((link) => {
      link.addEventListener('click', closeSidebar);
    });
  }

  if (document.body.classList.contains('app-workspace')) {
    document.body.querySelectorAll('.main-content a, .main-content button, .main-content .button, #sidebarToggle, #themeToggle, #notifBellBtn, #topbarPhishingBtn').forEach((node) => {
      if (!(node instanceof HTMLElement)) {
        return;
      }
      node.style.pointerEvents = 'auto';
      node.style.touchAction = 'manipulation';
      if (node instanceof HTMLAnchorElement || node instanceof HTMLButtonElement) {
        node.style.cursor = 'pointer';
      }
    });
  }

  if (themeToggle) {
    themeToggle.addEventListener('click', () => {
      const next = root.dataset.theme === 'dark' ? 'light' : 'dark';
      applyTheme(next);
    });
  }

  if (document.querySelector('[data-appointment-board]') || document.querySelector('[data-system-settings-page]')) {
    document.body.classList.add('toast-upper');
  }

  const eyeSvg = `
    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
      <path d="M12 5c5.5 0 9.9 3.4 11.5 7-1.6 3.6-6 7-11.5 7S2.1 15.6.5 12C2.1 8.4 6.5 5 12 5Zm0 2.2A4.8 4.8 0 1 0 12 19a4.8 4.8 0 0 0 0-9.6Z"/>
    </svg>`;
  const eyeOffSvg = `
    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
      <path d="M3 3 21 21"/>
      <path d="M2.5 12s3.6-7 9.5-7c2.1 0 3.9.6 5.3 1.5L18.9 8A18.7 18.7 0 0 1 22 12c-1.6 3.6-6 7-10 7-1.7 0-3.3-.4-4.8-1.1l1.9-1.9A4.8 4.8 0 0 0 12 19a4.8 4.8 0 1 0-4.8-4.8c0 .7.1 1.3.4 1.9L5.8 17C3.4 15.2 2.5 12 2.5 12Zm9.5-4.8a4.8 4.8 0 0 1 4.8 4.8c0 .4 0 .7-.1 1.1l-2-2A2.8 2.8 0 0 0 12 9.2c-.4 0-.8.1-1.2.2L9 9a4.7 4.7 0 0 1 3-1.8Z"/>
    </svg>`;

  const syncPasswordToggleGroup = (group) => {
    const input = group.querySelector('input[type="password"], input[type="text"]');
    const button = group.querySelector('[data-password-toggle-button]');
    if (!input || !button) return;

    const isHidden = input.type === 'password';
    button.type = 'button';
    button.innerHTML = isHidden ? eyeSvg : eyeOffSvg;
    button.setAttribute('aria-pressed', String(!isHidden));
    button.setAttribute('aria-label', isHidden ? 'Show password' : 'Hide password');
    button.title = isHidden ? 'Show password' : 'Hide password';
  };

  const initPasswordToggleGroup = (group) => {
    if (!(group instanceof HTMLElement)) return;
    if (group.dataset.passwordToggleReady === 'true') {
      syncPasswordToggleGroup(group);
      return;
    }

    const input = group.querySelector('input[type="password"], input[type="text"]');
    const button = group.querySelector('[data-password-toggle-button]');
    if (!input || !button) return;

    group.dataset.passwordToggleReady = 'true';
    syncPasswordToggleGroup(group);
  };

  document.querySelectorAll('[data-password-toggle]').forEach(initPasswordToggleGroup);

  document.addEventListener('click', (event) => {
    const button = event.target.closest('[data-password-toggle-button]');
    if (!(button instanceof HTMLElement)) return;

    const group = button.closest('[data-password-toggle]');
    if (!(group instanceof HTMLElement)) return;

    const input = group.querySelector('input[type="password"], input[type="text"]');
    if (!(input instanceof HTMLInputElement)) return;

    event.preventDefault();
    input.type = input.type === 'password' ? 'text' : 'password';
    syncPasswordToggleGroup(group);
    input.focus();
  });

  const passwordToggleObserver = new MutationObserver((mutations) => {
    mutations.forEach((mutation) => {
      mutation.addedNodes.forEach((node) => {
        if (!(node instanceof HTMLElement)) return;
        if (node.matches('[data-password-toggle]')) {
          initPasswordToggleGroup(node);
        }
        node.querySelectorAll?.('[data-password-toggle]').forEach(initPasswordToggleGroup);
      });
    });
  });

  passwordToggleObserver.observe(document.body, {
    childList: true,
    subtree: true,
  });

  const toastTypes = new Set(['success', 'error', 'danger', 'warning', 'info', 'neutral', 'default']);
  const toastTitles = {
    success: 'Success',
    error: 'Error',
    danger: 'Error',
    warning: 'Warning',
    info: 'Information',
    neutral: 'Notice',
    default: 'Notice',
  };
  const toastDurations = {
    success: 5600,
    error: 8200,
    danger: 8200,
    warning: 7200,
    info: 6200,
    neutral: 6200,
    default: 6200,
  };
  const recentToasts = new Map();

  const normalizeToastType = (type) => {
    const normalized = String(type || 'default').trim().toLowerCase();
    return toastTypes.has(normalized) ? (normalized === 'danger' ? 'error' : normalized) : 'default';
  };

  const getToastStack = () => {
    let stack = document.querySelector('[data-toast-stack], .toast-stack');
    if (!stack) {
      stack = document.createElement('div');
      stack.className = 'toast-stack';
      stack.dataset.toastStack = 'true';
      stack.setAttribute('aria-live', 'polite');
      stack.setAttribute('aria-atomic', 'true');
      document.body.appendChild(stack);
    }
    return stack;
  };

  const readToastMessage = (toast) => {
    const message = toast.querySelector('.toast-message');
    if (message) return message.textContent.trim();
    return toast.textContent.trim();
  };

  const dismissToast = (toast) => {
    if (!(toast instanceof HTMLElement) || toast.dataset.toastRemoving === 'true') return;
    toast.dataset.toastRemoving = 'true';
    window.clearTimeout(Number(toast.dataset.toastTimer || 0));
    toast.classList.remove('is-paused');
    toast.classList.add('is-dismissing');
    window.setTimeout(() => toast.remove(), 280);
  };

  const scheduleToastDismiss = (toast, duration) => {
    if (toast.dataset.toastPersistent === 'true' || duration <= 0) {
      return;
    }

    const startedAt = Date.now();
    let remaining = duration;
    let timer = 0;

    const startTimer = () => {
      window.clearTimeout(timer);
      toast.dataset.toastStartedAt = String(Date.now());
      timer = window.setTimeout(() => dismissToast(toast), remaining);
      toast.dataset.toastTimer = String(timer);
    };

    const pauseTimer = () => {
      window.clearTimeout(timer);
      const activeFor = Date.now() - Number(toast.dataset.toastStartedAt || startedAt);
      remaining = Math.max(900, remaining - activeFor);
      toast.classList.add('is-paused');
    };

    const resumeTimer = () => {
      if (toast.dataset.toastRemoving === 'true') return;
      toast.classList.remove('is-paused');
      startTimer();
    };

    toast.addEventListener('mouseenter', pauseTimer);
    toast.addEventListener('mouseleave', resumeTimer);
    toast.addEventListener('focusin', pauseTimer);
    toast.addEventListener('focusout', resumeTimer);
    toast.addEventListener('pointerdown', pauseTimer);
    toast.addEventListener('pointerup', resumeTimer);
    toast.addEventListener('pointercancel', resumeTimer);
    startTimer();
  };

  const enhanceToast = (toast) => {
    if (!(toast instanceof HTMLElement) || toast.dataset.toastReady === 'true') {
      return toast;
    }

    const typeClass = Array.from(toast.classList).find((name) => name.startsWith('toast-'));
    const type = normalizeToastType(toast.dataset.toastType || (typeClass ? typeClass.replace('toast-', '') : 'default'));
    const existingMessage = readToastMessage(toast);
    const title = toast.dataset.toastTitle || toastTitles[type] || toastTitles.default;
    const duration = Number(toast.dataset.dismissAfter || toastDurations[type] || toastDurations.default);

    toast.dataset.toastReady = 'true';
    toast.dataset.toastType = type;
    toast.dataset.dismissAfter = String(duration);
    toast.classList.add('toast', `toast-${type}`);
    toast.classList.add(type);
    toast.setAttribute('role', type === 'error' ? 'alert' : 'status');
    toast.setAttribute('aria-atomic', 'true');
    toast.style.setProperty('--toast-duration', `${duration}ms`);

    if (!toast.querySelector('.toast-content')) {
      toast.textContent = '';
      const icon = document.createElement('span');
      icon.className = 'toast-icon';
      icon.setAttribute('aria-hidden', 'true');

      const content = document.createElement('span');
      content.className = 'toast-content';

      const titleNode = document.createElement('strong');
      titleNode.className = 'toast-title';
      titleNode.textContent = title;

      const messageNode = document.createElement('span');
      messageNode.className = 'toast-message';
      messageNode.textContent = existingMessage;

      content.append(titleNode, messageNode);
      toast.append(icon, content);
    }

    let closeButton = toast.querySelector('.toast-close');
    if (!closeButton) {
      closeButton = document.createElement('button');
      closeButton.type = 'button';
      closeButton.className = 'toast-close';
      closeButton.setAttribute('aria-label', 'Dismiss notification');
      closeButton.textContent = '×';
      toast.appendChild(closeButton);
    }
    closeButton.addEventListener('click', () => dismissToast(toast));

    if (!toast.querySelector('.toast-progress') && toast.dataset.toastPersistent !== 'true' && duration > 0) {
      const progress = document.createElement('span');
      progress.className = 'toast-progress';
      progress.setAttribute('aria-hidden', 'true');
      toast.appendChild(progress);
    }

    requestAnimationFrame(() => toast.classList.add('is-visible'));
    scheduleToastDismiss(toast, duration);
    return toast;
  };

  const showNotification = (type, message, options = {}) => {
    let normalizedType = normalizeToastType(type);
    let normalizedMessage = message;
    const firstLooksLikeMessage = !toastTypes.has(String(type || '').trim().toLowerCase());
    const secondLooksLikeType = toastTypes.has(String(message || '').trim().toLowerCase());
    if (firstLooksLikeMessage && secondLooksLikeType) {
      normalizedType = normalizeToastType(message);
      normalizedMessage = type;
    }
    if (normalizedMessage === undefined || typeof normalizedMessage === 'object') {
      normalizedMessage = type;
      normalizedType = normalizeToastType(options.type || 'default');
    }

    const text = String(normalizedMessage || '').trim();
    if (!text) return null;

    const signature = `${normalizedType}:${text}`;
    const now = Date.now();
    if (recentToasts.has(signature) && now - recentToasts.get(signature) < 1500) {
      return null;
    }
    recentToasts.set(signature, now);
    window.setTimeout(() => recentToasts.delete(signature), 1600);

    const toast = document.createElement('div');
    toast.className = `toast toast-${normalizedType}`;
    toast.dataset.toastType = normalizedType;
    if (options.title) toast.dataset.toastTitle = String(options.title);
    if (options.persistent) toast.dataset.toastPersistent = 'true';
    if (options.duration !== undefined) toast.dataset.dismissAfter = String(Number(options.duration) || 0);
    toast.textContent = text;
    getToastStack().appendChild(toast);
    return enhanceToast(toast);
  };

  const showToast = (type = 'success', title = '', message = '', duration = 5000) => {
    const normalizedType = normalizeToastType(type);
    const text = String(message || '').trim();
    const heading = String(title || toastTitles[normalizedType] || toastTitles.default).trim();
    const toast = document.createElement('div');
    toast.className = `toast toast-${normalizedType} ${normalizedType}`;
    toast.dataset.toastType = normalizedType;
    toast.dataset.toastTitle = heading;
    toast.dataset.dismissAfter = String(Number(duration) || toastDurations[normalizedType] || toastDurations.default);
    toast.textContent = text || heading;
    getToastStack().appendChild(toast);
    const enhanced = enhanceToast(toast);
    const titleNode = enhanced.querySelector('.toast-title');
    const messageNode = enhanced.querySelector('.toast-message');
    if (titleNode) titleNode.textContent = heading;
    if (messageNode) messageNode.textContent = text;
    return enhanced;
  };

  document.querySelectorAll('.toast').forEach(enhanceToast);

  const toastObserver = new MutationObserver((mutations) => {
    mutations.forEach((mutation) => {
      mutation.addedNodes.forEach((node) => {
        if (!(node instanceof HTMLElement)) return;
        if (node.matches('.toast')) enhanceToast(node);
        node.querySelectorAll?.('.toast').forEach(enhanceToast);
      });
    });
  });
  toastObserver.observe(document.body, { childList: true, subtree: true });

  window.showNotification = showNotification;
  window.showToast = showToast;
  window.notify = (type, message, options) => showNotification(type, message, options);

  document.querySelectorAll('form').forEach((form) => {
    if (form.hasAttribute('data-no-loading')) {
      return;
    }
    const submit = form.querySelector('button[type="submit"]');
    form.addEventListener('submit', (event) => {
      if (submit) {
        submit.dataset.originalText = submit.textContent;
        submit.textContent = submit.dataset.loadingText || form.dataset.loadingText || 'Working...';
        submit.disabled = true;
      }
      form.classList.add('loading');
      window.setTimeout(() => {
        if (!event.defaultPrevented) {
          return;
        }
        if (submit) {
          submit.disabled = false;
          submit.textContent = submit.dataset.originalText || submit.textContent;
        }
        form.classList.remove('loading');
      }, 0);
    });
  });

  document.querySelectorAll('[data-confirm]').forEach((el) => {
    el.addEventListener('click', (event) => {
      const message = el.getAttribute('data-confirm') || 'Are you sure?';
      const requiredText = (el.getAttribute('data-confirm-text') || '').trim();
      if (requiredText) {
        const entered = window.prompt(`${message}\n\nType ${requiredText} to continue:`, '');
        if ((entered || '').trim() !== requiredText) {
          event.preventDefault();
          return;
        }
        return;
      }
      if (!confirm(message)) {
        event.preventDefault();
      }
    });
  });

  const clientNoteModal = document.querySelector('[data-client-note-modal]');
  if (clientNoteModal instanceof HTMLElement) {
    const noteText = clientNoteModal.querySelector('[data-client-note-text]');
    const closeButtons = clientNoteModal.querySelectorAll('[data-client-note-close]');
    let lastNoteTrigger = null;
    const formatAppointmentNote = (message) => {
      const text = (message || 'No notes were added for this appointment.').trim();
      return text.replace(/([.!?])(?=[A-Z])/g, '$1 ');
    };

    const openClientNoteModal = (message, trigger) => {
      if (noteText) {
        noteText.textContent = formatAppointmentNote(message);
      }
      lastNoteTrigger = trigger || null;
      clientNoteModal.classList.add('is-open');
      clientNoteModal.setAttribute('aria-hidden', 'false');
      document.body.classList.add('has-modal-open');
      const closeButton = clientNoteModal.querySelector('[data-client-note-close]');
      closeButton?.focus();
    };

    const closeClientNoteModal = () => {
      clientNoteModal.classList.remove('is-open');
      clientNoteModal.setAttribute('aria-hidden', 'true');
      document.body.classList.remove('has-modal-open');
      lastNoteTrigger?.focus();
    };

    document.addEventListener('click', (event) => {
      const trigger = event.target.closest('[data-client-note-open]');
      if (!(trigger instanceof HTMLElement)) return;
      event.preventDefault();
      openClientNoteModal(trigger.dataset.note || '', trigger);
    });

    closeButtons.forEach((button) => {
      button.addEventListener('click', closeClientNoteModal);
    });

    clientNoteModal.addEventListener('click', (event) => {
      if (event.target === clientNoteModal) {
        closeClientNoteModal();
      }
    });

    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && clientNoteModal.classList.contains('is-open')) {
        closeClientNoteModal();
      }
    });
  }

  const bindCustomTypeField = (root) => {
    const select = root.querySelector('[data-type-choice], [data-appointment-type]');
    const wrap = root.querySelector('[data-custom-type-wrap]');
    const input = root.querySelector('[data-custom-type]');
    if (!(select instanceof HTMLSelectElement) || !(wrap instanceof HTMLElement)) {
      return {
        typeLabel: () => (select instanceof HTMLSelectElement && select.value ? select.value : 'Consultation'),
      };
    }

    const syncCustomType = () => {
      const isCustom = select.value === 'Custom';
      wrap.hidden = !isCustom;
      if (input instanceof HTMLInputElement) {
        input.disabled = !isCustom;
        input.required = isCustom;
        if (isCustom) {
          input.setAttribute('required', 'required');
        } else {
          input.removeAttribute('required');
        }
      }
    };

    select.addEventListener('change', syncCustomType);
    select.addEventListener('input', syncCustomType);
    if (input instanceof HTMLInputElement) {
      input.addEventListener('input', syncCustomType);
    }
    syncCustomType();

    return {
      typeLabel: () => {
        if (select.value === 'Custom' && input instanceof HTMLInputElement && input.value.trim()) {
          return input.value.trim();
        }
        return select.value || 'Consultation';
      },
    };
  };

  document.querySelectorAll('form').forEach((form) => {
    if (form instanceof HTMLFormElement && form.querySelector('[data-custom-type-wrap]') && !form.matches('[data-client-appointment-form]')) {
      bindCustomTypeField(form);
    }
  });

  const appointmentForm = document.querySelector('[data-client-appointment-form]');
  if (appointmentForm instanceof HTMLFormElement) {
    const lawyerSelect = appointmentForm.querySelector('[data-appointment-lawyer-select]');
    const dateInput = appointmentForm.querySelector('[data-appointment-date]');
    const timeInput = appointmentForm.querySelector('[data-appointment-time]');
    const typeInput = appointmentForm.querySelector('[data-appointment-type]');
    const customTypeField = bindCustomTypeField(appointmentForm);
    const summaryLawyer = appointmentForm.querySelector('[data-appointment-summary-lawyer]');
    const summaryMeta = appointmentForm.querySelector('[data-appointment-summary-meta]');

    const formatDate = (value) => {
      if (!value) return '';
      const parsed = new Date(`${value}T00:00:00`);
      if (Number.isNaN(parsed.getTime())) return value;
      return parsed.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
    };

    const formatTime = (value) => {
      if (!value) return '';
      const parsed = new Date(`2000-01-01T${value}`);
      if (Number.isNaN(parsed.getTime())) return value;
      return parsed.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
    };

    const updateAppointmentSummary = () => {
      const selectedOption = lawyerSelect?.selectedOptions?.[0];
      const lawyerLabel = selectedOption && selectedOption.value ? selectedOption.textContent.trim() : 'Choose a lawyer';
      const specialization = selectedOption?.dataset?.specialization || 'General Practice';
      const dateLabel = formatDate(dateInput?.value || '');
      const timeLabel = formatTime(timeInput?.value || '');
      const typeLabel = customTypeField.typeLabel();

      if (summaryLawyer) {
        summaryLawyer.textContent = lawyerLabel;
      }
      if (summaryMeta) {
        summaryMeta.textContent = dateLabel && timeLabel
          ? `${typeLabel} with ${specialization} on ${dateLabel} at ${timeLabel}. The request will stay pending until the lawyer confirms it.`
          : 'Select a date and time to preview this request.';
      }
    };

    const customTypeInput = appointmentForm.querySelector('[data-custom-type]');
    [lawyerSelect, dateInput, timeInput, typeInput, customTypeInput].forEach((control) => {
      control?.addEventListener('change', updateAppointmentSummary);
      control?.addEventListener('input', updateAppointmentSummary);
    });
    updateAppointmentSummary();
  }

  const apiBase = document.body.dataset.apiBase;
  if (apiBase) {
    fetch(`${apiBase.replace(/\/$/, '')}/health`, { credentials: 'omit' })
      .then((res) => (res.ok ? res.json() : null))
      .then((data) => {
        if (data && data.status) {
          console.debug('LEXSHIELD API health:', data.status);
        }
      })
      .catch(() => {
        console.debug('LEXSHIELD API is not reachable from the browser right now.');
      });
  }

  const appointmentBoard = document.querySelector('[data-appointment-board]');
  if (appointmentBoard) {
    const endpoint = appointmentBoard.dataset.endpoint || window.location.href;
    const results = appointmentBoard.querySelector('[data-appointment-results]');
    const pagination = appointmentBoard.querySelector('[data-appointment-pagination]');
    const summary = appointmentBoard.querySelector('[data-appointment-summary]');
    const filters = appointmentBoard.querySelector('[data-appointment-filters]');
    const searchInput = filters ? filters.querySelector('[data-appointment-search]') : null;
    const statusInput = filters ? filters.querySelector('[data-appointment-status]') : null;
    const pageInput = filters ? filters.querySelector('[data-appointment-page-input]') : null;
    let searchTimer = null;
    let activeRequest = 0;

    const readState = () => ({
      q: searchInput ? searchInput.value.trim() : '',
      status: statusInput ? statusInput.value : 'all',
      page: pageInput ? pageInput.value : '1',
    });

    const readStateFromUrl = () => {
      const params = new URLSearchParams(window.location.search);
      return {
        q: params.get('q') || '',
        status: params.get('status') || 'all',
        page: params.get('page') || '1',
      };
    };

    const syncInputs = (state) => {
      if (searchInput && searchInput.value !== (state.q || '')) searchInput.value = state.q || '';
      if (statusInput && statusInput.value !== (state.status || 'all')) statusInput.value = state.status || 'all';
      if (pageInput) pageInput.value = String(state.page || '1');
    };

    const syncUrl = (state, mode) => {
      const url = new URL(window.location.href);
      if (state.q) url.searchParams.set('q', state.q); else url.searchParams.delete('q');
      if (state.status && state.status !== 'all') url.searchParams.set('status', state.status); else url.searchParams.delete('status');
      if (state.page && String(state.page) !== '1') url.searchParams.set('page', String(state.page)); else url.searchParams.delete('page');
      url.searchParams.delete('format');
      if (mode === 'push') {
        history.pushState({}, '', url);
      } else {
        history.replaceState({}, '', url);
      }
    };

    const buildUrl = (state) => {
      const url = new URL(endpoint, window.location.href);
      url.searchParams.set('format', 'json');
      if (state.q) url.searchParams.set('q', state.q); else url.searchParams.delete('q');
      if (state.status && state.status !== 'all') url.searchParams.set('status', state.status); else url.searchParams.delete('status');
      if (state.page && String(state.page) !== '1') url.searchParams.set('page', String(state.page)); else url.searchParams.delete('page');
      return url;
    };

    const setBusy = (isBusy) => {
      appointmentBoard.classList.toggle('is-loading', isBusy);
      if (results) {
        results.setAttribute('aria-busy', isBusy ? 'true' : 'false');
      }
    };

    const fetchAppointments = async (state, options = {}) => {
      if (!endpoint) return;
      const requestId = ++activeRequest;
      const nextState = {
        q: state.q || '',
        status: state.status || 'all',
        page: state.page || '1',
      };
      const url = buildUrl(nextState);
      setBusy(true);
      try {
        const response = await fetch(url.toString(), {
          headers: { Accept: 'application/json' },
          credentials: 'same-origin',
        });
        if (!response.ok) {
          throw new Error('Request failed');
        }
        const data = await response.json();
        if (requestId !== activeRequest) {
          return;
        }
        if (results && data.resultsHtml !== undefined) {
          results.innerHTML = data.resultsHtml;
        }
        if (pagination && data.paginationHtml !== undefined) {
          pagination.innerHTML = data.paginationHtml;
        }
        if (summary && data.summaryText !== undefined) {
          summary.textContent = data.summaryText;
        }
        const syncedState = data.state || nextState;
        syncInputs(syncedState);
        syncUrl(syncedState, options.push ? 'push' : 'replace');
      } catch (error) {
        console.debug('Unable to refresh appointments right now.');
      } finally {
        if (requestId === activeRequest) {
          setBusy(false);
        }
      }
    };

    if (filters) {
      filters.addEventListener('submit', (event) => {
        event.preventDefault();
        fetchAppointments({
          ...readState(),
          page: '1',
        }, { push: true });
      });
    }

    if (searchInput) {
      searchInput.addEventListener('input', () => {
        window.clearTimeout(searchTimer);
        searchTimer = window.setTimeout(() => {
          fetchAppointments({
            ...readState(),
            page: '1',
          }, { push: false });
        }, 220);
      });
    }

    if (statusInput) {
      statusInput.addEventListener('change', () => {
        fetchAppointments({
          ...readState(),
          page: '1',
        }, { push: true });
      });
    }

    if (pageInput) {
      pageInput.addEventListener('change', () => {
        fetchAppointments(readState(), { push: true });
      });
    }

    appointmentBoard.addEventListener('click', (event) => {
      const pageLink = event.target.closest('[data-appointment-page]');
      if (!pageLink) return;
      if (pageLink.getAttribute('aria-disabled') === 'true') {
        event.preventDefault();
        return;
      }
      if (pageLink.tagName !== 'A') {
        return;
      }
      event.preventDefault();
      fetchAppointments({
        ...readState(),
        page: pageLink.dataset.page || '1',
      }, { push: true });
    });

    window.addEventListener('popstate', () => {
      const state = readStateFromUrl();
      syncInputs(state);
      fetchAppointments(state, { push: false });
    });
  }

  const pinPads = Array.from(document.querySelectorAll('[data-pin-pad]'));
  pinPads.forEach((pad) => {
    const input = pad.querySelector('[data-pin-input]');
    const dots = pad.querySelector('[data-pin-dots]');
    if (!(input instanceof HTMLInputElement) || !dots) {
      return;
    }

    const syncDots = () => {
      const length = Math.min(input.value.length, 8);
      dots.innerHTML = '';
      for (let i = 0; i < 8; i += 1) {
        const dot = document.createElement('span');
        if (i < length) {
          dot.classList.add('is-filled');
        }
        dots.appendChild(dot);
      }
    };

    const focusPin = () => {
      input.focus({ preventScroll: true });
    };

    pad.querySelectorAll('[data-pin-key]').forEach((button) => {
      button.addEventListener('click', () => {
        const key = button.getAttribute('data-pin-key') || '';
        if (key === 'del') {
          input.value = input.value.slice(0, -1);
        } else if (/^[0-9]$/.test(key) && input.value.length < 8) {
          input.value += key;
        }
        syncDots();
        input.dispatchEvent(new Event('input', { bubbles: true }));
        focusPin();
      });
    });

    input.addEventListener('input', () => {
      input.value = input.value.replace(/\D+/g, '').slice(0, 8);
      syncDots();
    });

    pad.addEventListener('click', (event) => {
      if (event.target instanceof HTMLButtonElement) {
        return;
      }
      focusPin();
    });

    syncDots();
  });

  const lockCard = document.querySelector('[data-messages-lock]');
  if (lockCard && pinPads.length) {
    document.addEventListener('keydown', (event) => {
      const active = document.activeElement;
      if (active instanceof HTMLInputElement && !active.matches('[data-pin-input]')) {
        return;
      }
      if (active instanceof HTMLTextAreaElement || active instanceof HTMLSelectElement) {
        return;
      }

      const focusedPad = pinPads.find((pad) => pad.contains(active));
      const pad = focusedPad || pinPads[0];
      const input = pad.querySelector('[data-pin-input]');
      if (!(input instanceof HTMLInputElement)) {
        return;
      }
      const typingInPin = active === input;

      if (/^[0-9]$/.test(event.key)) {
        if (typingInPin) {
          return;
        }
        event.preventDefault();
        if (input.value.length < 8) {
          input.value += event.key;
          input.dispatchEvent(new Event('input', { bubbles: true }));
        }
        input.focus({ preventScroll: true });
        return;
      }

      if (event.key === 'Backspace' || event.key === 'Delete') {
        if (typingInPin) {
          return;
        }
        event.preventDefault();
        input.value = input.value.slice(0, -1);
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.focus({ preventScroll: true });
        return;
      }

      if (event.key !== 'Enter') {
        return;
      }
      if (input.value.replace(/\D+/g, '').length < 4) {
        return;
      }
      const nextPad = pinPads[pinPads.indexOf(pad) + 1];
      if (nextPad) {
        const nextInput = nextPad.querySelector('[data-pin-input]');
        if (nextInput instanceof HTMLInputElement) {
          event.preventDefault();
          nextInput.focus({ preventScroll: true });
        }
        return;
      }
      const form = input.closest('form');
      if (form instanceof HTMLFormElement) {
        event.preventDefault();
        if (typeof form.requestSubmit === 'function') {
          form.requestSubmit();
        } else {
          form.submit();
        }
      }
    });
  }

})();

(() => {
  const isHome = document.body.classList.contains('pao-home');
  if (!isHome) {
    return;
  }

  const page = document.querySelector('.pao-page');
  const header = document.querySelector('.pao-header');
  if (!page) {
    return;
  }

  const clearZoom = (node) => {
    if (!(node instanceof HTMLElement)) {
      return;
    }
    node.style.zoom = '';
    node.style.width = '';
    node.style.minWidth = '';
    node.style.transform = 'none';
  };

  const enableHomeTaps = () => {
    document.documentElement.style.minWidth = '0';
    document.body.style.minWidth = '0';
    page.style.minWidth = '0';
    page.style.width = '100%';
    page.style.zoom = '';
    page.style.transform = 'none';
    page.style.setProperty('--pao-zoom', '1');
    clearZoom(page);
    document.querySelectorAll('#main-content, .pao-footer').forEach((node) => clearZoom(node));

    page.querySelectorAll('a, button, input, select, textarea, label').forEach((node) => {
      if (!(node instanceof HTMLElement)) {
        return;
      }
      node.style.pointerEvents = 'auto';
      node.style.touchAction = 'manipulation';
      if (node instanceof HTMLAnchorElement || node instanceof HTMLButtonElement) {
        node.style.cursor = 'pointer';
        node.style.position = node.style.position || 'relative';
        node.style.zIndex = node.style.zIndex || '6';
      }
    });
    if (header instanceof HTMLElement) {
      header.querySelectorAll('a, button').forEach((node) => {
        if (!(node instanceof HTMLElement)) {
          return;
        }
        node.style.pointerEvents = 'auto';
        node.style.touchAction = 'manipulation';
        node.style.zoom = '';
      });
      const nav = header.querySelector('.pao-nav');
      if (nav instanceof HTMLElement) {
        nav.style.touchAction = 'pan-x manipulation';
        nav.style.pointerEvents = 'auto';
        nav.style.setProperty('display', 'flex', 'important');
        nav.style.setProperty('flex-wrap', 'nowrap', 'important');
        nav.style.setProperty('flex-direction', 'row', 'important');
        const links = [...nav.querySelectorAll('a')].filter((node) => node instanceof HTMLElement);
        const phone = window.innerWidth <= 1100;
        nav.style.overflowX = phone ? 'auto' : 'hidden';
        nav.style.overflowY = 'hidden';
        const applyNavType = (px) => {
          links.forEach((link) => {
            link.style.letterSpacing = '0';
            link.style.whiteSpace = 'nowrap';
            if (phone) {
              link.style.flex = '0 0 auto';
              link.style.minWidth = 'auto';
              link.style.overflow = 'visible';
              link.style.paddingLeft = '0.45rem';
              link.style.paddingRight = '0.45rem';
              link.style.textTransform = 'none';
              link.style.fontSize = px ? `${px}px` : '';
            } else {
              link.style.flex = '0 0 auto';
              link.style.minWidth = 'auto';
              link.style.overflow = 'visible';
              link.style.paddingLeft = '0.9rem';
              link.style.paddingRight = '0.9rem';
              link.style.fontSize = '';
            }
          });
        };
        applyNavType(phone ? 16 : 0);
        if (phone) {
          let px = 16;
          for (let step = 0; step < 16; step += 1) {
            const overflowing = nav.scrollWidth > nav.clientWidth + 1;
            if (!overflowing || px <= 13) {
              break;
            }
            px -= 0.5;
            applyNavType(px);
          }
        }
      }
    }
  };

  const fitPhoneHome = () => {
    enableHomeTaps();
  };

  fitPhoneHome();
  window.addEventListener('orientationchange', () => {
    window.setTimeout(fitPhoneHome, 50);
  });
  window.addEventListener('resize', fitPhoneHome);

  const directory = document.querySelector('.pao-directory-search');
  const cards = [...document.querySelectorAll('.pao-lawyer-card')];
  const filterLawyers = () => {
    if (!(directory instanceof HTMLFormElement)) {
      return;
    }
    const query = String(new FormData(directory).get('q') || '').trim().toLowerCase();
    const spec = String(new FormData(directory).get('spec') || '').trim().toLowerCase();
    cards.forEach((card) => {
      const name = String(card.getAttribute('data-name') || '').toLowerCase();
      const cardSpec = String(card.getAttribute('data-spec') || '').toLowerCase();
      const haystack = `${name} ${cardSpec}`;
      const matchesQuery = query === '' || haystack.includes(query);
      const matchesSpec = spec === '' || cardSpec === spec;
      card.hidden = !(matchesQuery && matchesSpec);
    });
  };
  if (directory instanceof HTMLFormElement) {
    directory.addEventListener('submit', (event) => {
      event.preventDefault();
      filterLawyers();
    });
    directory.addEventListener('input', filterLawyers);
    directory.addEventListener('change', filterLawyers);
  }
})();

(() => {
  const fitAnyScreen = () => {
    const root = document.documentElement;
    const body = document.body;
    if (!(root instanceof HTMLElement) || !(body instanceof HTMLElement)) {
      return;
    }
    root.style.maxWidth = '100%';
    body.style.maxWidth = '100%';
    body.style.minWidth = '0';
    body.style.overflowX = 'hidden';
    document.querySelectorAll('.app-shell, .main-content, .pao-page, .pao-header, .card, .table-wrap, .modal-card').forEach((node) => {
      if (!(node instanceof HTMLElement)) {
        return;
      }
      node.style.maxWidth = '100%';
      node.style.minWidth = '0';
      node.style.boxSizing = 'border-box';
    });
  };

  fitAnyScreen();
  window.addEventListener('resize', fitAnyScreen);
  window.addEventListener('orientationchange', () => {
    window.setTimeout(fitAnyScreen, 50);
  });
})();
