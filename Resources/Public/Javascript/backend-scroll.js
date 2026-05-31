(function () {
  'use strict';

  var key = 'ppl-deepl-v3-batch-scroll';
  var resizeTimer = 0;

  function getScroller() {
    return document.querySelector('.module')
      || document.querySelector('.module-body')
      || document.querySelector('.t3js-module-body')
      || document.scrollingElement
      || document.documentElement
      || document.body;
  }

  function updatePanelHeight(root) {
    var workspace = root.querySelector('.ppl-batch__workspace-grid')
      || root.querySelector('.ppl-batch__workspace-main')
      || root.querySelector('.ppl-batch__panel');
    if (!workspace) {
      return;
    }

    var viewportHeight = window.visualViewport && window.visualViewport.height
      ? window.visualViewport.height
      : window.innerHeight;
    var rect = workspace.getBoundingClientRect();
    var available = Math.floor(viewportHeight - rect.top - 20);
    var minimum = window.matchMedia('(max-width: 1280px)').matches ? 220 : 260;
    var panelHeight = available > minimum ? available : Math.max(140, available);
    root.style.setProperty('--ppl-batch-panel-height', panelHeight + 'px');
  }

  function schedulePanelHeightUpdate(root) {
    window.clearTimeout(resizeTimer);
    resizeTimer = window.setTimeout(function () {
      updatePanelHeight(root);
    }, 60);
  }

  function parseJsonData(root, role) {
    var node = root.querySelector('[data-role="' + role + '"]');
    if (!node) {
      return {};
    }

    try {
      return JSON.parse(node.textContent || '{}') || {};
    } catch (error) {
      return {};
    }
  }

  function selectedOption(select) {
    if (!select || select.selectedIndex < 0) {
      return null;
    }

    return select.options[select.selectedIndex] || null;
  }

  function selectedDeeplCode(select, attributeName) {
    var option = selectedOption(select);
    return option ? String(option.getAttribute(attributeName) || '') : '';
  }

  function normalizeGlossaryLanguage(language) {
    language = String(language || '').toUpperCase().replace('_', '-');
    if (language === 'DE-DE') {
      return 'DE';
    }
    if (language.indexOf('EN-') === 0) {
      return 'EN';
    }
    if (language.indexOf('PT-') === 0) {
      return 'PT';
    }
    if (language.indexOf('ES-') === 0) {
      return 'ES';
    }
    if (language === 'ZH-HANS' || language === 'ZH-HANT') {
      return 'ZH';
    }
    if (language.indexOf('-') !== -1) {
      return language.split('-', 1)[0];
    }

    return language;
  }

  function normalizeStyleRuleLanguage(language) {
    language = String(language || '').toUpperCase().replace('_', '-');
    if (language.indexOf('EN') === 0) {
      return 'EN';
    }
    if (language === 'DE' || language === 'DE-DE') {
      return 'DE';
    }
    if (language.indexOf('ES') === 0) {
      return 'ES';
    }
    if (language.indexOf('FR') === 0) {
      return 'FR';
    }
    if (language.indexOf('IT') === 0) {
      return 'IT';
    }
    if (language.indexOf('JA') === 0) {
      return 'JA';
    }
    if (language.indexOf('KO') === 0) {
      return 'KO';
    }
    if (language.indexOf('ZH') === 0) {
      return 'ZH';
    }

    return '';
  }

  function replaceSelectOptions(select, options, emptyLabel, unavailableLabel) {
    if (!select) {
      return;
    }

    options = options || {};
    var selectedValue = select.value;
    var optionIds = Object.keys(options);
    var empty = document.createElement('option');
    empty.value = '';
    empty.textContent = optionIds.length > 0 ? emptyLabel : unavailableLabel;

    select.innerHTML = '';
    select.appendChild(empty);

    optionIds.forEach(function (id) {
      var option = document.createElement('option');
      option.value = id;
      option.textContent = String(options[id] || id);
      select.appendChild(option);
    });

    select.disabled = optionIds.length === 0;
    select.value = selectedValue !== '' && Object.prototype.hasOwnProperty.call(options, selectedValue)
      ? selectedValue
      : '';
  }

  function updateTranslationResourceSelects(root) {
    var source = root.querySelector('[data-role="batch-source-language"]');
    var target = root.querySelector('[data-role="batch-target-language"]');
    var glossary = root.querySelector('[data-role="batch-glossary-select"]');
    var styleRule = root.querySelector('[data-role="batch-style-rule-select"]');
    var glossaryOptionsByCombination = parseJsonData(root, 'batch-glossary-options');
    var styleRuleOptionsByLanguage = parseJsonData(root, 'batch-style-rule-options-by-language');

    if (glossary && source && target) {
      var sourceLanguage = normalizeGlossaryLanguage(selectedDeeplCode(source, 'data-deepl-source'));
      var targetLanguage = normalizeGlossaryLanguage(selectedDeeplCode(target, 'data-deepl-target'));
      var glossaryOptions = glossaryOptionsByCombination[sourceLanguage + ':' + targetLanguage] || {};
      replaceSelectOptions(
        glossary,
        glossaryOptions,
        glossary.getAttribute('data-empty-label') || 'No glossary',
        glossary.getAttribute('data-unavailable-label') || 'No approved glossary'
      );
    }

    if (styleRule && target) {
      var styleRuleLanguage = normalizeStyleRuleLanguage(selectedDeeplCode(target, 'data-deepl-target'));
      var styleRuleOptions = styleRuleOptionsByLanguage[styleRuleLanguage] || {};
      replaceSelectOptions(
        styleRule,
        styleRuleOptions,
        styleRule.getAttribute('data-empty-label') || 'No style rule',
        styleRule.getAttribute('data-unavailable-label') || 'No approved style rule'
      );
    }
  }

  function announce(root, message) {
    var liveRegion = root.querySelector('[data-role="batch-live-region"]');
    if (!liveRegion || !message) {
      return;
    }
    liveRegion.textContent = '';
    window.setTimeout(function () {
      liveRegion.textContent = message;
    }, 20);
  }

  function focusWorkspace(root) {
    var form = root.querySelector('form');
    if (!form || form.getAttribute('data-focus-workspace') !== '1') {
      return;
    }
    var target = root.querySelector('#ppl-batch-main');
    if (target && typeof target.focus === 'function') {
      window.setTimeout(function () {
        target.focus({ preventScroll: true });
      }, 80);
    }
  }

  function scrollActiveStepIntoView(root) {
    var activeStep = root.querySelector('[aria-current="step"]');
    var scroller = activeStep ? activeStep.closest('.ppl-batch__workflow') : null;
    if (!activeStep || !scroller) {
      return;
    }
    scroller.scrollLeft = Math.max(0, activeStep.offsetLeft - ((scroller.clientWidth - activeStep.offsetWidth) / 2));
  }

  function initializeSetupDisclosure(root) {
    root.querySelectorAll('[data-role="translation-setup"]').forEach(function (details) {
      if (details.getAttribute('data-default-open') === '0') {
        details.removeAttribute('open');
      }
      details.addEventListener('toggle', function () {
        schedulePanelHeightUpdate(root);
      });
    });
  }

  function hasUnappliedPreview(root) {
    var form = root.querySelector('form');
    return form && form.getAttribute('data-has-preview') === '1';
  }

  function confirmationText(root, attributeName, fallback) {
    var form = root.querySelector('form');
    return form ? String(form.getAttribute(attributeName) || fallback) : fallback;
  }

  function initializePreviewInvalidationWarnings(root) {
    root.querySelectorAll('[data-preview-invalidates="1"]').forEach(function (control) {
      var previousValue = control.value;
      control.addEventListener('change', function (event) {
        if (!hasUnappliedPreview(root)) {
          previousValue = control.value;
          return;
        }

        if (!window.confirm(confirmationText(root, 'data-confirm-discard-preview', 'You have unapplied translation previews. Changing options will discard them. Continue?'))) {
          control.value = previousValue;
          event.preventDefault();
          event.stopImmediatePropagation();
          return;
        }

        previousValue = control.value;
      }, true);
    });
  }

  function initializeTranslationResourceControls(root) {
    var source = root.querySelector('[data-role="batch-source-language"]');
    var target = root.querySelector('[data-role="batch-target-language"]');

    [source, target].forEach(function (select) {
      if (!select) {
        return;
      }

      select.addEventListener('change', function () {
        updateTranslationResourceSelects(root);
      });
    });

    updateTranslationResourceSelects(root);
  }

  function readState() {
    try {
      return JSON.parse(window.sessionStorage.getItem(key) || '{}');
    } catch (error) {
      return {};
    }
  }

  function writeState(anchor) {
    var scroller = getScroller();
    try {
      window.sessionStorage.setItem(key, JSON.stringify({
        href: window.location.pathname + window.location.search,
        top: scroller ? scroller.scrollTop : 0,
        anchor: anchor || ''
      }));
    } catch (error) {
      // Ignore storage errors in restricted backend contexts.
    }
  }

  function restoreState() {
    var state = readState();
    var scroller = getScroller();
    if (!state || state.href !== window.location.pathname + window.location.search || !scroller) {
      return;
    }

    if (typeof state.top === 'number') {
      scroller.scrollTop = state.top;
    }
  }

  function applyTreeFilter(root) {
    var search = root.querySelector('[data-role="tree-search"]');
    var status = root.querySelector('[data-role="tree-status-filter"]');
    var rows = root.querySelectorAll('[data-role="page-tree"] .ppl-batch__tree-row');
    var query = search ? String(search.value || '').toLowerCase().trim() : '';
    var statusValue = status ? String(status.value || 'all') : 'all';

    rows.forEach(function (row) {
      var text = String(row.getAttribute('data-title') || '').toLowerCase();
      var searchText = String(row.getAttribute('data-search-text') || '').toLowerCase();
      var serverHit = String(row.getAttribute('data-search-hit') || '0') === '1';
      var uid = String(row.getAttribute('data-uid') || '');
      var rowStatus = String(row.getAttribute('data-status') || '');
      var rowSelected = String(row.getAttribute('data-selected') || '0') === '1';
      var rowHasContent = String(row.getAttribute('data-has-content') || '0') === '1';
      var matchesQuery = query === '' || serverHit || text.indexOf(query) !== -1 || searchText.indexOf(query) !== -1 || uid.indexOf(query) !== -1;
      var matchesStatus = statusValue === '' || statusValue === 'all' || rowStatus === statusValue;
      if (statusValue === 'selected') {
        matchesStatus = rowSelected;
      }
      if (statusValue === 'has_content') {
        matchesStatus = rowHasContent;
      }
      row.hidden = !(matchesQuery && matchesStatus);
    });
  }

  function syncBasketRemoval(button) {
    var root = button.closest('[data-module="ppl-batch-translation"]') || document;
    var row = button.closest('[data-selection-type][data-selection-value]');
    if (!row) {
      return;
    }

    var type = row.getAttribute('data-selection-type');
    var value = row.getAttribute('data-selection-value');
    row.querySelectorAll('input').forEach(function (input) {
      input.remove();
    });

    var selector = '';
    if (type === 'page') {
      selector = 'input[name="selected_pages[]"][value="' + value + '"]';
    } else if (type === 'subtree') {
      selector = 'input[name="selected_subtree_pages[]"][value="' + value + '"]';
    } else if (type === 'element') {
      selector = 'input[name="selected_elements[]"][value="' + value + '"]';
    }

    if (selector !== '') {
      root.querySelectorAll(selector).forEach(function (input) {
        input.checked = false;
        if (input.type === 'hidden') {
          input.remove();
        }
      });
    }

    row.remove();
    syncBasketFromInputs(root);
    announce(root, formLabel(root, 'selection-updated', 'Selection updated.'));
  }

  function syncExclusionRemoval(button) {
    var row = button.closest('[data-exclusion-type][data-exclusion-value]');
    if (!row) {
      return;
    }

    row.querySelectorAll('input').forEach(function (input) {
      input.remove();
    });
    row.remove();
    var root = button.closest('[data-module="ppl-batch-translation"]') || document;
    announce(root, formLabel(root, 'selection-updated', 'Selection updated.'));
  }

  function selectionType(input) {
    if (input.name === 'selected_pages[]') {
      return 'page';
    }
    if (input.name === 'selected_subtree_pages[]') {
      return 'subtree';
    }
    if (input.name === 'selected_elements[]') {
      return 'element';
    }

    return '';
  }

  function selectionName(type) {
    if (type === 'page') {
      return 'selected_pages[]';
    }
    if (type === 'subtree') {
      return 'selected_subtree_pages[]';
    }
    if (type === 'element') {
      return 'selected_elements[]';
    }

    return '';
  }

  function basketGroupConfig(root, type) {
    if (type === 'page') {
      return { role: 'basket-group-pages', title: formLabel(root, 'group-pages', 'Selected pages') };
    }
    if (type === 'subtree') {
      return { role: 'basket-group-subtrees', title: formLabel(root, 'group-subtrees', 'Selected recursive branches') };
    }
    if (type === 'element') {
      return { role: 'basket-group-elements', title: formLabel(root, 'group-elements', 'Selected elements') };
    }

    return { role: '', title: '' };
  }

  function ensureBasketGroup(root, type) {
    var basket = root.querySelector('[data-role="selection-basket"]');
    var config = basketGroupConfig(root, type);
    if (!basket || config.role === '') {
      return null;
    }

    var group = basket.querySelector('[data-role="' + config.role + '"]');
    if (group) {
      group.hidden = false;
      return group;
    }

    group = document.createElement('section');
    group.className = 'ppl-batch__basket-group';
    group.setAttribute('data-role', config.role);

    var title = document.createElement('strong');
    title.textContent = config.title;
    group.appendChild(title);

    var empty = basket.querySelector('.ppl-batch__empty');
    basket.insertBefore(group, empty || null);

    return group;
  }

  function createBasketRow(root, input, type) {
    var row = document.createElement('div');
    row.className = 'ppl-batch__basket-row';
    row.setAttribute('data-selection-type', type);
    row.setAttribute('data-selection-value', input.value);
    row.setAttribute('data-dynamic-selection', '1');

    var hidden = document.createElement('input');
    hidden.type = 'hidden';
    hidden.name = selectionName(type);
    hidden.value = input.value;
    row.appendChild(hidden);

    var scope = document.createElement('span');
    scope.className = 'ppl-batch__basket-type';
    scope.textContent = input.getAttribute('data-selection-scope') || input.value;
    row.appendChild(scope);

    var labelWrap = document.createElement('span');
    var label = document.createElement('strong');
    label.textContent = input.getAttribute('data-selection-label') || ('#' + input.value);
    var meta = document.createElement('small');
    meta.textContent = input.getAttribute('data-selection-meta') || '';
    labelWrap.appendChild(label);
    labelWrap.appendChild(meta);
    row.appendChild(labelWrap);

    var remove = document.createElement('button');
    remove.type = 'button';
    remove.className = 'ppl-batch__icon-button';
    remove.setAttribute('data-role', 'remove-selection');
    remove.title = formLabel(root, 'remove-selection', 'Remove selection');
    remove.innerHTML = '&times;';
    row.appendChild(remove);

    return row;
  }

  function updateBasketCounts(root) {
    var counts = {
      pages: root.querySelectorAll('[data-selection-type="page"]').length,
      subtrees: root.querySelectorAll('[data-selection-type="subtree"]').length,
      elements: root.querySelectorAll('[data-selection-type="element"]').length
    };

    Object.keys(counts).forEach(function (keyName) {
      var counter = root.querySelector('[data-role="basket-count-' + keyName + '"]');
      if (counter) {
        counter.textContent = String(counts[keyName]);
      }
    });

    var empty = root.querySelector('.ppl-batch__empty');
    if (empty) {
      empty.hidden = counts.pages + counts.subtrees + counts.elements > 0;
    }

    updateActionGating(root, counts);
  }

  function updateActionGating(root, counts) {
    var hasSelection = counts.pages + counts.subtrees + counts.elements > 0;

    root.querySelectorAll('[data-role="tree-selection-action"]').forEach(function (button) {
      button.hidden = !hasSelection;
      button.disabled = !hasSelection;
    });

    root.querySelectorAll('button[name="module_action"][value="review_selection"]').forEach(function (button) {
      button.disabled = !hasSelection;
    });
  }

  function clearGeneratedExclusions(root) {
    root.querySelectorAll('input[data-generated-exclusion="1"]').forEach(function (input) {
      input.remove();
    });
  }

  function syncReviewExclusions(root) {
    clearGeneratedExclusions(root);
    root.querySelectorAll('[data-role="include-toggle"]').forEach(function (input) {
      var container = input.closest('.ppl-batch__review-element, .ppl-batch__review-page');
      if (container) {
        container.classList.toggle('ppl-batch__review-item--excluded', !input.checked);
      }
      if (input.checked) {
        return;
      }

      var exclusionName = input.getAttribute('data-exclusion-name') || '';
      if (exclusionName === '') {
        return;
      }

      var hidden = document.createElement('input');
      hidden.type = 'hidden';
      hidden.name = exclusionName;
      hidden.value = input.value;
      hidden.setAttribute('data-generated-exclusion', '1');
      input.closest('form').appendChild(hidden);
    });
  }

  function initializeReviewInclusionControls(root) {
    root.querySelectorAll('[data-role="include-toggle"]').forEach(function (input) {
      input.setAttribute('data-previous-checked', input.checked ? '1' : '0');
      input.addEventListener('change', function () {
        if (hasUnappliedPreview(root) && !window.confirm(confirmationText(root, 'data-confirm-discard-preview', 'You have unapplied translation previews. Continue and discard them?'))) {
          input.checked = input.getAttribute('data-previous-checked') === '1';
          return;
        }
        input.setAttribute('data-previous-checked', input.checked ? '1' : '0');
        syncReviewExclusions(root);
      });
    });
    syncReviewExclusions(root);
  }

  function syncBasketFromInputs(root) {
    ['page', 'subtree', 'element'].forEach(function (type) {
      var checkedInputs = {};
      var existingRows = {};
      var inputName = selectionName(type);
      if (inputName === '') {
        return;
      }

      root.querySelectorAll('input[name="' + inputName + '"]:checked').forEach(function (input) {
        checkedInputs[input.value] = input;
      });

      root.querySelectorAll('[data-selection-type="' + type + '"]').forEach(function (row) {
        var value = row.getAttribute('data-selection-value') || '';
        var matchingCheckbox = root.querySelector('input[name="' + inputName + '"][value="' + value + '"]:not([type="hidden"])');
        if (matchingCheckbox && !matchingCheckbox.checked) {
          row.remove();
          return;
        }

        existingRows[value] = true;
      });

      Object.keys(checkedInputs).forEach(function (value) {
        if (existingRows[value]) {
          return;
        }

        var group = ensureBasketGroup(root, type);
        if (group) {
          group.appendChild(createBasketRow(root, checkedInputs[value], type));
        }
      });

      var config = basketGroupConfig(root, type);
      var group = config.role ? root.querySelector('[data-role="' + config.role + '"]') : null;
      if (group) {
        group.hidden = group.querySelectorAll('.ppl-batch__basket-row').length === 0;
      }
    });

    updateBasketCounts(root);
  }

  function formLabel(root, name, fallback) {
    var form = root.querySelector('form');
    return form ? String(form.getAttribute('data-label-' + name) || fallback) : fallback;
  }

  function ensureHiddenInput(form, name, value) {
    var input = form.querySelector('input[name="' + name + '"]');
    if (!input) {
      input = document.createElement('input');
      input.type = 'hidden';
      input.name = name;
      form.appendChild(input);
    }
    input.value = String(value || '');
  }

  function isAjaxPreviewAction(action) {
    return action.indexOf('generate_preview:') === 0 || action === 'generate_preview' || action === 'retranslate_selected';
  }

  function setButtonLoading(button, loading, root) {
    if (!button) {
      return;
    }
    if (loading) {
      button.setAttribute('data-original-label', button.textContent);
      button.textContent = formLabel(root, 'loading', 'Generating DeepL preview...');
      button.disabled = true;
      return;
    }

    button.textContent = button.getAttribute('data-original-label') || button.textContent;
    button.disabled = false;
  }

  function appendText(parent, tagName, className, text) {
    var node = document.createElement(tagName);
    if (className) {
      node.className = className;
    }
    node.textContent = text;
    parent.appendChild(node);
    return node;
  }

  function appendOperation(container, operation, root) {
    var labels = {
      source: formLabel(root, 'source', 'Source'),
      current: formLabel(root, 'current', 'Existing translation'),
      proposal: formLabel(root, 'proposal', 'Translation'),
      missing: formLabel(root, 'missing', 'No existing translation yet.'),
      placeholder: formLabel(root, 'placeholder', 'Translation will appear here.'),
      translate: formLabel(root, 'action-translate', 'will write'),
      fillEmpty: formLabel(root, 'action-fill_empty', 'fills empty field'),
      overwrite: formLabel(root, 'action-overwrite', 'will overwrite')
    };
    var actionLabel = labels.translate;
    if (String(operation.writeAction || '') === 'fill_empty') {
      actionLabel = labels.fillEmpty;
    } else if (String(operation.writeAction || '') === 'overwrite') {
      actionLabel = labels.overwrite;
    }
    var row = document.createElement('div');
    row.className = 'ppl-batch__inline-operation ppl-batch__inline-operation--' + String(operation.writeAction || 'skip');
    appendText(row, 'strong', '', String(operation.label || operation.field || 'Field'));
    appendText(row, 'span', 'ppl-batch__basket-type', actionLabel);
    appendText(row, 'small', '', labels.source + ': ' + String(operation.sourceValue || ''));
    if (String(operation.targetValue || '').trim() !== '') {
      appendText(row, 'small', '', labels.current + ': ' + String(operation.targetValue || '').trim());
    }
    appendText(row, 'small', '', labels.proposal + ': ' + (String(operation.translatedValue || '').trim() || labels.placeholder));
    container.appendChild(row);
  }

  function renderFieldProposal(target, operation, root) {
    var proposal = target.querySelector('[data-role="field-proposal"]');
    if (!proposal) {
      return;
    }
    var label = formLabel(root, 'proposal', 'Translation');
    var placeholder = formLabel(root, 'placeholder', 'Translation will appear here.');
    proposal.textContent = label + ': ' + (String(operation.translatedValue || '').trim() || placeholder);
    proposal.classList.toggle('ppl-batch__inline-preview--ready', String(operation.translatedValue || '').trim() !== '');
  }

  function markPreviewControlsReady(target, operations, root) {
    var hasProposal = operations.some(function (operation) {
      return String(operation.translatedValue || '').trim() !== '';
    });
    if (!hasProposal) {
      return;
    }

    target.querySelectorAll('button[name="module_action"]').forEach(function (button) {
      if (String(button.value || '').indexOf('generate_preview:') !== 0) {
        return;
      }
      button.textContent = formLabel(root, 'ready', 'DeepL preview ready.');
      button.disabled = true;
      button.classList.add('disabled');
    });
  }

  function renderPreviewItem(root, item) {
    var table = String(item.table || '');
    var uid = String(item.baseUid || item.sourceUid || '');
    if (table === '' || uid === '') {
      return;
    }
    var operations = Array.isArray(item.fieldOperations) ? item.fieldOperations : [];
    var targets = root.querySelectorAll('[data-preview-record="' + table + ':' + uid + '"]');

    targets.forEach(function (target) {
      var fieldName = target.getAttribute('data-preview-field') || '';
      if (fieldName !== '') {
        operations.forEach(function (operation) {
          if (String(operation.field || '') === fieldName) {
            renderFieldProposal(target, operation, root);
          }
        });
        markPreviewControlsReady(target, operations, root);
        return;
      }

      var container = target.querySelector('[data-role="inline-preview"]');
      if (!container) {
        return;
      }
      container.innerHTML = '';
      if (operations.length === 0) {
        appendText(container, 'small', '', formLabel(root, 'placeholder', 'Translation preview will appear here.'));
        return;
      }
      operations.forEach(function (operation) {
        appendOperation(container, operation, root);
      });
      container.classList.add('ppl-batch__inline-preview--ready');
      markPreviewControlsReady(target, operations, root);
    });
  }

  function renderInlinePreviews(root, preflight) {
    if (!preflight || !Array.isArray(preflight.items)) {
      return;
    }
    preflight.items.forEach(function (item) {
      renderPreviewItem(root, item);
    });
  }

  function applyPreviewJobState(root, data) {
    var form = root.querySelector('form');
    if (!form) {
      return;
    }
    var jobUid = String(data.confirmedJobUid || '');
    if (jobUid !== '') {
      ensureHiddenInput(form, 'confirmed_job_uid', jobUid);
      ensureHiddenInput(form, 'confirmed_preview_job', jobUid);
    }
    form.setAttribute('data-has-preview', jobUid !== '' && String(data.confirmedJobStatus || '') === 'previewed' ? '1' : '0');

    var actionState = data && data.actionState ? data.actionState : {};
    root.querySelectorAll('[data-role="preview-ready-action"]').forEach(function (button) {
      var action = String(button.value || '');
      var allowed = true;
      if (action === 'write_translations') {
        allowed = !!actionState.canWrite || !!actionState.canExecute;
      } else if (action === 'retranslate_selected') {
        allowed = !!actionState.canRetranslate;
      } else if (action === 'discard_preview') {
        allowed = !!actionState.canDiscardPreview;
      }
      button.hidden = !allowed;
      button.disabled = !allowed;
    });
    root.querySelectorAll('[data-role="preview-visibility-toggle"]').forEach(function (toggle) {
      toggle.setAttribute('data-visible', (!!actionState.canWrite || !!actionState.canExecute) ? '1' : '0');
    });
    root.querySelectorAll('button[name="module_action"][value="generate_preview:batch:0"]').forEach(function (button) {
      button.hidden = true;
      button.disabled = true;
    });
  }

  function submitPreviewAjax(root, submitter) {
    var form = root.querySelector('form');
    if (!form || !submitter) {
      return;
    }

    var formData = new FormData(form);
    formData.set('module_action', String(submitter.value || 'generate_preview:batch:0'));
    formData.set('ajax_preview', '1');
    setButtonLoading(submitter, true, root);

    window.fetch(form.action, {
      method: 'POST',
      body: formData,
      headers: {
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      },
      credentials: 'same-origin'
    }).then(function (response) {
      if (!response.ok) {
        throw new Error(formLabel(root, 'preview-failed', 'Preview request failed.'));
      }
      return response.json();
    }).then(function (data) {
      if (data && data.ok === false) {
        var messages = Array.isArray(data.messages) ? data.messages : [];
        var errorText = messages.map(function (message) {
          return String(message.text || '');
        }).filter(Boolean).join('\n');
        throw new Error(errorText || 'Preview request failed.');
      }
      applyPreviewJobState(root, data || {});
      renderInlinePreviews(root, data ? data.preflight : null);
      setButtonLoading(submitter, false, root);
      submitter.textContent = formLabel(root, 'ready', 'DeepL preview ready.');
      announce(root, formLabel(root, 'ready', 'DeepL preview ready.'));
      window.setTimeout(function () {
        submitter.textContent = submitter.getAttribute('data-original-label') || submitter.textContent;
      }, 1800);
    }).catch(function (error) {
      setButtonLoading(submitter, false, root);
      announce(root, error && error.message ? error.message : formLabel(root, 'preview-failed', 'Preview request failed.'));
      window.alert(error && error.message ? error.message : formLabel(root, 'preview-failed', 'Preview request failed.'));
    });
  }

  function initialize(root) {
    updatePanelHeight(root);
    initializeSetupDisclosure(root);
    initializePreviewInvalidationWarnings(root);
    initializeTranslationResourceControls(root);
    initializeReviewInclusionControls(root);
    scrollActiveStepIntoView(root);
    focusWorkspace(root);
    window.addEventListener('resize', function () {
      schedulePanelHeightUpdate(root);
      scrollActiveStepIntoView(root);
    });
    window.addEventListener('orientationchange', function () {
      schedulePanelHeightUpdate(root);
      scrollActiveStepIntoView(root);
    });
    var scroller = getScroller();
    if (scroller && typeof scroller.addEventListener === 'function') {
      scroller.addEventListener('scroll', function () {
        schedulePanelHeightUpdate(root);
      }, { passive: true });
    }
    window.addEventListener('scroll', function () {
      schedulePanelHeightUpdate(root);
    }, { passive: true });

    root.querySelectorAll('[data-role="tree-search"], [data-role="tree-status-filter"]').forEach(function (control) {
      control.addEventListener('input', function () {
        applyTreeFilter(root);
        schedulePanelHeightUpdate(root);
      });
      control.addEventListener('change', function () {
        applyTreeFilter(root);
        schedulePanelHeightUpdate(root);
      });
    });

    root.addEventListener('click', function (event) {
      var remove = event.target.closest('[data-role="remove-selection"]');
      if (remove) {
        event.preventDefault();
        syncBasketRemoval(remove);
      }

      var removeExclusion = event.target.closest('[data-role="remove-exclusion"]');
      if (removeExclusion) {
        event.preventDefault();
        syncExclusionRemoval(removeExclusion);
      }
    });

    root.querySelectorAll('input[name="selected_pages[]"], input[name="selected_subtree_pages[]"], input[name="selected_elements[]"]').forEach(function (input) {
      input.setAttribute('data-previous-checked', input.checked ? '1' : '0');
      input.addEventListener('change', function () {
        if (hasUnappliedPreview(root) && !window.confirm(confirmationText(root, 'data-confirm-discard-preview', 'You have unapplied translation previews. Continue and discard them?'))) {
          input.checked = input.getAttribute('data-previous-checked') === '1';
          return;
        }
        input.setAttribute('data-previous-checked', input.checked ? '1' : '0');
        syncBasketFromInputs(root);
        announce(root, formLabel(root, 'selection-updated', 'Selection updated.'));
      });
    });

    root.addEventListener('submit', function (event) {
      var submitter = event.submitter || document.activeElement;
      var action = submitter && submitter.name === 'module_action' ? String(submitter.value || '') : '';

      if (isAjaxPreviewAction(action)) {
        event.preventDefault();
        if (action === 'retranslate_selected' && !window.confirm(confirmationText(root, 'data-confirm-retranslate', 'Retranslate selected will replace cached preview suggestions. Continue?'))) {
          return;
        }
        syncReviewExclusions(root);
        submitPreviewAjax(root, submitter);
        return;
      }

      if (hasUnappliedPreview(root) && submitter && submitter.getAttribute('data-discards-preview') === '1') {
        if (!window.confirm(confirmationText(root, 'data-confirm-discard-preview', 'You have unapplied translation previews. Continue and discard them?'))) {
          event.preventDefault();
          return;
        }
      }

      if (action === 'write_translations') {
        if (!window.confirm(confirmationText(root, 'data-confirm-write', 'Write the confirmed translation preview into TYPO3 now?'))) {
          event.preventDefault();
          return;
        }
        announce(root, formLabel(root, 'writing-translations', 'Writing confirmed translations.'));
      }

      if (action === 'retranslate_selected') {
        if (!window.confirm(confirmationText(root, 'data-confirm-retranslate', 'Retranslate selected will replace cached preview suggestions. Continue?'))) {
          event.preventDefault();
          return;
        }
      }

      syncReviewExclusions(root);
      writeState(submitter && submitter.value ? String(submitter.value) : '');
    }, true);

    applyTreeFilter(root);
    syncBasketFromInputs(root);
    schedulePanelHeightUpdate(root);
  }

  function initializeAll() {
    var roots = document.querySelectorAll('[data-module="ppl-batch-translation"]');
    roots.forEach(initialize);
    restoreState();
    roots.forEach(schedulePanelHeightUpdate);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
      initializeAll();
    });
  } else {
    initializeAll();
  }
})();
