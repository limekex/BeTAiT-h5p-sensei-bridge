(function (wp) {
  'use strict';
  var h = wp.element.createElement, useState = wp.element.useState, useEffect = wp.element.useEffect;
  var C = wp.components, META = '_fkhs_threshold_map', labels = window.fkhsEditor.labels;
  var requests = new Map();
  function text(key) { return labels[key]; }
  function lessonId() { return wp.data.select('core/editor').getCurrentPostId(); }
  function useMap() {
    return wp.data.useSelect(function (select) {
      return (select('core/editor').getEditedPostAttribute('meta') || {})[META] || {};
    }, []);
  }
  function setThreshold(id, value) {
    var meta = wp.data.select('core/editor').getEditedPostAttribute('meta') || {};
    var map = Object.assign({}, meta[META] || {});
    if (value === null) delete map[id]; else map[id] = value;
    wp.data.dispatch('core/editor').editPost({ meta: Object.assign({}, meta, { [META]: map }) });
  }
  function useRequest(path, shared) {
    var state = useState({ loading: true }), result = state[0], setResult = state[1];
    var retryState = useState(0), attempt = retryState[0];
    useEffect(function () {
      var active = true;
      setResult({ loading: true });
      var promise = shared && requests.get(path);
      if (!promise) {
        promise = wp.apiFetch({ path: path });
        if (shared) {
          if (requests.size >= 100) requests.delete(requests.keys().next().value);
          requests.set(path, promise);
        }
      }
      promise.then(function (data) { if (active) setResult({ data: data }); }, function () {
        requests.delete(path); if (active) setResult({ error: true });
      });
      return function () { active = false; };
    }, [path, attempt]);
    return [result, function () { requests.delete(path); retryState[1](attempt + 1); }];
  }
  function idsFromContent(content) {
    var ids = [], index = 0, match;
    while ((match = wp.shortcode.next('h5p', content, index))) {
      var id = match.shortcode.attrs.named.id;
      if (/^[1-9][0-9]*$/.test(String(id)) && Number.isSafeInteger(Number(id)) && ids.indexOf(Number(id)) < 0) ids.push(Number(id));
      index = match.index + match.content.length;
    }
    return ids;
  }
  function Threshold(props) {
    var id = props.id, map = useMap(), explicit = Object.prototype.hasOwnProperty.call(map, id);
    var override = explicit ? Number(map[id]) : null;
    var input = useState(explicit ? String(override) : ''), draft = input[0];
    var invalid = explicit && (draft.trim() === '' || !Number.isFinite(Number(draft)) || Number(draft) < 0 || Number(draft) > 100);
    var lock = wp.element.useId();
    useEffect(function () { input[1](explicit ? String(override) : ''); }, [override, explicit]);
    useEffect(function () {
      var editor = wp.data.dispatch('core/editor'), key = 'fkhs-threshold-' + lock;
      if (invalid) { editor.lockPostSaving(key); editor.lockPostAutosaving(key); }
      else { editor.unlockPostSaving(key); editor.unlockPostAutosaving(key); }
      return function () { editor.unlockPostSaving(key); editor.unlockPostAutosaving(key); };
    }, [invalid, lock]);
    var path = '/fkhs/v1/activities?lesson_id=' + lessonId() + '&content_id=' + id + (explicit ? '&threshold=' + encodeURIComponent(override) : '');
    var request = useRequest(path, true), result = request[0];
    var item = result.data && result.data.items[0];
    return h('div', { className: 'fkhs-editor-threshold', style: { marginBottom: '16px' } },
      h('strong', null, item ? item.title + ' (#' + id + ')' : 'H5P #' + id),
      item && h('p', null, item.type),
      result.loading && h('p', { role: 'status' }, text('Loading')),
      result.error && h(C.Notice, { status: 'error', isDismissible: false }, text('Error'), h(C.Button, { variant: 'secondary', onClick: request[1] }, text('Retry'))),
      result.data && !item && h(C.Notice, { status: 'warning', isDismissible: false }, text('Unavailable')),
      h(C.ToggleControl, { label: text('Inherit'), checked: !explicit, disabled: !item, onChange: function (inherit) { setThreshold(id, inherit ? null : item.inherited); }, help: text('Legacy') }),
      explicit && h(C.TextControl, { label: text('Threshold'), type: 'number', min: 0, max: 100, step: 'any', value: draft, disabled: !item,
        onChange: function (value) { input[1](value); if (value.trim() !== '' && Number.isFinite(Number(value)) && Number(value) >= 0 && Number(value) <= 100) setThreshold(id, Number(value)); },
        help: invalid ? text('Invalid') : text('Zero'), 'aria-invalid': invalid }),
      !explicit && h('p', null, text('Zero')),
      item && h('p', { 'aria-live': 'polite' }, text('Inherited') + ': ' + item.inherited + '%. ' + text('Effective') + ': ' + item.effective + '%.'),
      h('p', { className: 'description' }, text('Filters'))
    );
  }
  function Picker(props) {
    var searchState = useState(''), search = searchState[0], pageState = useState(1), page = pageState[0];
    var settled = useState('');
    useEffect(function () { var timer = window.setTimeout(function () { settled[1](search); pageState[1](1); }, 250); return function () { window.clearTimeout(timer); }; }, [search]);
    var request = useRequest('/fkhs/v1/activities?lesson_id=' + lessonId() + '&search=' + encodeURIComponent(settled[0]) + '&page=' + page, false), result = request[0];
    return h('div', null,
      h(C.TextControl, { label: text('Search'), value: search, maxLength: 100, onChange: searchState[1] }),
      result.loading && h('p', { role: 'status' }, text('Loading')),
      result.error && h(C.Notice, { status: 'error', isDismissible: false }, text('Error'), h(C.Button, { variant: 'secondary', onClick: request[1] }, text('Retry'))),
      result.data && h('div', null,
        !result.data.items.length && h('p', { role: 'status' }, text('Empty')),
        h('ul', null, result.data.items.map(function (item) { return h('li', { key: item.id, style: { marginBottom: '8px' } },
          h(C.Button, { variant: 'secondary', onClick: function () { props.onSelect(item.id); } }, text('Select') + ': ' + item.title + ' (#' + item.id + ')'), h('div', null, item.type)); })),
        h(C.Button, { variant: 'secondary', disabled: page === 1, onClick: function () { pageState[1](page - 1); } }, text('Previous')),
        h(C.Button, { variant: 'secondary', disabled: !result.data.has_more, onClick: function () { pageState[1](page + 1); } }, text('Next'))
      )
    );
  }
  function Edit(props) {
    var id = props.attributes.contentId, replace = useState(false);
    var type = wp.data.useSelect(function (select) { return select('core/editor').getCurrentPostType(); }, []);
    return h('div', wp.blockEditor.useBlockProps(),
      h('h3', null, text('Activity')),
      type !== 'lesson' ? h(C.Notice, { status: 'warning', isDismissible: false }, text('OnlyLesson')) :
        h(wp.element.Fragment, null,
          id > 0 && h(wp.element.Fragment, null, h(Threshold, { id: id, key: id }), h('code', null, '[h5p id="' + id + '"]'),
            h('p', null, h(C.Button, { variant: 'secondary', onClick: function () { replace[1](!replace[0]); } }, text('Replace')))),
          (!id || replace[0]) && h(Picker, { onSelect: function (contentId) { props.setAttributes({ contentId: contentId }); replace[1](false); } }),
          h('p', null, text('Shared'))
        )
    );
  }
  wp.blocks.registerBlockType('fkhs/activity', {
    apiVersion: 3, title: text('Activity'), category: 'media', icon: 'welcome-learn-more',
    attributes: { contentId: { type: 'integer', default: 0 } }, supports: { html: false, reusable: false },
    edit: Edit,
    save: function (props) {
      var id = props.attributes.contentId;
      return Number.isSafeInteger(id) && id > 0 ? h(wp.element.RawHTML, null, '[h5p id="' + id + '"]') : null;
    }
  });
  function Overview() {
    var content = wp.data.useSelect(function (select) { return wp.blocks.serialize(select('core/block-editor').getBlocks()); }, []);
    var ids = idsFromContent(content);
    return h('div', null, h('p', null, text('Shared')), ids.length ? ids.map(function (id) { return h(Threshold, { key: id, id: id }); }) : h('p', null, text('None')));
  }
  wp.domReady(function () {
    function mount() {
      var root = document.getElementById('fkhs-editor-overview');
      if (!root) return false;
      wp.element.createRoot(root).render(h(Overview)); return true;
    }
    if (mount()) return;
    var observer = new MutationObserver(function () { if (mount()) observer.disconnect(); });
    observer.observe(document.body, { childList: true, subtree: true });
    window.setTimeout(function () { observer.disconnect(); }, 15000);
  });
})(window.wp);
