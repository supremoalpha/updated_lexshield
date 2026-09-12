(() => {
  const setModalInertSiblings = (modal, enabled) => {
    if (!(modal instanceof HTMLElement)) return;
    let current = modal;
    while (current && current !== document.body) {
      const parent = current.parentElement;
      if (!parent) break;
      Array.from(parent.children).forEach((sibling) => {
        if (!(sibling instanceof HTMLElement) || sibling === current) return;
        const currentCount = Number(sibling.dataset.lexInertCount || '0');
        if (enabled) {
          sibling.dataset.lexInertCount = String(currentCount + 1);
          sibling.inert = true;
          return;
        }
        const nextCount = Math.max(0, currentCount - 1);
        if (nextCount === 0) {
          delete sibling.dataset.lexInertCount;
          sibling.inert = false;
        } else {
          sibling.dataset.lexInertCount = String(nextCount);
        }
      });
      current = parent;
    }
  };

  const chatShell = document.querySelector('[data-chat-shell]');
  let genericModalReturnFocus = null;
  let phishingScanController = null;
  const isNewMessageDialog = (modal) => modal instanceof HTMLElement && modal.id === 'newMessageModal';
  const isNativeDialog = (modal) => typeof HTMLDialogElement !== 'undefined' && modal instanceof HTMLDialogElement;
  const isPhishingDialog = (modal) => modal instanceof HTMLElement && modal.id === 'phishingDetectorModal';

  const openNativeDialog = (modal) => {
    if (!(modal instanceof HTMLElement)) return;
    modal.inert = false;
    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
    if (typeof modal.showModal === 'function') {
      if (!modal.open) {
        try {
          modal.showModal();
        } catch (error) {
          modal.setAttribute('open', '');
        }
      }
      return;
    }
    modal.setAttribute('open', '');
  };

  const closeNativeDialog = (modal) => {
    if (!(modal instanceof HTMLElement)) return;
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
    modal.inert = false;
    if (typeof modal.close === 'function' && modal.open) {
      try {
        modal.close();
      } catch (error) {
        modal.removeAttribute('open');
      }
      return;
    }
    modal.removeAttribute('open');
  };

  const openNewMessageDialog = (modal) => {
    if (!isNewMessageDialog(modal)) return;
    openNativeDialog(modal);
  };

  const closeNewMessageDialog = (modal) => {
    if (!isNewMessageDialog(modal)) return;
    closeNativeDialog(modal);
  };

  const openModal = (modal) => {
    if (!modal) return;
    genericModalReturnFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    if (isNewMessageDialog(modal) || isPhishingDialog(modal) || isNativeDialog(modal)) {
      openNativeDialog(modal);
    } else {
      modal.classList.add('is-open');
      modal.setAttribute('aria-hidden', 'false');
      modal.inert = false;
      setModalInertSiblings(modal, true);
    }
    const firstField = modal.querySelector('input:not([type="hidden"]), select, textarea, button:not([data-modal-close])');
    if (firstField instanceof HTMLElement) {
      setTimeout(() => firstField.focus(), 50);
    }
  };

  const closeModal = (modal) => {
    if (!modal) return;
    const active = document.activeElement;
    if (active instanceof HTMLElement && modal.contains(active)) {
      active.blur();
    }
    if (modal.id && location.hash === `#${modal.id}`) {
      history.replaceState({}, '', `${location.pathname}${location.search}`);
    }
    if (isNewMessageDialog(modal) || isPhishingDialog(modal) || isNativeDialog(modal)) {
      closeNativeDialog(modal);
    } else {
      modal.classList.remove('is-open');
      modal.setAttribute('aria-hidden', 'true');
      modal.inert = true;
      setModalInertSiblings(modal, false);
    }
    const returnFocus = genericModalReturnFocus;
    genericModalReturnFocus = null;
    if (returnFocus instanceof HTMLElement && document.contains(returnFocus)) {
      setTimeout(() => {
        if (document.contains(returnFocus)) {
          returnFocus.focus();
        }
      }, 0);
    }
  };

  const renderAttachmentPreview = (container, files, emptyText = 'No file selected') => {
    if (!container) return;
    container.innerHTML = '';
    if (!files || !files.length) {
      container.textContent = emptyText;
      return;
    }
    files.forEach((file) => {
      const item = document.createElement('div');
      item.className = 'attachment-chip';
      item.textContent = `${file.name} - ${Math.max(1, Math.round(file.size / 1024))} KB`;
      container.appendChild(item);
    });
  };

  const escapeHtml = (value) => String(value || '').replace(/[&<>"']/g, (char) => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#039;',
  }[char]));

  const PhishingResult = {
    labels: {
      safe: 'Safe ✅',
      suspicious: 'Suspicious ⚠️',
      phishing: 'Phishing 🚨',
    },
    render(container, payload) {
      if (!container) return;
      const status = ['safe', 'suspicious', 'phishing'].includes(payload?.status) ? payload.status : 'suspicious';
      const score = Number.isFinite(Number(payload?.score)) ? Number(payload.score) : null;
      const message = escapeHtml(payload?.message || 'The scan completed, but no details were returned.');
      const findings = Array.isArray(payload?.findings)
        ? payload.findings.filter((finding) => typeof finding === 'string' && finding.trim() !== '')
        : [];
      const redirectCount = Number.isFinite(Number(payload?.redirect_count)) ? Number(payload.redirect_count) : 0;
      const finalUrl = typeof payload?.final_url === 'string' ? payload.final_url.trim() : '';
      const redirectHtml = redirectCount > 0 && finalUrl
        ? `<span>Final URL: ${escapeHtml(finalUrl)}</span><span>Redirects followed: ${redirectCount}</span>`
        : '';
      const engines = Array.isArray(payload?.engines) ? payload.engines.filter((engine) => typeof engine === 'string') : [];
      const engineHtml = engines.includes('python')
        ? '<span>Engines: PHP + Python</span>'
        : (engines.includes('php') ? '<span>Engine: PHP</span>' : '');
      const findingsHtml = findings.length
        ? `<ul>${findings.map((finding) => `<li>${escapeHtml(finding)}</li>`).join('')}</ul>`
        : '';
      container.className = `phishing-detector-result is-${status}`;
      container.innerHTML = `
        <strong>${this.labels[status]}</strong>
        ${score === null ? '' : `<span>Score: ${score}</span>`}
        ${engineHtml}
        ${redirectHtml}
        <p>${message}</p>
        ${findingsHtml}
      `;
      container.hidden = false;
    },
    clear(container) {
      if (!container) return;
      container.hidden = true;
      container.className = 'phishing-detector-result';
      container.innerHTML = '';
    },
  };

  const PhishingInput = {
    read(input) {
      return (input?.value || '').trim();
    },
    validate(value) {
      try {
        const parsed = new URL(value);
        return parsed.protocol === 'http:' || parsed.protocol === 'https:';
      } catch (error) {
        return false;
      }
    },
  };

  const parsePhishingJson = (text) => {
    const trimmed = String(text || '').replace(/^\uFEFF/, '').replace(/<br\s*\/?>/gi, '\n').trim();
    if (!trimmed) {
      return null;
    }
    try {
      const direct = JSON.parse(trimmed);
      if (direct && typeof direct === 'object') {
        return direct;
      }
    } catch (error) {
      // PHP may have printed a warning before the JSON object.
    }
    let start = trimmed.indexOf('{');
    while (start >= 0) {
      const end = trimmed.lastIndexOf('}');
      if (end <= start) {
        break;
      }
      try {
        const parsed = JSON.parse(trimmed.slice(start, end + 1));
        if (parsed && typeof parsed === 'object' && parsed.status) {
          return parsed;
        }
      } catch (error) {
        // Try the next opening brace in case a warning contained `{`.
      }
      start = trimmed.indexOf('{', start + 1);
    }
    return null;
  };

  const parsePhishingResponse = async (response) => {
    const text = await response.text();
    const trimmed = String(text || '').replace(/^\uFEFF/, '').trim();
    const parsed = parsePhishingJson(trimmed);
    if (parsed) {
      return parsed;
    }
    if (/<!doctype html/i.test(trimmed) || /<html[\s>]/i.test(trimmed)) {
      throw new Error('The scanner could not be reached. Refresh this page and try again.');
    }
    throw new Error('The phishing scanner returned an invalid response.');
  };

  const phishingScanEndpoints = (preferred) => {
    const list = [];
    const add = (value) => {
      if (typeof value === 'string' && value.trim() !== '' && !list.includes(value.trim())) {
        list.push(value.trim());
      }
    };
    add(preferred);
    const form = document.querySelector('[data-phishing-form]');
    add(form?.dataset.fallbackEndpoint || '');
    try {
      const here = new URL(window.location.href);
      here.searchParams.set('lex_phishing', '1');
      add(`${here.pathname}?${here.searchParams.toString()}`);
    } catch (error) {
      add('?lex_phishing=1');
    }
    add('notifications_api.php?lex_phishing=1');
    add('../notifications_api.php?lex_phishing=1');
    add('../../notifications_api.php?lex_phishing=1');
    add('go.php?lex_phishing=1');
    add('../go.php?lex_phishing=1');
    add('../../go.php?lex_phishing=1');
    add('phishing_check.php');
    add('../phishing_check.php');
    add('../../phishing_check.php');
    return list;
  };

  const fetchPhishingScan = async (preferred, url, signal) => {
    let lastError = new Error('The phishing scanner returned an invalid response.');
    for (const endpoint of phishingScanEndpoints(preferred)) {
      try {
        const response = await fetch(endpoint, {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Lex-Phishing': '1',
          },
          body: JSON.stringify({ url }),
          signal,
        });
        if (response.status === 404) {
          lastError = new Error('The scanner could not be reached. Refresh this page and try again.');
          continue;
        }
        return await parsePhishingResponse(response);
      } catch (error) {
        if (error?.name === 'AbortError') {
          throw error;
        }
        lastError = error instanceof Error ? error : lastError;
      }
    }
    throw lastError;
  };

  const PhishingButton = {
    setLoading(form, isLoading) {
      const buttons = form.querySelectorAll('button');
      const spinner = form.querySelector('[data-phishing-submit] .phishing-spinner');
      const label = form.querySelector('[data-phishing-submit-label]');
      buttons.forEach((button) => {
        button.disabled = isLoading;
        button.setAttribute('aria-disabled', String(isLoading));
      });
      if (spinner) spinner.hidden = !isLoading;
      if (label) label.textContent = isLoading ? 'Scanning...' : 'Scan';
      form.classList.toggle('loading', isLoading);
    },
  };

  const PhishingModal = {
    reset(modal) {
      if (!modal || modal.id !== 'phishingDetectorModal') return;
      const form = modal.querySelector('[data-phishing-form]');
      const input = modal.querySelector('[data-phishing-input]');
      const error = modal.querySelector('[data-phishing-error]');
      const result = modal.querySelector('[data-phishing-result]');
      const submit = modal.querySelector('[data-phishing-submit]');
      const label = modal.querySelector('[data-phishing-submit-label]');
      if (phishingScanController) {
        phishingScanController.abort();
        phishingScanController = null;
      }
      if (form) {
        form.dataset.scanCancelled = '1';
        PhishingButton.setLoading(form, false);
      }
      if (input) input.value = '';
      if (input) input.disabled = false;
      if (submit) {
        submit.disabled = false;
        submit.removeAttribute('aria-disabled');
      }
      if (label) label.textContent = 'Scan';
      if (error) {
        error.textContent = '';
        error.hidden = true;
      }
      PhishingResult.clear(result);
    },
    showError(node, message) {
      if (!node) return;
      node.textContent = message;
      node.hidden = false;
    },
  };

  const resetNewMessageModal = (modal) => {
    if (!modal || modal.id !== 'newMessageModal') return;
    const fileInput = modal.querySelector('[data-modal-attachment-input]');
    const fileName = modal.querySelector('[data-modal-attachment-name]');
    const messageInput = modal.querySelector('[data-message-input]');
    const errorBox = modal.querySelector('[data-modal-errors]');
    if (fileInput) fileInput.value = '';
    if (messageInput) messageInput.value = '';
    renderAttachmentPreview(fileName, []);
    if (errorBox) {
      errorBox.textContent = '';
      errorBox.hidden = true;
    }
  };

  const resetModalState = (modal) => {
    resetNewMessageModal(modal);
    PhishingModal.reset(modal);
  };

  document.querySelectorAll('#newMessageModal[data-modal]').forEach((modal) => {
    modal.inert = false;
    modal.addEventListener('close', () => {
      modal.classList.remove('is-open');
      modal.setAttribute('aria-hidden', 'true');
    });
  });

  const openNewMessageModal = (event) => {
    if (event) {
      event.preventDefault();
      event.stopPropagation();
    }
    const modal = document.getElementById('newMessageModal');
    if (!modal) {
      return false;
    }
    const recipientSelect = modal.querySelector('[data-recipient-select]');
    const messageInput = modal.querySelector('[data-message-input]');
    const fileInput = modal.querySelector('[data-modal-attachment-input]');
    const fileName = modal.querySelector('[data-modal-attachment-name]');
    const errorBox = modal.querySelector('[data-modal-errors]');
    if (recipientSelect) recipientSelect.value = '';
    if (messageInput) messageInput.value = '';
    if (fileInput) fileInput.value = '';
    renderAttachmentPreview(fileName, []);
    if (errorBox) {
      errorBox.textContent = '';
      errorBox.hidden = true;
    }
    openModal(modal);
    return false;
  };
  window.lexOpenNewMessage = openNewMessageModal;
  const closeNewMessageModal = (event) => {
    if (event) {
      event.preventDefault();
      event.stopPropagation();
    }
    const modal = document.getElementById('newMessageModal');
    closeModal(modal);
    resetNewMessageModal(modal);
    return false;
  };
  window.lexCloseNewMessage = closeNewMessageModal;

  if (chatShell) {
    chatShell.querySelectorAll('[data-modal-open="newMessageModal"]').forEach((button) => {
      button.addEventListener('click', openNewMessageModal);
    });
    const composeBtn = document.getElementById('inboxComposeBtn');
    if (composeBtn) {
      composeBtn.addEventListener('click', openNewMessageModal);
    }

    const scrollArea = chatShell.querySelector('[data-chat-scroll]');
    if (scrollArea) {
      scrollArea.scrollTop = scrollArea.scrollHeight;
    }

    const searchInput = chatShell.querySelector('[data-conversation-search]');
    const items = Array.from(chatShell.querySelectorAll('[data-conversation-item]'));
    const tabs = Array.from(chatShell.querySelectorAll('[data-filter-tab]'));
    const groups = Array.from(chatShell.querySelectorAll('.conversation-group'));
    let currentFilter = 'all';
    const applyFilter = (filter) => {
      currentFilter = filter;
      items.forEach((item) => {
        const unread = item.dataset.unread === '1';
        const important = item.dataset.important === '1';
        const show = filter === 'all' || (filter === 'unread' && unread) || (filter === 'important' && important);
        if (searchInput && searchInput.value.trim() !== '') {
          const query = searchInput.value.trim().toLowerCase();
          item.hidden = !show || !item.textContent.toLowerCase().includes(query);
        } else {
          item.hidden = !show;
        }
      });
      groups.forEach((group) => {
        const visibleItems = Array.from(group.querySelectorAll('[data-conversation-item]')).some((item) => !item.hidden);
        group.hidden = !visibleItems;
      });
    };
    if (searchInput && items.length) {
      searchInput.addEventListener('input', () => applyFilter(currentFilter));
    }
    if (tabs.length) {
      tabs.forEach((tab) => {
        tab.addEventListener('click', () => {
          tabs.forEach((t) => t.classList.remove('is-active'));
          tab.classList.add('is-active');
          const filter = tab.dataset.filterTab || 'all';
          applyFilter(filter);
          const url = new URL(window.location.href);
          if (filter === 'all') {
            url.searchParams.delete('filter');
          } else {
            url.searchParams.set('filter', filter);
          }
          window.history.replaceState({}, '', url);
        });
      });
      const requestedFilter = new URLSearchParams(window.location.search).get('filter') || 'all';
      const activeFilter = ['all', 'unread', 'important'].includes(requestedFilter) ? requestedFilter : 'all';
      tabs.forEach((tab) => tab.classList.toggle('is-active', (tab.dataset.filterTab || 'all') === activeFilter));
      applyFilter(activeFilter);
    }

    document.querySelectorAll('[data-modal-close]').forEach((button) => {
      button.addEventListener('click', (event) => {
        event.preventDefault();
        const modal = button.closest('[data-modal]');
        closeModal(modal);
        resetModalState(modal);
      });
    });

    document.querySelectorAll('[data-modal]').forEach((modal) => {
      modal.addEventListener('click', (event) => {
        if (event.target === modal) {
          closeModal(modal);
          resetModalState(modal);
        }
      });
    });

    document.addEventListener('click', (event) => {
      const openTrigger = event.target.closest('[data-modal-open]');
      if (openTrigger) {
        event.preventDefault();
        const target = document.getElementById(openTrigger.dataset.modalOpen || '');
        if (!target) return;
        if (target.id === 'conversationInfoModal') {
          const data = openTrigger.dataset;
          const setText = (selector, value) => {
            const node = target.querySelector(selector);
            if (node) node.textContent = value || '';
          };
          setText('[data-info-name]', data.infoName);
          setText('[data-info-role]', data.infoRole);
          setText('[data-info-status]', data.infoStatus);
          setText('[data-info-case]', data.infoCase);
          setText('[data-info-id]', data.infoId);
          setText('[data-info-created]', data.infoCreated);
          setText('[data-info-activity]', data.infoActivity);
          const avatar = target.querySelector('[data-info-avatar]');
          const avatarImg = target.querySelector('[data-info-avatar-img]');
          const avatarText = target.querySelector('[data-info-avatar-text]');
          const avatarUrl = data.infoAvatarUrl || '';
          if (avatarImg instanceof HTMLImageElement && avatarText) {
            if (avatarUrl) {
              avatarImg.src = avatarUrl;
              avatarImg.hidden = false;
              avatarText.hidden = true;
            } else {
              avatarImg.removeAttribute('src');
              avatarImg.hidden = true;
              avatarText.textContent = (data.infoName || '?').replace(/\s+/g, '').slice(0, 2).toUpperCase();
              avatarText.hidden = false;
            }
          } else if (avatar) {
            avatar.textContent = (data.infoName || '?').slice(0, 2).toUpperCase();
          }
        } else if (target.id === 'newMessageModal') {
          const data = openTrigger.dataset;
          const caseInput = target.querySelector('[data-default-case-input]');
          const recipientSelect = target.querySelector('[data-recipient-select]');
          const recipientRole = target.querySelector('[data-recipient-role]');
          const fileInput = target.querySelector('[data-modal-attachment-input]');
          const fileName = target.querySelector('[data-modal-attachment-name]');
          const errorBox = target.querySelector('[data-modal-errors]');
          if (caseInput && data.defaultCase) {
            caseInput.value = data.defaultCase;
          }
          if (recipientSelect) {
            const defaultRecipient = data.defaultRecipient || '';
            const hasDefaultRecipient = defaultRecipient !== '' && defaultRecipient !== '0' && Array.from(recipientSelect.options).some((option) => option.value === defaultRecipient && !option.disabled);
            recipientSelect.value = hasDefaultRecipient ? defaultRecipient : '';
          }
          if (recipientRole && data.defaultRole) {
            recipientRole.value = data.defaultRole;
          }
          if (recipientSelect) {
            recipientSelect.dispatchEvent(new Event('change', { bubbles: true }));
          }
          if (fileInput) fileInput.value = '';
          renderAttachmentPreview(fileName, []);
          if (errorBox) {
            errorBox.textContent = '';
            errorBox.hidden = true;
          }
        } else if (target.id === 'phishingDetectorModal') {
          PhishingModal.reset(target);
        } else if (target.id === 'profileModal') {
          const data = openTrigger.dataset;
          const setText = (selector, value) => {
            const node = target.querySelector(selector);
            if (node) node.textContent = value || '';
          };
          setText('[data-profile-name]', data.profileName);
          setText('[data-profile-role]', data.profileRole);
          setText('[data-profile-status]', data.profileStatus);
          setText('[data-profile-case]', data.profileCase);
          setText('[data-profile-email]', data.profileEmail || 'Not provided');
          setText('[data-profile-note]', data.profileNote);
          const avatar = target.querySelector('[data-profile-avatar]');
          if (avatar) avatar.textContent = (data.profileAvatar || '?').slice(0, 2).toUpperCase();
        }
        openModal(target);
        return;
      }

      const closeTrigger = event.target.closest('[data-modal-close]');
      if (closeTrigger) {
        event.preventDefault();
        const modal = closeTrigger.closest('[data-modal]');
        closeModal(modal);
        resetModalState(modal);
        return;
      }

      const overlay = event.target.closest('[data-modal]');
      if (overlay && event.target === overlay) {
        closeModal(overlay);
        resetModalState(overlay);
      }
    });

    const phishingForm = document.querySelector('[data-phishing-form]');
    if (phishingForm && phishingForm.dataset.bound !== '1') {
      phishingForm.dataset.bound = '1';
      phishingForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (phishingForm.dataset.scanning === '1') {
          return;
        }
        const input = phishingForm.querySelector('[data-phishing-input]');
        const error = phishingForm.querySelector('[data-phishing-error]');
        const result = phishingForm.querySelector('[data-phishing-result]');
        const endpoint = phishingForm.dataset.endpoint || '';
        const url = PhishingInput.read(input);
        const scanController = new AbortController();
        let timeoutId = null;

        if (error) {
          error.textContent = '';
          error.hidden = true;
        }
        PhishingResult.clear(result);

        if (!PhishingInput.validate(url)) {
          PhishingModal.showError(error, 'Enter a valid http or https URL.');
          input?.focus();
          return;
        }

        PhishingButton.setLoading(phishingForm, true);
        phishingForm.dataset.scanning = '1';
        phishingForm.dataset.scanCancelled = '0';
        phishingScanController = scanController;
        timeoutId = window.setTimeout(() => scanController.abort(), 15000);
        try {
          if (!endpoint) {
            throw new Error('The phishing scanner endpoint is not configured.');
          }
          const data = await fetchPhishingScan(endpoint, url, scanController.signal);
          if (data?.status) {
            PhishingResult.render(result, data);
          } else {
            throw new Error(data?.message || 'Unable to scan the URL right now.');
          }
        } catch (scanError) {
          if (scanController.signal.aborted && phishingForm.dataset.scanCancelled === '1') {
            return;
          }
          let message = scanError.message || 'Unable to scan the URL right now.';
          if (scanError instanceof TypeError) {
            message = 'Unable to reach the phishing scanner. Please try again.';
          } else if (scanError?.name === 'AbortError') {
            message = 'The scan took too long. Please try again.';
          }
          PhishingModal.showError(error, message);
        } finally {
          if (timeoutId) {
            window.clearTimeout(timeoutId);
          }
          if (phishingScanController === scanController) {
            phishingScanController = null;
          }
          delete phishingForm.dataset.scanning;
          PhishingButton.setLoading(phishingForm, false);
        }
      });
    }
  }

  const phishingModal = document.getElementById('phishingDetectorModal');
  const openPhishingModal = (event) => {
    if (event) {
      event.preventDefault();
      event.stopPropagation();
    }
    const modal = document.getElementById('phishingDetectorModal') || phishingModal;
    if (!modal) {
      return false;
    }
    PhishingModal.reset(modal);
    openModal(modal);
    return false;
  };
  window.lexOpenPhishingDetector = openPhishingModal;
  document.querySelectorAll('.phishing-detector-trigger, [data-modal-open="phishingDetectorModal"]').forEach((button) => {
    button.addEventListener('click', openPhishingModal);
  });
  if (phishingModal) {
    phishingModal.querySelectorAll('[data-modal-close]').forEach((button) => {
      button.addEventListener('click', (event) => {
        event.preventDefault();
        closeModal(phishingModal);
        PhishingModal.reset(phishingModal);
      });
    });
  }
  const alwaysPhishingForm = document.querySelector('[data-phishing-form]');
  if (alwaysPhishingForm && alwaysPhishingForm.dataset.bound !== '1') {
    alwaysPhishingForm.dataset.bound = '1';
    alwaysPhishingForm.addEventListener('submit', async (event) => {
      event.preventDefault();
      if (alwaysPhishingForm.dataset.scanning === '1') {
        return;
      }
      const input = alwaysPhishingForm.querySelector('[data-phishing-input]');
      const error = alwaysPhishingForm.querySelector('[data-phishing-error]');
      const result = alwaysPhishingForm.querySelector('[data-phishing-result]');
      const endpoint = alwaysPhishingForm.dataset.endpoint || '';
      const url = PhishingInput.read(input);
      const scanController = new AbortController();
      let timeoutId = null;
      if (error) {
        error.textContent = '';
        error.hidden = true;
      }
      PhishingResult.clear(result);
      if (!PhishingInput.validate(url)) {
        PhishingModal.showError(error, 'Enter a valid http or https URL.');
        input?.focus();
        return;
      }
      PhishingButton.setLoading(alwaysPhishingForm, true);
      alwaysPhishingForm.dataset.scanning = '1';
      alwaysPhishingForm.dataset.scanCancelled = '0';
      phishingScanController = scanController;
      timeoutId = window.setTimeout(() => scanController.abort(), 15000);
      try {
        if (!endpoint) {
          throw new Error('The phishing scanner endpoint is not configured.');
        }
        const data = await fetchPhishingScan(endpoint, url, scanController.signal);
        if (data?.status) {
          PhishingResult.render(result, data);
        } else {
          throw new Error(data?.message || 'Unable to scan the URL right now.');
        }
      } catch (scanError) {
        if (scanController.signal.aborted && alwaysPhishingForm.dataset.scanCancelled === '1') {
          return;
        }
        let message = scanError.message || 'Unable to scan the URL right now.';
        if (scanError instanceof TypeError) {
          message = 'Unable to reach the phishing scanner. Please try again.';
        } else if (scanError?.name === 'AbortError') {
          message = 'The scan took too long. Please try again.';
        }
        PhishingModal.showError(error, message);
      } finally {
        if (timeoutId) {
          window.clearTimeout(timeoutId);
        }
        if (phishingScanController === scanController) {
          phishingScanController = null;
        }
        delete alwaysPhishingForm.dataset.scanning;
        PhishingButton.setLoading(alwaysPhishingForm, false);
      }
    });
  }

  clientDeleteModal = document.querySelector('[data-client-delete-modal]');
  if (clientDeleteModal) {
    const deleteForm = clientDeleteModal.querySelector('[data-client-delete-form]');
    const deleteNameField = clientDeleteModal.querySelector('[data-client-delete-name]');
    const deleteConfirmInput = clientDeleteModal.querySelector('[data-client-delete-confirm-text]');
    const deleteSubmit = clientDeleteModal.querySelector('[data-client-delete-submit]');
    const deleteError = clientDeleteModal.querySelector('[data-client-delete-error]');
    const deleteClientId = deleteForm ? deleteForm.querySelector('input[name="client_id"]') : null;
    const deleteConfirmationField = deleteForm ? deleteForm.querySelector('[data-client-delete-confirmation]') : null;
    let expectedClientName = '';

    const clearDeleteState = () => {
      expectedClientName = '';
      if (deleteNameField) deleteNameField.value = '';
      if (deleteConfirmInput) deleteConfirmInput.value = '';
      if (deleteConfirmationField) deleteConfirmationField.value = '';
      if (deleteError) deleteError.textContent = '';
    };

    const validateDeleteText = () => {
      const entered = (deleteConfirmInput?.value || '').trim();
      const normalized = entered.replace(/\s+/g, ' ').trim();
      const requiredPhrase = `${expectedClientName} DELETE`.trim();
      const matches = expectedClientName !== '' && normalized === requiredPhrase;
      if (deleteConfirmationField) {
        deleteConfirmationField.value = normalized;
      }
      if (deleteError) {
        deleteError.textContent = entered !== '' && !matches ? 'Type the exact client name followed by DELETE.' : '';
      }
      return matches;
    };

    document.querySelectorAll('[data-client-delete-open]').forEach((button) => {
      button.addEventListener('click', () => {
        if (deleteClientId) {
          deleteClientId.value = button.dataset.clientId || '';
        }
        clearDeleteState();
        expectedClientName = button.dataset.clientName || '';
        if (deleteNameField) {
          deleteNameField.value = expectedClientName;
        }
        openModal(clientDeleteModal);
        validateDeleteText();
        if (deleteConfirmInput) {
          setTimeout(() => deleteConfirmInput.focus(), 50);
        }
      });
    });

    clientDeleteModal.querySelectorAll('[data-client-delete-close]').forEach((button) => {
      button.addEventListener('click', (event) => {
        event.preventDefault();
        closeModal(clientDeleteModal);
        clearDeleteState();
      });
    });

    clientDeleteModal.addEventListener('click', (event) => {
      if (event.target === clientDeleteModal) {
        closeModal(clientDeleteModal);
        clearDeleteState();
      }
    });

    if (deleteConfirmInput) {
      deleteConfirmInput.addEventListener('input', validateDeleteText);
      deleteConfirmInput.addEventListener('keyup', validateDeleteText);
      deleteConfirmInput.addEventListener('change', validateDeleteText);
    }

    if (deleteForm) {
      deleteForm.addEventListener('submit', (event) => {
        if (!validateDeleteText()) {
          event.preventDefault();
          if (deleteError && (deleteConfirmInput?.value || '').trim() === '') {
            deleteError.textContent = 'Type the client name followed by DELETE to confirm deletion.';
          }
        }
      });
    }

    if (deleteSubmit && deleteForm) {
      deleteSubmit.addEventListener('click', (event) => {
        if (!validateDeleteText()) {
          event.preventDefault();
          return;
        }
        event.preventDefault();
        deleteForm.submit();
      });
    }
  }

  if (chatShell) {
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      document.querySelectorAll('[data-modal].is-open').forEach((modal) => {
        closeModal(modal);
        resetModalState(modal);
      });
      if (clientDeleteModal && clientDeleteModal.classList.contains('is-open')) {
        closeModal(clientDeleteModal);
      }
    }
  });

  const composer = chatShell.querySelector('[data-inbox-composer]');
  if (composer) {
    const textarea = composer.querySelector('textarea');
    const fileInput = composer.querySelector('[data-attachment-input]');
    const resizeComposer = () => {
      if (!(textarea instanceof HTMLTextAreaElement)) return;
      textarea.style.height = 'auto';
      textarea.style.height = `${Math.min(Math.max(textarea.scrollHeight, 36), 120)}px`;
    };
    if (textarea instanceof HTMLTextAreaElement) {
      textarea.addEventListener('input', resizeComposer);
      textarea.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter' || event.shiftKey) return;
        event.preventDefault();
        const hasText = textarea.value.trim() !== '';
        const hasFile = Boolean(fileInput instanceof HTMLInputElement && fileInput.files && fileInput.files.length > 0);
        if (hasText || hasFile) {
          if (typeof composer.requestSubmit === 'function') {
            composer.requestSubmit();
          } else {
            composer.submit();
          }
        }
      });
      resizeComposer();
    }
  }

  const attachmentInput = chatShell.querySelector('[data-attachment-input]');
  const attachmentPreview = chatShell.querySelector('[data-attachment-preview]');
  if (attachmentInput && attachmentPreview) {
    attachmentInput.addEventListener('change', () => {
      attachmentPreview.innerHTML = '';
      const files = Array.from(attachmentInput.files || []);
      if (!files.length) {
        attachmentPreview.hidden = true;
        return;
      }
      files.forEach((file) => {
        const item = document.createElement('div');
        item.className = 'attachment-chip';
        item.textContent = `${file.name} - ${Math.max(1, Math.round(file.size / 1024))} KB`;
        attachmentPreview.appendChild(item);
      });
      attachmentPreview.hidden = false;
    });
  }

  const newMessageModal = document.querySelector('#newMessageModal');
  const newMessageForm = document.querySelector('[data-new-message-form]');
  if (newMessageModal && newMessageForm) {
    const caseInput = newMessageModal.querySelector('[data-default-case-input]');
    const recipientSelect = newMessageModal.querySelector('[data-recipient-select]');
    const messageInput = newMessageModal.querySelector('[data-message-input]');
    const errorBox = newMessageModal.querySelector('[data-modal-errors]');
    const recipientRole = newMessageModal.querySelector('[name="new_recipient_role"]');
    const attachmentButton = newMessageModal.querySelector('[data-modal-attachment-button]');
    const attachmentInputNew = newMessageModal.querySelector('[data-modal-attachment-input]');
    const attachmentName = newMessageModal.querySelector('[data-modal-attachment-name]');

    newMessageModal.querySelectorAll('[data-modal-close]').forEach((button) => {
      button.addEventListener('click', (event) => {
        event.preventDefault();
        closeModal(newMessageModal);
        resetNewMessageModal(newMessageModal);
      });
    });

    if (attachmentButton && attachmentInputNew && attachmentButton.tagName !== 'LABEL') {
      attachmentButton.addEventListener('click', () => attachmentInputNew.click());
    }
    if (attachmentInputNew) {
      attachmentInputNew.addEventListener('change', () => {
        renderAttachmentPreview(attachmentName, Array.from(attachmentInputNew.files || []));
      });
    }

    if (recipientSelect && recipientRole) {
      const syncRecipientRole = () => {
        const selected = recipientSelect.selectedOptions[0];
        recipientRole.value = selected?.dataset.role || recipientRole.value || '';
      };
      recipientSelect.addEventListener('change', syncRecipientRole);
      syncRecipientRole();
    }

    if (recipientSelect && caseInput) {
      const syncCaseToRecipient = () => {
        const selected = recipientSelect.selectedOptions[0];
        const linkedCaseId = selected?.dataset.caseId || '';
        caseInput.value = linkedCaseId;
      };
      recipientSelect.addEventListener('change', syncCaseToRecipient);
      syncCaseToRecipient();
    }

    newMessageForm.addEventListener('submit', (event) => {
      const errors = [];
      const recipient = recipientSelect?.value || '';
      const message = messageInput?.value.trim() || '';
      const hasAttachment = Boolean(attachmentInputNew?.files && attachmentInputNew.files.length > 0);
      if (!recipient) errors.push('Select a recipient first.');
      if (!message && !hasAttachment) errors.push('Message or attachment is required.');
      if (errorBox) {
        errorBox.textContent = errors.join(' ');
        errorBox.hidden = errors.length === 0;
      }
      if (errors.length) {
        event.preventDefault();
      }
    });
  }

  const typingIndicator = chatShell.querySelector('[data-typing-indicator]');
  const activeConversation = chatShell.getAttribute('data-active-conversation');
  const composerTextarea = chatShell.querySelector('[data-inbox-composer] textarea');
  if (typingIndicator && activeConversation === '1' && composerTextarea) {
    const recipientInput = chatShell.querySelector('[data-inbox-composer] [name="recipient_id"]');
    const peerId = recipientInput ? recipientInput.value : '';
    if (peerId) {
      const typingUrl = document.body.getAttribute('data-call-ring-url')
        ? document.body.getAttribute('data-call-ring-url').replace('chat_call_ring.php', 'chat_typing.php')
        : 'chat_typing.php';
      let typingTimeout = null;
      let lastTypingSent = 0;
      composerTextarea.addEventListener('input', () => {
        const now = Date.now();
        if (now - lastTypingSent > 2000) {
          lastTypingSent = now;
          fetch(typingUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ with: Number(peerId), typing: true }),
          }).catch(() => {});
        }
        if (typingTimeout) clearTimeout(typingTimeout);
        typingTimeout = setTimeout(() => {
          fetch(typingUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ with: Number(peerId), typing: false }),
          }).catch(() => {});
        }, 4000);
      });
      const pollTyping = () => {
        fetch(`${typingUrl}?with=${peerId}`, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
          .then((r) => r.json())
          .then((data) => {
            if (data && data.typing) {
              typingIndicator.textContent = 'typing…';
              typingIndicator.hidden = false;
            } else {
              typingIndicator.hidden = true;
            }
          })
          .catch(() => {});
      };
      pollTyping();
      setInterval(pollTyping, 2000);
    }
  }
  }
})();

