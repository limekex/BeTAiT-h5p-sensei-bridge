(function () {
  'use strict';

  // ---------- i18n: kildestrenger per nøkkel ----------
  var MSG = {
    sort: 'Sort',
    clickToFilter: 'Click to filter',
    filteredClickToEdit: 'Filtered – click to edit',
    filter: 'Filter',
    user: 'User',
    lesson: 'Lesson',
    dateRange: 'Date range',
    scorePct: 'Score (%)',
    criterionPct: 'Criterion (%)',
    passed: 'Passed',
    completed: 'Completed',
    noFilterForColumn: 'No filter for this column.',
    clear: 'Clear',
    apply: 'Apply',
    resetFilters: 'Reset filters',
    prev: 'Prev',
    next: 'Next',
    rowsLabel: 'Rows: %d',
    any: 'Any',
    yes: 'Yes',
    no: 'No'
  };

  // ---------- i18n helper ----------
// i18n helper: bruk lokaliserte tekster fra PHP først, deretter wp.i18n som sekundær fallback.
function t(key, fallback) {
  // 1) Først: våre lokaliserte strenger fra wp_localize_script
  if (window.fkhsAdmin && window.fkhsAdmin.i18n && window.fkhsAdmin.i18n[key]) {
    return window.fkhsAdmin.i18n[key];
  }
  // 2) Deretter: prøv wp.i18n (hvis du senere går "full JSON" med __() i JS)
  try {
    if (window.wp && wp.i18n && typeof wp.i18n.__ === 'function') {
      return wp.i18n.__(fallback, 'h5p-sensei-bridge');
    }
  } catch (e) {}
  // 3) Til slutt: ren fallback-tekst
  return fallback;
}


  var TABLE_ID = 'fkhs-report-table';
  var PAGE_SIZES = [10, 25, 50, 100, 200];

  var rows = [];
  var filtered = [];
  var sortKey = 'date';
  var sortDir = 'desc';
  var page = 1;
  var pageSize = 50;
  var openPop = null;

  var filters = {
    user: '',
    lesson: '',
    dateFrom: '',
    dateTo: '',
    scoreCmp: 'any',
    scoreVal: '',
    critCmp: 'any',
    critVal: '',
    passed: 'any',
    completed: 'any'
  };

  // --- global popup closers (fixes: can't reopen after closing) ---
  var popOpen = null;
  function removeGlobalClosers() {
    window.removeEventListener('click', onWinClick, true);
    window.removeEventListener('keydown', onWinKey, true);
  }
  function onWinClick(e) {
    if (popOpen && popOpen.contains(e.target)) return;
    closePop();
    removeGlobalClosers();
  }
  function onWinKey(e) {
    if (e.key === 'Escape') {
      closePop();
      removeGlobalClosers();
    }
  }
  function addGlobalClosers() {
    window.addEventListener('click', onWinClick, true);
    window.addEventListener('keydown', onWinKey, true);
  }

  function $(sel, ctx){ return (ctx || document).querySelector(sel); }
  function $all(sel, ctx){ return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); }
  function toNum(v){ var n = parseFloat(v); return isNaN(n) ? null : n; }
  function cmp(a,b){ return a<b?-1:a>b?1:0; }
  function closePop(){
    if (openPop && openPop.parentNode) openPop.parentNode.removeChild(openPop);
    openPop = null;
    popOpen = null;
  }

  function injectStyles() {
    var css = `
      .fkhs-input,.fkhs-select{border:1px solid #ddd;border-radius:8px;padding:.35rem .5rem;font:inherit;background:#fff}
      .fkhs-btn{border:1px solid #ddd;border-radius:8px;padding:.35rem .6rem;background:#fff;cursor:pointer}
      .fkhs-btn:hover{background:#f6f6f6}
      .fkhs-pill{border:1px solid #ddd;border-radius:999px;padding:.25rem .6rem;background:#fafafa}
      .fkhs-pagination{display:flex;gap:.5rem;align-items:center;margin:.75rem 0}
      .fkhs-header{position:relative; white-space:nowrap; user-select:none}
      .fkhs-head-wrap{display:inline-flex;align-items:center;gap:.35rem}
      .fkhs-sort-btn{border:1px solid #ddd;border-radius:6px;padding:.1rem .35rem;background:#fff;font-size:12px;cursor:pointer;opacity:.8}
      .fkhs-sort-btn:hover{background:#f6f6f6;opacity:1}
      .fkhs-pop{position:absolute;background:#fff;border:1px solid #ddd;border-radius:12px;box-shadow:0 12px 32px rgba(0,0,0,.10);padding:.75rem;z-index:99999;min-width:240px}
      .fkhs-pop h4{margin:.25rem 0 .5rem 0;font-size:12px;color:#666;text-transform:uppercase;letter-spacing:.04em}
      .fkhs-pop .row{display:flex;gap:.5rem;margin:.35rem 0}
      .fkhs-pop .row > * {flex:1}
      .fkhs-small{font-size:12px;color:#666}
      .fkhs-filter-icon{display:inline-flex;align-items:center;justify-content:center;margin-left:.25rem;opacity:.45;transition:opacity .15s,transform .15s}
      .fkhs-header:hover .fkhs-filter-icon{opacity:.85;transform:translateY(-1px)}
      .fkhs-filter-icon svg{width:.8rem;height:.8rem;display:block;fill:currentColor;color:#64748b}
      .fkhs-filter-active .fkhs-filter-icon svg{color:#3478f6}
      .fkhs-filter-dot{width:.5rem;height:.5rem;border-radius:50%;background:#3478f6;display:inline-block;margin-left:.35rem;vertical-align:middle;opacity:.85}
      .fkhs-filter-inactive .fkhs-filter-dot{display:none}
    `;
    var el = document.createElement('style');
    el.textContent = css;
    document.head.appendChild(el);
  }

  function parseRows() {
    var table = document.getElementById(TABLE_ID);
    if (!table) return;
    var bodyRows = $all('tbody tr', table);
    rows = bodyRows.map(function(tr){
      var d = tr.dataset;
      return {
        el: tr,
        dateISO: d.dateIso || '',
        user: (d.user || '').toLowerCase(),
        lesson: (d.lessonTitle || '').toLowerCase(),
        scoreRaw: toNum(d.scoreRaw),
        scoreMax: toNum(d.scoreMax),
        criterion: toNum(d.criterion),
        passed: d.passed === '1',
        completed: d.completed === '1'
      };
    });
  }

  function withinDate(iso, from, to){
    if (!iso) return true;
    if (from && iso < from) return false;
    if (to && iso > to + 'T23:59:59') return false;
    return true;
  }

  function matchCmp(val, cmpType, target) {
    if (cmpType === 'any' || target === '') return true;
    var tn = toNum(target);
    if (tn === null) return true;
    if (val === null) return false;
    if (cmpType === 'ge') return val >= tn;
    if (cmpType === 'le') return val <= tn;
    return true;
  }

  function applyFilters() {
    filtered = rows.filter(function(r){
      if (filters.user && !r.user.includes(filters.user.toLowerCase())) return false;
      if (filters.lesson && !r.lesson.includes(filters.lesson.toLowerCase())) return false;
      if (!withinDate(r.dateISO, filters.dateFrom, filters.dateTo)) return false;

      var pct = (r.scoreRaw != null && r.scoreMax && r.scoreMax > 0) ? (r.scoreRaw / r.scoreMax) * 100 : null;
      var scoreVal = (pct != null) ? pct : (r.scoreRaw != null ? r.scoreRaw : null);
      if (!matchCmp(scoreVal, filters.scoreCmp, filters.scoreVal)) return false;

      if (!matchCmp(r.criterion, filters.critCmp, filters.critVal)) return false;

      if (filters.passed === 'yes' && !r.passed) return false;
      if (filters.passed === 'no'  &&  r.passed) return false;

      if (filters.completed === 'yes' && !r.completed) return false;
      if (filters.completed === 'no'  &&  r.completed) return false;

      return true;
    });
  }

  function sortData() {
    filtered.sort(function(a,b){
      var res = 0;
      switch (sortKey) {
        case 'date': res = cmp(a.dateISO, b.dateISO); break;
        case 'user': res = cmp(a.user, b.user); break;
        case 'lesson': res = cmp(a.lesson, b.lesson); break;
        case 'score':
          var ap = (a.scoreRaw != null && a.scoreMax>0) ? a.scoreRaw/a.scoreMax : -1;
          var bp = (b.scoreRaw != null && b.scoreMax>0) ? b.scoreRaw/b.scoreMax : -1;
          res = cmp(ap, bp);
          break;
        case 'criterion': res = cmp(a.criterion ?? -1, b.criterion ?? -1); break;
        case 'passed': res = cmp(a.passed?1:0, b.passed?1:0); break;
        case 'completed': res = cmp(a.completed?1:0, b.completed?1:0); break;
        default: res = 0;
      }
      return (sortDir === 'asc') ? res : -res;
    });
  }

  // Reorders DOM i henhold til filtrering/sortering/paginering
  function renderPage() {
    var table = document.getElementById(TABLE_ID);
    if (!table) return;
    var tbody = $('tbody', table);
    if (!tbody) return;

    var start = (page-1)*pageSize;
    var end = start + pageSize;

    rows.forEach(function(r){ r.el.style.display = 'none'; });

    var slice = filtered.slice(start, end);
    slice.forEach(function(r){
      r.el.style.display = '';
      tbody.appendChild(r.el);
    });

    // Page info (x–y / total)
    var total = filtered.length;
    var from = Math.min(total, start+1);
    var to = Math.min(total, end);
    var info = $('#fkhs-page-info');
    if (info) info.textContent = total ? (from + '–' + to + ' / ' + total) : '0 / 0';
  }

  function rebuild() {
    applyFilters();
    sortData();
    page = 1;
    renderPage();
    updateSortBtns();
    updateFilterIndicators();
  }

  // -------- Header UI (sort + column popup) --------
  function attachHeaders() {
    var table = document.getElementById(TABLE_ID);
    if (!table) return;

    $all('thead th', table).forEach(function(th){
      var key = th.getAttribute('data-key');
      if (!key) return;

      th.classList.add('fkhs-header');

      var wrap = document.createElement('span');
      wrap.className = 'fkhs-head-wrap';
      wrap.innerHTML = th.innerHTML;
      th.innerHTML = '';
      th.appendChild(wrap);

      // Sort
      var sortBtn = document.createElement('button');
      sortBtn.type = 'button';
      sortBtn.className = 'fkhs-sort-btn';
      sortBtn.title = t('sort');
      sortBtn.textContent = '↕';
      sortBtn.addEventListener('click', function(e){
        e.preventDefault(); e.stopPropagation();
        if (sortKey === key) {
          sortDir = (sortDir === 'asc') ? 'desc' : 'asc';
        } else {
          sortKey = key;
          sortDir = (key === 'date') ? 'desc' : 'asc';
        }
        applyFilters();
        sortData();
        page = 1;
        renderPage();
        updateSortBtns();
      });
      wrap.appendChild(sortBtn);

      // Alltid synlig filter-ikon
      var ficon = document.createElement('span');
      ficon.className = 'fkhs-filter-icon';
      ficon.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 5h18l-7 8v5l-4 2v-7L3 5z"/></svg>';
      wrap.appendChild(ficon);

      var dot = document.createElement('span');
      dot.className = 'fkhs-filter-dot';
      wrap.appendChild(dot);

      th.classList.add('fkhs-filter-inactive');
      th.title = t('clickToFilter');

      th.addEventListener('click', function(e){
        if (e.target === sortBtn) return;
        openFilterPopup(th, key);
      });
    });
  }

  function updateSortBtns() {
    var table = document.getElementById(TABLE_ID);
    if (!table) return;
    $all('thead th', table).forEach(function(th){
      var key = th.getAttribute('data-key');
      var btn = th.querySelector('.fkhs-sort-btn');
      if (!btn) return;
      if (key === sortKey) {
        btn.textContent = (sortDir === 'asc' ? '▲' : '▼');
        btn.style.opacity = '1';
      } else {
        btn.textContent = '↕';
        btn.style.opacity = '.8';
      }
    });
  }

  function isColumnFiltered(key){
    switch (key) {
      case 'user': return !!filters.user;
      case 'lesson': return !!filters.lesson;
      case 'date': return !!filters.dateFrom || !!filters.dateTo;
      case 'score': return filters.scoreCmp !== 'any' && filters.scoreVal !== '';
      case 'criterion': return filters.critCmp !== 'any' && filters.critVal !== '';
      case 'passed': return filters.passed !== 'any';
      case 'completed': return filters.completed !== 'any';
      default: return false;
    }
  }

  function updateFilterIndicators(){
    var table = document.getElementById(TABLE_ID);
    if (!table) return;
    $all('thead th', table).forEach(function(th){
      var key = th.getAttribute('data-key');
      var active = isColumnFiltered(key);
      th.classList.toggle('fkhs-filter-active',  active);
      th.classList.toggle('fkhs-filter-inactive', !active);
      th.title = active ? t('filteredClickToEdit') : t('clickToFilter');
    });
  }

  function openFilterPopup(th, key) {
    closePop();

    var rect = th.getBoundingClientRect();
    var pop = document.createElement('div');
    pop.className = 'fkhs-pop';
    pop.style.top = (window.scrollY + rect.bottom + 6) + 'px';
    var left = Math.min(window.scrollX + rect.left, window.scrollX + (document.documentElement.clientWidth - 260));
    pop.style.left = left + 'px';
    pop.addEventListener('click', function(e){ e.stopPropagation(); });

    var h = document.createElement('h4');
    h.textContent = t('filter');
    pop.appendChild(h);

    var content = document.createElement('div');

    if (key === 'user' || key === 'lesson') {
      content.appendChild(buildTextRow(key === 'user' ? t('user') : t('lesson'), key));
    } else if (key === 'date') {
      content.appendChild(buildDateRow());
    } else if (key === 'score') {
      content.appendChild(buildCompareRow(t('scorePct'), 'scoreCmp', 'scoreVal'));
    } else if (key === 'criterion') {
      content.appendChild(buildCompareRow(t('criterionPct'), 'critCmp', 'critVal'));
    } else if (key === 'passed') {
      content.appendChild(buildYNRow(t('passed'), 'passed'));
    } else if (key === 'completed') {
      content.appendChild(buildYNRow(t('completed'), 'completed'));
    } else {
      var no = document.createElement('div');
      no.className='fkhs-small';
      no.textContent = t('noFilterForColumn');
      content.appendChild(no);
    }

    var actions = document.createElement('div');
    actions.style.display='flex';
    actions.style.gap='.5rem';
    actions.style.justifyContent='flex-end';
    actions.style.marginTop='.5rem';

    var clearBtn = btn(t('clear'), function(){
      clearFilterForKey(key);
      updateFilterIndicators();
      rebuild();
      closePop();
      removeGlobalClosers();
    });

    var applyBtn = btn(t('apply'), function(){
      updateFilterIndicators();
      rebuild();
      closePop();
      removeGlobalClosers();
    });

    actions.append(clearBtn, applyBtn);

    pop.appendChild(content);
    pop.appendChild(actions);
    document.body.appendChild(pop);

    openPop = pop;
    popOpen = pop;

    addGlobalClosers();
  }

  function clearFilterForKey(key){
    if (key === 'user') filters.user = '';
    else if (key === 'lesson') filters.lesson = '';
    else if (key === 'date') { filters.dateFrom=''; filters.dateTo=''; }
    else if (key === 'score') { filters.scoreCmp='any'; filters.scoreVal=''; }
    else if (key === 'criterion') { filters.critCmp='any'; filters.critVal=''; }
    else if (key === 'passed') filters.passed='any';
    else if (key === 'completed') filters.completed='any';
  }

  // popup UI builders
  function buildTextRow(labelText, which){
    var wrap = document.createElement('div'); wrap.className='row';
    var label = document.createElement('label'); label.textContent = labelText; label.className='fkhs-small';
    var input = document.createElement('input'); input.type='text'; input.className='fkhs-input';
    input.value = (which === 'user') ? filters.user : filters.lesson;
    input.addEventListener('input', function(e){
      if (which==='user') filters.user=e.target.value.trim(); else filters.lesson=e.target.value.trim();
    });
    wrap.append(label, input); return wrap;
  }

  function buildDateRow(){
    var wrap = document.createElement('div');
    var l = document.createElement('div'); l.textContent=t('dateRange'); l.className='fkhs-small';
    var r = document.createElement('div'); r.className='row';
    var from = document.createElement('input'); from.type='date'; from.className='fkhs-input'; from.value=filters.dateFrom; from.addEventListener('change', function(e){ filters.dateFrom=e.target.value; });
    var to = document.createElement('input'); to.type='date'; to.className='fkhs-input'; to.value=filters.dateTo; to.addEventListener('change', function(e){ filters.dateTo=e.target.value; });
    r.append(from,to); wrap.append(l,r); return wrap;
  }

  function buildCompareRow(labelText, cmpKey, valKey){
    var wrap = document.createElement('div');
    var l = document.createElement('div'); l.textContent=labelText; l.className='fkhs-small';
    var r = document.createElement('div'); r.className='row';
    var sel = document.createElement('select'); sel.className='fkhs-select';
    [['any', t('any')], ['ge','≥'], ['le','≤']].forEach(function(p){
      var o=document.createElement('option'); o.value=p[0]; o.textContent=p[1]; sel.appendChild(o);
    });
    sel.value = filters[cmpKey]; sel.addEventListener('change', function(e){ filters[cmpKey]=e.target.value; });
    var num = document.createElement('input'); num.type='number'; num.className='fkhs-input'; num.min='0'; num.max='100'; num.step='1'; num.placeholder='%'; num.value=filters[valKey];
    num.addEventListener('input', function(e){ filters[valKey]=e.target.value; });
    r.append(sel,num); wrap.append(l,r); return wrap;
  }

  function buildYNRow(labelText, key){
    var wrap = document.createElement('div');
    var l = document.createElement('div'); l.textContent=labelText; l.className='fkhs-small';
    var r = document.createElement('div'); r.className='row';
    var sel = document.createElement('select'); sel.className='fkhs-select';
    [['any', t('any')], ['yes', t('yes')], ['no', t('no')]].forEach(function(p){
      var o=document.createElement('option'); o.value=p[0]; o.textContent=p[1]; sel.appendChild(o);
    });
    sel.value = filters[key]; sel.addEventListener('change', function(e){ filters[key]=e.target.value; });
    r.append(sel); wrap.append(l,r); return wrap;
  }

  function btn(label, onClick){
    var b = document.createElement('button');
    b.type='button';
    b.className='fkhs-btn';
    b.textContent=label;
    b.addEventListener('click', onClick);
    return b;
  }

  function buildPagination() {
    var bar = document.createElement('div');
    bar.className = 'fkhs-pagination';

    var reset = btn(t('resetFilters'), function(){
      filters = { user:'', lesson:'', dateFrom:'', dateTo:'', scoreCmp:'any', scoreVal:'', critCmp:'any', critVal:'', passed:'any', completed:'any' };
      rebuild();
    });

    var prev = btn('‹ ' + t('prev'), function(){
      if (page > 1) { page--; renderPage(); }
    });

    var info = document.createElement('span');
    info.id='fkhs-page-info';
    info.className='fkhs-pill';

    var next = btn(t('next') + ' ›', function(){
      var total = filtered.length; var pages = Math.max(1, Math.ceil(total/pageSize));
      if (page < pages) { page++; renderPage(); }
    });

    var sizeSel = document.createElement('select');
    sizeSel.className='fkhs-select';
    PAGE_SIZES.forEach(function(s){
      var o=document.createElement('option'); o.value=String(s);
      o.textContent = t('rowsLabel').replace('%d', s);
      sizeSel.appendChild(o);
    });
    sizeSel.value = String(pageSize);
    sizeSel.addEventListener('change', function(e){
      pageSize = parseInt(e.target.value,10)||50;
      page=1;
      renderPage();
    });

    bar.append(reset, prev, info, next, sizeSel);

    var table = document.getElementById(TABLE_ID);
    if (table && table.parentNode) table.parentNode.insertBefore(bar, table.nextSibling);
  }

  function init() {
    var table = document.getElementById(TABLE_ID);
    if (!table) return;

    injectStyles();
    parseRows();
    attachHeaders();
    updateFilterIndicators();
    applyFilters();
    sortData();
    renderPage();
    buildPagination();

    window.addEventListener('scroll', closePop);
  }

  document.addEventListener('DOMContentLoaded', init);
})();
