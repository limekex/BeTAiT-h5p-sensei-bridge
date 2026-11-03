(function (wp) {
  'use strict';
  var h = wp.element.createElement, labels = window.fkhsProgressBlock;
  wp.blocks.registerBlockType('fkhs/progress', {
    apiVersion: 3, title: labels.title, description: labels.description, category: 'design', icon: 'chart-bar',
    usesContext: ['postId', 'postType'],
    supports: { html: false, align: ['wide', 'full'], spacing: { margin: true, padding: true } },
    edit: function () {
      return h('div', wp.blockEditor.useBlockProps({ className: 'fkhs-editor-card' }),
        h('strong', { className: 'fkhs-editor-title' }, labels.title),
        h('p', null, labels.description));
    },
    // Retain the existing serialized fallback; PHP owns conditional/template rendering.
    save: function () { return h(wp.element.RawHTML, null, '[fkhs_progress]'); }
  });
  wp.blocks.registerBlockType('fkhs/course-progress', {
    apiVersion: 3, title: labels.courseTitle, description: labels.courseDescription, category: 'design', icon: 'chart-bar',
    usesContext: ['postId', 'postType'],
    supports: { html: false, align: ['wide', 'full'], spacing: { margin: true, padding: true } },
    edit: function () {
      return h('div', wp.blockEditor.useBlockProps({ className: 'fkhs-editor-card' }),
        h('strong', { className: 'fkhs-editor-title' }, labels.courseTitle),
        h('p', null, labels.courseDescription));
    },
    save: function () { return h(wp.element.RawHTML, null, '[fkhs_course_progress]'); }
  });
})(window.wp);
