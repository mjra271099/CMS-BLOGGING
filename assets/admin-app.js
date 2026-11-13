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
    const scriptUrl = new URL('assets/admin-app.js', window.location.href);
    return scriptUrl.href.replace(/assets\/admin-app\.js.*$/, 'admin/index.php');
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
          body: bodyField.value,
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
    $$('[data-preview-width]').forEach((button) => {
      button.addEventListener('click', () => {
        previewFrame.style.width = button.dataset.previewWidth + (button.dataset.previewWidth === '100%' ? '' : 'px');
        previewFrame.style.margin = button.dataset.previewWidth === '100%' ? '0' : '0 auto';
      });
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
          settings: { background: '', padding: 'md', width: 'normal', class: '' },
          blocks
        });
      });
      sync('Perubahan belum disimpan.');
      renderPreview();
    };

    const renderPreview = () => {
      const frame = $('[data-builder-preview]');
      if (!frame) return;
      frame.innerHTML = `<p class="muted">${document_.sections.reduce((total, item) => total + (item.blocks || []).length, 0)} blok siap disimpan.</p>`;
    };

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

    const appendSection = () => {
      const sectionNode = document.createElement('section');
      sectionNode.className = 'builder-section-edit';
      sectionNode.dataset.sectionId = Math.random().toString(16).slice(2, 10);
      sectionNode.innerHTML = `
        <div class="builder-section-head">
          <strong>Bagian Baru</strong>
          <button type="button" class="secondary small" data-remove-section>Hapus bagian</button>
        </div>
        <div class="builder-blocks" data-section-blocks></div>
        <button type="button" class="secondary small" data-add-section-block>+ Blok</button>`;
      sectionsWrap.appendChild(sectionNode);
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
        renderPreview();
      }
    });

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
})();
