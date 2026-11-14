/**
 * Embed (iframe video) support for the post editor.
 *
 * Tiptap has no iframe node, so any <iframe> inside the body is silently
 * dropped while the visual editor loads. This keeps iframes out of the editor
 * by swapping them for short [[embed:N]] tokens, then restores the original
 * markup on every save path (form submit + autosave).
 *
 * The stored body is still plain HTML with a real <iframe>, so the database,
 * revisions and front-end rendering stay exactly as before.
 */
(() => {
  'use strict';

  const TOKEN = /\[\[embed:(\d+)\]\]/g;
  const IFRAME = /<iframe\b[^>]*>(?:[\s\S]*?<\/iframe>)?/gi;
  const SRC = /<iframe\b[^>]*\bsrc\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s>]+))/i;
  const states = new WeakMap();

  const escapeAttr = (value) => String(value)
    .replace(/&/g, '&amp;')
    .replace(/"/g, '&quot;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;');

  /** Pull a usable URL out of a pasted URL or a pasted <iframe> snippet. */
  function normalizeSrc(raw) {
    const value = String(raw || '').trim();
    if (value === '') return '';
    const tagged = SRC.exec(value);
    const candidate = tagged ? (tagged[1] || tagged[2] || tagged[3] || '') : value;
    if (!/^https?:\/\//i.test(candidate)) return '';
    try {
      const url = new URL(candidate);
      return url.protocol === 'https:' || url.protocol === 'http:' ? url.href : '';
    } catch (error) {
      return '';
    }
  }

  /** Responsive 16:9 embed; the height attribute is a fallback for old browsers. */
  function buildIframe(src) {
    return '<iframe src="' + escapeAttr(src) + '" title="Video embed" width="100%" height="450"'
      + ' loading="lazy" frameborder="0" scrolling="no"'
      + ' allow="fullscreen; picture-in-picture; encrypted-media" allowfullscreen'
      + ' style="width:100%;aspect-ratio:16/9;height:auto;border:0;display:block"></iframe>';
  }

  /** Replace every safe iframe with a token and remember the exact tag. */
  function tokenize(state, html) {
    return String(html || '').replace(IFRAME, (tag) => {
      const match = SRC.exec(tag);
      const url = match ? (match[1] || match[2] || match[3] || '') : '';
      if (normalizeSrc(url) === '') return tag;
      const id = state.next++;
      state.tags.set(id, tag);
      return '[[embed:' + id + ']]';
    });
  }

  /** Put the stored iframe markup back where the token is. */
  function expand(state, html) {
    return String(html || '').replace(TOKEN, (match, id) => {
      const tag = state.tags.get(Number(id));
      return tag === undefined ? match : tag;
    });
  }

  function setup(form) {
    if (states.has(form)) return states.get(form);
    const body = form.querySelector('textarea[name="body"]');
    if (!body) return null;

    const state = { tags: new Map(), next: 1 };
    states.set(form, state);

    const seeded = tokenize(state, body.value);
    if (seeded !== body.value) {
      body.value = seeded;
      const api = window.CMSRichEditor;
      if (api && typeof api.setHTML === 'function') {
        try {
          api.setHTML(seeded);
        } catch (error) {
          // The textarea stays authoritative, so saving still works.
        }
      }
    }

    form.addEventListener('submit', () => {
      const area = form.querySelector('textarea.editor-source');
      const html = area && !area.hidden ? area.value : body.value;
      body.value = expand(state, tokenize(state, html));
    });

    return state;
  }

  function insert(form) {
    const state = states.get(form) || setup(form);
    if (!state) return false;

    const input = window.prompt(
      'Tempel URL video atau kode <iframe>.\nContoh: https://v-dey.com/player/cdn.html?id=02CgPcG01.mp4',
      ''
    );
    if (input === null) return false;

    const src = normalizeSrc(input);
    if (src === '') {
      window.alert('URL tidak valid. Gunakan alamat yang diawali https:// atau http://.');
      return false;
    }

    const id = state.next++;
    state.tags.set(id, buildIframe(src));

    const api = window.CMSRichEditor;
    let inserted = false;
    if (api && typeof api.insertHtml === 'function') {
      try {
        inserted = api.insertHtml('<p>[[embed:' + id + ']]</p>') === true;
      } catch (error) {
        inserted = false;
      }
    }

    if (!inserted) {
      state.tags.delete(id);
      window.alert('Editor belum siap menerima embed. Muat ulang halaman lalu coba lagi.');
    }
    return inserted;
  }

  window.CMSEmbed = {
    /** Used by the autosave request so drafts never lose an embed. */
    expand(form, html) {
      const state = form ? states.get(form) : null;
      return state ? expand(state, html) : html;
    },
    insert(form) {
      return form ? insert(form) : false;
    }
  };

  const boot = () => document.querySelectorAll('form[data-editor]').forEach(setup);
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }

  document.addEventListener('click', (event) => {
    const target = event.target;
    if (!target || typeof target.closest !== 'function') return;

    const embedButton = target.closest('[data-editor-embed]');
    if (embedButton) {
      event.preventDefault();
      const form = embedButton.closest('form[data-editor]');
      if (form) insert(form);
      return;
    }

    // Keep the "Mode HTML" round trip lossless: raw iframes typed into the
    // source textarea are folded back into tokens before returning to visual
    // mode, and shown as real markup while the source textarea is visible.
    const sourceButton = target.closest('[data-editor-cmd="source"]');
    if (!sourceButton) return;
    const form = sourceButton.closest('form[data-editor]');
    const state = form && states.get(form);
    if (!state) return;
    const area = form.querySelector('textarea.editor-source');
    if (!area) return;

    if (!area.hidden) {
      area.value = tokenize(state, area.value);
    } else {
      // Runs after the editor seeds the textarea with the current HTML.
      window.setTimeout(() => { area.value = expand(state, area.value); }, 0);
    }
  }, true);
})();
