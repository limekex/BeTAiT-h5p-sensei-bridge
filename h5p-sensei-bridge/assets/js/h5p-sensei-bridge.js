(function () {
  'use strict';

  window.fkhsGatePassed = false;
  function setGate(val){ window.fkhsGatePassed = !!val; }

  function injectStyle(id, css){
    if (document.getElementById(id)) return;
    var el = document.createElement('style'); el.id = id; el.textContent = css; document.head.appendChild(el);
  }

  // Map of completion-only H5P ids
var fkhsCompletionOnly = new Set();
// Debounce timers and done-flags per content id
var fkhsSynthTimers = Object.create(null);
var fkhsSynthDone   = Object.create(null);
var fkhsCompletionReady = false;        // blir true når /h5p-status er lest
var fkhsReplayOnce = Object.create(null); // engangs-retry per cid
// Track om siste hendelse var en faktisk evaluering
var fkhsEvaluated = Object.create(null);


// Fire a synthetic "completed" statement for an H5P with threshold==0
function fkhsSendSyntheticCompleted(cid) {
  if (!cid || fkhsSynthDone[cid]) return;
  fkhsSynthDone[cid] = true;

  if (window.fkH5P?.debug) {
    console.debug('[FKHS] synthetic completed → POST', { cid });
  }

  // Minimal xAPI that our REST understands (server relies on result.completion / success)
  var stmt = {
    verb:   { id: 'http://adlnet.gov/expapi/verbs/completed' },
    result: { completion: true, success: true }
    // (We don't need to spoof actor/object — contentId is passed separately.)
  };

  postXAPI(stmt, cid);
}


  injectStyle('fkhs-bridge-status-css', `
    .fkhs-status-panel{ margin-top:.75rem; border:1px solid #e5e7eb; border-radius:12px; padding:.75rem 1rem; background:#fafafa; box-shadow:0 8px 18px rgba(0,0,0,.05) }
    .fkhs-status-title{font-weight:600; margin:0 0 .25rem 0}
    .fkhs-status-sub{color:#475569; margin:0 0 .5rem 0}
    .fkhs-status-list{margin:0; padding-left:1rem}
    .fkhs-status-list li{margin:.15rem 0}
    .fkhs-status-link{cursor:pointer; text-decoration:underline}
    .sensei-lesson-footer{display:block !important;}
    .sensei-lesson-footer > .fkhs-status-panel{ margin-top:0; margin-bottom:1rem; order:0; padding:.5rem 1rem; }
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

    function postXAPI(statement, contentId) {
      try {
        fetch(window.fkH5P?.restUrl, {
          method: 'POST',
          credentials: 'same-origin',       // 👈 VIKTIG
          cache: 'no-store',                // 👈 unngå mellomlagring
          headers: {
            'Content-Type': 'application/json',
            'X-WP-Nonce': window.fkH5P?.nonce
          },
          body: JSON.stringify({
            statement: statement,
            contentId: contentId || null,
            lesson_id: window.fkH5P?.lessonId || null,
            threshold: window.fkH5P?.threshold || 70
          })
        })
        .then(function (r) { return r.ok ? r.json() : Promise.reject(r); })
        .then(function () {
          fetchAndRenderH5PStatus();
        })
        .catch(function (e) {
          if (window.fkH5P?.debug) console.warn('[FKHS] xAPI POST failed', e);
        });
      } catch (e) {}
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
    var footer = document.querySelector('.sensei-lesson-footer');
    if (footer) {
      if (footer.firstChild !== panel) footer.insertBefore(panel, footer.firstChild);
      panel.style.display = 'block'; panel.style.width = '100%';
      if (window.fkH5P?.debug) panel.style.outline = '2px dashed #10b981';
      return panel;
    }
    var buttons = document.querySelector('.sensei-buttons-container') ||
                  document.querySelector('.sensei-lesson-actions') ||
                  document.querySelector('form.lesson_button_form') ||
                  document.querySelector('.wp-block-sensei-lms-button-view-quiz');
    if (buttons && getComputedStyle(buttons).display !== 'none') {
      if (panel.parentNode !== buttons) buttons.appendChild(panel);
      buttons.classList && buttons.classList.add('fkhs-has-status');
      panel.style.display = 'block'; panel.style.width = '100%';
      if (window.fkH5P?.debug) panel.style.outline = '2px dashed #10b981';
      return panel;
    }
    var lastH5P = document.querySelector('.h5p-iframe[data-content-id]') ||
                  document.querySelector('.h5p-content[id^="h5p-"]');
    if (lastH5P) {
      var wrap = lastH5P.closest('.entry-content, .lesson-content, article, .site-main, main') || lastH5P.parentElement || document.body;
      if (panel.parentNode !== wrap) wrap.appendChild(panel);
      panel.style.display = 'block'; panel.style.width = '100%';
      if (window.fkH5P?.debug) panel.style.outline = '2px dashed #10b981';
      return panel;
    }
    var fallback = document.querySelector('main, .site-main, .entry-content, article, body') || document.body;
    if (panel.parentNode !== fallback) fallback.appendChild(panel);
    panel.style.display = 'block'; panel.style.width = '100%';
    if (window.fkH5P?.debug) panel.style.outline = '2px dashed #10b981';
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

  // Stable identities: repeated renders must not retain stale blocking closures.
  var fkhsGateLocked = false;
  var fkhsGateTooltip = '';
  var fkhsOriginalControls = new WeakMap();
    function showBlock(e){
      var t=e?.target?.closest('button,a'); if(!t) return;
      if(!t.title) t.title=fkhsGateTooltip;
      showBlockedNotice(t);
    }
    function preventSubmit(e){ if(fkhsGateLocked){ e.preventDefault(); e.stopPropagation(); showBlock(e); } }
    function preventClick(e){  if(fkhsGateLocked){ e.preventDefault(); e.stopPropagation(); showBlock(e); } }
    function hostClick(e){
      if (!fkhsGateLocked) return;
      var t=e.target.closest('a,button'); if(!t) return;
      if (t.classList.contains('fkhs-locked') || t.getAttribute('aria-disabled')==='true' || t.disabled){
        e.preventDefault(); e.stopPropagation(); showBlockedNotice(t);
      }
    }
    function preventKey(e){
      if(fkhsGateLocked && (e.key==='Enter'||e.key===' ')){
        var t=e.target.closest('button,a'); if(!t) return;
        e.preventDefault(); e.stopPropagation(); showBlockedNotice(t);
      }
    }

  function setQuizButtonVisibility(passed) {
  try {
    var require = !!(window.fkH5P && window.fkH5P.require);
    var bypass  = !!(window.fkH5P && window.fkH5P.bypass);

    // Hvis ingen gating: oppfør deg som "passed"
    if (!require) passed = true;
    if (bypass)   passed = true;

    if (window.fkH5P?.debug) {
      console.debug('[FKHS] setQuizButtonVisibility', { require, bypass, passed });
    }

    var tooltip = window.fkH5P?.i18n?.completeH5PFirst || 'Complete the H5P first';
    fkhsGateLocked = !passed;
    fkhsGateTooltip = tooltip;

    // Kjente noder
    var links = [].slice.call(document.querySelectorAll(
      '.sensei-lesson-actions a[href*="quiz"], a.sensei-quiz-link, a[href*="/quiz/"], .sensei-buttons-container a[href*="quiz"]'
    ));
    var buttons = [].slice.call(document.querySelectorAll(
      '.wp-block-sensei-lms-button-view-quiz button.wp-block-button__link,' +
      'form.lesson_button_form [data-id="complete-lesson-button"],' +
      '.wp-block-sensei-lms-button-view-quiz button,' +
      '.lesson_button_form button,' +
      'button.complete-lesson,' +
      '.sensei-lesson-actions button'
    ));
    var forms = [].slice.call(document.querySelectorAll(
      'form.lesson_button_form, .wp-block-sensei-lms-button-view-quiz form, .sensei-buttons-container form'
    )).filter(function (f) {
      var action = (f.getAttribute('action') || '').toLowerCase();
      return action.includes('/quiz/') || action.includes('view-quiz') || action.includes('quiz');
    });
    var hosts = [].slice.call(document.querySelectorAll(
      '.sensei-buttons-container, .wp-block-sensei-lms-button-view-quiz, form.lesson_button_form, .sensei-lesson-actions, .sensei-lesson-footer'
    ));

    // Sørg for at wrapperne faktisk er synlige når passert
    if (passed) {
      document.querySelectorAll('.sensei-lesson-footer, .sensei-lesson-actions, .sensei-buttons-container')
        .forEach(function(el){ el.style.display = ''; el.classList.remove('fkhs-hidden'); });
    }

    function disableEl(el){
      if(!el) return;
      if (!fkhsOriginalControls.has(el)) {
        fkhsOriginalControls.set(el, { aria: el.getAttribute('aria-disabled'), opacity: el.style.opacity, title: el.title, disabled: el.disabled });
      }
      el.setAttribute('aria-disabled','true');
      el.style.opacity='0.5';
      el.title=tooltip;
      el.classList.add('fkhs-locked');
      if ('disabled' in el) try { el.disabled = true; } catch(_) {}
    }
    function enableEl(el){
      if(!el) return;
      var original = fkhsOriginalControls.get(el);
      if (!original) return; // Never enable a control disabled by Sensei/another plugin.
      if (original.aria === null) el.removeAttribute('aria-disabled');
      else el.setAttribute('aria-disabled', original.aria);
      el.style.opacity=original.opacity;
      el.title=original.title;
      el.classList.remove('fkhs-locked');
      if ('disabled' in el) el.disabled=original.disabled;
      fkhsOriginalControls.delete(el);
    }


    if (passed) {
      links.forEach(enableEl); buttons.forEach(enableEl);
      forms.forEach(function (f){ f.removeEventListener('submit', preventSubmit, true); });
      links.forEach(function (a){ a.removeEventListener('click', preventClick, true); a.removeEventListener('keydown', preventKey, true); });
      buttons.forEach(function (b){ b.removeEventListener('click', preventClick, true); b.removeEventListener('keydown', preventKey, true); });
      hosts.forEach(function (h){ if (h._fkhsLockBound){ h.removeEventListener('click', hostClick, true); h._fkhsLockBound=false; } });
    } else {
      links.forEach(disableEl); buttons.forEach(disableEl);
      forms.forEach(function (f){ f.addEventListener('submit', preventSubmit, true); });
      links.forEach(function (a){ a.addEventListener('click', preventClick, true); a.addEventListener('keydown', preventKey, true); });
      buttons.forEach(function (b){ b.addEventListener('click', preventClick, true); b.addEventListener('keydown', preventKey, true); });
      hosts.forEach(function (h){ if (!h._fkhsLockBound){ h.addEventListener('click', hostClick, true); h._fkhsLockBound=true; } });
    }

    if (window.fkH5P?.debug) {
      console.debug('[FKHS] setQuizButtonVisibility affected', {
        links: links.length, buttons: buttons.length, forms: forms.length, hosts: hosts.length
      });
    }
  } catch (e) {
    if (window.fkH5P?.debug) console.warn('[FKHS] setQuizButtonVisibility error', e);
  }
}


  function extractContentId(evt, stmt, fallbackCid) {
    if (fallbackCid) return fallbackCid;
    if (evt?.data?.instance?.contentId) {
      var cid = parseInt(evt.data.instance.contentId, 10); if (!isNaN(cid)) return cid;
    }
    var url = stmt?.object?.id || '';
    var m = url.match(/[?&]id=(\d+)/) || url.match(/\/(\d+)(?:\/|\?|$)/);
    if (m && m[1]) { var cid2 = parseInt(m[1], 10); if (!isNaN(cid2)) return cid2; }
    var iframe = document.querySelector('.h5p-iframe[data-content-id]'); if (iframe && iframe.dataset.contentId) {
      var cid3 = parseInt(iframe.dataset.contentId, 10); if (!isNaN(cid3)) return cid3;
    }
    var wrap = document.querySelector('.h5p-content[id^="h5p-"]'); if (wrap) {
      var mm = wrap.id.match(/h5p-(\d+)/); if (mm && mm[1]) { var cid4 = parseInt(mm[1], 10); if (!isNaN(cid4)) return cid4; }
    }
    if (window.H5PIntegration?.contents) {
      var keys = Object.keys(window.H5PIntegration.contents);
      for (var i=0;i<keys.length;i++){ var k=keys[i]; var mm2=k.match(/^cid-(\d+)$/); if (mm2 && mm2[1]) { var cid5=parseInt(mm2[1],10); if(!isNaN(cid5)) return cid5; } }
    }
    return null;
  }

    function onXAPI(event) {
      try {
        var stmt = event?.data?.statement;
        if (!stmt) return;

        var cid = extractContentId(event, stmt, event?.data?.contentId);
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

        // (syntetisk completed for threshold==0 beholdes uendret)
        var isActivity = /\/(attempted|progressed|experienced|interacted|initialized)$/.test(verb);
        if (cid && isActivity && fkhsCompletionOnly.has(cid) && !fkhsSynthDone[cid]) {
          clearTimeout(fkhsSynthTimers[cid]);
          fkhsSynthTimers[cid] = setTimeout(function() {
            fkhsSendSyntheticCompleted(cid);
          }, 900);
        }
      } catch (e) {
        if (window.fkH5P?.debug) console.warn('[FKHS] onXAPI error', e);
      }
    }





  var fkhsBound = false;
    function bind() {
      if (fkhsBound) return;
      if (window.H5P && H5P.externalDispatcher) {
        H5P.externalDispatcher.on('xAPI', onXAPI);
        fkhsBound = true;
        if (window.fkH5P?.debug) console.log('[FKHS] Bound H5P xAPI listener');
      } else {
        setTimeout(bind, 300);
      }
    }

  document.addEventListener('DOMContentLoaded', function(){
    if (window.fkH5P?.debug) console.debug('[FKHS] boot', window.fkH5P);

    setGate(false);
    setQuizButtonVisibility(window.fkhsGatePassed);

    var mo = new MutationObserver(function(){ setQuizButtonVisibility(window.fkhsGatePassed); });
    mo.observe(document.body, { childList: true, subtree: true });

    // 1) Hent status (fyller fkhsCompletionOnly)
    fetchAndRenderH5PStatus();

    // 2) Bind H5P-lytter litt etterpå (reduserer race mot fkhsCompletionOnly)
    setTimeout(bind, 400);
  });

    function fetchAndRenderH5PStatus(){
      try{
        var base = (window.fkH5P?.restUrl || '').replace(/\/h5p-xapi$/, '/h5p-status');
        var q = '?lesson_id=' + encodeURIComponent(window.fkH5P?.lessonId || 0) + '&_ts=' + Date.now();

        fetch(base + q, {
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
        .then(function(r){ return r.ok ? r.json() : Promise.reject(r); })
        .then(function(json){
          if (window.fkH5P?.debug) console.debug('[FKHS] status items length:', json.items?.length, json);
          if (!json?.ok || !Array.isArray(json.items)) return;

          // NEW: bygg/oppdater sett av completion-only (threshold==0)
          fkhsCompletionOnly = new Set();
          json.items.forEach(function(it){
            if (typeof it.threshold === 'number' && it.threshold === 0) {
              fkhsCompletionOnly.add(it.content_id);
            }
          });
          fkhsCompletionReady = true;
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

        }).catch(function(err){
          if (window.fkH5P?.debug) console.warn('[FKHS] status fetch failed', err);
        });
      }catch(e){
        if (window.fkH5P?.debug) console.warn('[FKHS] status error', e);
      }
    }


  //bind();
})();
