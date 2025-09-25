(function () {
  'use strict';
  // Global “gate”-status vi bruker overalt og
 // var fkhsGatePassed = false;
window.fkhsGatePassed = false;

// 2) Legg til en hjelpefunksjon:
function setGate(val) {
  window.fkhsGatePassed = !!val;
}

  // Generic style injector (prevents duplicates by id)
  function injectStyle(id, css){
    if (document.getElementById(id)) return;
    var el = document.createElement('style');
    el.id = id;
    el.textContent = css;
    document.head.appendChild(el);
  }

  // 1) Status panel styles (under lesson buttons)
injectStyle('fkhs-bridge-status-css', `
  .fkhs-status-panel{
    margin-top:.75rem; border:1px solid #e5e7eb; border-radius:12px; padding:.75rem 1rem;
    background:#fafafa; box-shadow:0 8px 18px rgba(0,0,0,.05)
  }
  .fkhs-status-title{font-weight:600; margin:0 0 .25rem 0}
  .fkhs-status-sub{color:#475569; margin:0 0 .5rem 0}
  .fkhs-status-list{margin:0; padding-left:1rem}
  .fkhs-status-list li{margin:.15rem 0}
  .fkhs-status-link{cursor:pointer; text-decoration:underline}

  /* NEW: when inside the lesson footer */
  .sensei-lesson-footer {display: block !important;}
  .sensei-lesson-footer > .fkhs-status-panel{
    /*display:block;*/
    7*width:auto;*/
    margin-top:0;         /* vi legger den først – null toppmarg */
    margin-bottom:1rem;   /* litt luft til resten */
    order:0;
    padding:.5rem 1rem;
  }
`);


  // 2) Overlay + badge styles (H5P containers)
  injectStyle('fkhs-bridge-overlay-css', `
    .fkhs-h5p-wrap{ position:relative; }

    .fkhs-h5p-overlay{
      position:absolute; inset:0; z-index:9; display:flex; align-items:center; justify-content:center;
      background:rgba(255,255,255,.88); backdrop-filter:saturate(1.2) blur(2px);
      border-radius:8px; text-align:center; padding:1rem;
    }
    .fkhs-h5p-overlay .inner{
      max-width:720px; background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:1rem 1.25rem;
      box-shadow:0 12px 28px rgba(0,0,0,.12);
    }
    .fkhs-h5p-overlay.pass .inner{ border-color:#c6f6d5; }
    .fkhs-h5p-overlay.fail .inner{ border-color:#fed7d7; }

    .fkhs-row{ display:flex; gap:.75rem; justify-content:center; margin-top:.75rem; flex-wrap:wrap; }
    .fkhs-btn-sm{
      background:#f8fafc; border:1px solid #e5e7eb; border-radius:8px; padding:.45rem .7rem; cursor:pointer;
    }
    .fkhs-btn-sm.primary{ background:#0ea5e9; color:#fff; border-color:#0ea5e9; }
    .fkhs-close{
      position:absolute; top:.5rem; right:.5rem; background:transparent; border:0; cursor:pointer; font-size:1rem; line-height:1;
    }

    /* Compact corner badge */
    .fkhs-h5p-badge{
      position:absolute; top:.5rem; right:.5rem; z-index:10;
      display:inline-block;
      border-radius:999px; padding:.25rem .6rem;
      font-size:.85rem; line-height:1; color:#fff;
      box-shadow:0 6px 18px rgba(0,0,0,.15); user-select:none
    }
    .fkhs-h5p-badge.pass{ background:#1e7f34; }
    .fkhs-h5p-badge.fail{ background:#b02a37; cursor:pointer; }
  `);

  // 3) Notice / toast under buttons (when clicking blocked CTAs)
  injectStyle('fkhs-bridge-notice-css', `
    .fkhs-quiz-warning{
      margin-top:.5rem; background:#0b5; color:#fff; border-radius:8px; padding:.5rem .75rem;
      font-size:.925rem; line-height:1.35; box-shadow:0 6px 18px rgba(0,0,0,.08)
    }
    .fkhs-quiz-warning.fkhs-warn{ background:#d9480f }
    .fkhs-quiz-warning.fkhs-bump{ animation:fkhs-bump .25s ease }
    @keyframes fkhs-bump{ 0%{transform:translateY(-2px)} 100%{transform:translateY(0)} }
  `);



  /**
   * Post a single xAPI statement to our REST endpoint.
   */
  function postXAPI(statement, contentId) {
    try {
      fetch(window.fkH5P?.restUrl, {
        method: 'POST',
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
      .then(function () {
        // Optimistic UI: update quiz-button state after we *think* we logged.
        var res = statement?.result;
        var raw = res?.score?.raw, max = res?.score?.max;
        var passed = !!res?.success;
        if (!passed && typeof raw === 'number' && typeof max === 'number' && max > 0) {
          passed = (raw / max) * 100 >= (window.fkH5P?.threshold || 70);
        }
        //fkhsGatePassed = !!passed;
        //setQuizButtonVisibility(passed);
        fetchAndRenderH5PStatus();
      })
      .catch(function () {});
    } catch (e) {}
  }


function showBlockedNotice(fromEl){
  try{
    var isQuiz = fromEl?.closest('.wp-block-sensei-lms-button-view-quiz, form[action*="quiz"], .sensei-quiz-link');
    var txt = isQuiz
      ? (window.fkH5P?.i18n?.blockedNoticeQuiz || 'You need to pass the in-lesson tasks before you can take the quiz.')
      : (window.fkH5P?.i18n?.blockedNoticeLesson || 'You need to pass the in-lesson tasks before you can complete the lesson.');

    // Finn container (knappene er som oftest i .sensei-buttons-container)
    var host = fromEl?.closest('.sensei-buttons-container')
           || fromEl?.closest('.wp-block-sensei-lms-button-view-quiz')
           || fromEl?.closest('form.lesson_button_form')
           || fromEl?.parentElement;

    if (!host || !host.parentNode) return;

    var notice = host.nextElementSibling && host.nextElementSibling.classList?.contains('fkhs-quiz-warning')
      ? host.nextElementSibling
      : null;

    if (!notice) {
      notice = document.createElement('div');
      notice.className = 'fkhs-quiz-warning fkhs-warn';
      notice.setAttribute('role', 'status');
      notice.setAttribute('aria-live', 'polite');
      notice.textContent = txt;
      host.parentNode.insertBefore(notice, host.nextSibling);
    } else {
      notice.textContent = txt;
      notice.classList.add('fkhs-bump');
      setTimeout(function(){ notice.classList.remove('fkhs-bump'); }, 250);
    }

    clearTimeout(notice._fkhsTimer);
    notice._fkhsTimer = setTimeout(function(){
      if (!notice.matches(':hover')) {
        notice.remove();
      }
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
function getButtonsHost(){
  // 1) Vanlige Sensei/Gutenberg-containere
  var host =
    document.querySelector('.sensei-buttons-container') ||
    document.querySelector('.sensei-lesson-actions') ||
    document.querySelector('.sensei-lesson-footer') ||
    document.querySelector('.wp-block-sensei-lms-button-view-quiz') ||
    document.querySelector('form.lesson_button_form');

  if (host) return host;

  // 2) Fallback: legg panelet rett etter siste H5P på siden
  var lastH5P =
    document.querySelector('.h5p-iframe[data-content-id]') ||
    document.querySelector('.h5p-content[id^="h5p-"]') ||
    null;

  if (lastH5P) {
    // Sett panelet inn i nærmeste fornuftige wrapper (entry-content / lesson-body)
    var wrap = lastH5P.closest('.entry-content, .lesson-content, article, .site-main, main') || lastH5P.parentElement;
    return wrap || lastH5P;
  }

  // 3) Worst-case: body (viser panelet rett under toppen av innholdet)
  return document.body;
}


function ensureStatusPanel(){
  // Finn eller opprett panel
  var panel = document.getElementById('fkhs-status-panel');
  if (!panel) {
    panel = document.createElement('div');
    panel.id = 'fkhs-status-panel';
    panel.className = 'fkhs-status-panel';
    panel.setAttribute('role','status');
    panel.setAttribute('aria-live','polite');
  }

  // 1) Foretrekk: helt først i lesson footer
  var footer = document.querySelector('.sensei-lesson-footer');
  if (footer) {
    if (footer.firstChild !== panel) {
      footer.insertBefore(panel, footer.firstChild);
    }
    panel.style.display = 'block';
    panel.style.width = '100%';
    if (window.fkH5P?.debug) panel.style.outline = '2px dashed #10b981';
    return panel;
  }

  // 2) Fallback: inne i knappecontainer (dersom synlig)
  var buttons =
    document.querySelector('.sensei-buttons-container') ||
    document.querySelector('.sensei-lesson-actions') ||
    document.querySelector('form.lesson_button_form') ||
    document.querySelector('.wp-block-sensei-lms-button-view-quiz');

  if (buttons && getComputedStyle(buttons).display !== 'none') {
    if (panel.parentNode !== buttons) buttons.appendChild(panel);
    if (buttons.classList) buttons.classList.add('fkhs-has-status'); // ufarlig selv om CSS ikke brukes
    panel.style.display = 'block';
    panel.style.width = '100%';
    if (window.fkH5P?.debug) panel.style.outline = '2px dashed #10b981';
    return panel;
  }

  // 3) Fallback: etter siste H5P i innholds-wrapper
  var lastH5P = document.querySelector('.h5p-iframe[data-content-id]') ||
                document.querySelector('.h5p-content[id^="h5p-"]');
  if (lastH5P) {
    var wrap = lastH5P.closest('.entry-content, .lesson-content, article, .site-main, main') || lastH5P.parentElement || document.body;
    if (panel.parentNode !== wrap) wrap.appendChild(panel);
    panel.style.display = 'block';
    panel.style.width = '100%';
    if (window.fkH5P?.debug) panel.style.outline = '2px dashed #10b981';
    return panel;
  }

  // 4) Siste utvei: main/body
  var fallback = document.querySelector('main, .site-main, .entry-content, article, body') || document.body;
  if (panel.parentNode !== fallback) fallback.appendChild(panel);
  panel.style.display = 'block';
  panel.style.width = '100%';
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

  function attachOverlay(container, kind, title, body, opts){
    if (!container) return;
    // Fjern tidligere overlay
    [].slice.call(container.querySelectorAll('.fkhs-h5p-overlay')).forEach(function(el){ el.remove(); });

    var ov = document.createElement('div');
    ov.className = 'fkhs-h5p-overlay ' + (kind === 'pass' ? 'pass' : 'fail');

    var inner = document.createElement('div');
    inner.className = 'inner';
    inner.innerHTML = '<strong>'+title+'</strong><div style="margin-top:.35rem">'+body+'</div>';

    var row = document.createElement('div'); row.className = 'fkhs-row';

    if (kind === 'pass') {
      // Kan lukkes – enten X eller “Practice again”
      var close = document.createElement('button');
      close.className = 'fkhs-close';
      close.setAttribute('aria-label','Close');
      close.innerHTML = '×';
      close.addEventListener('click', function(){ ov.remove(); container.dataset.fkhsDismissed = '1'; });
      ov.appendChild(close);

      var again = document.createElement('button');
      again.className = 'fkhs-btn-sm';
      again.textContent = (window.fkH5P?.i18n?.practiceAgain || 'Practice again');
      again.addEventListener('click', function(){ ov.remove(); container.dataset.fkhsDismissed = '1'; });
      row.appendChild(again);
    } else {
      // Må tas på nytt – ingen X; “Retry now” fjerner bare overlay for å starte
      var retry = document.createElement('button');
      retry.className = 'fkhs-btn-sm primary';
      retry.textContent = (window.fkH5P?.i18n?.retryNow || 'Retry now');
      retry.addEventListener('click', function(){
        ov.remove();
        // scroll til oppgaven for å starte på nytt
        container.scrollIntoView({ behavior:'smooth', block:'center' });
      });
      row.appendChild(retry);
    }

    inner.appendChild(row);
    ov.appendChild(inner);
    container.appendChild(ov);
  }

  function renderStatusPanelFromItems(items){
    if (window.fkH5P?.debug) {
  console.debug('[FKHS] status items:', items);
}

  try{
    var panel = ensureStatusPanel();
    if (!panel) return;

    var total = items.length;
   if (!total) {
   panel.innerHTML =
     '<p class="fkhs-status-title">' + (window.fkH5P?.i18n?.statusHeading || 'Lesson tasks status') + '</p>' +
     '<p class="fkhs-status-sub">No H5P tasks detected for this lesson.</p>';
   return;
 }

    // tell hvor mange som er bestått (etter "beste forsøk"-logikk)
    var passedCount = 0;
    var remaining = [];
    items.forEach(function(item){
      var bestPct = (typeof item.best?.pct === 'number')
        ? item.best.pct
        : ((typeof item.best?.raw === 'number' && typeof item.best?.max === 'number' && item.best.max > 0)
            ? (item.best.raw/item.best.max)*100 : null);
      var latestPassed = !!item.latest?.passed;
      var threshold = item.threshold;
      var bestPasses = (typeof bestPct === 'number') && bestPct >= threshold;

      if (bestPasses || latestPassed) {
        passedCount++;
      } else {
        remaining.push(item);
      }
    });

    // hvis alt er bestått → skjul panel (eller vis en kort grønn beskjed)
    if (passedCount === total) {
      panel.innerHTML =
        '<p class="fkhs-status-title">' + (window.fkH5P?.i18n?.statusHeading || 'Lesson tasks status') + '</p>' +
        '<p class="fkhs-status-sub">' + (window.fkH5P?.i18n?.statusAllPassed || 'All tasks are passed. You can continue.') + '</p>';
      // kan alternativt: panel.remove();
      return;
    }

    var title = window.fkH5P?.i18n?.statusHeading || 'Lesson tasks status';
    var progress = (window.fkH5P?.i18n?.statusProgress || '%1$d of %2$d tasks passed')
                    .replace('%1$d', passedCount).replace('%2$d', total);
    var remainingLabel = window.fkH5P?.i18n?.statusRemaining || 'Remaining:';
    var viewLabel = window.fkH5P?.i18n?.statusViewTask || 'View task';

    var html = '<p class="fkhs-status-title">'+title+'</p>' +
               '<p class="fkhs-status-sub">'+progress+'</p>' +
               '<p class="fkhs-status-sub" style="margin-bottom:.25rem">'+remainingLabel+'</p>' +
               '<ul class="fkhs-status-list">';

      remaining.forEach(function(item){
        var t = item.title || ('H5P #' + item.content_id);

        // regn ut bestPct (fallback fra raw/max)
        var bestPct = (typeof item.best?.pct === 'number')
          ? item.best.pct
          : ((typeof item.best?.raw === 'number' && typeof item.best?.max === 'number' && item.best.max > 0)
              ? (item.best.raw / item.best.max) * 100
              : null);

        var bestTxt = (typeof bestPct === 'number') ? (Math.round(bestPct * 100) / 100) + '%' : '—';
        var needTxt = (typeof item.threshold === 'number') ? (Math.round(item.threshold * 100) / 100) + '%' : '—';

        // i18n: "%1$s — best %2$s, required %3$s"
        var line = (window.fkH5P?.i18n?.statusRemainingItem || '%1$s — best %2$s, required %3$s')
          .replace('%1$s', t.replace(/</g,'&lt;').replace(/>/g,'&gt;'))
          .replace('%2$s', bestTxt)
          .replace('%3$s', needTxt);

        html += '<li><span class="fkhs-status-link" data-cid="'+item.content_id+'">'+ line +'</span></li>';
      });
    html += '</ul>';

    panel.innerHTML = html;

    // klikk = scroll til riktig H5P
    panel.querySelectorAll('.fkhs-status-link').forEach(function(a){
      a.addEventListener('click', function(e){
        var cid = parseInt(e.currentTarget.getAttribute('data-cid'), 10);
        var targets = findH5PContainersById(cid);
        if (targets.length) {
          targets[0].scrollIntoView({ behavior:'smooth', block:'center' });
        }
      });
    });
  }catch(e){}
}


  /**
   * Hide/disable quiz CTA until passed (cosmetic; server-gate is authoritative).
   */
      function setQuizButtonVisibility(passed) {
        try {
          if (!window.fkH5P?.require) return;   // kravet er av
          if (window.fkH5P?.bypass)  return;    // admin/lærer omgår

          var tooltip = window.fkH5P?.i18n?.completeH5PFirst || 'Complete the H5P first';

          // Finn alle varianter: <a>, <button>, <form>
          var links = Array.prototype.slice.call(document.querySelectorAll(
            '.sensei-lesson-actions a[href*="quiz"], a.sensei-quiz-link, a[href*="/quiz/"], .sensei-buttons-container a[href*="quiz"]'
          ));

          var buttons = Array.prototype.slice.call(document.querySelectorAll(
            // Sensei-blokka med <button>
            '.wp-block-sensei-lms-button-view-quiz button.wp-block-button__link,' +
            // Din konkrete markup: data-id="complete-lesson-button"
            'form.lesson_button_form [data-id="complete-lesson-button"],' +
            // Fallback: alle knapper inne i “view quiz”-blokk
            '.wp-block-sensei-lms-button-view-quiz button'
          ));

          var forms = Array.prototype.slice.call(document.querySelectorAll(
            'form.lesson_button_form, .wp-block-sensei-lms-button-view-quiz form, .sensei-buttons-container form'
          )).filter(function (f) {
            var action = (f.getAttribute('action') || '').toLowerCase();
            return action.includes('/quiz/') || action.includes('view-quiz') || action.includes('quiz');
          });

          // Relevante verter rundt knappene/lenkene (brukes for capture-klikk)
          var hosts = Array.prototype.slice.call(document.querySelectorAll(
            '.sensei-buttons-container, .wp-block-sensei-lms-button-view-quiz, form.lesson_button_form'
          ));

          if (window.fkH5P?.debug) {
            console.debug('[FKHS] require:', window.fkH5P.require, 'bypass:', window.fkH5P.bypass, 'passed:', passed);
            console.debug('[FKHS] found links:', links.length, 'buttons:', buttons.length, 'forms:', forms.length, 'hosts:', hosts.length);
          }

          // Hjelpere for (en)able
          function disableEl(el) {
            if (!el) return;
            // Ikke bruk disabled/pointer-events; vi vil fange klikket og vise varsel.
            el.setAttribute('aria-disabled', 'true');
            el.style.opacity = '0.5';
            el.title = tooltip;
            el.classList.add('fkhs-locked');
          }
          function enableEl(el) {
            if (!el) return;
            el.removeAttribute('aria-disabled');
            el.style.opacity = '';
            el.title = '';
            el.classList.remove('fkhs-locked');
          }

          // Stopp både submit OG click når ikke bestått
          function preventSubmit(e) {
            if (!passed) {
              e.preventDefault();
              e.stopPropagation();
              var t = e.target.closest('button, a');
              if (t && !t.title) t.title = tooltip;
              showBlockedNotice(t);
            }
          }
          function preventClick(e) {
            if (!passed) {
              e.preventDefault();
              e.stopPropagation();
              var t = e.target.closest('button, a');
              if (t && !t.title) t.title = tooltip;
              showBlockedNotice(t);
            }
          }
          function hostClick(e) {
            if (passed) return;
            var t = e.target.closest('a, button');
            if (!t) return;
            // Kun stopp hvis "låst" eller markert aria-disabled
            if (t.classList.contains('fkhs-locked') || t.getAttribute('aria-disabled') === 'true') {
              e.preventDefault();
              e.stopPropagation();
              if (!t.title) t.title = tooltip;
              showBlockedNotice(t);
            }
          }
          function preventKey(e) {
            if (!passed && (e.key === 'Enter' || e.key === ' ')) {
              var t = e.target.closest('button, a');
              if (!t) return;
              e.preventDefault();
              e.stopPropagation();
              if (!t.title) t.title = tooltip;
              showBlockedNotice(t);
            }
          }

          if (passed) {
            // Enable alt
            links.forEach(enableEl);
            buttons.forEach(enableEl);

            forms.forEach(function (f) {
              f.removeEventListener('submit', preventSubmit, true);
            });

            // Fjern click/keydown-stoppere også
            links.forEach(function (a) {
              a.removeEventListener('click',   preventClick, true);
              a.removeEventListener('keydown', preventKey,   true);
            });
            buttons.forEach(function (b) {
              b.removeEventListener('click',   preventClick, true);
              b.removeEventListener('keydown', preventKey,   true);
            });

            // Fjern host-click “gate” når ulåst
            hosts.forEach(function (h) {
              if (h._fkhsLockBound) {
                h.removeEventListener('click', hostClick, true);
                h._fkhsLockBound = false;
              }
            });

          } else {
            // Disable alt
            links.forEach(disableEl);
            buttons.forEach(disableEl);

            // Fang både submit, click og keydown i capture-phase
            forms.forEach(function (f) {
              f.addEventListener('submit', preventSubmit, true);
            });
            links.forEach(function (a) {
              a.addEventListener('click',   preventClick, true);
              a.addEventListener('keydown', preventKey,   true);
            });
            buttons.forEach(function (b) {
              b.addEventListener('click',   preventClick, true);
              b.addEventListener('keydown', preventKey,   true);
            });

            // Legg til host-click “gate” når låst (men bare én gang)
            hosts.forEach(function (h) {
              if (!h._fkhsLockBound) {
                h.addEventListener('click', hostClick, true);
                h._fkhsLockBound = true;
              }
            });
          }

        } catch (e) {
          if (window.fkH5P?.debug) console.warn('[FKHS] setQuizButtonVisibility error', e);
        }
      }




  /**
   * Extract H5P content id robustly.
   */
  function extractContentId(evt, stmt, fallbackCid) {
    if (fallbackCid) return fallbackCid;

    // 1) Some events provide instance.contentId
    if (evt?.data?.instance?.contentId) {
      var cid = parseInt(evt.data.instance.contentId, 10);
      if (!isNaN(cid)) return cid;
    }

    // 2) From xAPI object.id (…action=h5p_embed&id=123 or trailing /123)
    var url = stmt?.object?.id || '';
    var m = url.match(/[?&]id=(\d+)/) || url.match(/\/(\d+)(?:\/|\?|$)/);
    if (m && m[1]) {
      var cid2 = parseInt(m[1], 10);
      if (!isNaN(cid2)) return cid2;
    }

    // 3) From DOM containers (iframe or wrapper)
    var iframe = document.querySelector('.h5p-iframe[data-content-id]');
    if (iframe && iframe.dataset.contentId) {
      var cid3 = parseInt(iframe.dataset.contentId, 10);
      if (!isNaN(cid3)) return cid3;
    }
    var wrap = document.querySelector('.h5p-content[id^="h5p-"]');
    if (wrap) {
      var mm = wrap.id.match(/h5p-(\d+)/);
      if (mm && mm[1]) {
        var cid4 = parseInt(mm[1], 10);
        if (!isNaN(cid4)) return cid4;
      }
    }

    // 4) From H5PIntegration.contents (cid-123)
    if (window.H5PIntegration?.contents) {
      var keys = Object.keys(window.H5PIntegration.contents);
      for (var i = 0; i < keys.length; i++) {
        var k = keys[i];
        var mm2 = k.match(/^cid-(\d+)$/);
        if (mm2 && mm2[1]) {
          var cid5 = parseInt(mm2[1], 10);
          if (!isNaN(cid5)) return cid5;
        }
      }
    }

    return null;
  }

  function onXAPI(event) {
    try {
      var stmt = event?.data?.statement;
      if (!stmt || !stmt.result) return;
      var cid = extractContentId(event, stmt, event?.data?.contentId);
      postXAPI(stmt, cid);
    } catch (e) {}
  }

  function bind() {
    if (window.H5P && H5P.externalDispatcher) {
      H5P.externalDispatcher.on('xAPI', onXAPI);
      if (window.fkH5P?.debug) console.log('[FKHS] Bound H5P xAPI listener');
    } else {
      setTimeout(bind, 300);
    }
  }

  // On load: be conservative; hide quiz CTA until passed.
    document.addEventListener('DOMContentLoaded', function(){
      if (window.fkH5P?.debug) console.debug('[FKHS] boot', window.fkH5P);

      // start konservativt (låst) til vi har status
      setGate(false);
      setQuizButtonVisibility(window.fkhsGatePassed);

      // Ved DOM-endringer: behold nåværende sannhet, ikke hardkode false
      var mo = new MutationObserver(function(){
        //setQuizButtonVisibility(fkhsGatePassed);
        setQuizButtonVisibility(window.fkhsGatePassed);
      });
      mo.observe(document.body, { childList: true, subtree: true });

      // Hent status
      fetchAndRenderH5PStatus();
    });


function fetchAndRenderH5PStatus(){
  try {
    var base = (window.fkH5P?.restUrl || '').replace(/\/h5p-xapi$/, '/h5p-status');
    var q = '?lesson_id=' + encodeURIComponent(window.fkH5P?.lessonId || 0) +
            '&_ts=' + Date.now(); // cache-bust

    fetch(base + q, {
      method: 'GET',
      credentials: 'same-origin',
      cache: 'no-store', // viktig mot nettleser-cache
      headers: {
        'X-WP-Nonce': window.fkH5P?.nonce || '',
        // ekstra signaler mot mellomlagre / proxy
        'Cache-Control': 'no-cache, no-store, must-revalidate',
        'Pragma': 'no-cache',
        'Expires': '0'
      }
    })
    .then(function(r){ return r.ok ? r.json() : Promise.reject(r); })
    .then(function(json){
      if (!json?.ok || !Array.isArray(json.items)) return;

      var overallPassed = true; // anta passert til vi finner unntak

      json.items.forEach(function(item){
        var cid       = item.content_id;
        var latest    = item.latest || {};
        var best      = item.best || {};
        var threshold = item.threshold;

        var latestPct = (typeof latest.pct === 'number')
          ? latest.pct
          : ((typeof latest.raw === 'number' && typeof latest.max === 'number' && latest.max > 0)
              ? (latest.raw/latest.max)*100 : null);

        var bestPct = (typeof best.pct === 'number')
          ? best.pct
          : ((typeof best.raw === 'number' && typeof best.max === 'number' && best.max > 0)
              ? (best.raw/best.max)*100 : null);

        var bestPasses   = (typeof bestPct === 'number') && (bestPct >= threshold);
        var latestPasses = !!latest.passed;

        if (!(bestPasses || latestPasses)) overallPassed = false;

        var useRaw = (typeof best.raw === 'number' ? best.raw : latest.raw);
        var useMax = (typeof best.max === 'number' ? best.max : latest.max);
        var usePct = (typeof bestPct === 'number' ? bestPct : latestPct);

        var passedTxt = (window.fkH5P?.i18n?.overlayPassed || 'This task is passed: %1$s / %2$s points (accepted).')
          .replace('%1$s', format(useRaw))
          .replace('%2$s', format(useMax));
        if (typeof usePct === 'number') passedTxt += ' (' + formatPct(usePct) + ')';

        var failTxt = (window.fkH5P?.i18n?.overlayNotPassed || 'Not passed. Best score: %1$s / %2$s. Required: %3$s. Click to retry.')
          .replace('%1$s', format(best.raw))
          .replace('%2$s', format(best.max))
          .replace('%3$s', formatPct(threshold));
        if (typeof bestPct === 'number') {
          failTxt = failTxt.replace('Best score:', 'Best score (' + formatPct(bestPct) + '):');
        }

        var targets = findH5PContainersById(cid);
        if (!targets.length) return;

        targets.forEach(function (t) {
          var titlePassed = window.fkH5P?.i18n?.taskPassed || 'Task passed';
          var titleFail   = window.fkH5P?.i18n?.notPassedYet || 'Not passed yet';

          if (bestPasses || latestPasses) {
            attachBadge(t, passedTxt, 'pass');
            if (t.dataset.fkhsDismissed !== '1') {
              attachOverlay(t, 'pass', titlePassed, passedTxt, {});
            }
          } else {
            attachBadge(t, failTxt, 'fail', function () {
              t.scrollIntoView({ behavior: 'smooth', block: 'center' });
            });
            attachOverlay(t, 'fail', titleFail, failTxt, {});
          }
        });
      });

      // global sannhet + CTA
      setGate(overallPassed);
      setQuizButtonVisibility(window.fkhsGatePassed);

      // statuspanelet under knappene
      renderStatusPanelFromItems(json.items);
    })
    .catch(function(err){
      if (window.fkH5P?.debug) console.warn('[FKHS] status fetch failed', err);
    });
  } catch(e){
    if (window.fkH5P?.debug) console.warn('[FKHS] status error', e);
  }
}



  bind();
})();
