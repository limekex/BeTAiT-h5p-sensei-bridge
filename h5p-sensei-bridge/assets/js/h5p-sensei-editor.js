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
      // Keep selected-activity controls mounted while its threshold preview loads.
      // Clearing the item here would disable the field between keystrokes.
      setResult(function (previous) { return { loading: true, data: shared ? previous.data : undefined }; });
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
    return h('div', { className: 'fkhs-editor-threshold' },
      h('div', { className: 'fkhs-editor-identity' },
        h('strong', { className: 'fkhs-editor-title' }, item ? item.title : 'H5P #' + id),
        h('span', { className: 'fkhs-editor-meta' }, '#' + id + (item ? ' · ' + item.type : ''))),
      result.loading && !item && h('p', { role: 'status', className: 'fkhs-editor-note' }, text('Loading')),
      result.error && h(C.Notice, { status: 'error', isDismissible: false }, text('Error'), h(C.Button, { variant: 'secondary', onClick: request[1] }, text('Retry'))),
      result.data && !item && h(C.Notice, { status: 'warning', isDismissible: false }, text('Unavailable')),
      h('div', { className: 'fkhs-editor-controls' },
        h(C.ToggleControl, { label: text('Inherit'), checked: !explicit, disabled: !item, onChange: function (inherit) { setThreshold(id, inherit ? null : item.inherited); } }),
        explicit ? h('div', { className: 'fkhs-editor-number' },
          h(C.TextControl, { label: text('Threshold'), type: 'number', min: 0, max: 100, step: 'any', value: draft, disabled: !item,
            onChange: function (value) { input[1](value); if (value.trim() !== '' && Number.isFinite(Number(value)) && Number(value) >= 0 && Number(value) <= 100) setThreshold(id, Number(value)); },
            'aria-invalid': invalid })) : item && h('span', { className: 'fkhs-editor-value' }, item.inherited + '%')),
      invalid && h('p', { role: 'alert', className: 'fkhs-editor-invalid' }, text('Invalid')),
      item && (explicit ? override : item.inherited) === 0 && h('p', { className: 'fkhs-editor-note' }, text('Zero')),
      item && !result.loading && item.effective !== (explicit ? override : item.inherited) && h('p', { className: 'fkhs-editor-note', 'aria-live': 'polite' }, text('Effective') + ': ' + item.effective + '%.'),
      h('details', { className: 'fkhs-editor-details' },
        h('summary', null, text('Details')),
        item && !result.loading && h('p', null, text('Inherited') + ': ' + item.inherited + '%. ' + text('Effective') + ': ' + item.effective + '%.'),
        h('p', null, text('Legacy')),
        h('p', null, text('Shared')),
        h('p', null, text('Filters')),
        props.showShortcode && h('code', null, '[h5p id="' + id + '"]'))
    );
  }

  function Picker(props) {
    var searchState = useState(''), search = searchState[0], pageState = useState(1), page = pageState[0];
    var settled = useState('');
    useEffect(function () { var timer = window.setTimeout(function () { settled[1](search); pageState[1](1); }, 250); return function () { window.clearTimeout(timer); }; }, [search]);
    var request = useRequest('/fkhs/v1/activities?lesson_id=' + lessonId() + '&search=' + encodeURIComponent(settled[0]) + '&page=' + page, false), result = request[0];
    return h('div', { className: 'fkhs-editor-picker' },
      h(C.TextControl, { label: text('Search'), value: search, maxLength: 100, onChange: searchState[1], autoComplete: 'off' }),
      result.loading && h('p', { role: 'status', className: 'fkhs-editor-note' }, text('Loading')),
      result.error && h(C.Notice, { status: 'error', isDismissible: false }, text('Error'), h(C.Button, { variant: 'secondary', onClick: request[1] }, text('Retry'))),
      result.data && h('div', null,
        !result.data.items.length && h('p', { role: 'status', className: 'fkhs-editor-note' }, text('Empty')),
        h('ul', { className: 'fkhs-editor-results' }, result.data.items.map(function (item) { return h('li', { key: item.id },
          h(C.Button, { className: 'fkhs-editor-result', 'aria-label': text('Select') + ': ' + item.title + ' (#' + item.id + ')', onClick: function () { props.onSelect(item.id); } },
            h('span', { className: 'fkhs-editor-result-title' }, item.title),
            h('span', { className: 'fkhs-editor-meta' }, '#' + item.id + ' · ' + item.type))); })),
        (page > 1 || result.data.has_more) && h('div', { className: 'fkhs-editor-pagination' },
          h(C.Button, { variant: 'secondary', disabled: page === 1, onClick: function () { pageState[1](page - 1); } }, text('Previous')),
          h(C.Button, { variant: 'secondary', disabled: !result.data.has_more, onClick: function () { pageState[1](page + 1); } }, text('Next')))
      )
    );
  }
  function Edit(props) {
    var id = props.attributes.contentId, replace = useState(false);
    var type = wp.data.useSelect(function (select) { return select('core/editor').getCurrentPostType(); }, []);
    function selectActivity(contentId) { props.setAttributes({ contentId: contentId }); replace[1](false); }
    return h('div', wp.blockEditor.useBlockProps({ className: 'fkhs-editor-card' }),
      h('div', { className: 'fkhs-editor-heading' },
        h('span', { className: 'fkhs-editor-label' }, text('Activity')),
        id > 0 && type === 'lesson' && h(C.Button, { variant: 'tertiary', onClick: function () { replace[1](true); } }, text('Replace'))),
      type !== 'lesson' ? h(C.Notice, { status: 'warning', isDismissible: false }, text('OnlyLesson')) :
        h(wp.element.Fragment, null,
          id > 0 ? h(Threshold, { id: id, key: id, showShortcode: true }) : h(Picker, { onSelect: selectActivity }),
          replace[0] && h(C.Modal, { title: text('Replace'), className: 'fkhs-editor-picker-modal', onRequestClose: function () { replace[1](false); } }, h(Picker, { onSelect: selectActivity }))
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
    return h('div', { className: 'fkhs-editor-overview' }, ids.length ? ids.map(function (id) { return h(Threshold, { key: id, id: id }); }) : h('p', null, text('None')));
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
