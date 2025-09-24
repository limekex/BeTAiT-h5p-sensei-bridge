(function () {
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
      }).catch(function(){});
    } catch (e) {}
  }

  function extractContentId(stmt, fallback) {
    // 1) Bruk H5P event.data.contentId om den finnes
    if (Number.isInteger(fallback)) return fallback;

    // 2) Prøv å parse fra xAPI object.id (inneholder ofte &id=123)
    var objId = stmt?.object?.id || '';
    if (objId) {
      var m = objId.match(/[?&]id=(\d+)/);
      if (m && m[1]) return parseInt(m[1], 10);
    }
    return null;
  }

  function onXAPI(event) {
    try {
      var stmt = event?.data?.statement;
      if (!stmt || !stmt.result) return;

      var cid = event?.data?.contentId;
      cid = extractContentId(stmt, cid);

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
  bind();
})();
