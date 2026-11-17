/**
 * Admin app runtime — progressive enhancement for the dashboard screens.
 * No dependencies. Every feature degrades gracefully without JS.
 */
(() => {
  'use strict';

  const $ = (selector, scope = document) => scope.querySelector(selector);
  const $$ = (selector, scope = document) => Array.from(scope.querySelectorAll(selector));
  const base = () => (document.body.dataset.adminBase || '');

  const adminRoot = () => {
    // The shell passes the canonical admin base URL (e.g. /admin/index.php).
    const declared = document.body.dataset.adminBase;
    if (declared) {
      return declared;
    }
    // Fallback: derive it from this script's own URL.
    const script = document.querySelector('script[src*="admin-app.js"]');
    if (script) {
      return new URL(script.getAttribute('src'), window.location.href).href.replace(/assets\/admin-app\.js.*$/, 'admin/index.php');
    }
    return new URL('admin/index.php', window.location.href).href;
  };

  const csrfToken = () => {
    const field = $('input[name="csrf"]');
    return field ? field.value : '';
  };

  const debounce = (fn, delay = 250) => {
    let timer = null;
    return (...args) => {
      clearTimeout(timer);
      timer = setTimeout(() => fn(...args), delay);
    };
  };

  // ---------------------------------------------------------------
  // Bulk selection on list screens
  // ---------------------------------------------------------------
  const selectAll = $('#select-all');
  if (selectAll) {
    const checks = $$('.bulk-check');
    selectAll.addEventListener('change', () => {
      checks.forEach((check) => { check.checked = selectAll.checked; });
    });
    checks.forEach((check) => {
      check.addEventListener('change', () => {
        selectAll.checked = checks.every((item) => item.checked);
      });
    });
  }

  // ---------------------------------------------------------------
  // Editor: character counters + live SEO preview
  // ---------------------------------------------------------------
  const counters = $$('[data-counter]');
  counters.forEach((field) => {
    const output = $(`[data-count-for="${field.dataset.counter}"]`);
    const limit = field.dataset.counter === 'meta_title' ? 65 : 160;
    const refresh = () => {
      const length = field.value.trim().length;
      if (output) {
        output.textContent = `${length} / ${limit} karakter`;
        output.classList.toggle('over', length > limit);
      }
    };
    field.addEventListener('input', refresh);
    refresh();
  });

  const titleInput = $('#title');
  const metaTitle = $('#meta_title');
  const metaDescription = $('#meta_description');
  const excerpt = $('#excerpt');
  const seoTitle = $('[data-seo-title]');
  const seoDesc = $('[data-seo-desc]');
  const permalink = $('#permalink-preview');
  const slugInput = $('#slug');

  const updateSeoPreview = () => {
    if (seoTitle) {
      seoTitle.textContent = (metaTitle && metaTitle.value.trim()) || (titleInput && titleInput.value.trim()) || 'Judul artikel';
    }
    if (seoDesc) {
      const text = (metaDescription && metaDescription.value.trim()) || (excerpt && excerpt.value.trim()) || 'Ringkasan artikel akan muncul di sini.';
      seoDesc.textContent = text.slice(0, 160);
    }
    if (permalink && slugInput) {
      const slug = slugInput.value.trim() || 'judul-artikel';
      const stamp = new Date();
      const year = stamp.getFullYear();
      const month = String(stamp.getMonth() + 1).padStart(2, '0');
      permalink.textContent = `${window.location.origin}/` + `${year}/${month}/${slug}.html`;
    }
  };
  [titleInput, metaTitle, metaDescription, excerpt, slugInput].forEach((field) => {
    if (field) field.addEventListener('input', updateSeoPreview);
  });
  updateSeoPreview();

  // ---------------------------------------------------------------
  // Slug availability check (debounced)
  // ---------------------------------------------------------------
  if (slugInput && slugInput.hasAttribute('data-slug-check')) {
    const status = $('[data-slug-status]');
    const checkSlug = debounce(async () => {
      const slug = slugInput.value.trim();
      if (!slug || !status) return;
      status.textContent = 'memeriksa…';
      status.className = 'slug-status';
      try {
        const response = await fetch(`${adminRoot()}?ajax=slug.check&table=posts&slug=${encodeURIComponent(slug)}&exclude=${$('input[name="id"]')?.value || 0}`, {
          headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': csrfToken() },
          credentials: 'same-origin'
        });
        const data = await response.json();
        if (data.ok) {
          status.textContent = data.available ? '✓ tersedia' : '✕ sudah dipakai';
          status.className = 'slug-status ' + (data.available ? 'ok' : 'bad');
          if (data.slug && data.slug !== slug) slugInput.value = data.slug;
        }
      } catch (error) {
        status.textContent = '';
      }
    }, 500);
    slugInput.addEventListener('blur', checkSlug);
    titleInput?.addEventListener('blur', () => {
      if (!slugInput.value.trim()) {
        slugInput.value = (titleInput.value || '')
          .toLowerCase().replace(/[^a-z0-9\s-]/g, '').trim().replace(/\s+/g, '-');
        checkSlug();
      }
    });
  }

  // ---------------------------------------------------------------
  // Visibility password field
  // ---------------------------------------------------------------
  const visibility = $('#visibility');
  const passwordField = $('[data-password-field]');
  if (visibility && passwordField) {
    const sync = () => { passwordField.hidden = visibility.value !== 'password'; };
    visibility.addEventListener('change', sync);
    sync();
  }

  // ---------------------------------------------------------------
  // Category chips (remove by clicking)
  // ---------------------------------------------------------------
  $$('[data-add-tag]').forEach((chip) => {
    chip.addEventListener('click', () => {
      const target = document.getElementById(chip.dataset.addTag);
      if (!target) return;
      const value = chip.dataset.value;
      target.value = target.value
        .split(',').map((item) => item.trim()).filter((item) => item && item !== value).join(', ');
      chip.remove();
    });
  });

  // ---------------------------------------------------------------
  // Editor autosave (draft every 20s when the editor is dirty)
  // ---------------------------------------------------------------
  const postForm = $('#post-form');
  if (postForm && postForm.hasAttribute('data-editor')) {
    const idField = $('input[name="id"]', postForm);
    const bodyField = $('textarea[name="body"]', postForm);
    const statusLine = $('[data-autosave-status]');
    let dirty = false;
    postForm.addEventListener('input', () => { dirty = true; });
    if (statusLine) {
      setInterval(async () => {
        if (!dirty || !idField || !bodyField || Number(idField.value) <= 0) return;
        dirty = false;
        const payload = new URLSearchParams({
          csrf: csrfToken(),
          id: idField.value,
          title: ($('#title', postForm) || {}).value || '',
          body: window.CMSEmbed ? window.CMSEmbed.expand(postForm, bodyField.value) : bodyField.value,
          excerpt: ($('#excerpt', postForm) || {}).value || ''
        });
        try {
          const response = await fetch(`${adminRoot()}?ajax=post.autosave`, {
            method: 'POST',
            body: payload,
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': csrfToken() },
            credentials: 'same-origin'
          });
          const data = await response.json();
          statusLine.textContent = data.ok ? `Draf tersimpan otomatis pukul ${data.savedAt}.` : 'Autosave gagal.';
        } catch (error) {
          statusLine.textContent = 'Autosave gagal — periksa koneksi.';
        }
      }, 20000);
    }
  }

  // ---------------------------------------------------------------
  // Media picker modal
  // ---------------------------------------------------------------
  const modal = $('[data-media-modal]');
  const mediaGrid = $('[data-media-grid]');
  let mediaTarget = null;

  const openMedia = async (target) => {
    if (!modal || !mediaGrid) return;
    mediaTarget = target || 'body';
    modal.hidden = false;
    mediaGrid.innerHTML = '<p class="muted">Memuat media…</p>';
    try {
      const response = await fetch(`${adminRoot()}?ajax=media.list`, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin'
      });
      const data = await response.json();
      if (!data.ok || !data.items.length) {
        mediaGrid.innerHTML = '<p class="muted">Belum ada media. Unggah berkas terlebih dahulu.</p>';
        return;
      }
      mediaGrid.innerHTML = '';
      data.items.forEach((item) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'media-pick';
        button.title = item.name;
        const image = document.createElement('img');
        image.src = item.url;
        image.alt = item.alt || item.name;
        image.loading = 'lazy';
        button.appendChild(image);
        button.addEventListener('click', () => insertMedia(item));
        mediaGrid.appendChild(button);
      });
    } catch (error) {
      mediaGrid.innerHTML = '<p class="muted">Media tidak dapat dimuat.</p>';
    }
  };

  const insertMedia = (item) => {
    if (modal) modal.hidden = true;
    if (mediaTarget === 'featured') {
      const input = $('[data-featured-input]');
      if (input) {
        input.value = item.url;
        input.dispatchEvent(new Event('input', { bubbles: true }));
        const preview = $('[data-featured-preview]');
        if (preview) preview.innerHTML = `<img src="${item.url}" alt="">`;
      }
      return;
    }
    // Preferred path: the Tiptap editor exposes a global insert hook.
    if (window.CMSRichEditor && typeof window.CMSRichEditor.insertImage === 'function') {
      window.CMSRichEditor.focus();
      window.CMSRichEditor.insertImage(item.url, item.alt || item.name || '');
      return;
    }
    // Fallback for any legacy contenteditable surface.
    const editor = $('[data-wysiwyg]');
    const source = $('textarea[name="body"]');
    const markup = `<img src="${item.url}" alt="${(item.alt || '').replace(/"/g, '&quot;')}" loading="lazy">`;
    if (editor && !editor.hidden) {
      editor.focus();
      document.execCommand('insertHTML', false, markup);
      if (source) source.value = editor.innerHTML;
    } else if (source) {
      source.value += '\n' + markup;
    }
  };

  $$('[data-media-open]').forEach((button) => {
    button.addEventListener('click', () => openMedia(button.dataset.mediaTarget));
  });
  $$('[data-media-close]').forEach((button) => {
    button.addEventListener('click', () => { if (modal) modal.hidden = true; });
  });
  if (modal) {
    modal.addEventListener('click', (event) => {
      if (event.target === modal) modal.hidden = true;
    });
    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') modal.hidden = true;
    });
  }

  // ---------------------------------------------------------------
  // Dropzone + inline media details
  // ---------------------------------------------------------------
  $$('[data-dropzone]').forEach((zone) => {
    const input = $('[data-file-input]', zone);
    const list = $('[data-dropzone-list]', zone);
    if (!input) return;
    const render = () => {
      if (!list) return;
      list.innerHTML = '';
      Array.from(input.files || []).forEach((file, index) => {
        const row = document.createElement('div');
        row.textContent = `${index + 1}. ${file.name} — ${(file.size / 1024).toFixed(0)} KB`;
        list.appendChild(row);
      });
    };
    input.addEventListener('change', render);
    ['dragenter', 'dragover'].forEach((name) => {
      zone.addEventListener(name, (event) => {
        event.preventDefault();
        zone.classList.add('dragover');
      });
    });
    ['dragleave', 'drop'].forEach((name) => {
      zone.addEventListener(name, (event) => {
        event.preventDefault();
        zone.classList.remove('dragover');
      });
    });
    zone.addEventListener('drop', (event) => {
      if (event.dataTransfer?.files?.length) {
        input.files = event.dataTransfer.files;
        render();
      }
    });
  });

  $$('[data-media-edit]').forEach((button) => {
    button.addEventListener('click', () => {
      const form = $(`[data-media-form="${button.dataset.mediaEdit}"]`);
      if (form) form.hidden = !form.hidden;
    });
  });

  // ---------------------------------------------------------------
  // Widget drag-and-drop ordering
  // ---------------------------------------------------------------
  const areas = $('#widget-areas');
  if (areas) {
    let dragged = null;
    const saveOrder = debounce(async (sectionId) => {
      const list = $(`[data-section="${sectionId}"]`, areas);
      if (!list) return;
      const rows = $$('.widget-item', list).map((item, index) => ({
        id: Number(item.dataset.widgetId),
        section: sectionId,
        order: index
      }));
      const heading = $('.area-head .muted', list.closest('.area-card'));
      if (heading) heading.nextElementSibling;
      try {
        const response = await fetch(`${adminRoot()}?ajax=widget.reorder`, {
          method: 'POST',
          body: new URLSearchParams({ csrf: csrfToken(), payload: JSON.stringify(rows) }),
          headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': csrfToken() },
          credentials: 'same-origin'
        });
        const data = await response.json();
        if (!data.ok) throw new Error(data.error || 'gagal');
        const card = list.closest('.area-card');
        const note = card ? $('.area-head .muted', card) : null;
        if (note) note.textContent = 'Urutan disimpan ✓';
        setTimeout(() => { if (note) note.textContent = `${sectionId} · ${rows.length} widget`; }, 2500);
        const preview = $('[data-preview-frame]');
        if (preview && preview.contentWindow) preview.contentWindow.location.reload();
      } catch (error) {
        // Reload to show the persisted order if saving failed.
        window.location.reload();
      }
    }, 400);

    areas.addEventListener('dragstart', (event) => {
      const item = event.target.closest('.widget-item');
      if (!item) return;
      dragged = item;
      item.classList.add('dragging');
      event.dataTransfer.effectAllowed = 'move';
      event.dataTransfer.setData('text/plain', item.dataset.widgetId);
    });
    areas.addEventListener('dragend', () => {
      if (dragged) dragged.classList.remove('dragging');
      $$('.widget-item').forEach((item) => item.classList.remove('drop-target'));
      dragged = null;
    });
    areas.addEventListener('dragover', (event) => {
      const target = event.target.closest('.widget-item');
      if (!target || !dragged || target === dragged) return;
      event.preventDefault();
      $$('.widget-item').forEach((item) => item.classList.remove('drop-target'));
      target.classList.add('drop-target');
      const list = target.parentElement;
      if (list !== dragged.parentElement) return;
      const after = target.compareDocumentPosition(dragged) & Node.DOCUMENT_POSITION_FOLLOWING;
      if (after) list.insertBefore(dragged, target.nextSibling);
      else list.insertBefore(dragged, target);
    });
    areas.addEventListener('drop', (event) => {
      event.preventDefault();
      const list = dragged?.parentElement;
      if (list && list.dataset.section) saveOrder(list.dataset.section);
    });
  }

  // ---------------------------------------------------------------
  // Widget type dynamic field switching
  // ---------------------------------------------------------------
  const widgetType = $('[data-widget-type]');
  if (widgetType) {
    widgetType.addEventListener('change', () => {
      const note = $('#dynamic-fields');
      if (note) {
        note.innerHTML = '<p class="help">Simpan dulu untuk memuat bidang pengaturan jenis widget baru.</p>';
      }
    });
  }

  // ---------------------------------------------------------------
  // Customizer live preview
  // ---------------------------------------------------------------
  const previewFrame = $('[data-preview-frame]');
  if (previewFrame) {
    const applyPreview = () => {
      const doc = previewFrame.contentDocument;
      if (!doc) return;
      const root = doc.documentElement;
      const mapping = {
        primary_color: '--t-primary',
        primary_dark: '--t-primary-dark',
        accent_color: '--t-accent',
        background_color: '--t-bg',
        surface_color: '--t-surface',
        text_color: '--t-text',
        muted_color: '--t-muted',
        border_color: '--t-border',
        font_family: '--t-font',
        font_heading: '--t-font-head'
      };
      $$('[data-live-setting]').forEach((field) => {
        const variable = mapping[field.dataset.liveSetting];
        if (!variable) return;
        let value = field.type === 'checkbox' ? (field.checked ? '1' : '0') : field.value;
        if (!value) return;
        root.style.setProperty(variable, value);
      });
    };
    previewFrame.addEventListener('load', applyPreview);
    $$('[data-live-setting]').forEach((field) => {
      field.addEventListener('input', debounce(applyPreview, 120));
    });
    // Optional color fields (e.g. primary_dark) can be reset to "automatic".
    // The browser color input cannot be blank, so we mark the field disabled
    // and clear the paired hex echo; the disabled input is not submitted, which
    // makes the backend fall back to the derived value.
    $$('[data-clear-setting]').forEach((link) => {
      link.addEventListener('click', (event) => {
        event.preventDefault();
        const key = link.dataset.clearSetting;
        const color = document.querySelector(`input[type="color"][name="settings[${key}]"]`);
        const echo = document.querySelector(`[data-color-echo][aria-label]`);
        if (color) {
          color.value = '#000000';
          color.disabled = true;
          color.dispatchEvent(new Event('input', { bubbles: true }));
        }
        document.querySelectorAll('[data-color-echo]').forEach((el) => {
          if (color && el.previousElementSibling === color) el.value = '';
        });
        link.textContent = 'Otomatis aktif';
        link.classList.add('muted');
      });
    });
    $$('[data-preview-width]').forEach((button) => {
      button.addEventListener('click', () => {
        previewFrame.style.width = button.dataset.previewWidth + (button.dataset.previewWidth === '100%' ? '' : 'px');
        previewFrame.style.margin = button.dataset.previewWidth === '100%' ? '0' : '0 auto';
      });
    });

    // Tata Letak screen: choose which front-end route to preview.
    const targetSelect = $('[data-preview-target]');
    if (targetSelect) {
      targetSelect.addEventListener('change', () => {
        previewFrame.src = targetSelect.value;
        const open = $('[data-preview-open]');
        if (open) open.href = targetSelect.value;
      });
    }
    $('[data-preview-refresh]')?.addEventListener('click', () => {
      if (previewFrame.contentWindow) previewFrame.contentWindow.location.reload();
      else previewFrame.src = previewFrame.src;
    });
    // Sync color picker with its hex field
    $$('.color-field').forEach((wrap) => {
      const picker = $('input[type="color"]', wrap);
      const hex = $('input[type="text"]', wrap);
      if (!picker || !hex) return;
      picker.addEventListener('input', () => {
        hex.value = picker.value;
        picker.dispatchEvent(new Event('input', { bubbles: false }));
        applyPreview();
      });
      hex.addEventListener('change', () => {
        if (/^#[0-9a-f]{3,8}$/i.test(hex.value)) {
          picker.value = hex.value.slice(0, 7);
          applyPreview();
        }
      });
    });
  }

  // ---------------------------------------------------------------
  // Page builder
  // ---------------------------------------------------------------
  const builderForm = $('#builder-form');
  if (builderForm) {
    const docField = $('[data-builder-document]', builderForm);
    const sectionsWrap = $('[data-builder-sections]', builderForm);
    let document_ = JSON.parse(docField?.value || '{"version":1,"sections":[]}');
    if (!Array.isArray(document_.sections)) document_.sections = [];

    const sync = (state) => {
      const stateNode = $('[data-builder-state]', builderForm);
      if (stateNode && state) stateNode.textContent = state;
      if (docField) docField.value = JSON.stringify(document_, null, 2);
    };

    const collectFromDom = () => {
      document_ = { version: 1, sections: [] };
      $$('.builder-section-edit', sectionsWrap).forEach((sectionNode) => {
        const blocks = [];
        $$('.builder-block-edit', sectionNode).forEach((blockNode) => {
          const settings = {};
          $$('[data-block-setting]', blockNode).forEach((field) => {
            const key = field.dataset.blockSetting;
            if (field.dataset.blockSettingJson) {
              try {
                settings[key] = JSON.parse(field.value || '[]');
              } catch {
                settings[key] = [];
              }
            } else {
              settings[key] = field.value;
            }
          });
          blocks.push({
            id: blockNode.dataset.blockId || Math.random().toString(16).slice(2, 10),
            type: blockNode.dataset.blockType,
            settings
          });
        });
        document_.sections.push({
          id: sectionNode.dataset.sectionId || Math.random().toString(16).slice(2, 10),
          settings: sectionSettings(sectionNode),
          blocks
        });
      });
      sync('Perubahan belum disimpan.');
      refreshPreview();
    };

    function sectionSettings(sectionNode) {
      const settings = { background: '', padding: 'md', width: 'normal', class: '' };
      $$('[data-section-setting]', sectionNode).forEach((field) => {
        settings[field.dataset.sectionSetting] = field.value;
      });
      return settings;
    }

    const previewFrame = $('[data-builder-preview]', builderForm);
    const previewState = $('[data-builder-preview-state]', builderForm);
    const previewModal = $('[data-preview-modal]');
    const previewModalFrame = $('[data-preview-modal-frame]');
    let previewHtml = previewFrame ? (previewFrame.getAttribute('srcdoc') || '') : '';
    let previewToken = 0;

    const applyPreviewHtml = (html) => {
      previewHtml = html;
      if (previewFrame) previewFrame.srcdoc = html;
      if (previewModal && !previewModal.hidden && previewModalFrame) previewModalFrame.srcdoc = html;
    };

    const refreshPreview = debounce(async () => {
      if (!previewFrame) return;
      const token = ++previewToken;
      if (previewState) previewState.textContent = 'Memperbarui…';
      try {
        const themeSlug = builderForm.dataset.builderTheme || '';
        const previewQuery = themeSlug ? '&theme_preview=' + encodeURIComponent(themeSlug) : '';
        const response = await fetch(`${adminRoot()}?ajax=builder.preview${previewQuery}`, {
          method: 'POST',
          body: new URLSearchParams({
            csrf: csrfToken(),
            title: ($('#builder-title', builderForm) || {}).value || '',
            document: JSON.stringify(document_)
          }),
          headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': csrfToken() },
          credentials: 'same-origin'
        });
        const data = await response.json();
        if (token !== previewToken) return;
        if (!data.ok || typeof data.html !== 'string') throw new Error(data.error || 'gagal');
        applyPreviewHtml(data.html);
        if (previewState) previewState.textContent = 'Pratinjau terbaru.';
      } catch (error) {
        if (token === previewToken && previewState) previewState.textContent = 'Pratinjau gagal dimuat.';
      }
    }, 400);

    // Large preview overlay: same document, more room to judge the design.
    const openPreviewModal = () => {
      if (!previewModal || !previewModalFrame) return;
      previewModal.hidden = false;
      document.body.classList.add('modal-open');
      previewModalFrame.srcdoc = previewHtml;
    };
    const closePreviewModal = () => {
      if (!previewModal) return;
      previewModal.hidden = true;
      document.body.classList.remove('modal-open');
      if (previewModalFrame) previewModalFrame.srcdoc = '';
    };

    $$('[data-preview-expand]', builderForm).forEach((button) => button.addEventListener('click', openPreviewModal));
    $('[data-preview-close]')?.addEventListener('click', closePreviewModal);
    previewModal?.addEventListener('click', (event) => {
      if (event.target === previewModal) closePreviewModal();
    });
    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && previewModal && !previewModal.hidden) closePreviewModal();
    });
    $$('[data-modal-width]').forEach((button) => {
      button.addEventListener('click', () => {
        if (!previewModalFrame) return;
        const width = button.dataset.modalWidth;
        previewModalFrame.style.width = width === '100%' ? '100%' : width + 'px';
        previewModalFrame.style.margin = width === '100%' ? '0' : '0 auto';
      });
    });

    // Colour picker mirrors its hex field (and vice versa) for section
    // backgrounds. Bound per section so dynamically added sections work too.
    const bindSectionColor = (root) => {
      $$('[data-section-color]', root).forEach((picker) => {
        if (picker.dataset.colorBound === '1') return;
        picker.dataset.colorBound = '1';
        const text = picker.parentElement?.querySelector('[data-section-setting]');
        if (!text) return;
        picker.addEventListener('input', () => { text.value = picker.value; });
        text.addEventListener('input', () => {
          if (/^#[0-9a-f]{3,8}$/i.test(text.value)) picker.value = text.value.slice(0, 7);
        });
      });
    };
    bindSectionColor(builderForm);

    // Device width presets (mirrors the customizer preview controls).
    $$('[data-preview-width]', builderForm).forEach((button) => {
      button.addEventListener('click', () => {
        if (!previewFrame) return;
        const width = button.dataset.previewWidth;
        previewFrame.style.width = width === '100%' ? '100%' : width + 'px';
        previewFrame.style.margin = width === '100%' ? '0' : '0 auto';
      });
    });

    const fieldTemplate = (blockType, settings) => {
      const fields = {
        hero: ['title', 'subtitle', 'button_text', 'button_url'],
        heading: ['text', 'level', 'align'],
        text: ['text', 'align'],
        image: ['src', 'alt', 'caption'],
        gallery: ['images', 'columns'],
        features: ['items', 'columns'],
        faq: ['items'],
        testimonials: ['items'],
        button: ['text', 'url'],
        quote: ['text', 'author'],
        cta: ['title', 'text', 'button_text', 'button_url'],
        spacer: ['height'],
        html: ['html'],
        video: ['url'],
        newsletter: ['title', 'text', 'button']
      };
      return (fields[blockType] || ['text']);
    };

    const appendBlock = (sectionNode, type) => {
      const blockNode = document.createElement('div');
      blockNode.className = 'builder-block-edit';
      blockNode.dataset.blockType = type;
      blockNode.dataset.blockId = Math.random().toString(16).slice(2, 10);
      const fields = fieldTemplate(type, {});
      const inputs = fields.map((field) => {
        const label = `<label>${field.replace(/_/g, ' ')}</label>`;
        if (field === 'items' || field === 'images') {
          return label + `<textarea data-block-setting="${field}" data-block-setting-json="1" rows="4" spellcheck="false">[]</textarea>`;
        }
        if (field === 'text' || field === 'subtitle' || field === 'html') {
          return label + `<textarea data-block-setting="${field}" rows="3"></textarea>`;
        }
        return label + `<input type="text" data-block-setting="${field}" value="">`;
      }).join('');
      blockNode.innerHTML = `
        <div class="block-head">
          <span class="pill info">${type}</span>
          <div class="admin-actions">
            <button type="button" class="secondary small" data-remove-block>Hapus</button>
          </div>
        </div>
        <div class="block-fields">${inputs}</div>`;
      const list = $('[data-section-blocks]', sectionNode);
      if (list) list.appendChild(blockNode);
      else {
        const blocks = document.createElement('div');
        blocks.className = 'builder-blocks';
        blocks.dataset.sectionBlocks = '';
        blocks.appendChild(blockNode);
        sectionNode.insertBefore(blocks, sectionNode.querySelector('[data-add-section-block]'));
      }
      collectFromDom();
    };

    const sectionSettingsMarkup = (settings = {}) => {
      const background = settings.background || '';
      const padding = settings.padding || 'md';
      const width = settings.width || 'normal';
      const cssClass = settings.class || '';
      const option = (value, label, current) => `<option value="${value}"${current === value ? ' selected' : ''}>${label}</option>`;
      return `
        <div class="builder-section-settings">
          <label>Latar bagian
            <span class="section-color">
              <input type="color" data-section-color="background" value="${/^#?[0-9a-f]{6}$/i.test(background) ? (background.startsWith('#') ? background : '#' + background) : '#ffffff'}">
              <input type="text" data-section-setting="background" value="${background}" placeholder="(bawaan)">
            </span>
          </label>
          <label>Jarak isi
            <select data-section-setting="padding">${option('none', 'Rapat', padding)}${option('sm', 'Kecil', padding)}${option('md', 'Sedang', padding)}${option('lg', 'Besar', padding)}</select>
          </label>
          <label>Lebar isi
            <select data-section-setting="width">${option('normal', 'Normal', width)}${option('wide', 'Lebar', width)}${option('full', 'Penuh', width)}${option('narrow', 'Sempit', width)}</select>
          </label>
          <label>Kelas CSS
            <input type="text" data-section-setting="class" value="${cssClass}" placeholder="mis. sorotan">
          </label>
        </div>`;
    };

    const appendSection = () => {
      const sectionNode = document.createElement('section');
      sectionNode.className = 'builder-section-edit';
      sectionNode.dataset.sectionId = Math.random().toString(16).slice(2, 10);
      sectionNode.innerHTML = `
        <div class="builder-section-head">
          <strong>Bagian Baru</strong>
          <button type="button" class="secondary small" data-remove-section>Hapus bagian</button>
        </div>
        ${sectionSettingsMarkup()}
        <div class="builder-blocks" data-section-blocks></div>
        <button type="button" class="secondary small" data-add-section-block>+ Blok</button>`;
      sectionsWrap.appendChild(sectionNode);
      bindSectionColor(sectionNode);
      collectFromDom();
    };

    sectionsWrap?.addEventListener('input', collectFromDom);

    builderForm.addEventListener('click', (event) => {
      const addBlock = event.target.closest('[data-add-block]');
      if (addBlock) {
        let lastSection = sectionsWrap.querySelector('.builder-section-edit:last-of-type');
        if (!lastSection) {
          appendSection();
          lastSection = sectionsWrap.querySelector('.builder-section-edit:last-of-type');
        }
        if (lastSection) appendBlock(lastSection, addBlock.dataset.addBlock);
        return;
      }
      if (event.target.closest('[data-builder-add-section]')) {
        appendSection();
        return;
      }
      const sectionBlockButton = event.target.closest('[data-add-section-block]');
      if (sectionBlockButton) {
        appendBlock(sectionBlockButton.closest('.builder-section-edit'), 'text');
        return;
      }
      if (event.target.closest('[data-remove-block]')) {
        event.target.closest('.builder-block-edit')?.remove();
        collectFromDom();
        return;
      }
      if (event.target.closest('[data-remove-section]')) {
        event.target.closest('.builder-section-edit')?.remove();
        collectFromDom();
        return;
      }
      const duplicate = event.target.closest('[data-duplicate-block]');
      if (duplicate) {
        const block = duplicate.closest('.builder-block-edit');
        const clone = block.cloneNode(true);
        clone.dataset.blockId = Math.random().toString(16).slice(2, 10);
        block.after(clone);
        collectFromDom();
        return;
      }
      const move = event.target.closest('[data-move-block]');
      if (move) {
        const block = move.closest('.builder-block-edit');
        if (move.dataset.moveBlock === 'up' && block.previousElementSibling) {
          block.parentElement.insertBefore(block, block.previousElementSibling);
        }
        if (move.dataset.moveBlock === 'down' && block.nextElementSibling) {
          block.parentElement.insertBefore(block.nextElementSibling, block);
        }
        collectFromDom();
        return;
      }
      if (event.target.closest('[data-builder-refresh]')) {
        refreshPreview();
      }
    });

    // The page title feeds the preview header while a new page is being built.
    $('#builder-title', builderForm)?.addEventListener('input', refreshPreview);

    builderForm.addEventListener('submit', collectFromDom);

    // Keep local inputs in sync even before user interaction
    sync('');
  }

  // ---------------------------------------------------------------
  // SEO audit refresh
  // ---------------------------------------------------------------
  const auditButton = $('[data-seo-audit]');
  if (auditButton) {
    auditButton.addEventListener('click', async () => {
      auditButton.disabled = true;
      auditButton.textContent = 'Memeriksa…';
      try {
        const response = await fetch(`${adminRoot()}?ajax=seo.audit`, {
          headers: { 'X-Requested-With': 'XMLHttpRequest' },
          credentials: 'same-origin'
        });
        const data = await response.json();
        if (data.ok) window.location.reload();
        else auditButton.textContent = 'Gagal';
      } catch (error) {
        auditButton.textContent = 'Gagal';
      }
    });
  }

  // ---------------------------------------------------------------
  // Menu quick links fill the form
  // ---------------------------------------------------------------
  $$('[data-fill-url]').forEach((chip) => {
    chip.addEventListener('click', () => {
      const label = $('#label');
      const url = $('#url');
      if (label) label.value = chip.dataset.fillLabel || '';
      if (url) url.value = chip.dataset.fillUrl || '';
    });
  });

  // ---------------------------------------------------------------
  // Confirm on destructive forms not covered by inline handlers
  // ---------------------------------------------------------------
  $$('form[data-confirm]').forEach((form) => {
    form.addEventListener('submit', (event) => {
      if (!window.confirm(form.dataset.confirm)) event.preventDefault();
    });
  });

  // ---------------------------------------------------------------
  // Shell: theme toggle, sidebar collapse / drawer, user menu, toasts
  // ---------------------------------------------------------------
  const root = document.documentElement;

  const themeToggle = $('[data-theme-toggle]');
  if (themeToggle) {
    themeToggle.addEventListener('click', () => {
      const next = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
      root.setAttribute('data-theme', next);
      try { localStorage.setItem('cms-theme', next); } catch (error) { /* ignore */ }
    });
  }

  const sidebar = $('#admin-sidebar');
  const scrim = $('.sidebar-scrim');
  const sidebarToggle = $('[data-sidebar-toggle]');
  const isMobileNav = () => window.matchMedia('(max-width: 1024px)').matches;

  const openDrawer = () => {
    document.body.classList.add('sidebar-open');
    if (scrim) scrim.hidden = false;
    if (sidebarToggle) sidebarToggle.setAttribute('aria-expanded', 'true');
  };
  const closeDrawer = () => {
    document.body.classList.remove('sidebar-open');
    if (scrim) scrim.hidden = true;
    if (sidebarToggle) sidebarToggle.setAttribute('aria-expanded', 'false');
  };
  const persistCollapsed = (collapsed) => {
    try { localStorage.setItem('cms-sidebar', collapsed ? 'collapsed' : 'expanded'); } catch (error) { /* ignore */ }
  };

  if (sidebarToggle) {
    sidebarToggle.addEventListener('click', () => {
      if (isMobileNav()) {
        document.body.classList.contains('sidebar-open') ? closeDrawer() : openDrawer();
        return;
      }
      persistCollapsed(document.body.classList.toggle('sidebar-collapsed'));
    });
  }

  const syncCollapseLabel = () => {
    const collapsed = document.body.classList.contains('sidebar-collapsed');
    $$('[data-sidebar-collapse]').forEach((button) => {
      const label = collapsed ? 'Perluas sidebar' : 'Ringkas sidebar';
      button.setAttribute('title', label);
      button.setAttribute('aria-label', label);
      button.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
    });
  };
  $$('[data-sidebar-collapse]').forEach((button) => {
    button.addEventListener('click', () => {
      persistCollapsed(document.body.classList.toggle('sidebar-collapsed'));
      syncCollapseLabel();
    });
  });
  syncCollapseLabel();

  $$('[data-sidebar-close]').forEach((button) => button.addEventListener('click', closeDrawer));
  if (sidebar) {
    $$('a', sidebar).forEach((link) => link.addEventListener('click', () => { if (isMobileNav()) closeDrawer(); }));
  }
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') closeDrawer();
  });
  window.addEventListener('resize', debounce(() => { if (!isMobileNav()) closeDrawer(); }, 150));

  const userMenu = $('.user-menu');
  if (userMenu) {
    document.addEventListener('click', (event) => {
      if (userMenu.open && !userMenu.contains(event.target)) userMenu.open = false;
    });
    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') userMenu.open = false;
    });
  }

  $$('.toast').forEach((toast) => {
    const dismiss = () => {
      toast.style.transition = 'opacity .18s ease, transform .18s ease';
      toast.style.opacity = '0';
      toast.style.transform = 'translateY(-6px)';
      setTimeout(() => toast.remove(), 180);
    };
    const close = $('[data-toast-close]', toast);
    if (close) close.addEventListener('click', dismiss);
    setTimeout(dismiss, 6500);
  });

  const slugEcho = $('[data-slug-echo]');
  const pageSlug = $('#slug');
  if (slugEcho && pageSlug) {
    const syncSlug = () => { slugEcho.textContent = pageSlug.value.trim() || 'slug'; };
    pageSlug.addEventListener('input', syncSlug);
    syncSlug();
  }
})();
