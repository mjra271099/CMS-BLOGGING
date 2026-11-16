/* ==========================================================================
   CV & Resume Maker — mesin aplikasi
   --------------------------------------------------------------------------
   • Satu state (localStorage) menjadi sumber semua template: ganti desain
     tidak menghilangkan isian.
   • Intelligent layout: ukur tinggi dokumen, kecilkan font secara bertahap
     sampai batas minimum, rapatkan spacing, atur margin — lalu peringatkan
     pengguna bila konten tetap melebihi satu halaman.
   • Download = cetak browser (Save as PDF) pada ukuran kertas asli, sehingga
     pratinjau dan hasil PDF identik.
   • Popup dukungan sukarela + direct link iklan di tab baru; unduhan tetap
     berjalan di halaman utama.
   ========================================================================== */
(function () {
  'use strict';

  var app = document.getElementById('cvApp');
  if (!app) return;

  var CFG = {};
  try { CFG = JSON.parse(app.getAttribute('data-config') || '{}') || {}; } catch (e) { CFG = {}; }

  var STORE = 'cv_maker_state_v2';
  var PHOTO_KEY = 'cv_maker_photo_v1';
  var $ = function (sel, root) { return (root || document).querySelector(sel); };
  var $$ = function (sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); };

  /* ---------------- Ukuran kertas ---------------- */
  var PAPERS = {
    a4: { label: 'A4', w: 210, h: 297 },
    letter: { label: 'Letter', w: 215.9, h: 279.4 },
    legal: { label: 'Legal', w: 215.9, h: 355.6 }
  };
  var PX_PER_MM = 96 / 25.4;

  var TEMPLATES = {
    modern: { label: 'Modern — Sidebar Gelap + Foto', photo: true },
    colored: { label: 'Klasik Serif — Elegan & Formal', photo: false },
    minimal: { label: 'Minimalis — Bersih + Foto', photo: true },
    ats: { label: 'ATS Friendly — Satu Kolom', photo: false },
    timeline: { label: 'Timeline — Riwayat Bergaris Waktu', photo: false }
  };

  var LIST_DEFS = {
    experience: { tpl: 'tplEntryExperience', label: 'Pengalaman', keys: ['title', 'company', 'place', 'start', 'end', 'desc'] },
    education: { tpl: 'tplEntryEducation', label: 'Pendidikan', keys: ['degree', 'school', 'place', 'start', 'end', 'note'] },
    certs: { tpl: 'tplEntryCerts', label: 'Sertifikasi', keys: ['title', 'issuer', 'year'] },
    languages: { tpl: 'tplEntryLanguages', label: 'Bahasa', keys: ['name', 'level'] },
    projects: { tpl: 'tplEntryProjects', label: 'Proyek', keys: ['title', 'meta', 'desc'] },
    awards: { tpl: 'tplEntryAwards', label: 'Penghargaan', keys: ['title', 'issuer', 'year'] }
  };

  /* ---------------- State ---------------- */
  function blank() {
    var template = CFG.defaultTemplate && TEMPLATES[CFG.defaultTemplate] ? CFG.defaultTemplate : 'modern';
    return {
      template: template,
      paper: PAPERS[CFG.paper] ? CFG.paper : 'a4',
      paperW: 210,
      paperH: 297,
      name: '',
      role: '',
      email: '',
      phone: '',
      city: '',
      link: '',
      summary: '',
      skills: '',
      extra: '',
      experience: [],
      education: [],
      certs: [],
      languages: [],
      projects: [],
      awards: []
    };
  }

  function normalize(raw) {
    var base = blank();
    if (!raw || typeof raw !== 'object') return base;
    Object.keys(base).forEach(function (k) {
      if (raw[k] === undefined || raw[k] === null) return;
      if (Array.isArray(base[k])) {
        if (Array.isArray(raw[k])) {
          base[k] = raw[k].filter(function (row) { return row && typeof row === 'object'; }).slice(0, 100).map(function (row) {
            var out = {};
            (LIST_DEFS[k] ? LIST_DEFS[k].keys : []).forEach(function (kk) {
              out[kk] = String(row[kk] == null ? '' : row[kk]).slice(0, 1000);
            });
            return out;
          });
        }
      } else if (typeof base[k] === 'string') {
        base[k] = String(raw[k]).slice(0, 900);
      }
    });
    if (!TEMPLATES[base.template]) base.template = 'modern';
    if (base.paper !== 'custom' && !PAPERS[base.paper]) base.paper = 'a4';
    base.paperW = clamp(parseFloat(raw.paperW) || 210, 105, 500);
    base.paperH = clamp(parseFloat(raw.paperH) || 297, 148, 600);
    return base;
  }

  function clamp(v, min, max) { return Math.min(max, Math.max(min, v)); }

  var state = blank();
  var photo = '';
  var saveTimer = null;
  var fitTimer = null;
  var dirtySincePrint = false;

  function loadState() {
    try {
      var raw = localStorage.getItem(STORE);
      if (raw) state = normalize(JSON.parse(raw));
      photo = localStorage.getItem(PHOTO_KEY) || '';
    } catch (e) {
      state = blank();
    }
  }

  function saveState() {
    app.querySelector('#cvSaveState') && ($('#cvSaveState').textContent = 'Menyimpan…');
    clearTimeout(saveTimer);
    saveTimer = setTimeout(function () {
      try {
        localStorage.setItem(STORE, JSON.stringify(state));
        if ($('#cvSaveState')) $('#cvSaveState').textContent = 'Tersimpan otomatis · ' + new Date().toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit' });
      } catch (e) {
        if ($('#cvSaveState')) $('#cvSaveState').textContent = 'Penyimpanan peramban penuh — data mungkin tidak tersimpan.';
      }
    }, 320);
  }

  /* ---------------- Pemilih desain ---------------- */
  function buildTemplateSelect() {
    var sel = $('#cvTpl');
    if (!sel) return;
    var allowed = Array.isArray(CFG.templates) && CFG.templates.length ? CFG.templates : Object.keys(TEMPLATES);
    // Tiga desain utama selalu tersedia; tambahan mengikuti pengaturan admin.
    ['modern', 'colored', 'minimal'].forEach(function (slug) {
      if (allowed.indexOf(slug) === -1) allowed.push(slug);
    });
    sel.innerHTML = '';
    allowed.forEach(function (slug) {
      if (!TEMPLATES[slug]) return;
      var opt = document.createElement('option');
      opt.value = slug;
      opt.textContent = TEMPLATES[slug].label;
      sel.appendChild(opt);
    });
    if (!allowed.length) sel.value = 'modern';
  }

  function photoSupported(template) {
    return !!(TEMPLATES[template] && TEMPLATES[template].photo);
  }

  /* ---------------- Bind field sederhana ---------------- */
  function bindSimple() {
    $$('[data-bind]', $(('#cvForm'))).forEach(function (el) {
      var key = el.getAttribute('data-bind');
      el.value = state[key] || '';
      el.addEventListener('input', function () {
        state[key] = el.value;
        var counter = $('[data-count="' + key + '"]');
        if (counter) counter.textContent = String(el.value.length);
        el.classList.remove('invalid');
        onInput();
      });
      if (el.tagName === 'TEXTAREA') {
        var counter = $('[data-count="' + key + '"]');
        if (counter) counter.textContent = String((state[key] || '').length);
      }
    });
  }

  /* ---------------- Daftar dinamis ---------------- */
  function makeItem(kind, data, index) {
    var def = LIST_DEFS[kind];
    var tpl = document.getElementById(def.tpl);
    if (!tpl) return null;
    var node = tpl.content.firstElementChild.cloneNode(true);
    node.setAttribute('data-index', String(index));
    $('[data-k]', node) && $$('[data-k]', node).forEach(function (field) {
      var k = field.getAttribute('data-k');
      field.value = data[k] || '';
      field.addEventListener('input', function () {
        var idx = parseInt(node.getAttribute('data-index'), 10);
        if (state[kind][idx]) state[kind][idx][k] = field.value;
        updateItemTitle(node, kind);
        field.classList.remove('invalid');
        onInput();
      });
      field.addEventListener('change', function () {
        var idx = parseInt(node.getAttribute('data-index'), 10);
        if (state[kind][idx]) state[kind][idx][k] = field.value;
        updateItemTitle(node, kind);
        onInput();
      });
    });
    updateItemTitle(node, kind);
    return node;
  }

  function updateItemTitle(node, kind) {
    var label = $('[data-item-title]', node);
    if (!label) return;
    var prim = kind === 'education' ? 'degree' : (kind === 'languages' ? 'name' : 'title');
    var v = '';
    var primEl = $('[data-k="' + prim + '"]', node);
    if (primEl && primEl.value.trim()) v = primEl.value.trim();
    var sec = $('[data-k="company"]', node) || $('[data-k="school"]', node) || $('[data-k="issuer"]', node);
    if (sec && sec.value.trim()) v = v ? v + ' · ' + sec.value.trim() : sec.value.trim();
    label.textContent = v || LIST_DEFS[kind].label;
  }

  function renderList(kind) {
    var wrap = $('[data-list="' + kind + '"]');
    if (!wrap) return;
    wrap.innerHTML = '';
    (state[kind] || []).forEach(function (row, i) {
      var node = makeItem(kind, row, i);
      if (node) wrap.appendChild(node);
    });
  }

  function moveItem(kind, index, dir) {
    var list = state[kind];
    if (!list) return;
    var target = index + (dir === 'up' ? -1 : 1);
    if (target < 0 || target >= list.length) return;
    var tmp = list[index];
    list[index] = list[target];
    list[target] = tmp;
    renderList(kind);
    saveState();
    renderCV();
  }

  function removeItem(kind, index) {
    var label = LIST_DEFS[kind].label;
    if (!window.confirm('Hapus ' + label.toLowerCase() + ' ini? Data yang dihapus tidak bisa dikembalikan.')) return;
    state[kind].splice(index, 1);
    renderList(kind);
    saveState();
    renderCV();
  }

  function addItem(kind) {
    if (!LIST_DEFS[kind]) return;
    var row = {};
    LIST_DEFS[kind].keys.forEach(function (k) { row[k] = ''; });
    state[kind].push(row);
    renderList(kind);
    var wrap = $('[data-list="' + kind + '"]');
    if (wrap && wrap.lastElementChild) {
      wrap.lastElementChild.scrollIntoView({ behavior: 'smooth', block: 'center' });
      var first = $('input, textarea, select', wrap.lastElementChild);
      if (first && window.innerWidth > 720) first.focus({ preventScroll: true });
    }
    onInput();
  }

  function bindLists() {
    app.addEventListener('click', function (ev) {
      var add = ev.target.closest('[data-add]');
      if (add) { addItem(add.getAttribute('data-add')); return; }
      var item = ev.target.closest('[data-item]');
      if (!item) return;
      var kind = item.parentElement.getAttribute('data-list');
      var index = parseInt(item.getAttribute('data-index'), 10);
      if (ev.target.closest('[data-remove]')) { removeItem(kind, index); return; }
      var mv = ev.target.closest('[data-move]');
      if (mv) moveItem(kind, index, mv.getAttribute('data-move'));
    });
  }

  /* ---------------- Foto ---------------- */
  function bindPhoto() {
    var input = $('#cvPhotoInput');
    var preview = $('#cvPhotoPreview');
    var clear = $('#cvPhotoClear');

    function paint() {
      if (!preview) return;
      if (photo) {
        preview.style.backgroundImage = 'url("' + photo + '")';
        preview.classList.add('has-photo');
      } else {
        preview.style.backgroundImage = '';
        preview.classList.remove('has-photo');
      }
    }
    paint();

    if (input) input.addEventListener('change', function () {
      var file = input.files && input.files[0];
      input.value = '';
      if (!file) return;
      if (!/^image\/(jpeg|png|webp)$/.test(file.type)) {
        window.alert('Format foto harus JPG, PNG, atau WebP.');
        return;
      }
      if (file.size > 2 * 1024 * 1024) {
        window.alert('Ukuran foto maksimal 2 MB.');
        return;
      }
      var reader = new FileReader();
      reader.onload = function () {
        var img = new Image();
        img.onload = function () {
          var max = 460;
          var scale = Math.min(1, max / Math.max(img.width, img.height));
          var canvas = document.createElement('canvas');
          canvas.width = Math.max(1, Math.round(img.width * scale));
          canvas.height = Math.max(1, Math.round(img.height * scale));
          canvas.getContext('2d').drawImage(img, 0, 0, canvas.width, canvas.height);
          photo = canvas.toDataURL('image/jpeg', 0.86);
          try { localStorage.setItem(PHOTO_KEY, photo); } catch (e) { /* diamkan */ }
          paint();
          renderCV();
        };
        img.onerror = function () { window.alert('Foto tidak dapat dibaca.'); };
        img.src = String(reader.result || '');
      };
      reader.readAsDataURL(file);
    });

    if (clear) clear.addEventListener('click', function () {
      if (!photo) return;
      if (!window.confirm('Hapus foto profil?')) return;
      photo = '';
      try { localStorage.removeItem(PHOTO_KEY); } catch (e) { /* diamkan */ }
      paint();
      renderCV();
    });
  }

  /* ---------------- Ukuran kertas ---------------- */
  function paperSize() {
    if (state.paper === 'custom') {
      return { label: 'Kustom', w: clamp(state.paperW, 105, 500), h: clamp(state.paperH, 148, 600) };
    }
    return PAPERS[state.paper] || PAPERS.a4;
  }

  function paperCss() {
    var p = paperSize();
    var w = Math.round(p.w * PX_PER_MM);
    var h = Math.round(p.h * PX_PER_MM);
    var root = $('#cvPages');
    if (root) {
      root.style.setProperty('--paper-w', w + 'px');
      root.style.setProperty('--paper-h', h + 'px');
    }
    scheduleFitScroll();
    return { w: w, h: h };
  }

  var scaleTimer = null;
  function scheduleFitScroll() {
    clearTimeout(scaleTimer);
    scaleTimer = setTimeout(fitScroll, 90);
  }

  /* ---------------- Render CV ---------------- */
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function initials(name) {
    return name.trim().split(/\s+/).slice(0, 2).map(function (w) { return w.charAt(0).toUpperCase(); }).join('');
  }

  function has(list) { return Array.isArray(list) && list.length > 0; }

  function nonEmpty(row, keys) {
    return keys.some(function (k) { return String(row[k] || '').trim() !== ''; });
  }

  function bulletLines(text) {
    return String(text || '').split(/\r?\n/).map(function (l) { return l.replace(/^[-•*]\s*/, '').trim(); }).filter(Boolean);
  }

  function bullets(text) {
    var lines = bulletLines(text);
    if (!lines.length) return '';
    if (lines.length === 1) return '<p>' + esc(lines[0]) + '</p>';
    return '<ul class="cv-bullets">' + lines.map(function (l) { return '<li>' + esc(l) + '</li>'; }).join('') + '</ul>';
  }

  function when(start, end) {
    var a = String(start || '').trim();
    var b = String(end || '').trim();
    if (a && b) return a + ' – ' + b;
    return a || b || '';
  }

  function skills() {
    return String(state.skills || '').split(/[,;\n]/).map(function (s) { return s.trim(); }).filter(Boolean).slice(0, 60);
  }

  function contactLine(sep) {
    var items = [state.email, state.phone, state.city, state.link].filter(function (v) { return String(v || '').trim() !== ''; });
    if (!items.length) return '';
    return '<div class="cv-contact">' + items.map(function (v) { return '<span>' + esc(v) + '</span>'; }).join(sep || '') + '</div>';
  }

  function secTitle(title) {
    return '<h2 class="cv-sec-title">' + esc(title) + '</h2>';
  }

  function expBlock(list) {
    return list.filter(function (r) { return nonEmpty(r, LIST_DEFS.experience.keys); }).map(function (r) {
      return '<div class="cv-entry">'
        + '<div class="cv-entry-head"><span class="cv-entry-title">' + esc(r.title || '') + (r.company ? ' — ' + esc(r.company) : '') + '</span>'
        + (when(r.start, r.end) ? '<span class="cv-entry-when">' + esc(when(r.start, r.end)) + '</span>' : '') + '</div>'
        + (r.place ? '<p class="cv-entry-sub">' + esc(r.place) + '</p>' : '')
        + bullets(r.desc)
        + '</div>';
    }).join('');
  }

  function eduBlock(list) {
    return list.filter(function (r) { return nonEmpty(r, LIST_DEFS.education.keys); }).map(function (r) {
      return '<div class="cv-entry">'
        + '<div class="cv-entry-head"><span class="cv-entry-title">' + esc(r.degree || '') + (r.school ? ' — ' + esc(r.school) : '') + '</span>'
        + (when(r.start, r.end) ? '<span class="cv-entry-when">' + esc(when(r.start, r.end)) + '</span>' : '') + '</div>'
        + ((r.place || r.note) ? '<p class="cv-entry-sub">' + [r.place, r.note].filter(Boolean).map(esc).join(' · ') + '</p>' : '')
        + '</div>';
    }).join('');
  }

  function certBlock(list) {
    var rows = list.filter(function (r) { return nonEmpty(r, LIST_DEFS.certs.keys); }).map(function (r) {
      var meta = [r.issuer, r.year].filter(Boolean).map(esc).join(' · ');
      return '<li>' + esc(r.title || '') + (meta ? ' <span style="opacity:.7">(' + meta + ')</span>' : '') + '</li>';
    });
    return rows.length ? '<ul class="cv-bullets">' + rows.join('') + '</ul>' : '';
  }

  function langBlock(list) {
    var rows = list.filter(function (r) { return nonEmpty(r, LIST_DEFS.languages.keys); }).map(function (r) {
      return '<div class="row"><span>' + esc(r.name || '') + '</span><span style="opacity:.7">' + esc(r.level || '') + '</span></div>';
    });
    return rows.length ? '<div class="cv-inline-list">' + rows.join('') + '</div>' : '';
  }

  function projectBlock(list) {
    return list.filter(function (r) { return nonEmpty(r, LIST_DEFS.projects.keys); }).map(function (r) {
      return '<div class="cv-entry"><div class="cv-entry-head"><span class="cv-entry-title">' + esc(r.title || '') + '</span>'
        + (r.meta ? '<span class="cv-entry-when">' + esc(r.meta) + '</span>' : '') + '</div>' + bullets(r.desc) + '</div>';
    }).join('');
  }

  function awardBlock(list) {
    var rows = list.filter(function (r) { return nonEmpty(r, LIST_DEFS.awards.keys); }).map(function (r) {
      var meta = [r.issuer, r.year].filter(Boolean).map(esc).join(' · ');
      return '<li>' + esc(r.title || '') + (meta ? ' <span style="opacity:.7">(' + meta + ')</span>' : '') + '</li>';
    });
    return rows.length ? '<ul class="cv-bullets">' + rows.join('') + '</ul>' : '';
  }

  function skillsBlock() {
    var list = skills();
    if (!list.length) return '';
    return '<div class="cv-tags">' + list.map(function (s) { return '<span class="cv-tag">' + esc(s) + '</span>'; }).join('') + '</div>';
  }

  function summaryBlock() {
    var s = String(state.summary || '').trim();
    return s ? '<p>' + esc(s) + '</p>' : '';
  }

  function extraBlock() {
    var s = String(state.extra || '').trim();
    return s ? '<p>' + esc(s).replace(/\n/g, '<br>') + '</p>' : '';
  }

  function photoBox() {
    if (!photo) return '';
    return ' style="background-image:url(\'' + photo + '\')"';
  }

  /** Bagian-bagian CV dalam satu daftar agar bisa didistribusikan per template. */
  function sections() {
    return {
      summary: { title: 'Ringkasan Profesional', html: summaryBlock() },
      experience: { title: 'Pengalaman Kerja', html: expBlock(state.experience) },
      education: { title: 'Pendidikan', html: eduBlock(state.education) },
      skills: { title: 'Keterampilan', html: skillsBlock() },
      certs: { title: 'Sertifikasi', html: certBlock(state.certs) },
      languages: { title: 'Bahasa', html: langBlock(state.languages) },
      projects: { title: 'Proyek', html: projectBlock(state.projects) },
      awards: { title: 'Penghargaan', html: awardBlock(state.awards) },
      extra: { title: 'Informasi Tambahan', html: extraBlock() }
    };
  }

  function renderSection(key, sec) {
    if (!sec.html) return '';
    return '<section data-section="' + key + '">' + secTitle(sec.title) + sec.html + '</section>';
  }

  function headContacts(sep) { return contactLine(sep); }

  function contactItems() {
    return [state.email, state.phone, state.city, state.link].filter(function (v) { return String(v || '').trim() !== ''; });
  }

  /**
   * Bangun HTML isi CV untuk satu template. Mengembalikan STRING markup template
   * (belum dipaginasi). Setiap template memilih sendiri bagian mana di kolom mana.
   */
  function buildTemplate(tpl) {
    var s = sections();
    var name = String(state.name || '').trim() || 'Nama Anda';
    var role = String(state.role || '').trim();

    if (tpl === 'modern') {
      var side = renderSection('skills', s.skills)
        + renderSection('languages', s.languages)
        + renderSection('certs', s.certs)
        + renderSection('awards', s.awards);
      var main = renderSection('summary', s.summary)
        + renderSection('experience', s.experience)
        + renderSection('education', s.education)
        + renderSection('projects', s.projects)
        + renderSection('extra', s.extra);
      var photoHtml = photo
        ? '<div class="photo"' + photoBox() + ' role="img" aria-label="Foto profil"></div>'
        : '<div class="photo photo-fallback" role="img" aria-label="Foto profil">' + esc(initials(name)) + '</div>';
      var sideTop = '<div class="side-top">' + photoHtml
        + '<h1 class="name">' + esc(name) + '</h1>'
        + (role ? '<p class="role">' + esc(role) + '</p>' : '') + '</div>';
      return '<div class="tpl-modern">'
        + '<aside class="side">' + sideTop
        + '<div>' + renderSection('contact', { title: 'Kontak', html: headContacts('') }) + '</div>'
        + side + '</aside>'
        + '<div class="main"><div class="headline">'
        + '<h1 class="name-lg">' + esc(name) + '</h1>'
        + (role ? '<p class="role-lg">' + esc(role) + '</p>' : '')
        + '</div><div class="contact-row">' + headContacts('') + '</div>'
        + main + '</div></div>';
    }

    if (tpl === 'colored') {
      return '<div class="tpl-colored">'
        + '<header class="head"><h1 class="name">' + esc(name) + '</h1>'
        + (role ? '<p class="role">' + esc(role) + '</p>' : '') + headContacts() + '</header>'
        + ['summary', 'experience', 'education', 'skills', 'projects', 'certs', 'awards', 'languages', 'extra']
            .map(function (k) { return renderSection(k, s[k]); }).join('')
        + '</div>';
    }

    if (tpl === 'minimal') {
      return '<div class="tpl-minimal">'
        + '<header class="head">'
        + (photo ? '<div class="photo"' + photoBox() + ' role="img" aria-label="Foto profil"></div>' : '')
        + '<div class="head-text"><h1 class="name">' + esc(name) + '</h1>'
        + (role ? '<p class="role">' + esc(role) + '</p>' : '') + headContacts() + '</div>'
        + '</header>'
        + '<div class="cv-head-rule"></div>'
        + ['summary', 'experience', 'education', 'skills', 'projects', 'certs', 'awards', 'languages', 'extra']
            .map(function (k) { return renderSection(k, s[k]); }).join('')
        + '</div>';
    }

    if (tpl === 'ats') {
      return '<div class="tpl-ats">'
        + '<header><h1 class="name">' + esc(name) + '</h1>'
        + (role ? '<p class="role">' + esc(role) + '</p>' : '') + headContacts() + '</header>'
        + ['summary', 'skills', 'experience', 'education', 'projects', 'certs', 'awards', 'languages', 'extra']
            .map(function (k) { return renderSection(k, s[k]); }).join('')
        + '</div>';
    }

    // timeline
    var contactGrid = contactItems().map(function (v) { return '<span>' + esc(v) + '</span>'; }).join('');
    var left = ['summary', 'experience', 'projects'];
    var right = ['education', 'skills', 'certs', 'awards', 'languages', 'extra'];
    function tlSection(k, withTimeline) {
      if (!s[k].html) return '';
      var inner = withTimeline ? '<div class="tl">' + s[k].html + '</div>' : s[k].html;
      return '<section data-section="' + k + '">' + secTitle(s[k].title) + inner + '</section>';
    }
    return '<div class="tpl-timeline">'
      + '<header class="head"><div class="head-main"><h1 class="name">' + esc(name) + '</h1>'
      + (role ? '<p class="role">' + esc(role) + '</p>' : '') + '</div>'
      + (contactGrid ? '<div class="head-contact">' + contactGrid + '</div>' : '') + '</header>'
      + left.map(function (k) { return tlSection(k, true); }).join('')
      + '<div class="cols2">'
      + right.map(function (k) { return tlSection(k, k === 'education'); }).join('')
      + '</div></div>';
  }

  /* ---------------- Paginasi multi-halaman ---------------- */

  var pageStyleEl = null;
  function applyPageSize() {
    var p = paperSize();
    if (!pageStyleEl) {
      pageStyleEl = document.createElement('style');
      pageStyleEl.id = 'cvPageStyle';
      document.head.appendChild(pageStyleEl);
    }
    pageStyleEl.textContent = '@page { size: ' + p.w + 'mm ' + p.h + 'mm; margin: 0; }';
  }

  /** Terapkan setelan layout (font/gap/pad) + sesuaikan ukuran nama. */
  function applyStep(doc, step) {
    doc.style.setProperty('--fs', step.fs.toFixed(2) + 'px');
    doc.style.setProperty('--gap', (6.4 * step.gap).toFixed(2) + 'px');
    doc.style.setProperty('--pad', (step.pad * 32).toFixed(2) + 'px');
    doc.style.setProperty('--pad-x', (step.pad * 48).toFixed(2) + 'px');
    doc.style.setProperty('--lh', (1.34 + 0.06 * step.gap).toFixed(2));
    fitNames(doc, step);
  }

  /** Kecilkan nama (tanpa melewati lantai) agar muat satu baris bila mungkin. */
  function fitNames(doc, step) {
    var tpl = state.template;
    var scaleMap = { modern: 2.1, colored: 2.45, minimal: 2.35, ats: 1.95, timeline: 2.3 };
    var scale = scaleMap[tpl] || 2.1;
    $$('.name, .name-lg', doc).forEach(function (nameEl) {
      nameEl.style.fontSize = '';
      nameEl.style.whiteSpace = '';
      nameEl.style.overflowWrap = '';
      var baseFs = parseFloat(getComputedStyle(nameEl).fontSize) || (step.fs * scale);
      var parent = nameEl.parentElement;
      var avail = parent ? parent.clientWidth : nameEl.clientWidth;
      if (avail <= 0) return;
      nameEl.style.whiteSpace = 'nowrap';
      var w = measureWidth(nameEl);
      // Lantai: minimal ~1.55x font isi agar tetap menonjol namun terbaca.
      var floor = step.fs * 1.55;
      if (w > avail + 0.5) {
        var fitted = Math.max(floor, baseFs * (avail / w) * 0.985);
        nameEl.style.fontSize = fitted.toFixed(2) + 'px';
        if (measureWidth(nameEl) > avail + 0.5) {
          // Masih kurang: biarkan membungkus ke baris berikutnya, jangan
          // mengecilkan sampai tak terbaca.
          nameEl.style.whiteSpace = 'normal';
          nameEl.style.overflowWrap = 'anywhere';
        }
      }
    });
  }

  /**
   * Ukur tinggi alami isi dokumen (tanpa batas kertas). Doc pada tahap ini
   * dirender di dalam panggung ukur dengan tinggi bebas.
   */
  function measureContent(doc) {
    var first = doc.firstElementChild;
    if (!first) return 0;
    return Math.max(first.scrollHeight, Math.ceil(first.getBoundingClientRect().height));
  }

  function measureWidth(el) {
    var cs = getComputedStyle(el);
    var clone = el.cloneNode(true);
    clone.style.cssText = [
      'position:absolute', 'visibility:hidden', 'left:-9999px', 'top:0',
      'width:auto', 'max-width:none', 'white-space:nowrap', 'display:inline-block',
      'font-size:' + cs.fontSize, 'font-family:' + cs.fontFamily, 'font-weight:' + cs.fontWeight,
      'font-style:' + cs.fontStyle, 'letter-spacing:' + cs.letterSpacing,
      'text-transform:' + cs.textTransform, 'margin:0'
    ].join(';');
    document.body.appendChild(clone);
    var w = clone.getBoundingClientRect().width;
    document.body.removeChild(clone);
    return w;
  }


  var fitScrollTimer = null;
  function fitScroll() {
    clearTimeout(fitScrollTimer);
    fitScrollTimer = setTimeout(runFitScroll, 30);
  }

  var zoomMode = 'fit'; // 'fit' | 'full'

  /**
   * Skalakan semua halaman agar muat lebar panggung (mode "fit"), atau 100%
   * dengan scroll horizontal (mode "full"). Transform tidak memengaruhi
   * layout, jadi tinggi tiap halaman dicadangkan manual di .cv-page-slot.
   */
  function runFitScroll() {
    var stage = $('#cvStage');
    var root = $('#cvPages');
    if (!stage || !root) return;
    var viewWidth = document.documentElement.clientWidth || window.innerWidth;
    var stageW = Math.min(stage.clientWidth || viewWidth, viewWidth);
    var available = stageW - (window.innerWidth >= 1080 ? 30 : 16);
    if (available < 80) return;
    var size = paperSize();
    var paperPx = parseFloat(getComputedStyle(root).getPropertyValue('--paper-w')) || size.w * PX_PER_MM;
    var scale = zoomMode === 'full' ? 1 : Math.min(1, available / Math.max(1, paperPx));
    root.style.setProperty('--paper-scale', scale.toFixed(4));
    // `zoom` (bukan transform) supaya layout ikut menyusut — tinggi tiap
    // halaman otomatis dicadangkan dengan benar.
    $$('.cv-page', root).forEach(function (pg) {
      pg.style.zoom = scale === 1 ? '' : scale.toFixed(4);
    });
  }

  /* ---------------- Render CV + paginasi ---------------- */

  /** Setelan auto-fit: pilih kombinasi font/spacing paling lega yang muat. */
  var FIT_STEPS = [
    { fs: 1.00, gap: 1.00, pad: 1.00 },
    { fs: 0.97, gap: 0.90, pad: 0.95 },
    { fs: 0.94, gap: 0.82, pad: 0.90 },
    { fs: 0.91, gap: 0.74, pad: 0.86 },
    { fs: 0.88, gap: 0.66, pad: 0.82 },
    { fs: 0.86, gap: 0.60, pad: 0.78 }
  ];

  function baseFont() {
    var scale = parseFloat(CFG.fontScale) > 0 ? parseFloat(CFG.fontScale) : 1;
    return 10.6 * scale;
  }

  function renderCV() {
    var root = $('#cvPages');
    if (!root) return;
    var tpl = TEMPLATES[state.template] ? state.template : 'modern';
    var size = paperCss();
    applyPageSize();

    // Hitung layout: cari step paling lega yang membuat konten muat dalam SATU
    // halaman. Bila tidak ada yang muat, pakai step ter ketat lalu pecah ke
    // beberapa halaman secara wajar (tidak memotong konten).
    var layout = pickLayout(tpl, size);
    buildPages(tpl, layout, size);

    root.className = 'cv-pages';
    var count = $$('.cv-page', root).length || 1;
    var counter = $('#cvPageCount');
    if (counter) counter.textContent = count + ' halaman';

    updateFitNotice(layout, count);
    fitScroll();
  }

  /** Ukur tinggi template pada setelan tertentu memakai panggung tak terlihat. */
  function measureLayout(tpl, step, size) {
    var probe = document.createElement('div');
    probe.className = 'cv-page cv-measure';
    probe.style.cssText = 'position:absolute;left:-10000px;top:0;height:auto;opacity:0;pointer-events:none;transform:none;';
    probe.style.setProperty('--paper-w', size.w + 'px');
    probe.style.setProperty('--paper-h', '0px');
    var doc = document.createElement('article');
    doc.className = 'cv-doc';
    doc.style.cssText = 'min-height:0;';
    doc.style.setProperty('--accent', CFG.accent || '#0f172a');
    doc.innerHTML = buildTemplate(tpl);
    applyStep(doc, {
      fs: baseFont() * step.fs,
      gap: step.gap,
      pad: step.pad
    });
    probe.appendChild(doc);
    app.appendChild(probe);
    var height = measureContent(doc);
    app.removeChild(probe);
    return height;
  }

  /**
   * Pilih step layout.
   * Kebijakan: tipografi yang nyaman lebih penting daripada memaksa 1 halaman.
   * Hanya mengecilkan font sebatas "lantai terbaca" (≈ 6% dari ukuran dasar).
   * Bila dengan font terbaca konten tetap > 1 halaman, biarkan beberapa
   * halaman dengan font lega — jangan mengecilkan sampai sulit dibaca.
   */
  var FIT_SINGLE_PAGE_LIMIT = 3; // hanya 3 langkah teratas yang boleh untuk 1 halaman

  function pickLayout(tpl, size) {
    var results = FIT_STEPS.map(function (st) {
      var hh = measureLayout(tpl, st, size);
      return { step: st, height: hh, pages: Math.max(1, Math.ceil(hh / size.h)), fits1: hh <= size.h + 2 };
    });

    // 1) Upaya 1 halaman dengan pengecilan wajar (≤ FIT_SINGLE_PAGE_LIMIT langkah).
    for (var i = 0; i < Math.min(FIT_SINGLE_PAGE_LIMIT, results.length); i++) {
      if (results[i].fits1) {
        return { step: results[i].step, fits: true, height: results[i].height };
      }
    }

    // 2) Tidak muat tanpa mengecil berlebihan → pakai font paling terbaca
    //    (langkah 0) dan biarkan konten mengalir ke beberapa halaman. Ini
    //    lebih baik daripada font kecil yang sulit dibaca.
    var best = results[0];
    return { step: best.step, fits: false, height: best.height };
  }

  /**
   * Bangun lembar halaman.
   * • Template satu-alur (colored/minimal/ats/timeline): pecah pada batas
   *   section/entry — tidak ada teks terpotong.
   * • Template modern (dua kolom): halaman 1 memuat sidebar + header, kolom
   *   utama dipecah; halaman lanjutan memakai satu kolom penuh (tanpa sidebar)
   *   sehingga konten panjang tetap rapi dan terbaca.
   */
  function buildPages(tpl, layout, size) {
    var root = $('#cvPages');
    root.innerHTML = '';
    var step = { fs: baseFont() * layout.step.fs, gap: layout.step.gap, pad: layout.step.pad };

    if (tpl === 'modern') {
      paginateModern(step, size, layout.fits);
    } else if (layout.fits) {
      root.appendChild(makePageDoc(tpl, step, size));
    } else {
      paginateSingleFlow(tpl, step, size);
    }
  }

  function makePageDoc(tpl, step, size) {
    var page = document.createElement('div');
    page.className = 'cv-page';
    page.style.setProperty('--paper-w', size.w + 'px');
    page.style.setProperty('--paper-h', size.h + 'px');
    var doc = document.createElement('article');
    doc.className = 'cv-doc';
    doc.style.setProperty('--accent', CFG.accent || '#0f172a');
    doc.innerHTML = buildTemplate(tpl);
    applyStep(doc, step);
    page.appendChild(doc);
    return page;
  }

  /** Bungkus satu node template ke dalam lembar halaman. */
  function wrapPage(tpl, wrapper, step, size, idx) {
    var page = document.createElement('div');
    page.className = 'cv-page';
    page.style.setProperty('--paper-w', size.w + 'px');
    page.style.setProperty('--paper-h', size.h + 'px');
    page.setAttribute('data-page', String(idx + 1));
    var doc = document.createElement('article');
    doc.className = 'cv-doc';
    doc.style.setProperty('--accent', CFG.accent || '#0f172a');
    doc.appendChild(wrapper);
    applyStep(doc, step);
    page.appendChild(doc);
    return page;
  }

  /**
   * Paginasi template modern: sidebar + kontak di halaman pertama; bagian
   * utama dipecah per section/entry. Halaman lanjutan satu kolom penuh.
   */
  function paginateModern(step, size, fits) {
    var root = $('#cvPages');

    if (fits) {
      root.appendChild(makePageDoc('modern', step, size));
      return;
    }

    var work = makeWorkDoc('modern', step, size);
    var tplEl = work.querySelector('.tpl-modern');
    var sideEl = tplEl.querySelector('.side');
    var mainEl = tplEl.querySelector('.main');

    var headKids = Array.prototype.slice.call(mainEl.children);
    var headerNodes = [];
    var bodyNodes = [];
    headKids.forEach(function (el) {
      if (el.classList.contains('headline') || el.classList.contains('contact-row')) headerNodes.push(el);
      else bodyNodes.push(el);
    });

    // Pecah section panjang menjadi unit entry agar tidak terpotong.
    var units = [];
    bodyNodes.forEach(function (el) {
      if (el.tagName === 'SECTION' && el.querySelector('.cv-entry') && el.offsetHeight > size.h * 0.55) {
        var t = el.querySelector('.cv-sec-title');
        if (t) units.push(t);
        Array.prototype.slice.call(el.querySelectorAll('.cv-entry')).forEach(function (e) { units.push(e); });
      } else {
        units.push(el);
      }
    });
    // Lepaskan unit dari dokumen kerja agar tidak terukur di dua tempat.
    units.forEach(function (n) { if (n.parentNode) n.parentNode.removeChild(n); });

    var mainCs = getComputedStyle(mainEl);
    // Pengukur live: meniru padding & lebar kolom utama, termasuk header.
    // Dibungkus `.cv-doc` agar box-model (border-box) & variabel layout sama.
    var mShell = document.createElement('div');
    mShell.className = 'cv-page';
    mShell.style.cssText = 'position:absolute;visibility:hidden;left:-99999px;top:0;height:auto;';
    mShell.style.setProperty('--paper-w', size.w + 'px');
    var mDoc = document.createElement('article');
    mDoc.className = 'cv-doc';
    mDoc.style.minHeight = '0';
    mDoc.style.setProperty('--accent', CFG.accent || '#0f172a');
    var measureWrap = document.createElement('div');
    measureWrap.className = 'main';
    measureWrap.style.boxSizing = 'border-box';
    measureWrap.style.width = mainEl.offsetWidth + 'px';
    measureWrap.style.padding = mainCs.padding;
    headerNodes.forEach(function (n) { measureWrap.appendChild(n.cloneNode(true)); });
    mDoc.appendChild(measureWrap);
    mShell.appendChild(mDoc);
    app.appendChild(mShell);
    applyStep(mDoc, step);

    var pages = distributeUnits(measureWrap, units, size.h, measureWrap);
    app.removeChild(mShell);
    app.removeChild(work);

    // Halaman 1: sidebar + header + bagian utama pertama.
    var p1 = document.createElement('div');
    p1.className = 'tpl-modern';
    p1.appendChild(sideEl.cloneNode(true));
    var main1 = document.createElement('div');
    main1.className = 'main';
    headerNodes.forEach(function (n) { main1.appendChild(n.cloneNode(true)); });
    (pages[0] || []).forEach(function (n) { main1.appendChild(n); });
    p1.appendChild(main1);
    root.appendChild(wrapPage('modern', p1, step, size, 0));

    // Halaman lanjutan: satu kolom penuh (tanpa sidebar).
    for (var p = 1; p < pages.length; p++) {
      var wrap = document.createElement('div');
      wrap.className = 'tpl-modern tpl-modern-cont';
      var main = document.createElement('div');
      main.className = 'main';
      pages[p].forEach(function (n) { main.appendChild(n); });
      wrap.appendChild(main);
      root.appendChild(wrapPage('modern', wrap, step, size, p));
    }
  }

  /**
   * Bagikan node ke halaman dengan pengukuran LIVE: tambahkan satu per satu ke
   * kolom pengukur; saat tinggi melebihi batas, unit pemicu pindah ke halaman
   * baru. Memperhitungkan margin & kolaps secara akurat.
   */
  function distributeUnits(container, units, limit, measurer) {
    var pages = [];
    var cur = [];
    for (var i = 0; i < units.length; i++) {
      var node = units[i];
      container.appendChild(node);
      if (measurer.scrollHeight > limit + 1 && cur.length > 0) {
        // Halaman penuh: buang item halaman ini dari pengukur, simpan, mulai baru.
        cur.forEach(function (n) { if (n.parentNode === container) container.removeChild(n); });
        pages.push(cur);
        cur = [];
        container.appendChild(node);
      }
      cur.push(node);
    }
    if (cur.length) pages.push(cur);

    // Cegah "judul menggantung": bila sebuah halaman berakhir dengan judul
    // section (tanpa isi), pindahkan judul itu ke halaman berikutnya.
    for (var p = 0; p < pages.length - 1; p++) {
      var last = pages[p][pages[p].length - 1];
      if (last && last.classList && last.classList.contains('cv-sec-title')) {
        pages[p].pop();
        pages[p + 1].unshift(last);
        last.classList.add('cv-continued');
      }
    }
    return pages.filter(function (pg) { return pg.length; });
  }

  function makeWorkDoc(tpl, step, size) {
    var work = document.createElement('article');
    work.className = 'cv-doc';
    work.style.cssText = 'position:absolute;left:-10000px;top:0;visibility:hidden;';
    work.style.setProperty('--paper-w', size.w + 'px');
    work.style.setProperty('--accent', CFG.accent || '#0f172a');
    work.innerHTML = buildTemplate(tpl);
    work.style.cssText += ';position:absolute;left:-10000px;top:0;visibility:hidden;';
    applyStep(work, step);
    app.appendChild(work);
    return work;
  }

  /**
   * Paginasi untuk template satu alur: distribusikan section (dan, untuk
   * section yang sangat panjang, entry di dalamnya) ke halaman berurutan.
   * Header hanya muncul di halaman pertama.
   */
  function paginateSingleFlow(tpl, step, size) {
    var root = $('#cvPages');
    var work = makeWorkDoc(tpl, step, size);
    var tplEl = work.firstElementChild;
    var headerEl = tplEl.querySelector('.head, header');
    var usable = size.h;

    // Unit yang dipindah: anak template selain header. Section yang lebih
    // tinggi dari satu halaman dipecah per entry agar tidak terpotong.
    var units = [];
    Array.prototype.slice.call(tplEl.children).forEach(function (el) {
      if (el === headerEl) return;
      if (el.tagName === 'SECTION' && el.offsetHeight > usable * 0.55 && el.querySelector('.cv-entry')) {
        var titleEl = el.querySelector('.cv-sec-title');
        if (titleEl) units.push({ node: titleEl, title: true });
        Array.prototype.slice.call(el.querySelectorAll('.cv-entry')).forEach(function (entry) {
          units.push({ node: entry });
        });
        return;
      }
      units.push({ node: el });
    });

    // Pengukur live DIBANGUN DI DALAM struktur .cv-page > .cv-doc yang sama
    // seperti halaman akhir, sehingga box-model & variabel layout identik.
    var shell = document.createElement('div');
    shell.className = 'cv-page';
    shell.style.cssText = 'position:absolute;visibility:hidden;left:-99999px;top:0;height:auto;min-height:0;';
    shell.style.setProperty('--paper-w', size.w + 'px');
    shell.style.setProperty('--paper-h', '0px');
    var shellDoc = document.createElement('article');
    shellDoc.className = 'cv-doc';
    shellDoc.style.cssText = 'min-height:0;';
    shellDoc.style.setProperty('--accent', CFG.accent || '#0f172a');
    var measureWrap = document.createElement('div');
    if (tplEl.className) measureWrap.className = tplEl.className;
    measureWrap.style.minHeight = '0';
    shellDoc.appendChild(measureWrap);
    shell.appendChild(shellDoc);
    app.appendChild(shell);
    applyStep(shellDoc, step);

    var nodes = units.map(function (u) { return u.node; });
    // Lepaskan dari dokumen kerja supaya tidak terukur ganda.
    nodes.forEach(function (n) { if (n.parentNode) n.parentNode.removeChild(n); });
    // Sertakan header di pengukur agar halaman 1 memperhitungkan tingginya.
    if (headerEl) measureWrap.appendChild(headerEl.cloneNode(true));
    var pages = distributeUnits(measureWrap, nodes, usable, measureWrap);
    app.removeChild(shell);
    app.removeChild(work);

    pages.forEach(function (pg, idx) {
      var wrapper = document.createElement('div');
      if (tplEl.className) wrapper.className = tplEl.className;
      if (idx === 0 && headerEl) wrapper.appendChild(headerEl.cloneNode(true));
      pg.forEach(function (node) { wrapper.appendChild(node); });
      root.appendChild(wrapPage(tpl, wrapper, step, size, idx));
    });
  }

  function updateFitNotice(layout, pageCount) {
    var notice = $('#cvFitNotice');
    if (!notice) return;
    if (pageCount <= 1) {
      notice.hidden = true;
      notice.textContent = '';
      notice.className = 'cv-notice';
      return;
    }
    notice.hidden = false;
    notice.className = 'cv-notice';
    notice.innerHTML = '<strong>CV ini terdiri dari ' + pageCount + ' halaman.</strong> '
      + 'Pratinjau menampilkan semua halaman sesuai urutan — hasil PDF akan identik. '
      + 'Bila ingin 1 halaman, ringkas deskripsi atau kurangi bagian kurang penting; font tidak akan dikecilkan di bawah batas keterbacaan.';
  }


  /* ---------------- Input handler ---------------- */
  function onInput() {
    dirtySincePrint = true;
    saveState();
    clearTimeout(app.__renderTimer);
    app.__renderTimer = setTimeout(renderCV, 90);
  }

  /* ---------------- Download / print ---------------- */
  function doPrint() {
    // Pastikan ukuran halaman & layout sudah final sebelum dialog cetak.
    if (dirtySincePrint) renderCV();
    applyPageSize();
    dirtySincePrint = false;
    setTimeout(function () {
      try {
        window.print();
      } finally {
        setTimeout(fitScroll, 400);
      }
    }, 60);
  }

  function validateDownload() {
    var ok = true;
    var nameEl = $('#p_name');
    if (!nameEl.value.trim()) {
      nameEl.classList.add('invalid');
      ok = false;
    }
    if (ok) return true;
    var sec = nameEl.closest('details');
    if (sec) sec.open = true;
    nameEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
    nameEl.focus({ preventScroll: true });
    return false;
  }

  function openDirectLink() {
    var url = CFG.monetize && CFG.directUrl ? String(CFG.directUrl) : '';
    if (!/^https?:\/\//i.test(url)) return;
    try {
      var win = window.open(url, '_blank', 'noopener');
      if (win) win.opener = null;
    } catch (e) { /* pemblokir popup: abaikan, unduhan tetap jalan */ }
  }

  /* ---------------- Popup dukungan ---------------- */
  var supportResolve = null;

  function showSupport() {
    return new Promise(function (resolve) {
      var modal = $('#cvSupport');
      if (!modal || CFG.supportEnabled === false) { resolve(); return; }
      var title = $('#cvSupportTitle');
      var desc = $('#cvSupportDesc');
      var btn = $('#cvSupportButton');
      if (title && CFG.title) title.textContent = CFG.title;
      if (desc) desc.textContent = CFG.description || 'Dukungan sukarela membantu pengembangan alat ini. Anda tetap bisa mengunduh CV tanpa memberikan dukungan.';
      if (btn) {
        if (CFG.supportUrl && /^https?:\/\//i.test(CFG.supportUrl)) {
          btn.href = CFG.supportUrl;
          btn.textContent = CFG.button || 'Dukung Kami';
          btn.hidden = false;
        } else {
          btn.hidden = true;
        }
      }
      supportResolve = resolve;
      modal.hidden = false;
      modal.classList.add('is-open');
      var later = $('#cvSupportLater');
      if (later) later.focus({ preventScroll: true });
      document.documentElement.style.overflow = 'hidden';
    });
  }

  function closeSupport() {
    var modal = $('#cvSupport');
    if (modal) { modal.hidden = true; modal.classList.remove('is-open'); }
    document.documentElement.style.overflow = '';
    if (supportResolve) { var r = supportResolve; supportResolve = null; r(); }
  }

  function triggerDownload() {
    if (!validateDownload()) {
      return;
    }
    if (dirtySincePrint) renderCV();
    // Direct link dibuka sinkron di dalam klik pengguna agar tidak diblokir.
    openDirectLink();

    if (CFG.supportEnabled === false || !$('#cvSupport')) {
      doPrint();
      return;
    }
    showSupport().then(function () { doPrint(); });
  }

  function bindDownload() {
    var btn = $('#cvDownload');
    if (btn) btn.addEventListener('click', triggerDownload);

    var later = $('#cvSupportLater');
    if (later) later.addEventListener('click', closeSupport);
    var close = $('#cvSupportClose');
    if (close) close.addEventListener('click', closeSupport);
    var supportBtn = $('#cvSupportButton');
    if (supportBtn) supportBtn.addEventListener('click', function () {
      // Tetap tutup popup & lanjutkan unduhan setelah tab dukungan dibuka.
      setTimeout(closeSupport, 150);
    });
    var modal = $('#cvSupport');
    if (modal) modal.addEventListener('click', function (ev) { if (ev.target === modal) closeSupport(); });
    document.addEventListener('keydown', function (ev) {
      if (ev.key === 'Escape' && modal && modal.classList.contains('is-open')) closeSupport();
    });

    window.addEventListener('afterprint', fitScroll);
  }

  /* ---------------- Pratinjau mobile ---------------- */
  function bindPreviewToggle() {
    var toggle = $('#cvPreviewToggle');
    var preview = $('#cvPreview');
    var close = $('#cvPreviewClose');
    function set(open) {
      if (!preview) return;
      preview.classList.toggle('is-open', open);
      if (toggle) toggle.setAttribute('aria-pressed', open ? 'true' : 'false');
      document.documentElement.style.overflow = open && window.innerWidth < 1080 ? 'hidden' : '';
      if (open) setTimeout(fitScroll, 80);
    }
    if (toggle) toggle.addEventListener('click', function () {
      set(!preview.classList.contains('is-open'));
    });
    if (close) close.addEventListener('click', function () { set(false); });
  }

  /* ---------------- Zoom pratinjau ---------------- */
  function bindZoom() {
    var btns = $$('[data-zoom]');
    if (!btns.length) return;
    btns.forEach(function (btn) {
      btn.addEventListener('click', function () {
        zoomMode = btn.getAttribute('data-zoom') === 'full' ? 'full' : 'fit';
        btns.forEach(function (b) {
          b.setAttribute('aria-pressed', b === btn ? 'true' : 'false');
        });
        fitScroll();
      });
    });
  }

  /* ---------------- Reset ---------------- */
  function bindReset() {
    var btn = $('#cvReset');
    if (!btn) return;
    btn.addEventListener('click', function () {
      if (!window.confirm('Hapus semua data CV yang tersimpan di peramban ini? Tindakan ini tidak bisa dibatalkan.')) return;
      state = blank();
      photo = '';
      try { localStorage.removeItem(STORE); localStorage.removeItem(PHOTO_KEY); } catch (e) { /* diamkan */ }
      var preview = $('#cvPhotoPreview');
      if (preview) { preview.style.backgroundImage = ''; preview.classList.remove('has-photo'); }
      hydrate();
      renderCV();
      if ($('#cvSaveState')) $('#cvSaveState').textContent = 'Data baru — tersimpan otomatis saat Anda mengetik';
    });
  }

  /* ---------------- Preset / kertas ---------------- */
  function bindPresets() {
    var tpl = $('#cvTpl');
    var paper = $('#cvPaperSize');
    var custom = $('#cvCustomSize');
    var pw = $('#cvPaperW');
    var ph = $('#cvPaperH');

    if (tpl) tpl.addEventListener('change', function () {
      state.template = tpl.value;
      // Bagian foto hanya relevan pada desain yang mendukungnya.
      var photoSec = app.querySelector('[data-photo-section]');
      if (photoSec) photoSec.hidden = !photoSupported(state.template);
      saveState();
      renderCV();
    });
    if (paper) paper.addEventListener('change', function () {
      state.paper = paper.value;
      if (custom) custom.hidden = paper.value !== 'custom';
      saveState();
      renderCV();
    });
    [pw, ph].forEach(function (el) {
      if (!el) return;
      el.addEventListener('input', function () {
        state.paperW = clamp(parseFloat(pw.value) || 210, 105, 500);
        state.paperH = clamp(parseFloat(ph.value) || 297, 148, 600);
        saveState();
        clearTimeout(app.__sizeTimer);
        app.__sizeTimer = setTimeout(renderCV, 160);
      });
    });
    window.addEventListener('resize', function () { fitScroll(); });
  }

  /* ---------------- Hydrate dari state ---------------- */
  function hydrate() {
    var tpl = $('#cvTpl');
    if (tpl) tpl.value = state.template;
    var paper = $('#cvPaperSize');
    if (paper) paper.value = state.paper;
    var custom = $('#cvCustomSize');
    if (custom) custom.hidden = state.paper !== 'custom';
    var pw = $('#cvPaperW');
    var ph = $('#cvPaperH');
    if (pw) pw.value = String(Math.round(state.paperW));
    if (ph) ph.value = String(Math.round(state.paperH));
    var photoSec = app.querySelector('[data-photo-section]');
    if (photoSec) photoSec.hidden = !photoSupported(state.template);

    $$('[data-bind]').forEach(function (el) {
      var key = el.getAttribute('data-bind');
      el.value = state[key] || '';
      el.classList.remove('invalid');
      var counter = $('[data-count="' + key + '"]');
      if (counter) counter.textContent = String((state[key] || '').length);
    });
    Object.keys(LIST_DEFS).forEach(renderList);

    var preview = $('#cvPhotoPreview');
    if (preview) {
      if (photo) { preview.style.backgroundImage = 'url("' + photo + '")'; preview.classList.add('has-photo'); }
      else { preview.style.backgroundImage = ''; preview.classList.remove('has-photo'); }
    }
  }

  /* ---------------- Init ---------------- */
  function init() {
    loadState();
    buildTemplateSelect();
    bindSimple();
    bindLists();
    bindPhoto();
    bindPresets();
    bindDownload();
    bindPreviewToggle();
    bindZoom();
    bindReset();
    hydrate();
    renderCV();

    // Layout ulang setelah font siap (metrik teks berubah).
    if (document.fonts && document.fonts.ready) {
      document.fonts.ready.then(function () {
        renderCV();
        setTimeout(fitScroll, 60);
      });
    }
    window.addEventListener('beforeunload', function () {
      try { localStorage.setItem(STORE, JSON.stringify(state)); } catch (e) { /* diamkan */ }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
