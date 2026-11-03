(function () {
  'use strict';
  var config = window.fkhsCourseProgress;
  if (!config) return;
  var roots = Array.from(document.querySelectorAll('[data-fkhs-course-progress]'));
  if (!roots.length) return;
  var labels = config.labels, generation = 0, controller, timer;
  function element(tag, text, className) {
    var node = document.createElement(tag);
    if (text != null) node.textContent = text;
    if (className) node.className = className;
    return node;
  }
  function count(row) { return labels.count.replace('%1$d', row.met_count).replace('%2$d', row.required_count); }
  function optional(row) { return labels.optional.replace('%d', row.optional_count); }
  function safeUrl(value) {
    try {
      var url = new URL(value, window.location.href);
      return /^https?:$/.test(url.protocol) && url.origin === window.location.origin ? url.href : null;
    } catch (_) { return null; }
  }
  var views = roots.map(function (root) {
    root.replaceChildren();
    var title = element('h2', labels.title), message = element('p', '', 'fkhs-progress-message');
    message.setAttribute('role', 'status');
    var refresh = element('button', labels.refresh, 'fkhs-progress-link');
    refresh.type = 'button';
    refresh.addEventListener('click', function () { if (refresh.getAttribute('aria-disabled') !== 'true') load(); });
    var body = element('div', null, 'fkhs-progress-body');
    root.append(title, message, refresh, body);
    return {root: root, message: message, refresh: refresh, body: body};
  });
  function validCounts(row) {
    return ['required_count', 'met_count', 'optional_count', 'activity_count'].every(function (key) {
      return Number.isSafeInteger(row[key]) && row[key] >= 0 && row[key] <= 500;
    }) && row.met_count <= row.required_count && row.required_count + row.optional_count === row.activity_count;
  }
  function valid(data) {
    if (!data || data.ok !== true || data.course_id !== Number(config.courseId) || typeof data.limited !== 'boolean' ||
        !validCounts(data) || !Array.isArray(data.lessons) || data.lessons.length > 200) return false;
    var ids = new Set(), sums = {required_count: 0, met_count: 0, optional_count: 0, activity_count: 0};
    return data.lessons.every(function (row) {
      if (!row || !validCounts(row) || !row.activity_count || !Number.isSafeInteger(row.lesson_id) || row.lesson_id < 1 || ids.has(row.lesson_id) || typeof row.title !== 'string' || typeof row.url !== 'string') return false;
      ids.add(row.lesson_id);
      Object.keys(sums).forEach(function (key) { sums[key] += row[key]; });
      return true;
    }) && Object.keys(sums).every(function (key) { return sums[key] === data[key]; });
  }
  function render(data) {
    views.forEach(function (view) {
      view.root.hidden = !data.activity_count && !data.limited;
      view.message.textContent = '';
      view.body.replaceChildren();
      if (data.limited) view.body.append(element('p', labels.limited));
      if (data.activity_count) {
        view.body.append(element('p', data.required_count ? count(data) : labels.noRequired));
        // The denominator covers accessible lessons, never imply full course completion.
        if (data.required_count) {
          var progress = element('progress');
          progress.max = data.required_count; progress.value = data.met_count;
          progress.setAttribute('aria-label', count(data));
          view.body.append(progress);
        }
        if (data.optional_count) view.body.append(element('p', optional(data)));
        view.body.append(element('p', labels.scope));
        var list = element('ul', null, 'fkhs-progress-list');
        data.lessons.forEach(function (row) {
          var li = element('li'), detail = element('div', null, 'fkhs-progress-detail');
          var url = safeUrl(row.url), link = element(url ? 'a' : 'span', row.title, url ? 'fkhs-progress-link' : '');
          if (url) link.href = url;
          detail.append(link);
          if (row.optional_count) detail.append(element('p', optional(row)));
          li.append(detail, element('span', row.required_count ? count(row) : labels.noRequired,
            'fkhs-progress-state' + (row.required_count && row.met_count === row.required_count ? ' is-met' : '')));
          list.append(li);
        });
        view.body.append(list);
        if (data.optional_count) view.body.append(element('p', labels.optionalHelp));
      }
      view.body.hidden = false;
    });
  }
  async function load() {
    var current = ++generation;
    if (controller) controller.abort();
    clearTimeout(timer);
    controller = new AbortController();
    var signal = controller.signal;
    views.forEach(function (view) {
      view.root.hidden = false; view.root.setAttribute('aria-busy', 'true');
      view.body.hidden = true; view.message.textContent = labels.loading;
      view.refresh.setAttribute('aria-disabled', 'true');
    });
    timer = setTimeout(function () { if (current === generation) controller.abort(); }, 15000);
    try {
      var url = new URL(config.url, window.location.href);
      url.searchParams.set('course_id', config.courseId);
      var response = await fetch(url.href, {method: 'GET', credentials: 'same-origin', cache: 'no-store', headers: {'X-WP-Nonce': config.nonce}, signal: signal});
      if (current !== generation) return;
      if (!response.ok) throw {auth: response.status === 401 || response.status === 403};
      var data = await response.json();
      if (current !== generation) return;
      if (!valid(data)) throw new Error('Invalid course snapshot');
      render(data);
    } catch (error) {
      if (current !== generation) return;
      views.forEach(function (view) {
        view.message.textContent = error && error.auth ? labels.auth : labels.error;
        var login = error && error.auth && safeUrl(config.loginUrl);
        if (login) {
          var link = element('a', labels.login); link.href = login; link.target = '_blank'; link.rel = 'noopener noreferrer';
          view.message.append(link);
        }
      });
    } finally {
      if (current === generation) {
        clearTimeout(timer);
        views.forEach(function (view) { view.root.removeAttribute('aria-busy'); view.refresh.removeAttribute('aria-disabled'); });
      }
    }
  }
  window.addEventListener('pageshow', function (event) { if (event.persisted) load(); });
  document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'visible') load(); });
  load();
})();
