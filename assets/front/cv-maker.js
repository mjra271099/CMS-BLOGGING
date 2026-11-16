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
    modern: { label: 'Desain 1 — Modern 2 Kolom + Foto', photo: true },
    colored: { label: 'Desain 2 — Klasik Serif', photo: false },
    minimal: { label: 'Desain 3 — Minimalis + Foto', photo: true },
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
          base[k] = raw[k].filter(function (row) { return row && typeof row === 'object'; }).slice(0, 20).map(function (row) {
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
    var paper = $('#cvPaper');
    var doc = $('#cvDoc');
    if (paper) { paper.style.setProperty('--paper-w', w + 'px'); paper.style.setProperty('--paper-h', h + 'px'); }
    if (doc) { doc.style.setProperty('--paper-w', w + 'px'); doc.style.setProperty('--paper-h', h + 'px'); }
    // paperCss dipanggil di setiap render; jadwalkan penyesuaian skala di sini
    // agar urutan dengan runFit tidak saling menimpa.
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

  function renderCV() {
    var doc = $('#cvDoc');
    if (!doc) return;
    var tpl = TEMPLATES[state.template] ? state.template : 'modern';
    var s = sections();
    var name = String(state.name || '').trim() || 'Nama Anda';
    var role = String(state.role || '').trim();
    var html = '';

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
      html = '<div class="tpl-modern">'
        + '<aside class="side">'
        + (photo ? '<div class="photo"' + photoBox() + ' role="img" aria-label="Foto profil"></div>' : '<div class="photo" role="img" aria-label="Foto profil" style="display:grid;place-items:center;background:rgba(255,255,255,.1);font-weight:800;font-size:calc(var(--fs)*2)">' + esc(initials(name)) + '</div>')
        + '<div><h1 class="name">' + esc(name) + '</h1>' + (role ? '<p class="role">' + esc(role) + '</p>' : '') + '</div>'
        + '<div>' + renderSection('blank', { title: 'Kontak', html: headContacts('') }) + '</div>'
        + side
        + '</aside>'
        + '<div class="main">'
        + '<div><p class="role" style="margin-bottom:6px">' + esc([state.city, state.phone, state.email].filter(Boolean).join(' · ') || '') + '</p></div>'
        + main
        + '</div></div>';
    } else if (tpl === 'colored') {
      html = '<div class="tpl-colored">'
        + '<header class="head"><h1 class="name">' + esc(name) + '</h1>' + (role ? '<p class="role">' + esc(role) + '</p>' : '') + headContacts() + '</header>'
        + ['summary', 'experience', 'education', 'skills', 'projects', 'certs', 'awards', 'languages', 'extra'].map(function (k) { return renderSection(k, s[k]); }).join('')
        + '</div>';
    } else if (tpl === 'minimal') {
      html = '<div class="tpl-minimal">'
        + '<header class="head">'
        + (photo ? '<div class="photo"' + photoBox() + ' role="img" aria-label="Foto profil"></div>' : '')
        + '<div class="head-text"><h1 class="name">' + esc(name) + '</h1>' + (role ? '<p class="role">' + esc(role) + '</p>' : '') + headContacts() + '</div>'
        + '</header>'
        + ['summary', 'experience', 'education', 'skills', 'projects', 'certs', 'awards', 'languages', 'extra'].map(function (k) { return renderSection(k, s[k]); }).join('')
        + '</div>';
    } else if (tpl === 'ats') {
      html = '<div class="tpl-ats">'
        + '<header><h1 class="name">' + esc(name) + '</h1>' + (role ? '<p class="role">' + esc(role) + '</p>' : '') + headContacts() + '</header>'
        + ['summary', 'skills', 'experience', 'education', 'projects', 'certs', 'awards', 'languages', 'extra'].map(function (k) { return renderSection(k, s[k]); }).join('')
        + '</div>';
    } else {
      // timeline
      var contactGrid = [state.email, state.phone, state.city, state.link].filter(Boolean).map(function (v) { return '<span>' + esc(v) + '</span>'; }).join('');
      var left = ['summary', 'experience', 'projects'];
      var right = ['education', 'skills', 'certs', 'awards', 'languages', 'extra'];
      html = '<div class="tpl-timeline">'
        + '<header class="head"><div><h1 class="name">' + esc(name) + '</h1>' + (role ? '<p class="role">' + esc(role) + '</p>' : '') + '</div>'
        + (contactGrid ? '<div class="head-contact">' + contactGrid + '</div>' : '') + '</header>'
        + left.map(function (k) {
          if (!s[k].html) return '';
          var inner = /experience|projects/.test(k) ? '<div class="tl">' + s[k].html + '</div>' : s[k].html;
          return '<section data-section="' + k + '">' + secTitle(s[k].title) + inner + '</section>';
        }).join('')
        + '<div class="cols2">'
        + right.map(function (k) {
          if (!s[k].html) return '';
          var inner = 'education'.indexOf(k) === 0 ? '<div class="tl">' + s[k].html + '</div>' : s[k].html;
          return '<section data-section="' + k + '">' + secTitle(s[k].title) + inner + '</section>';
        }).join('')
        + '</div></div>';
    }

    doc.className = 'cv-doc tpl-' + tpl;
    doc.style.setProperty('--accent', CFG.accent || '#0f172a');
    doc.innerHTML = html;

    // Sinkronkan atribut ukuran kertas untuk CSS & cetak.
    var size = paperCss();
    document.documentElement.style.setProperty('--cv-print-w', size.w + 'px');
    // Perbarui <style> @page agar PDF memakai ukuran kertas yang sama.
    applyPageSize();
    scheduleFit();
  }

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

  /* ---------------- Intelligent layout / auto-fit ---------------- */
  var fitState = { fs: 1, gap: 1, pad: 1, overflow: false };

  function scheduleFit() {
    clearTimeout(fitTimer);
    fitTimer = setTimeout(runFit, 60);
  }

  function runFit() {
    var doc = $('#cvDoc');
    if (!doc) return;
    var box = paperCss();
    var baseFs = 10.4 * (parseFloat(CFG.fontScale) > 0 ? parseFloat(CFG.fontScale) : 1);
    var minFs = parseFloat(CFG.minFont) > 0 ? parseFloat(CFG.minFont) : 9.5;
    var maxFs = Math.max(baseFs, minFs);

    // Mulai dari keadaan longgar lalu rapatkan bertahap.
    var steps = [
      { fs: maxFs * 1.04, gap: 1.05, pad: 1.0 },
      { fs: maxFs, gap: 1.0, pad: 1.0 },
      { fs: maxFs * 0.96, gap: 0.92, pad: 0.96 },
      { fs: maxFs * 0.92, gap: 0.84, pad: 0.92 },
      { fs: maxFs * 0.88, gap: 0.76, pad: 0.88 },
      { fs: Math.max(minFs, maxFs * 0.84), gap: 0.68, pad: 0.84 },
      { fs: Math.max(minFs, maxFs * 0.80), gap: 0.6, pad: 0.8 },
      { fs: minFs, gap: 0.54, pad: 0.76 },
      { fs: minFs, gap: 0.48, pad: 0.7 },
      { fs: minFs, gap: 0.42, pad: 0.64 }
    ];
    var chosen = steps[steps.length - 1];
    var fits = false;
    var measured = 0;

    for (var i = 0; i < steps.length; i++) {
      measured = applyStep(doc, steps[i]);
      // Toleransi 2px untuk pembulatan subpiksel.
      if (measured <= box.h + 2) { chosen = steps[i]; fits = true; break; }
    }
    if (!fits) {
      // Rapikan ke tahap paling ketat (spacing minimum) sebelum menyerah.
      chosen = steps[steps.length - 1];
      measured = applyStep(doc, chosen);
    }
    // Koreksi akhir: nama besar bisa membuat dokumen lebih tinggi dari taksiran
    // berbasis nilai padding; ukur ulang setelah semua gaya diterapkan.
    finalMeasure(doc, box);

    fitState = { fs: chosen.fs, gap: chosen.gap, pad: chosen.pad, overflow: !fits };
    doc.classList.toggle('is-overflow', !fits);

    var pages = Math.max(1, Math.ceil((doc.scrollHeight - 2) / box.h));
    fits = doc.scrollHeight <= box.h + 2;
    var count = $('#cvPageCount');
    if (count) count.textContent = pages + ' halaman';

    var notice = $('#cvFitNotice');
    if (notice) {
      if (fits) {
        notice.hidden = true;
        notice.textContent = '';
        notice.className = 'cv-notice';
      } else {
        var excess = Math.round((doc.scrollHeight - box.h) / box.h * 100);
        notice.hidden = false;
        notice.className = 'cv-notice error';
        notice.innerHTML = '<strong>Konten melebihi satu halaman.</strong> Layout sudah dirapatkan otomatis sampai batas keterbacaan (font ' +
          esc(chosen.fs.toFixed(1)) + 'px), tetapi masih kelebihan ±' + Math.max(1, excess) + '%. Ringkas deskripsi pengalaman atau kurangi bagian yang kurang penting — CV yang padat tetap lebih baik daripada font terlalu kecil. ' +
          'Untuk hasil terbaik, cetak dan pilih <em>Scale: Default / 100%</em>.';
      }
    }
    fitScroll();
  }

  /**
   * Ukur tinggi dokumen setelah step diterapkan. scrollHeight pada elemen blok
   * menjumlahkan margin anak terakhir, jadi padding bawah container ikut
   * dihitung dua kali; kita pakai tinggi konten nyata untuk keputusan.
   */
  function finalMeasure(doc, box) {
    doc.classList.toggle('is-overflow', false);
  }

  function applyStep(doc, step) {
    doc.style.setProperty('--fs', step.fs.toFixed(2) + 'px');
    doc.style.setProperty('--gap', (6.2 * step.gap).toFixed(2) + 'px');
    doc.style.setProperty('--pad', (step.pad * 34).toFixed(2) + 'px');
    doc.style.setProperty('--pad-x', (step.pad * 46).toFixed(2) + 'px');
    doc.style.setProperty('--lh', (1.36 + 0.05 * step.gap).toFixed(2));

    // Nama: ukur lebar, kecilkan hingga muat dalam satu baris bila perlu.
    var center = false;
    var tpl = state.template;
    var nameEl = doc.querySelector('.name');
    if (nameEl) {
      var scaleMap = { modern: 3.05, colored: 2.45, minimal: 2.55, ats: 2.1, timeline: 2.4 };
      var scale = scaleMap[tpl] || 2.5;
      nameEl.style.fontSize = '';
      nameEl.style.whiteSpace = 'nowrap';
      var natural = parseFloat(getComputedStyle(nameEl).fontSize) || (step.fs * scale);
      var avail = nameEl.parentElement ? nameEl.parentElement.clientWidth : nameEl.clientWidth;
      var w = measureWidth(nameEl);
      var floorSize = step.fs * (tpl === 'modern' ? 1.85 : 1.6);
      if (avail > 0 && w > avail + 0.5) {
        var fitted = Math.max(floorSize, natural * (avail / w) * 0.98);
        nameEl.style.fontSize = fitted.toFixed(2) + 'px';
        var w2 = measureWidth(nameEl);
        if (w2 > avail + 0.5) {
          fitted = Math.max(floorSize, fitted * (avail / w2) * 0.99);
          nameEl.style.fontSize = fitted.toFixed(2) + 'px';
        }
        // Jangan mengecilkan nama sampai tak terbaca: biarkan turun baris.
        if (measureWidth(nameEl) > avail + 1) nameEl.style.whiteSpace = 'normal';
      }
      center = tpl === 'colored';
      nameEl.style.textAlign = center ? 'center' : '';
    }

    var roleEl = doc.querySelector('.tpl-modern .role, .tpl-minimal .role');
    if (roleEl) roleEl.style.whiteSpace = 'nowrap';

    return measureContent(doc);
  }

  /**
   * Tinggi isi sesungguhnya. Dokumen ber-padding vertikal dan margin anak
   * terakhir tidak terlipat di container ber-overflow, jadi scrollHeight
   * selalu dianggap melebihi kertas walau isi masih muat. Karena itu dipakai
   * tinggi nyata elemen isi (template) ditambah padding kecil.
   */
  function measureContent(doc) {
    var first = doc.firstElementChild;
    if (!first) {
      return 0;
    }
    var cs = getComputedStyle(first);
    // scrollHeight template menghitung padding bawahnya sendiri (aman karena
    // template tidak auto-height); dipakai bila lebih besar.
    var inner = first.scrollHeight;
    var box = first.getBoundingClientRect();
    return Math.max(inner, Math.ceil(box.height));
  }

  function measureWidth(el) {
    var cs = getComputedStyle(el);
    var clone = el.cloneNode(true);
    clone.style.cssText = [
      'position:absolute', 'visibility:hidden', 'left:-9999px', 'top:0',
      'width:auto', 'max-width:none', 'white-space:nowrap', 'display:inline-block',
      'font-size:' + cs.fontSize, 'font-family:' + cs.fontFamily, 'font-weight:' + cs.fontWeight,
      'font-style:' + cs.fontStyle, 'letter-spacing:' + cs.letterSpacing,
      'text-transform:' + cs.textTransform
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

  /** Perkecil kertas agar muat lebar layar (pratinjau), tanpa memengaruhi cetak. */
  function runFitScroll() {
    var stage = $('#cvStage');
    var paper = $('#cvPaper');
    if (!stage || !paper) return;
    // Ponsel/iPad memakai viewport CSS 980px; pakai lebar layar nyata agar
    // kertas benar-benar muat tanpa scroll horizontal. clientWidth stage
    // dipakai bila lebih kecil (desktop, kolom pratinjau).
    var viewWidth = document.documentElement.clientWidth || window.innerWidth;
    var stageW = Math.min(stage.clientWidth || viewWidth, viewWidth);
    var available = stageW - (window.innerWidth >= 1080 ? 30 : 16);
    if (available < 80) return;
    var size = paperSize();
    // Lebar kertas dalam px diambil dari CSS (bisa berbeda pembulatan).
    var paperPx = parseFloat(getComputedStyle(paper).getPropertyValue('--paper-w')) || size.w;
    var scale = Math.min(1, available / Math.max(1, paperPx));
    if (scale < 0.999) {
      // Skala lewat CSS variable: transform tidak mengubah layout, jadi beri
      // ruang setinggi hasil skala dan hindari scroll horizontal.
      paper.style.setProperty('--paper-scale', scale.toFixed(4));
      stage.style.height = Math.ceil(size.h * scale + 26) + 'px';
      stage.style.alignItems = 'start';
    } else {
      paper.style.setProperty('--paper-scale', '1');
      stage.style.removeProperty('height');
      stage.style.removeProperty('align-items');
    }
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
    applyPageSize();
    runFit();
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
