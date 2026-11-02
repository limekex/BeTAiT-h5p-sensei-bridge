(function () {
  'use strict';

  window.fkhsGatePassed = false;
  function setGate(val){ window.fkhsGatePassed = !!val; }

  function injectStyle(id, css){
    if (document.getElementById(id)) return;
    var el = document.createElement('style'); el.id = id; el.textContent = css; document.head.appendChild(el);
  }

  var fkhsEvaluated = Object.create(null);
  var fkhsQueue = [];
  var fkhsSending = false;
  var fkhsSaveError = null;
  var fkhsStatusError = null;
  var fkhsInputError = null;
  var fkhsLastStored = false;
  var fkhsStatusVersion = 0;
  var fkhsStatusPending = false;
  var fkhsTransportBlocked = false;
  var fkhsRuntimeState = 'idle';

  injectStyle('fkhs-bridge-status-css', `
    .fkhs-status-panel{ margin-top:.75rem; border:1px solid #e5e7eb; border-radius:12px; padding:.75rem 1rem; background:#fafafa; box-shadow:0 8px 18px rgba(0,0,0,.05) }
    .fkhs-status-panel[hidden]{ display:none !important; }
    .fkhs-locked{ opacity:.5; cursor:not-allowed; }
    .fkhs-status-title{font-weight:600; margin:0 0 .25rem 0}
    .fkhs-status-sub{color:#475569; margin:0 0 .5rem 0}
    .fkhs-status-list{margin:0; padding-left:1rem}
    .fkhs-status-list li{margin:.15rem 0}
    .fkhs-status-link{cursor:pointer; text-decoration:underline}
    .fkhs-feedback{ width:100%; margin-block:.75rem; }
    .fkhs-status-optional{ color:#64748b; font-style:italic; margin-top:.5rem }
  `);

  injectStyle('fkhs-bridge-overlay-css', `
    .fkhs-h5p-wrap{ position:relative; }
    .fkhs-h5p-overlay{ position:absolute; inset:0; z-index:9; display:flex; align-items:center; justify-content:center; background:rgba(255,255,255,.88); backdrop-filter:saturate(1.2) blur(2px); border-radius:8px; text-align:center; padding:1rem; }
    .fkhs-h5p-overlay .inner{ max-width:720px; background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:1rem 1.25rem; box-shadow:0 12px 28px rgba(0,0,0,.12); }
    .fkhs-h5p-overlay.pass .inner{ border-color:#c6f6d5; }
    .fkhs-h5p-overlay.fail .inner{ border-color:#fed7d7; }
    .fkhs-row{ display:flex; gap:.75rem; justify-content:center; margin-top:.75rem; flex-wrap:wrap; }
    .fkhs-btn-sm{ background:#f8fafc; border:1px solid #e5e7eb; border-radius:8px; padding:.45rem .7rem; cursor:pointer; }
    .fkhs-btn-sm.primary{ background:#0ea5e9; color:#fff; border-color:#0ea5e9; }
    .fkhs-close{ position:absolute; top:.5rem; right:.5rem; background:transparent; border:0; cursor:pointer; font-size:1rem; line-height:1; }
    .fkhs-h5p-badge{ position:absolute; top:.5rem; right:.5rem; z-index:10; display:inline-block; border-radius:999px; padding:.25rem .6rem; font-size:.85rem; line-height:1; color:#fff; box-shadow:0 6px 18px rgba(0,0,0,.15); user-select:none }
    .fkhs-h5p-badge.pass{ background:#1e7f34; }
    .fkhs-h5p-badge.fail{ background:#b02a37; cursor:pointer; }
  `);

  injectStyle('fkhs-bridge-notice-css', `
    .fkhs-quiz-warning{ margin-top:.5rem; background:#0b5; color:#fff; border-radius:8px; padding:.5rem .75rem; font-size:.925rem; line-height:1.35; box-shadow:0 6px 18px rgba(0,0,0,.08) }
    .fkhs-quiz-warning.fkhs-warn{ background:#d9480f }
    .fkhs-quiz-warning.fkhs-bump{ animation:fkhs-bump .25s ease }
    @keyframes fkhs-bump{ 0%{transform:translateY(-2px)} 100%{transform:translateY(0)} }
  `);

  function transportText(key, fallback) { return window.fkH5P?.i18n?.[key] || fallback; }

  function syncTransport() {
    fkhsTransportBlocked = !!(fkhsQueue.length || fkhsSaveError || fkhsStatusError || fkhsInputError || fkhsStatusPending || fkhsRuntimeState === 'waiting' || fkhsRuntimeState === 'failed');
    setQuizButtonVisibility(window.fkhsGatePassed);
    var panel = ensureStatusPanel();
    var redirectNotice = document.getElementById('fkhs-redirect-notice');
    if (redirectNotice) redirectNotice.hidden = window.fkhsGatePassed && !fkhsTransportBlocked;
    // A previous pass must not say "You can continue" while saving is unresolved.
    panel.hidden = fkhsTransportBlocked;
    var notice = document.getElementById('fkhs-save-status');
    if (!notice) {
      notice = document.createElement('div');
      notice.id = 'fkhs-save-status';
      notice.className = 'fkhs-status-panel';
      notice.setAttribute('role', 'status');
      notice.setAttribute('aria-live', 'polite');
      panel.parentNode.insertBefore(notice, panel.nextSibling);
    }
    var message;
    var failure = fkhsSaveError || fkhsStatusError;
    if (fkhsInputError) message = transportText('eventNotRecorded', 'An activity event could not be recorded. Reload the page and repeat the activity.');
    else if (failure?.auth) message = transportText('sessionExpired', 'Your session or access has expired. Sign in, then reload this page and repeat any unsaved activity.');
    else if (fkhsSaveError?.retryable === false) message = transportText('saveUnconfirmed', 'Saving could not be confirmed. Reload the page to check progress before repeating the activity.');
    else if (fkhsSaveError) message = transportText('saveFailed', 'Your activity has not been confirmed saved. Keep this page open and retry.');
    else if (fkhsQueue.length) message = transportText('saving', 'Saving your activity… Keep this page open.');
    else if (fkhsStatusError) message = transportText('statusFailed', 'Progress could not be checked. Retry before continuing.');
    else if (fkhsRuntimeState === 'failed') message = transportText('h5pUnavailable', 'H5P tracking could not start. Retry, or reload this page before repeating the activity.');
    else if (fkhsRuntimeState === 'waiting') message = transportText('h5pWaiting', 'Waiting for H5P tracking to start…');
    else if (fkhsStatusPending) message = transportText('checkingProgress', 'Checking progress…');
    else if (fkhsLastStored) message = transportText('activitySaved', 'Your activity is saved.');
    else message = '';
    notice.replaceChildren();
    notice.hidden = !message;
    var text = document.createElement('span'); text.textContent = message; notice.appendChild(text);
    if (failure && !failure.auth && failure.retryable !== false && !fkhsSending) {
      var retry = document.createElement('button');
      retry.type = 'button'; retry.className = 'fkhs-btn-sm';
      retry.textContent = transportText('retrySaving', 'Retry');
      retry.addEventListener('click', retryTransport);
      notice.appendChild(retry);
    }
    if (failure?.auth && window.fkH5P?.loginUrl) {
      var login = document.createElement('a');
      login.href = window.fkH5P.loginUrl; login.target = '_blank'; login.rel = 'noopener';
      login.textContent = transportText('signInAgain', 'Sign in in a new tab');
      notice.appendChild(login);
    }
    if (fkhsRuntimeState === 'failed' && !failure && !fkhsInputError) {
      var retryRuntime = document.createElement('button');
      retryRuntime.type = 'button'; retryRuntime.className = 'fkhs-btn-sm';
      retryRuntime.textContent = transportText('retryH5P', 'Retry H5P tracking');
      retryRuntime.addEventListener('click', function() { startH5PTracking(true); });
      notice.appendChild(retryRuntime);
    }
  }

  async function requestJSON(url, options) {
    var controller = new AbortController();
    var timer = setTimeout(function () { controller.abort(); }, 15000);
    try {
      var response = await fetch(url, Object.assign({}, options, { signal: controller.signal }));
      var json;
      try { json = await response.json(); } catch (_) { json = null; }
      if (!response.ok || !json || json.ok !== true) {
        throw { status: response.status, auth: response.status === 401 || response.status === 403,
          retryable: response.status >= 500 || response.status === 429,
          reason: json?.reason || json?.code || 'invalid_response' };
      }
      return json;
    } catch (error) {
      if (typeof error?.status === 'number') throw error;
      throw { status: 0, retryable: true, reason: 'network_error' };
    } finally { clearTimeout(timer); }
  }

  function eventUUID() {
    if (window.crypto?.randomUUID) return window.crypto.randomUUID();
    if (!window.crypto?.getRandomValues) return null;
    var bytes = new Uint8Array(16); window.crypto.getRandomValues(bytes);
    bytes[6] = (bytes[6] & 15) | 64; bytes[8] = (bytes[8] & 63) | 128;
    var hex = Array.from(bytes, function (b) { return b.toString(16).padStart(2, '0'); }).join('');
    return hex.slice(0,8)+'-'+hex.slice(8,12)+'-'+hex.slice(12,16)+'-'+hex.slice(16,20)+'-'+hex.slice(20);
  }

  function postXAPI(statement, contentId) {
    var id = eventUUID();
    if (!id || !contentId || fkhsQueue.length >= 100) {
      fkhsInputError = true; syncTransport(); return Promise.resolve(false);
    }
    var body;
    try { body = JSON.stringify({ statement: statement, contentId: contentId, lesson_id: window.fkH5P.lessonId, event_id: id }); }
    catch (_) { fkhsInputError = true; syncTransport(); return Promise.resolve(false); }
    // Freeze one payload/identity per received event, including every manual retry.
    var promise = new Promise(function (resolve) { fkhsQueue.push({ body: body, resolve: resolve }); });
    ++fkhsStatusVersion; fkhsStatusPending = false; // Invalidate reads started before this write.
    syncTransport();
    if (!fkhsSaveError) pumpAttempts();
    return promise;
  }

  async function pumpAttempts() {
    if (fkhsSending) return;
    fkhsSending = true;
    while (fkhsQueue.length) {
      var job = fkhsQueue[0];
      try {
        var receipt = await requestJSON(window.fkH5P.restUrl, {
          method: 'POST', credentials: 'same-origin', cache: 'no-store',
          headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.fkH5P.nonce }, body: job.body
        });
        if (!(receipt.stored === true && Number.isSafeInteger(receipt.attempt_id) && receipt.attempt_id > 0) &&
            !(receipt.stored === false && receipt.skipped === true && ['completion_pending', 'filtered', 'nested_activity'].includes(receipt.reason))) {
          // An older server's {ok:true} is not a persistence acknowledgement.
          throw { retryable: false, reason: 'unconfirmed_save' };
        }
        fkhsLastStored = fkhsLastStored || receipt.stored;
        fkhsQueue.shift(); fkhsSaveError = null;
        ++fkhsStatusVersion; fkhsStatusPending = false;
        job.resolve(receipt);
      } catch (error) {
        fkhsSaveError = error; job.resolve(false); break;
      }
    }
    fkhsSending = false;
    syncTransport();
    if (!fkhsQueue.length) await fetchAndRenderH5PStatus();
  }

  function retryTransport() {
    if (fkhsSending || (fkhsSaveError || fkhsStatusError)?.auth) return;
    if (fkhsQueue.length) { fkhsSaveError = null; return pumpAttempts(); }
    return fetchAndRenderH5PStatus();
  }

  function showBlockedNotice(fromEl){
    try{
      var isQuiz = fromEl?.closest('.wp-block-sensei-lms-button-view-quiz, form[action*="quiz"], .sensei-quiz-link');
      var txt = isQuiz
        ? (window.fkH5P?.i18n?.blockedNoticeQuiz   || 'You need to pass the in-lesson tasks before you can take the quiz.')
        : (window.fkH5P?.i18n?.blockedNoticeLesson || 'You need to pass the in-lesson tasks before you can complete the lesson.');
      var host = fromEl?.closest('.sensei-buttons-container')
             || fromEl?.closest('.wp-block-sensei-lms-button-view-quiz')
             || fromEl?.closest('form.lesson_button_form')
             || fromEl?.parentElement;
      if (!host || !host.parentNode) return;
      var notice = host.nextElementSibling && host.nextElementSibling.classList?.contains('fkhs-quiz-warning')
        ? host.nextElementSibling : null;
      if (!notice) {
        notice = document.createElement('div');
        notice.className = 'fkhs-quiz-warning fkhs-warn';
        notice.setAttribute('role','status');
        notice.setAttribute('aria-live','polite');
        notice.textContent = txt;
        host.parentNode.insertBefore(notice, host.nextSibling);
      } else {
        notice.textContent = txt;
        notice.classList.add('fkhs-bump');
        setTimeout(function(){ notice.classList.remove('fkhs-bump'); }, 250);
      }
      clearTimeout(notice._fkhsTimer);
      notice._fkhsTimer = setTimeout(function(){
        if (!notice.matches(':hover')) notice.remove();
      }, 6000);
    }catch(e){}
  }

  function format(n){ return (typeof n === 'number' && isFinite(n)) ? (Math.round(n*100)/100) : '—'; }
  function formatPct(n){ return (typeof n === 'number' && isFinite(n)) ? (Math.round(n*100)/100) + '%' : '—'; }

  function findH5PContainersById(contentId){
    if (!contentId) return [];
    var nodes = [];
    nodes = nodes.concat([].slice.call(document.querySelectorAll('.h5p-iframe[data-content-id="'+contentId+'"]')));
    nodes = nodes.concat([].slice.call(document.querySelectorAll('#h5p-'+contentId)));
    return nodes.map(function(node){
      var wrap = node.closest('.fkhs-h5p-wrap');
      if (!wrap) {
        wrap = node.closest('.h5p-content') || node.parentElement;
        if (wrap && !wrap.classList.contains('fkhs-h5p-wrap')) {
          wrap.classList.add('fkhs-h5p-wrap');
          if (getComputedStyle(wrap).position === 'static') wrap.style.position = 'relative';
        }
      }
      return wrap || node;
    }).filter(Boolean);
  }

  function ensureStatusPanel(){
    var panel = document.getElementById('fkhs-status-panel');
    if (!panel) {
      panel = document.createElement('div');
      panel.id = 'fkhs-status-panel';
      panel.className = 'fkhs-status-panel';
      panel.setAttribute('role','status');
      panel.setAttribute('aria-live','polite');
    }
    var feedback = document.getElementById('fkhs-feedback');
    if (!feedback) {
      feedback = document.createElement('div');
      feedback.id = 'fkhs-feedback';
      feedback.className = 'fkhs-feedback';
    }
    // Keep feedback outside Sensei's forms/flex rows and screen-reader navigation.
    var anchor = Array.from(document.querySelectorAll(
      '.sensei-lesson-footer, .wp-block-sensei-lms-lesson-actions, .sensei-buttons-container, form.lesson_button_form'
    )).find(function(el) { return !el.closest('.screen-reader-text'); });
    if (anchor && anchor.parentNode) {
      if (feedback.nextSibling !== anchor) anchor.parentNode.insertBefore(feedback, anchor);
    } else {
      var fallback = document.querySelector('main, .site-main, .entry-content, article, body') || document.body;
      if (feedback.parentNode !== fallback) fallback.appendChild(feedback);
    }
    if (panel.parentNode !== feedback) feedback.appendChild(panel);
    var redirectNotice = document.getElementById('fkhs-redirect-notice');
    if (redirectNotice && redirectNotice.parentNode !== feedback) feedback.insertBefore(redirectNotice, panel);
    return panel;
  }

  function attachBadge(container, html, className, onClick){
    if (!container) return;
    [].slice.call(container.querySelectorAll('.fkhs-h5p-badge')).forEach(function(el){ el.remove(); });
    var b = document.createElement('div');
    b.className = 'fkhs-h5p-badge ' + className;
    b.innerHTML = html;
    if (onClick) b.addEventListener('click', onClick);
    container.appendChild(b);
  }

  function attachOverlay(container, kind, title, body){
    if (!container) return;
    [].slice.call(container.querySelectorAll('.fkhs-h5p-overlay')).forEach(function(el){ el.remove(); });
    var ov = document.createElement('div'); ov.className = 'fkhs-h5p-overlay ' + (kind === 'pass' ? 'pass' : 'fail');
    var inner = document.createElement('div'); inner.className = 'inner';
    inner.innerHTML = '<strong>'+title+'</strong><div style="margin-top:.35rem">'+body+'</div>';
    var row = document.createElement('div'); row.className = 'fkhs-row';
    if (kind === 'pass') {
      var close = document.createElement('button'); close.className = 'fkhs-close'; close.setAttribute('aria-label','Close'); close.innerHTML = '×';
      close.addEventListener('click', function(){ ov.remove(); container.dataset.fkhsDismissed = '1'; });
      ov.appendChild(close);
      var again = document.createElement('button'); again.className = 'fkhs-btn-sm'; again.textContent = (window.fkH5P?.i18n?.practiceAgain || 'Practice again');
      again.addEventListener('click', function(){ ov.remove(); container.dataset.fkhsDismissed = '1'; });
      row.appendChild(again);
    } else {
      var retry = document.createElement('button'); retry.className = 'fkhs-btn-sm primary'; retry.textContent = (window.fkH5P?.i18n?.retryNow || 'Retry now');
      retry.addEventListener('click', function(){ ov.remove(); container.scrollIntoView({ behavior:'smooth', block:'center' }); });
      row.appendChild(retry);
    }
    inner.appendChild(row); ov.appendChild(inner); container.appendChild(ov);
  }

  function renderStatusPanelFromItems(items){
    try{
      var panel = ensureStatusPanel(); if (!panel) return;

      // Separate into gating vs optional (no-score without activity yet)
      var gating = [];
      var optional = [];

      items.forEach(function(item){
        var thr = (typeof item.threshold === 'number') ? item.threshold : 0;
        var hasAttempt = !!(item.latest?.has_attempt ?? item.has_attempt);
        if (item.counts_for_gate ?? (thr > 0 || (thr === 0 && hasAttempt))) {
          gating.push(item);
        } else {
          optional.push(item); // threshold==0 and no activity yet
        }
      });

      var total = gating.length;
      if (!total) {
        var title0 = window.fkH5P?.i18n?.statusHeading || 'Lesson tasks status';
        var none   = window.fkH5P?.i18n?.noH5Ps || 'No mandatory H5P tasks yet.';
        panel.innerHTML = '<p class="fkhs-status-title">'+title0+'</p><p class="fkhs-status-sub">'+none+'</p>';
        if (optional.length){
          panel.innerHTML += '<p class="fkhs-status-optional">'+(window.fkH5P?.i18n?.optionalNotice || 'Optional interactive content is available.')+'</p>';
        }
        return;
      }

      var passedCount = 0, remaining = [];
      gating.forEach(function(item){
        var thr = item.threshold || 0;
        var latest = item.latest || {}, best = item.best || {};
        var latestPct = (typeof latest.pct === 'number') ? latest.pct :
          ((typeof latest.raw === 'number' && typeof latest.max === 'number' && latest.max > 0) ? (latest.raw/latest.max)*100 : null);
        var bestPct = (typeof best.pct === 'number') ? best.pct :
          ((typeof best.raw === 'number' && typeof best.max === 'number' && best.max > 0) ? (best.raw/best.max)*100 : null);

        var met;
        if (thr > 0){
          var bestPass   = (typeof bestPct === 'number')   && bestPct   >= thr;
          var latestPass = !!latest.passed || (typeof latestPct === 'number' && latestPct >= thr);
          met = bestPass || latestPass;
        } else {
          met = !!latest.completed;
        }

        if (typeof item.requirement_met === 'boolean') met = item.requirement_met;
        if (met) passedCount++; else remaining.push(item);
      });

      var title = window.fkH5P?.i18n?.statusHeading || 'Lesson tasks status';
      if (passedCount === total) {
        panel.innerHTML = '<p class="fkhs-status-title">'+title+'</p>' +
                          '<p class="fkhs-status-sub">'+(window.fkH5P?.i18n?.statusAllPassed || 'All required tasks are satisfied. You can continue.')+'</p>';
        if (optional.length){
          panel.innerHTML += '<p class="fkhs-status-optional">'+(window.fkH5P?.i18n?.optionalNotice || 'Optional interactive content is available.')+'</p>';
        }
        return;
      }

      var progress = (window.fkH5P?.i18n?.statusProgress || '%1$d of %2$d tasks passed')
                      .replace('%1$d', passedCount).replace('%2$d', total);
      var remainingLabel = window.fkH5P?.i18n?.statusRemaining || 'Remaining:';

      var html = '<p class="fkhs-status-title">'+title+'</p>' +
                 '<p class="fkhs-status-sub">'+progress+'</p>' +
                 '<p class="fkhs-status-sub" style="margin-bottom:.25rem">'+remainingLabel+'</p>' +
                 '<ul class="fkhs-status-list">';

      remaining.forEach(function(item){
        var t = item.title || ('H5P #' + item.content_id);
        var thr = item.threshold || 0;
        var best = item.best || {}, bestPct =
          (typeof best.pct === 'number') ? best.pct :
          ((typeof best.raw === 'number' && typeof best.max === 'number' && best.max > 0) ? (best.raw/best.max)*100 : null);
        var bestTxt = (typeof bestPct === 'number') ? (Math.round(bestPct * 100) / 100) + '%' : '—';
        var needTxt = (typeof thr === 'number') ? (Math.round(thr * 100) / 100) + '%' : '—';
        var line = (thr > 0)
          ? ((window.fkH5P?.i18n?.statusRemainingItem || '%1$s — best %2$s, required %3$s')
              .replace('%1$s', t.replace(/</g,'&lt;').replace(/>/g,'&gt;')).replace('%2$s', bestTxt).replace('%3$s', needTxt))
          : ((window.fkH5P?.i18n?.statusRemainingComplete || '%1$s — not completed yet')
              .replace('%1$s', t.replace(/</g,'&lt;').replace(/>/g,'&gt;')));
        html += '<li><span class="fkhs-status-link" data-cid="'+item.content_id+'">'+ line +'</span></li>';
      });
      html += '</ul>';

      if (optional.length){
        html += '<p class="fkhs-status-optional">'+(window.fkH5P?.i18n?.optionalNotice || 'Optional interactive content is available.')+'</p>';
      }

      panel.innerHTML = html;

      panel.querySelectorAll('.fkhs-status-link').forEach(function(a){
        a.addEventListener('click', function(e){
          var cid = parseInt(e.currentTarget.getAttribute('data-cid'), 10);
          var targets = findH5PContainersById(cid);
          if (targets.length) targets[0].scrollIntoView({ behavior:'smooth', block:'center' });
        });
      });
    }catch(e){}
  }

  // Target actions, including Sensei's intentional screen-reader Learning Mode controls.
  var fkhsActionSelector = [
    '.sensei-lesson-actions a[href*="quiz"]', 'a.sensei-quiz-link', 'a[href*="/quiz/"]',
    '.sensei-buttons-container a[href*="quiz"]',
    '.wp-block-sensei-lms-button-view-quiz button',
    '.wp-block-sensei-lms-button-complete-lesson button',
    '[data-id="complete-lesson-button"]',
    '.lesson_button_form button', 'button.complete-lesson', '.sensei-lesson-actions button',
    '.sensei-course-theme-lesson-actions__take-quiz'
  ].join(',');
  var fkhsFormSelector = 'form.lesson_button_form, form[data-id="complete-lesson-form"], ' +
    'form.sensei-course-theme-lesson-actions__take-quiz-form, .wp-block-sensei-lms-button-view-quiz form, .sensei-buttons-container form';
  var fkhsCompletedSelector = '.wp-block-sensei-lms-button-lesson-completed button';
  var fkhsGateLocked = false;
  var fkhsGateHandlersBound = false;
  var fkhsOriginalControls = new Map();

  function isGatedForm(form) {
    if (!form || !form.matches(fkhsFormSelector)) return false;
    return form.matches('form.lesson_button_form, form[data-id="complete-lesson-form"]') ||
      /quiz/i.test(form.getAttribute('action') || '') || !!form.querySelector(fkhsActionSelector);
  }

  function blockGateEvent(event) {
    if (!fkhsGateLocked) return;
    if (event.type === 'keydown' && event.key !== 'Enter' && event.key !== ' ') return;
    var target = event.target?.closest?.(event.type === 'submit' ? 'form' : fkhsActionSelector);
    if (!target || (event.type === 'submit' && !isGatedForm(target))) return;
    event.preventDefault();
    event.stopImmediatePropagation();
    showBlockedNotice(target);
  }

  function bindGateHandlers() {
    if (fkhsGateHandlersBound) return;
    fkhsGateHandlersBound = true;
    // Capture before Sensei handlers; delegation also covers newly inserted controls
    // before the mutation observer has had a chance to decorate them.
    ['click', 'keydown', 'submit'].forEach(function(type) {
      document.addEventListener(type, blockGateEvent, true);
    });
  }

  function restoreControl(el, original) {
    // Only undo attributes still owned by us; preserve later external changes.
    if (el.getAttribute('aria-disabled') === 'true') {
      if (original.aria === null) el.removeAttribute('aria-disabled');
      else el.setAttribute('aria-disabled', original.aria);
    }
    if (el.title === original.tooltip) el.title = original.title;
    el.classList.remove('fkhs-locked');
    fkhsOriginalControls.delete(el);
  }

  function setQuizButtonVisibility(passed) {
    var require = !!window.fkH5P?.require;
    var bypass = !!window.fkH5P?.bypass;
    if (!require) passed = true;
    if (fkhsTransportBlocked) passed = false;
    if (bypass) passed = true;
    fkhsGateLocked = !passed;
    bindGateHandlers();

    var tooltip = window.fkH5P?.i18n?.completeH5PFirst || 'Complete the H5P first';
    var controls = new Set(document.querySelectorAll(fkhsActionSelector));
    fkhsOriginalControls.forEach(function(original, el) {
      if (passed || !controls.has(el)) restoreControl(el, original);
    });
    if (!passed) controls.forEach(function(el) {
      if (!fkhsOriginalControls.has(el)) {
        fkhsOriginalControls.set(el, { aria: el.getAttribute('aria-disabled'), title: el.title, tooltip: tooltip });
      }
      el.setAttribute('aria-disabled', 'true');
      el.title = tooltip;
      el.classList.add('fkhs-locked');
      // Do not write native disabled: Sensei/video requirements own that state.
    });
    // Sensei documents this block as a status indicator with no action.
    document.querySelectorAll(fkhsCompletedSelector).forEach(function(el) {
      el.disabled = true;
    });
  }

  function extractContentId(evt, stmt) {
    var candidates = [];
    var invalid = false;
    function add(value) {
      if (value === undefined || value === null) return;
      var id = typeof value === 'number' ? value : (/^[1-9][0-9]*$/.test(value) ? Number(value) : NaN);
      if (!Number.isSafeInteger(id) || id <= 0) invalid = true;
      else candidates.push(id);
    }
    add(evt?.data?.contentId);
    add(evt?.data?.instance?.contentId);
    var ext = stmt?.object?.definition?.extensions || {};
    add(ext['http://h5p.org/x-api/h5p-local-content-id']);
    add(ext['https://h5p.org/x-api/h5p-local-content-id']);
    // Match the exact URL supplied by H5P; never guess from an arbitrary URL number
    // or select the first activity in the DOM.
    var url = stmt?.object?.id;
    var contents = window.H5PIntegration?.contents || {};
    Object.keys(contents).forEach(function (key) {
      if (typeof url === 'string' && contents[key].url === url && /^cid-[1-9][0-9]*$/.test(key)) add(key.slice(4));
    });
    var ids = Array.from(new Set(candidates));
    return !invalid && ids.length === 1 ? ids[0] : null;
  }

  function isNestedStatement(stmt) {
    var ext = stmt?.object?.definition?.extensions || {};
    return Object.prototype.hasOwnProperty.call(ext, 'http://h5p.org/x-api/h5p-subContentId') ||
      Object.prototype.hasOwnProperty.call(ext, 'https://h5p.org/x-api/h5p-subContentId') ||
      /[?&]subContentId=/i.test(stmt?.object?.id || '');
  }

    function onXAPI(event) {
      try {
        var stmt = event?.data?.statement;
        if (!stmt || isNestedStatement(stmt)) return;

        var cid = extractContentId(event, stmt);
        if (!cid) { fkhsInputError = true; syncTransport(); return; }
        var verb = (stmt?.verb?.id || '').toLowerCase();

        // Vurder om dette er "evaluering"
        var hasScore   = !!(stmt?.result?.score && (typeof stmt.result.score.raw !== 'undefined' || typeof stmt.result.score.max !== 'undefined'));
        var hasSuccess = (typeof stmt?.result?.success !== 'undefined');
        var hasCompl   = (typeof stmt?.result?.completion !== 'undefined');

        var isEvalVerb = /\/(answered|completed|passed|failed|scored|submitted)$/.test(verb);
        var isAttemptStart = /\/(attempted|initialized)$/.test(verb);

        if (cid && isAttemptStart) {
          // Ny runde: nullstill "evaluert" til vi faktisk får en evaluering
          fkhsEvaluated[cid] = false;
        }
        if (cid && (isEvalVerb || hasScore || hasSuccess || hasCompl)) {
          fkhsEvaluated[cid] = true;
        }

        if (window.fkH5P?.debug) {
          console.debug('[FKHS] xAPI → POST', {
            cid,
            verb: stmt?.verb?.id,
            hasResult: !!stmt.result,
            result: stmt.result
          });
        }

        // Post alltid original-eventet
        postXAPI(stmt, cid);

      } catch (e) {
        fkhsInputError = true; syncTransport();
        if (window.fkH5P?.debug) console.warn('[FKHS] onXAPI error', e);
      }
    }





  var fkhsBound = false;
  var fkhsBindTimer = null;
  var fkhsBindChecks = 0;
  var fkhsBindDeadline = 0;
  var fkhsRefreshOnBind = false;

  function checkH5PTracking() {
    fkhsBindTimer = null;
    if (fkhsBound || fkhsRuntimeState !== 'waiting') return;
    if (Date.now() >= fkhsBindDeadline) {
      fkhsRuntimeState = 'failed';
      syncTransport();
      return;
    }
    var dispatcher = window.H5P?.externalDispatcher;
    if (typeof dispatcher?.on === 'function') {
      try {
        dispatcher.on('xAPI', onXAPI);
        fkhsBound = true;
        fkhsRuntimeState = 'ready';
        syncTransport();
        // A late/retried bind needs fresh progress, never a stale pre-wait gate.
        if (fkhsRefreshOnBind || fkhsBindChecks > 0) fetchAndRenderH5PStatus();
        return;
      } catch (_) {
        fkhsRuntimeState = 'failed';
      }
    } else if (fkhsBindChecks++ < 50) {
      // One finite 15-second polling window. Only an explicit retry starts another.
      fkhsBindTimer = setTimeout(checkH5PTracking, 300);
      return;
    } else {
      fkhsRuntimeState = 'failed';
    }
    syncTransport();
  }

  function startH5PTracking(retry) {
    if (fkhsBound || fkhsRuntimeState === 'waiting') return;
    if (!retry && !window.fkH5P?.require) {
      fkhsRuntimeState = 'unused';
      return;
    }
    fkhsBindChecks = 0;
    fkhsBindDeadline = Date.now() + 15000;
    fkhsRefreshOnBind = !!retry;
    fkhsRuntimeState = 'waiting';
    syncTransport();
    checkH5PTracking();
  }

  function boot(){
    if (window.fkH5P?.debug) console.debug('[FKHS] boot', window.fkH5P);

    setGate(false);
    setQuizButtonVisibility(window.fkhsGatePassed);

    var gateRefreshPending = false;
    var mo = new MutationObserver(function(records){
      var selector = fkhsActionSelector + ',' + fkhsCompletedSelector;
      var relevant = records.some(function(record) {
        return Array.from(record.addedNodes).concat(Array.from(record.removedNodes)).some(function(node) {
          return node.nodeType === 1 && (node.matches(selector) || node.querySelector(selector));
        });
      });
      if (!relevant || gateRefreshPending) return;
      gateRefreshPending = true;
      Promise.resolve().then(function() {
        gateRefreshPending = false;
        setQuizButtonVisibility(window.fkhsGatePassed);
      });
    });
    mo.observe(document.body, { childList: true, subtree: true });

    startH5PTracking(false);
    fetchAndRenderH5PStatus();
    window.addEventListener('beforeunload', function (event) {
      if (fkhsQueue.length) { event.preventDefault(); event.returnValue = ''; }
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
  else if (document.readyState) boot();

    function fetchAndRenderH5PStatus(){
      var generation = ++fkhsStatusVersion;
      fkhsStatusPending = true;
      syncTransport();
      try{
        var base = (window.fkH5P?.restUrl || '').replace(/\/h5p-xapi$/, '/h5p-status');
        var q = '?lesson_id=' + encodeURIComponent(window.fkH5P?.lessonId || 0) + '&_ts=' + Date.now();

        return requestJSON(base + q, {
          method: 'GET',
          credentials: 'same-origin',
          cache: 'no-store',
          headers: {
            'X-WP-Nonce': window.fkH5P?.nonce || '',
            'Cache-Control': 'no-cache, no-store, must-revalidate',
            'Pragma': 'no-cache',
            'Expires': '0'
          }
        })
        .then(function(json){
          if (generation !== fkhsStatusVersion) return false;
          if (!Array.isArray(json.items)) throw { retryable: true, reason: 'invalid_status' };
          fkhsStatusError = null; fkhsStatusPending = false;
          var overallPassed = true;

          json.items.forEach(function(item){
            var cid        = item.content_id;
            var latest     = item.latest || {};
            var best       = item.best || {};
            var threshold  = (typeof item.threshold === 'number') ? item.threshold : 0;
            var hasAttempt = !!(latest.has_attempt ?? item.has_attempt);

            var latestPct = (typeof latest.pct === 'number') ? latest.pct :
                            ((typeof latest.raw === 'number' && typeof latest.max === 'number' && latest.max > 0) ? (latest.raw/latest.max)*100 : null);
            var bestPct   = (typeof best.pct === 'number')   ? best.pct   :
                            ((typeof best.raw === 'number' && typeof best.max === 'number' && best.max > 0) ? (best.raw/best.max)*100 : null);

            // Count threshold==0 items først etter aktivitet (hasAttempt)
            var countsForGate = item.counts_for_gate ?? ((threshold > 0) || (threshold === 0 && hasAttempt));

            var requirementMet;
            if (threshold > 0){
              var bestPass   = (typeof bestPct === 'number')   && (bestPct   >= threshold);
              var latestPass = !!latest.passed || (typeof latestPct === 'number' && latestPct >= threshold);
              requirementMet = bestPass || latestPass;
            } else {
              requirementMet = !!latest.completed; // completion-only
            }

            if (typeof item.requirement_met === 'boolean') requirementMet = item.requirement_met;
            if (countsForGate && !requirementMet) {
              overallPassed = false;
            }

            // Tekster / overlays
            var useRaw = (typeof best.raw === 'number' ? best.raw : latest.raw);
            var useMax = (typeof best.max === 'number' ? best.max : latest.max);
            var usePct = (typeof bestPct === 'number' ? bestPct : latestPct);

            var titlePassed = window.fkH5P?.i18n?.taskPassed || 'Task passed';
            var titleFail   = (threshold > 0)
              ? (window.fkH5P?.i18n?.notPassedYet || 'Not passed yet')
              : (window.fkH5P?.i18n?.notCompletedYet || 'Not completed yet');

            var passedTxt, failTxt;
            if (threshold > 0) {
              passedTxt = (window.fkH5P?.i18n?.overlayPassed || 'This task is passed: %1$s / %2$s points (accepted).')
                .replace('%1$s', format(useRaw)).replace('%2$s', format(useMax));
              if (typeof usePct === 'number') passedTxt += ' (' + formatPct(usePct) + ')';

              failTxt = (window.fkH5P?.i18n?.overlayNotPassed || 'Not passed. Best score: %1$s / %2$s. Required: %3$s. Click to retry.')
                .replace('%1$s', format(best.raw)).replace('%2$s', format(best.max)).replace('%3$s', formatPct(threshold));
              if (typeof bestPct === 'number') failTxt = failTxt.replace('Best score:', 'Best score (' + formatPct(bestPct) + '):');
            } else {
              passedTxt = window.fkH5P?.i18n?.overlayCompleted || 'This task is completed.';
              failTxt   = window.fkH5P?.i18n?.overlayIncomplete || 'Not completed yet. Click to view.';
            }

            var targets = findH5PContainersById(cid);
            if (!targets.length) return;

            targets.forEach(function (t) {
              // Overlays kun etter første forsøk
              var wasEvaluated = !!fkhsEvaluated[cid];

                if (requirementMet) {
                  attachBadge(t, passedTxt, 'pass');
                  // Pass-overlay kun når evaluert (eller completion-only med completed)
                  var showPassOverlay = wasEvaluated || (threshold === 0 && hasAttempt && !!latest.completed);
                  if (showPassOverlay && t.dataset.fkhsDismissed !== '1') {
                    attachOverlay(t, 'pass', titlePassed, passedTxt);
                  }
                } else {
                  attachBadge(t, failTxt, 'fail', function(){ t.scrollIntoView({ behavior:'smooth', block:'center' }); });
                  // Fail-overlay KUN etter evaluering (ikke på hver "progressed")
                  if (hasAttempt && wasEvaluated) {
                    if (t.dataset.fkhsFailShown === '1') return;
                      t.dataset.fkhsFailShown = '1';
                    attachOverlay(t, 'fail', titleFail, failTxt);
                  }
                }
            });
          });

          // “Require” kun hvis minst ett gating-element finnes:
          //  – hvilken som helst threshold>0, eller
          //  – threshold==0 som har aktivitet (hasAttempt)
          var hasGating = json.items.some(function(item){
            var thr = (typeof item.threshold === 'number') ? item.threshold : 0;
            var hasAttempt = !!(item.latest?.has_attempt ?? item.has_attempt);
            return (thr > 0) || (thr === 0 && hasAttempt);
          });

          window.fkH5P = window.fkH5P || {};
          window.fkH5P.require = typeof json.required === 'boolean' ? json.required : hasGating;
          if (typeof json.passed === 'boolean') overallPassed = json.passed;

          setGate(overallPassed);
          setQuizButtonVisibility(window.fkhsGatePassed);

          // Statuspanel bruker samme gating-definisjon
          renderStatusPanelFromItems(json.items);
          syncTransport();
          return true;

        }).catch(function(err){
          if (generation !== fkhsStatusVersion) return false;
          fkhsStatusPending = false; fkhsStatusError = err; syncTransport();
          return false;
        });
      }catch(e){
        fkhsStatusPending = false; fkhsStatusError = { retryable: true }; syncTransport();
        return Promise.resolve(false);
      }
    }


  //bind();
})();
