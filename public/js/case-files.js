(() => {
    const caseFileClientSelect = document.querySelector('[data-casefile-client-select]');
    const caseFileFullName = document.querySelector('[data-casefile-fullname]');
    let clientDeleteModal = null;
    if (caseFileClientSelect && caseFileFullName) {
      const syncCaseFileName = () => {
        const selected = caseFileClientSelect.selectedOptions[0];
        if (selected) {
          const selectedName = selected.textContent.replace(/\s*-\s*Client\s*$/i, '').trim();
        if (caseFileFullName.value.trim() === '' || caseFileFullName.dataset.autofill === '1') {
          caseFileFullName.value = selectedName;
          caseFileFullName.dataset.autofill = '1';
        }
      }
    };
    caseFileClientSelect.addEventListener('change', syncCaseFileName);
    caseFileFullName.addEventListener('input', () => {
      caseFileFullName.dataset.autofill = caseFileFullName.value.trim() === '' ? '1' : '0';
    });
    syncCaseFileName();
  }

  const caseFilesApp = document.querySelector('[data-case-files-app]');
  if (caseFilesApp) {
    const endpoint = caseFilesApp.dataset.endpoint || '';
    const summaryContainer = document.querySelector('[data-case-summary-container]');
    const listContainer = document.querySelector('[data-case-list-container]');
    const detailContainer = document.querySelector('[data-case-detail-container]');
    const vaultContainer = document.querySelector('[data-case-vault-container]');
    const activityContainer = document.querySelector('[data-case-activity-container]');
    const filterForm = document.querySelector('[data-case-filter-form]');
    const searchStatus = document.querySelector('[data-case-search-status]');
    const editorContainer = document.querySelector('[data-case-editor-container]');
    const searchInput = filterForm ? filterForm.querySelector('input[name="q"]') : null;
    const sortViewInput = filterForm ? filterForm.querySelector('[data-case-sort-view]') : null;
    const hiddenSortInput = filterForm ? filterForm.querySelector('[data-case-sort-hidden]') : null;
    const hiddenDirInput = filterForm ? filterForm.querySelector('[data-case-dir-hidden]') : null;
    const trackedInputs = filterForm ? Array.from(filterForm.querySelectorAll('input[name="q"], select[name="status"], select[name="sort_view"]')) : [];
    const scrollVaultIntoView = () => {
      const section = document.querySelector('[data-case-vault-container]');
      if (section) {
        section.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }
    };
    let searchTimer = null;
    let activeDetailModal = null;
    let activeCreateModal = null;
    let activeEditModal = null;

    const syncSortFields = (sort, dir) => {
      if (hiddenSortInput) hiddenSortInput.value = sort || 'updated_at';
      if (hiddenDirInput) hiddenDirInput.value = dir || 'desc';
      if (sortViewInput) {
        sortViewInput.value = `${sort || 'updated_at'}_${dir || 'desc'}`;
      }
    };

    const readFilters = () => ({
      q: searchInput ? searchInput.value.trim() : '',
      status: filterForm ? (filterForm.querySelector('select[name="status"]')?.value || 'all') : 'all',
      sort: hiddenSortInput ? (hiddenSortInput.value || 'updated_at') : 'updated_at',
      dir: hiddenDirInput ? (hiddenDirInput.value || 'desc') : 'desc',
      page: 1,
      record: 0,
    });

    const readStateFromUrl = () => {
      const params = new URLSearchParams(window.location.search);
      return {
        q: params.get('q') || '',
        status: params.get('status') || 'all',
        sort: params.get('sort') || 'updated_at',
        dir: params.get('dir') || 'desc',
        page: Math.max(1, parseInt(params.get('page') || '1', 10) || 1),
        record: Math.max(0, parseInt(params.get('record') || '0', 10) || 0),
      };
    };

    const buildUrl = (state) => {
      const url = new URL(endpoint, window.location.href);
      url.searchParams.set('format', 'json');
      if (state.q) url.searchParams.set('q', state.q); else url.searchParams.delete('q');
      if (state.status && state.status !== 'all') url.searchParams.set('status', state.status); else url.searchParams.delete('status');
      if (state.sort && state.sort !== 'updated_at') url.searchParams.set('sort', state.sort); else url.searchParams.delete('sort');
      if (state.dir && state.dir !== 'desc') url.searchParams.set('dir', state.dir); else url.searchParams.delete('dir');
      if (state.page && state.page > 1) url.searchParams.set('page', String(state.page)); else url.searchParams.delete('page');
      if (state.record && state.record > 0) url.searchParams.set('record', String(state.record)); else url.searchParams.delete('record');
      return url;
    };

    const syncUrl = (state, mode) => {
      const url = new URL(window.location.href);
      if (state.q) url.searchParams.set('q', state.q); else url.searchParams.delete('q');
      if (state.status && state.status !== 'all') url.searchParams.set('status', state.status); else url.searchParams.delete('status');
      if (state.sort && state.sort !== 'updated_at') url.searchParams.set('sort', state.sort); else url.searchParams.delete('sort');
      if (state.dir && state.dir !== 'desc') url.searchParams.set('dir', state.dir); else url.searchParams.delete('dir');
      if (state.page && state.page > 1) url.searchParams.set('page', String(state.page)); else url.searchParams.delete('page');
      if (state.record && state.record > 0) url.searchParams.set('record', String(state.record)); else url.searchParams.delete('record');
      url.searchParams.delete('format');
      if (mode === 'push') {
        history.pushState({}, '', url);
      } else {
        history.replaceState({}, '', url);
      }
    };

    const updateFilterForm = (state) => {
      if (!filterForm) return;
      const q = filterForm.querySelector('input[name="q"]');
      const status = filterForm.querySelector('select[name="status"]');
      const page = filterForm.querySelector('input[name="page"]');
      if (q && q.value !== state.q) q.value = state.q || '';
      if (status && status.value !== state.status) status.value = state.status || 'all';
      syncSortFields(state.sort || 'updated_at', state.dir || 'desc');
      if (page) page.value = String(state.page || 1);
    };

    const setBusy = (isBusy, message) => {
      const text = message || (isBusy ? 'Loading case files...' : 'Ready.');
      if (searchStatus) searchStatus.textContent = text;
      [summaryContainer, listContainer, detailContainer, activityContainer].forEach((node) => {
        if (node) node.setAttribute('aria-busy', isBusy ? 'true' : 'false');
      });
    };

    let detailModalReturnFocus = null;
    let createModalReturnFocus = null;
    let editModalReturnFocus = null;

    const captureModalReturnFocus = () => {
      const active = document.activeElement;
      return active instanceof HTMLElement ? active : null;
    };

    const restoreModalFocus = (modal, fallback) => {
      const active = document.activeElement;
      if (active instanceof HTMLElement && modal && modal.contains(active)) {
        active.blur();
      }
      if (fallback instanceof HTMLElement && document.contains(fallback)) {
        window.setTimeout(() => fallback.focus(), 0);
      }
    };

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

    const openDetailModal = () => {
      const modal = document.querySelector('[data-case-detail-modal]');
      if (!modal) return;
      if (modal.classList.contains('is-open')) return;
      detailModalReturnFocus = captureModalReturnFocus();
      activeDetailModal = modal;
      modal.classList.add('is-open');
      modal.setAttribute('aria-hidden', 'false');
      setModalInertSiblings(modal, true);
      modal.dataset.lexModalInertApplied = '1';
      const focusTarget = modal.querySelector('[data-case-detail-close]') || modal.querySelector('button, a, input, select, textarea');
      if (focusTarget) {
        window.setTimeout(() => focusTarget.focus(), 0);
      }
    };

    const closeDetailModal = () => {
      if (!activeDetailModal) {
        const modal = document.querySelector('[data-case-detail-modal]');
        if (!modal) return;
        activeDetailModal = modal;
      }
      restoreModalFocus(activeDetailModal, detailModalReturnFocus);
      activeDetailModal.classList.remove('is-open');
      activeDetailModal.setAttribute('aria-hidden', 'true');
      if (activeDetailModal.dataset.lexModalInertApplied === '1') {
        setModalInertSiblings(activeDetailModal, false);
        delete activeDetailModal.dataset.lexModalInertApplied;
      }
      detailModalReturnFocus = null;
    };

    const openCreateModal = () => {
      const modal = document.querySelector('[data-case-create-modal]');
      if (!modal) return;
      if (modal.classList.contains('is-open')) return;
      createModalReturnFocus = captureModalReturnFocus();
      activeCreateModal = modal;
      modal.classList.add('is-open');
      modal.setAttribute('aria-hidden', 'false');
      setModalInertSiblings(modal, true);
      modal.dataset.lexModalInertApplied = '1';
      const focusTarget = modal.querySelector('[data-case-create-close]') || modal.querySelector('button, a, input, select, textarea');
      if (focusTarget) {
        window.setTimeout(() => focusTarget.focus(), 0);
      }
    };

    const closeCreateModal = () => {
      if (!activeCreateModal) {
        const modal = document.querySelector('[data-case-create-modal]');
        if (!modal) return;
        activeCreateModal = modal;
      }
      restoreModalFocus(activeCreateModal, createModalReturnFocus);
      activeCreateModal.classList.remove('is-open');
      activeCreateModal.setAttribute('aria-hidden', 'true');
      if (activeCreateModal.dataset.lexModalInertApplied === '1') {
        setModalInertSiblings(activeCreateModal, false);
        delete activeCreateModal.dataset.lexModalInertApplied;
      }
      createModalReturnFocus = null;
    };

    const dismissDetailModalForEdit = () => {
      if (!activeDetailModal) {
        const modal = document.querySelector('[data-case-detail-modal]');
        if (!modal || !modal.classList.contains('is-open')) return;
        activeDetailModal = modal;
      }
      activeDetailModal.classList.remove('is-open');
      activeDetailModal.setAttribute('aria-hidden', 'true');
      if (activeDetailModal.dataset.lexModalInertApplied === '1') {
        setModalInertSiblings(activeDetailModal, false);
        delete activeDetailModal.dataset.lexModalInertApplied;
      }
      detailModalReturnFocus = null;
    };

    const openEditModal = (data = {}) => {
      const modal = document.querySelector('[data-case-edit-modal]');
      if (!modal) return;
      if (modal.classList.contains('is-open')) return;
      editModalReturnFocus = captureModalReturnFocus();
      dismissDetailModalForEdit();
      activeEditModal = modal;
      const idField = modal.querySelector('[data-case-edit-id]');
      const fullNameField = modal.querySelector('[data-case-edit-full-name]');
      const titleField = modal.querySelector('[data-case-edit-case-title]');
      const descriptionField = modal.querySelector('[data-case-edit-description]');
      const clientField = modal.querySelector('[data-case-edit-client]');
      const lawyerField = modal.querySelector('[data-case-edit-lawyer]');
      const statusField = modal.querySelector('[data-case-edit-status]');
      if (idField) idField.value = data.caseId || '';
      if (fullNameField) fullNameField.value = data.fullName || '';
      if (titleField) titleField.value = data.caseFileTitle || '';
      if (descriptionField) descriptionField.value = data.description || '';
      if (clientField && data.clientUserId && data.clientUserId !== '0') clientField.value = data.clientUserId;
      if (lawyerField && data.assignedLawyerUserId && data.assignedLawyerUserId !== '0') lawyerField.value = data.assignedLawyerUserId;
      if (statusField && data.status) statusField.value = data.status;
      modal.classList.add('is-open');
      modal.setAttribute('aria-hidden', 'false');
      setModalInertSiblings(modal, true);
      modal.dataset.lexModalInertApplied = '1';
      const focusTarget = modal.querySelector('[data-case-edit-full-name]') || modal.querySelector('button, a, input, select, textarea');
      if (focusTarget) {
        window.setTimeout(() => focusTarget.focus(), 0);
      }
    };

    const closeEditModal = () => {
      if (!activeEditModal) {
        const modal = document.querySelector('[data-case-edit-modal]');
        if (!modal) return;
        activeEditModal = modal;
      }
      restoreModalFocus(activeEditModal, editModalReturnFocus);
      activeEditModal.classList.remove('is-open');
      activeEditModal.setAttribute('aria-hidden', 'true');
      if (activeEditModal.dataset.lexModalInertApplied === '1') {
        setModalInertSiblings(activeEditModal, false);
        delete activeEditModal.dataset.lexModalInertApplied;
      }
      editModalReturnFocus = null;
    };

    const bindDetailModal = () => {
      const modal = document.querySelector('[data-case-detail-modal]');
      if (!modal) return;
      modal.querySelectorAll('[data-case-detail-close]').forEach((button) => {
        button.addEventListener('click', (event) => {
          event.preventDefault();
          closeDetailModal();
        });
      });
      modal.addEventListener('click', (event) => {
        if (event.target === modal) {
          closeDetailModal();
        }
      });
    };

    const bindCreateModal = () => {
      const modal = document.querySelector('[data-case-create-modal]');
      if (!modal) return;
      modal.querySelectorAll('[data-case-create-close]').forEach((button) => {
        button.addEventListener('click', (event) => {
          event.preventDefault();
          closeCreateModal();
        });
      });
      modal.addEventListener('click', (event) => {
        if (event.target === modal) {
          closeCreateModal();
        }
      });
    };

    const bindEditModal = () => {
      const modal = document.querySelector('[data-case-edit-modal]');
      if (!modal) return;
      modal.querySelectorAll('[data-case-edit-close]').forEach((button) => {
        button.addEventListener('click', (event) => {
          event.preventDefault();
          closeEditModal();
        });
      });
      modal.addEventListener('click', (event) => {
        if (event.target === modal) {
          closeEditModal();
        }
      });
    };

    const bindPersistedForms = () => {
      Array.from(document.querySelectorAll('[data-persist-form]')).forEach((form) => {
        const key = `lex-case-files:${form.dataset.persistForm}`;
        const fields = Array.from(form.querySelectorAll('input, select, textarea')).filter((field) => {
          if (!field.name) return false;
          if (field.type === 'file' || field.type === 'hidden') return false;
          if (field.name === 'csrf_token') return false;
          return true;
        });
        const loadDraft = () => {
          try {
            const raw = localStorage.getItem(key);
            if (!raw) return;
            const draft = JSON.parse(raw);
            fields.forEach((field) => {
              if (!(field.name in draft)) return;
              if (field.type === 'checkbox') {
                field.checked = Boolean(draft[field.name]);
              } else {
                field.value = draft[field.name];
              }
            });
          } catch (error) {
            console.debug('Unable to restore case file draft.');
          }
        };
        const saveDraft = () => {
          try {
            const draft = {};
            fields.forEach((field) => {
              draft[field.name] = field.type === 'checkbox' ? field.checked : field.value;
            });
            localStorage.setItem(key, JSON.stringify(draft));
          } catch (error) {
            console.debug('Unable to store case file draft.');
          }
        };
        loadDraft();
        fields.forEach((field) => {
          field.addEventListener('input', saveDraft);
          field.addEventListener('change', saveDraft);
        });
        form.addEventListener('reset', () => {
          localStorage.removeItem(key);
        });
      });
    };

    const bindValidationForms = () => {
      Array.from(document.querySelectorAll('[data-casefile-form]')).forEach((form) => {
        const fields = Array.from(form.querySelectorAll('input, select, textarea'));
        const validate = () => {
          const errors = [];
          const fullName = form.querySelector('[name="full_name"]');
          const caseFileTitle = form.querySelector('[name="case_file_title"]');
          const client = form.querySelector('[name="client_user_id"]');
          const lawyer = form.querySelector('[name="assigned_lawyer_user_id"]');
          const status = form.querySelector('[name="status"]');
          const errorBox = form.querySelector('[data-form-errors]');
          const mark = (field, invalid) => {
            if (field) field.setAttribute('aria-invalid', invalid ? 'true' : 'false');
          };
          if (fullName && !fullName.value.trim()) { errors.push('FULLNAME is required.'); mark(fullName, true); } else { mark(fullName, false); }
          if (caseFileTitle && !caseFileTitle.value.trim()) { errors.push('CASE FILE is required.'); mark(caseFileTitle, true); } else { mark(caseFileTitle, false); }
          if (client && !client.value) { errors.push('Select a client.'); mark(client, true); } else { mark(client, false); }
          if (lawyer && !lawyer.value) { errors.push('Select an assigned lawyer.'); mark(lawyer, true); } else { mark(lawyer, false); }
          if (status && !status.value) { errors.push('Select a status.'); mark(status, true); } else { mark(status, false); }
          if (errorBox) {
            errorBox.textContent = errors.join(' ');
            errorBox.hidden = errors.length === 0;
          }
          return errors.length === 0;
        };

        fields.forEach((field) => {
          field.addEventListener('input', validate);
          field.addEventListener('change', validate);
        });
        form.addEventListener('submit', (event) => {
          if (!validate()) {
            event.preventDefault();
          }
        });
        validate();
      });
    };

    const bindVaultFolders = () => {
      const folders = Array.from(document.querySelectorAll('.case-vault-folder-section'));
      if (!folders.length) return;
      folders.forEach((folder) => {
        folder.addEventListener('toggle', () => {
          if (!folder.open) return;
          folders.forEach((other) => {
            if (other !== folder) {
              other.open = false;
            }
          });
        });
      });
    };

    const swapContent = (data) => {
      closeDetailModal();
      closeCreateModal();
      closeEditModal();
      if (summaryContainer && data.summaryHtml) summaryContainer.innerHTML = data.summaryHtml;
      if (listContainer && data.listHtml) listContainer.innerHTML = data.listHtml;
      if (detailContainer && data.detailHtml) detailContainer.innerHTML = data.detailHtml;
      if (vaultContainer && data.vaultHtml) vaultContainer.innerHTML = data.vaultHtml;
      if (activityContainer && data.activityHtml) activityContainer.innerHTML = data.activityHtml;
      if (editorContainer && data.editorHtml !== undefined) editorContainer.innerHTML = data.editorHtml;
      const pagination = document.querySelector('[data-case-pagination-container]');
      if (pagination && data.paginationHtml) pagination.innerHTML = data.paginationHtml;
      bindDetailModal();
      bindCreateModal();
      bindEditModal();
      bindPersistedForms();
      bindValidationForms();
      bindVaultFolders();
    };

    const fetchState = async (state, options = {}) => {
      if (!endpoint) return;
      const nextState = {
        q: state.q || '',
        status: state.status || 'all',
        sort: state.sort || 'updated_at',
        dir: state.dir || 'desc',
        page: Math.max(1, parseInt(state.page || 1, 10) || 1),
        record: Math.max(0, parseInt(state.record || 0, 10) || 0),
      };
      const url = buildUrl(nextState);
      setBusy(true, 'Loading case files...');
      try {
        const response = await fetch(url.toString(), { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        if (!response.ok) {
          throw new Error('Request failed');
        }
        const data = await response.json();
        if (!data.ok) {
          throw new Error('Invalid response');
        }
        swapContent(data);
        const meta = data.meta || {};
        const syncedState = {
          q: meta.search || nextState.q,
          status: meta.status || nextState.status,
          sort: meta.sort || nextState.sort,
          dir: meta.dir || nextState.dir,
          page: meta.page || nextState.page,
          record: meta.selectedId || nextState.record,
        };
        updateFilterForm(syncedState);
        syncUrl(syncedState, options.push ? 'push' : 'replace');
        setBusy(false, `Showing ${meta.total || 0} case file${(meta.total || 0) === 1 ? '' : 's'}.`);
        if (options.openDetail) {
          openDetailModal();
        } else if (options.openVault) {
          scrollVaultIntoView();
        } else if (options.openCreate) {
          openCreateModal();
        }
      } catch (error) {
        setBusy(false, 'Unable to load case files right now.');
      }
    };

    if (filterForm) {
      filterForm.addEventListener('submit', (event) => {
        event.preventDefault();
        fetchState({
          ...readFilters(),
          page: 1,
          record: 0,
        }, { push: true });
      });
    }

    trackedInputs.forEach((input) => {
      if (input.name === 'q') {
        input.addEventListener('input', () => {
          window.clearTimeout(searchTimer);
          searchTimer = window.setTimeout(() => {
            fetchState({
              ...readFilters(),
              page: 1,
              record: 0,
            }, { push: false });
          }, 220);
        });
      } else {
        input.addEventListener('change', () => {
          if (input === sortViewInput) {
            const rawValue = String(input.value || 'updated_at_desc');
            const splitIndex = rawValue.lastIndexOf('_');
            const sort = splitIndex > 0 ? rawValue.slice(0, splitIndex) : 'updated_at';
            const dir = splitIndex > 0 ? rawValue.slice(splitIndex + 1) : 'desc';
            syncSortFields(sort, dir);
          }
          fetchState({
            ...readFilters(),
            page: 1,
            record: 0,
          }, { push: true });
        });
      }
    });

    document.addEventListener('click', (event) => {
      const createTrigger = event.target.closest('[data-case-create-open]');
      if (createTrigger) {
        event.preventDefault();
        openCreateModal();
        return;
      }

      const editTrigger = event.target.closest('[data-case-edit-open]');
      if (editTrigger) {
        event.preventDefault();
        openEditModal(editTrigger.dataset);
        return;
      }

      const rowTrigger = event.target.closest('[data-case-select]');
      if (rowTrigger) {
        event.preventDefault();
        const recordId = parseInt(rowTrigger.dataset.caseId || '0', 10) || 0;
        if (!recordId) return;
        fetchState({
          ...readFilters(),
          page: Math.max(1, parseInt(readStateFromUrl().page || 1, 10) || 1),
          record: recordId,
        }, { push: true, openDetail: true });
        return;
      }

      const vaultTrigger = event.target.closest('[data-case-open-vault]');
      if (vaultTrigger) {
        event.preventDefault();
        const recordId = parseInt(vaultTrigger.dataset.caseId || '0', 10) || 0;
        if (!recordId) return;
        fetchState({
          ...readFilters(),
          page: Math.max(1, parseInt(readStateFromUrl().page || 1, 10) || 1),
          record: recordId,
        }, { push: true, openVault: true });
        return;
      }

      const pageTrigger = event.target.closest('[data-case-page]');
      if (pageTrigger) {
        event.preventDefault();
        const page = parseInt(pageTrigger.dataset.casePage || '1', 10) || 1;
        fetchState({
          ...readFilters(),
          page,
          record: 0,
        }, { push: true });
        return;
      }

      const resetTrigger = event.target.closest('[data-case-reset]');
      if (resetTrigger) {
        event.preventDefault();
        fetchState({
          q: '',
          status: 'all',
          sort: 'updated_at',
          dir: 'desc',
          page: 1,
          record: 0,
        }, { push: true });
      }
    });

    window.addEventListener('popstate', () => {
      fetchState(readStateFromUrl(), { push: false });
    });

    const initial = window.LEX_CASE_FILES_STATE || readStateFromUrl();
    updateFilterForm(initial);
    bindDetailModal();
    bindCreateModal();
    bindEditModal();

    bindPersistedForms();
    bindValidationForms();
    bindVaultFolders();

    if (initial.failedAction === 'create' && initial.error) {
      openCreateModal();
    } else if (initial.failedAction === 'update' && initial.error) {
      openEditModal();
    }
  }

})();
