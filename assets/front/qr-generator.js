
/* ================================================================== */
/*  Util                                                              */
/* ================================================================== */
const $  = id => document.getElementById(id);
const $$ = sel => Array.from(document.querySelectorAll(sel));

/* Akar halaman + Direct Link iklan (opsional) dari Pengaturan CMS. */
const ROOT = document.querySelector('.qr-app');
const DIRECT_URL = ROOT ? (ROOT.dataset.directUrl || '').trim() : '';


function isHttpUrl(v){
  if(!v) return false;
  try{
    const u = new URL(v);
    return u.protocol === 'http:' || u.protocol === 'https:';
  }catch(e){ return false; }
}

const escWifi  = s => String(s).replace(/([\\;,:"])/g, '\\$1');
const escVCard = s => String(s)
  .replace(/\\/g,'\\\\')
  .replace(/\r/g,'')
  .replace(/\n/g,'\\n')
  .replace(/,/g,'\\,')
  .replace(/;/g,'\\;');

/* ================================================================== */
/*  SVG logo → PNG data URL                                           */
/* ================================================================== */
function svgToPngDataURL(svg, size){
  return new Promise(resolve => {
    const img = new Image();
    img.onload = () => {
      try{
        const c = document.createElement('canvas');
        c.width = c.height = size;
        const ctx = c.getContext('2d');
        ctx.drawImage(img, 0, 0, size, size);
        resolve(c.toDataURL('image/png'));
      }catch(e){ resolve(null); }
    };
    img.onerror = () => resolve(null);
    img.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg);
  });
}

/* ================================================================== */
/*  Katalog logo                                                      */
/* ================================================================== */
const M = (bg, fill, path) => `<svg xmlns="http://www.w3.org/2000/svg" width="256" height="256" viewBox="0 0 40 40">
    <circle cx="20" cy="20" r="19.5" fill="${bg}"/>
    <circle cx="20" cy="20" r="13" fill="${fill}"/>
    <g transform="translate(8,8)"><path fill="#ffffff" d="${path}"/></g>
  </svg>`;

const LOGO_SVGS = {
  play: bg => `<svg xmlns="http://www.w3.org/2000/svg" width="256" height="256" viewBox="0 0 40 40">
      <circle cx="20" cy="20" r="19.5" fill="${bg}"/>
      <rect x="6.5" y="11" width="27" height="18" rx="4.5" fill="#ef4444"/>
      <path d="M17.2 15.6 25.8 20 17.2 24.4Z" fill="#ffffff"/>
    </svg>`,
  whatsapp: bg => `<svg xmlns="http://www.w3.org/2000/svg" width="256" height="256" viewBox="0 0 40 40">
      <circle cx="20" cy="20" r="19.5" fill="${bg}"/>
      <path fill="#25D366" d="M20 4.2A15.8 15.8 0 0 0 6.4 27.9L4.2 36l8.3-2.1A15.8 15.8 0 1 0 20 4.2Z"/>
      <path fill="#ffffff" d="M27.9 23.5c-.4-.2-2.4-1.2-2.8-1.3-.4-.1-.7-.2-.9.2-.3.4-1.1 1.3-1.3 1.5-.2.3-.5.3-.9.1-.4-.2-1.7-.6-3.2-2-1.2-1-2-2.3-2.2-2.7-.2-.4 0-.6.2-.8l.6-.7c.2-.2.3-.4.4-.6.1-.3 0-.5 0-.7-.1-.2-.9-2.1-1.2-2.9-.3-.7-.6-.6-.9-.6h-.8c-.3 0-.7.1-1 .5-.4.4-1.4 1.4-1.4 3.3s1.4 3.8 1.6 4.1c.2.3 2.8 4.3 6.8 6 .9.4 1.6.6 2.1.7.9.3 1.7.2 2.3.1.7-.1 2.4-1 2.7-1.9.3-.9.3-1.7.2-1.9-.1-.2-.4-.3-.8-.5Z"/>
    </svg>`,
  globe: bg => `<svg xmlns="http://www.w3.org/2000/svg" width="256" height="256" viewBox="0 0 40 40">
      <circle cx="20" cy="20" r="19.5" fill="${bg}"/>
      <circle cx="20" cy="20" r="12.5" fill="#2563eb"/>
      <g fill="none" stroke="#ffffff" stroke-width="1.5">
        <circle cx="20" cy="20" r="12.5"/>
        <ellipse cx="20" cy="20" rx="5.6" ry="12.5"/>
        <path d="M7.5 20h25"/>
        <path d="M9.2 14.6h21.6M9.2 25.4h21.6"/>
      </g>
    </svg>`,
  instagram: bg => `<svg xmlns="http://www.w3.org/2000/svg" width="256" height="256" viewBox="0 0 40 40">
      <defs><linearGradient id="ig" x1="0" y1="1" x2="1" y2="0">
        <stop offset="0" stop-color="#feda75"/>
        <stop offset="0.5" stop-color="#d62976"/>
        <stop offset="1" stop-color="#4f5bd5"/>
      </linearGradient></defs>
      <circle cx="20" cy="20" r="19.5" fill="${bg}"/>
      <rect x="7.5" y="7.5" width="25" height="25" rx="7.5" fill="url(#ig)"/>
      <circle cx="20" cy="20" r="6.2" fill="none" stroke="#ffffff" stroke-width="1.9"/>
      <circle cx="26.6" cy="13.4" r="1.7" fill="#ffffff"/>
    </svg>`,
  shop: bg => `<svg xmlns="http://www.w3.org/2000/svg" width="256" height="256" viewBox="0 0 40 40">
      <circle cx="20" cy="20" r="19.5" fill="${bg}"/>
      <circle cx="20" cy="20" r="12.5" fill="#f59e0b"/>
      <path d="M14 17.5h12l-1.1 8.2H15.1Z" fill="#ffffff"/>
      <path d="M17.1 17.5v-1.8a2.9 2.9 0 0 1 5.8 0v1.8" fill="none"
            stroke="#ffffff" stroke-width="1.7" stroke-linecap="round"/>
    </svg>`,
  heart: bg => `<svg xmlns="http://www.w3.org/2000/svg" width="256" height="256" viewBox="0 0 40 40">
      <circle cx="20" cy="20" r="19.5" fill="${bg}"/>
      <g transform="translate(4,4) scale(1.333)">
        <path fill="#ef4444" d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/>
      </g>
    </svg>`,
  star: bg => `<svg xmlns="http://www.w3.org/2000/svg" width="256" height="256" viewBox="0 0 40 40">
      <circle cx="20" cy="20" r="19.5" fill="${bg}"/>
      <g transform="translate(4,4) scale(1.333)">
        <path fill="#f59e0b" d="M12 2l2.9 6.3 6.9.8-5.1 4.7 1.4 6.8L12 17.2 5.9 20.6l1.4-6.8L2.2 9.1l6.9-.8z"/>
      </g>
    </svg>`,
  mail: bg => M(bg, '#2563eb',
    'M20 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z'),
  phone: bg => M(bg, '#16a34a',
    'M6.62 10.79c1.44 2.83 3.76 5.14 6.59 6.59l2.2-2.2c.27-.27.67-.36 1.02-.24 1.12.37 2.33.57 3.57.57.55 0 1 .45 1 1V20c0 .55-.45 1-1 1-9.39 0-17-7.61-17-17 0-.55.45-1 1-1h3.5c.55 0 1 .45 1 1 0 1.25.2 2.45.57 3.57.11.35.03.74-.25 1.02l-2.2 2.2z'),
  wifi: bg => M(bg, '#0ea5e9',
    'M1 9l2 2c4.97-4.97 13.03-4.97 18 0l2-2C16.93 2.93 7.08 2.93 1 9zm8 8l3 3 3-3c-1.65-1.66-4.34-1.66-6 0zm-4-4l2 2c2.76-2.76 7.24-2.76 10 0l2-2C15.14 9.14 8.87 9.14 5 13z'),
  pin: bg => M(bg, '#ef4444',
    'M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z')
};

const AUTO_LOGO = {
  url:'play', wa:'whatsapp', text:'star', email:'mail', tel:'phone',
  sms:'whatsapp', wifi:'wifi', vcard:'globe', loc:'pin'
};

const logoCache = new Map();

async function getLogoDataURL(key, bg, customSrc){
  if(!key || key === 'none') return null;
  if(key === 'custom') return customSrc || null;
  const builder = LOGO_SVGS[key];
  if(!builder) return null;
  const cacheKey = key + '|' + bg;
  if(logoCache.has(cacheKey)) return logoCache.get(cacheKey);
  const url = await svgToPngDataURL(builder(bg), 512);
  logoCache.set(cacheKey, url);
  return url;
}

/* ================================================================== */
/*  Preset tema                                                       */
/* ================================================================== */
const PRESETS = [
  { name:'Biru',   dark:'#2563eb', light:'#ffffff', c3:'#60a5fa', grad:false, dots:'rounded',        sq:'extra-rounded', dot:'dot' },
  { name:'Klasik', dark:'#111827', light:'#ffffff', c3:'#475569', grad:false, dots:'square',         sq:'square',        dot:'square' },
  { name:'WA',     dark:'#128c7e', light:'#ffffff', c3:'#25d366', grad:false, dots:'dots',           sq:'dot',           dot:'dot' },
  { name:'Ungu',   dark:'#7c3aed', light:'#ffffff', c3:'#db2777', grad:true,  dots:'classy-rounded', sq:'extra-rounded', dot:'dot' },
  { name:'Sunset', dark:'#f97316', light:'#fff7ed', c3:'#ef4444', grad:true,  dots:'rounded',        sq:'extra-rounded', dot:'dot' },
  { name:'Gelap',  dark:'#f8fafc', light:'#0f172a', c3:'#93c5fd', grad:false, dots:'classy',         sq:'extra-rounded', dot:'dot' },
  { name:'Pink',   dark:'#db2777', light:'#fdf2f8', c3:'#f472b6', grad:false, dots:'extra-rounded',  sq:'extra-rounded', dot:'dot' },
  { name:'Tosca',  dark:'#0d9488', light:'#ecfeff', c3:'#22d3ee', grad:false, dots:'dots',           sq:'dot',           dot:'dot' }
];

/* ================================================================== */
/*  Tipe konten                                                       */
/* ================================================================== */
const TYPES = [
  { id:'url',   label:'🌐 Website'  },
  { id:'wa',    label:'🟢 WhatsApp' },
  { id:'text',  label:'📝 Teks'     },
  { id:'email', label:'✉️ Email'    },
  { id:'tel',   label:'📞 Telepon'  },
  { id:'sms',   label:'💬 SMS'      },
  { id:'wifi',  label:'📶 WiFi'     },
  { id:'vcard', label:'👤 vCard'    },
  { id:'loc',   label:'📍 Lokasi'   }
];

/* ================================================================== */
/*  State                                                             */
/* ================================================================== */
const STORAGE_KEY = 'qr-generator-state-v1';
const state = { type: 'url', customLogo: null, cfg: null };
let renderSeq = 0;
let timer = null;
let mainQR = null;
let saveTimer = null;
let flashTimer = null;

/* ================================================================== */
/*  Toast + badge                                                     */
/* ================================================================== */
let toastTimer = null;
function toast(message, type){
  const el = $('toast');
  el.textContent = message;
  el.className = 'toast show ' + (type || '');
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => el.classList.remove('show'), 2600);
}

function setBadge(text, kind){
  const el = $('statusBadge');
  el.textContent = text;
  el.className = 'badge' + (kind ? ' ' + kind : '');
}

function setStatus(text, kind){
  const el = $('status');
  el.textContent = text || '';
  el.className = 'status' + (kind ? ' ' + kind : '');
}

/* ================================================================== */
/*  INPUT ENHANCEMENT (clear + paste buttons)                         */
/* ================================================================== */
function enhanceInput(input, opts){
  if(!input || input.dataset.enhanced) return;
  input.dataset.enhanced = '1';

  const wrap = document.createElement('div');
  wrap.className = 'input-wrap' + (opts && opts.paste ? ' has-paste' : '');
  input.parentNode.insertBefore(wrap, input);
  wrap.appendChild(input);

  // Clear button
  const clearBtn = document.createElement('button');
  clearBtn.type = 'button';
  clearBtn.className = 'input-btn input-clear';
  clearBtn.setAttribute('aria-label', 'Hapus isi');
  clearBtn.tabIndex = -1;
  clearBtn.innerHTML = '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>';
  clearBtn.addEventListener('click', () => {
    input.value = '';
    input.dispatchEvent(new Event('input', { bubbles: true }));
    input.dispatchEvent(new Event('change', { bubbles: true }));
    input.focus();
  });
  wrap.appendChild(clearBtn);

  // Paste button (opt-in)
  if(opts && opts.paste){
    const pasteBtn = document.createElement('button');
    pasteBtn.type = 'button';
    pasteBtn.className = 'input-btn input-paste';
    pasteBtn.setAttribute('aria-label', 'Tempel dari papan klip');
    pasteBtn.tabIndex = -1;
    pasteBtn.innerHTML = '<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1"/></svg>';
    pasteBtn.addEventListener('click', async () => {
      try{
        const text = await navigator.clipboard.readText();
        if(text){
          input.value = text.trim();
          input.dispatchEvent(new Event('input', { bubbles: true }));
        }
      }catch(e){
        toast('Akses clipboard ditolak — tempel manual (Ctrl+V)', 'error');
      }
    });
    wrap.appendChild(pasteBtn);
  }

  const updateClear = () => { clearBtn.hidden = !input.value; };
  input.addEventListener('input', updateClear);
  updateClear();
}

function enhanceAllInputs(){
  const ids = [
    'f-url','f-wa','f-wa-num','f-text','f-email-to','f-email-sub','f-email-body',
    'f-tel','f-sms-num','f-sms-msg','f-wifi-ssid','f-wifi-pass',
    'f-vc-name','f-vc-tel','f-vc-email','f-vc-org','f-vc-title','f-vc-url','f-vc-addr',
    'f-lat','f-lng'
  ];
  ids.forEach(id => {
    const input = $(id);
    if(!input) return;
    enhanceInput(input, { paste: id === 'f-url' || id === 'f-wa-num' || id === 'f-wa' });
  });
}

/* ================================================================== */
/*  SMART PASTE (deteksi jenis + auto-fill)                           */
/* ================================================================== */
function detectPasteType(text){
  const t = String(text || '').trim();
  if(!t) return null;
  if(/^WIFI:/i.test(t)) return 'wifi';
  if(/^BEGIN:VCARD/i.test(t)) return 'vcard';
  if(/^geo:/i.test(t)) return 'loc';
  if(/^tel:/i.test(t)) return 'tel';
  if(/^sms:/i.test(t)) return 'sms';
  if(/^mailto:/i.test(t)) return 'email';
  if(/wa\.me\//i.test(t) || /api\.whatsapp\.com/i.test(t)) return 'wa';
  if(/^https?:\/\//i.test(t)) return 'url';
  if(/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(t)) return 'email';
  if(/^\+?[\d\s\-()]{7,}$/.test(t) && /\d{7,}/.test(t.replace(/\D/g, ''))) return 'tel';
  if(/^[\w-]+(\.[\w-]+)+/.test(t)) return 'url';
  return null;
}

function parseVCard(text){
  const fields = {};
  let inVcard = false;
  text.split(/\r?\n/).forEach(line => {
    if(/^BEGIN:VCARD/i.test(line)){ inVcard = true; return; }
    if(/^END:VCARD/i.test(line)){ inVcard = false; return; }
    if(!inVcard) return;
    const m = line.match(/^([A-Z0-9-]+)(?:;[^:]*)?:(.*)$/i);
    if(!m) return;
    const k = m[1].toUpperCase();
    let v = m[2];
    if(v.includes('\\')) v = v.replace(/\\(.)/g, '$1');
    if(!fields[k]) fields[k] = v;
  });
  return fields;
}

function parseWifi(text){
  const m = text.match(/^WIFI:(.*)$/is);
  if(!m) return null;
  const params = {};
  m[1].split(';').forEach(part => {
    if(!part) return;
    const idx = part.indexOf(':');
    if(idx === -1) return;
    const k = part.slice(0, idx).toUpperCase();
    const v = part.slice(idx + 1).replace(/\\(.)/g, '$1');
    if(k) params[k] = v;
  });
  return params;
}

function fillSmartPaste(type, text){
  let filled = true;
  switch(type){
    case 'url':
      if(!/^https?:\/\//i.test(text)) text = 'https://' + text.replace(/^\/+/, '');
      $('f-url').value = text;
      break;
    case 'wa':
      $('f-wa').value = text;
      break;
    case 'email':
      $('f-email-to').value = text.replace(/^mailto:/i, '').split('?')[0];
      break;
    case 'tel': {
      const n = text.replace(/^tel:/i, '');
      $('f-tel').value = n;
      break;
    }
    case 'sms': {
      const n = text.replace(/^sms:/i, '');
      const [num, query] = n.split('?');
      $('f-sms-num').value = num;
      if(query){
        const p = new URLSearchParams(query);
        if(p.get('body')) $('f-sms-msg').value = p.get('body');
      }
      break;
    }
    case 'wifi': {
      const params = parseWifi(text);
      if(!params){ filled = false; break; }
      if(params.S) $('f-wifi-ssid').value = params.S;
      if(params.P) $('f-wifi-pass').value = params.P;
      if(params.T) $('f-wifi-enc').value = params.T;
      if(String(params.H).toLowerCase() === 'true') $('f-wifi-hidden').checked = true;
      break;
    }
    case 'vcard': {
      const v = parseVCard(text);
      if(v.FN) $('f-vc-name').value = v.FN;
      if(v.TEL) $('f-vc-tel').value = v.TEL.replace(/[^\d+\s\-()]/g, '');
      if(v.EMAIL) $('f-vc-email').value = v.EMAIL;
      if(v.ORG) $('f-vc-org').value = v.ORG;
      if(v.TITLE) $('f-vc-title').value = v.TITLE;
      if(v.URL) $('f-vc-url').value = v.URL;
      if(v.ADR) $('f-vc-addr').value = v.ADR.replace(/^;+|;+$/g, '').replace(/;+/g, ', ');
      break;
    }
    case 'loc': {
      const parts = text.replace(/^geo:/i, '').split(',');
      if(parts.length >= 2){
        $('f-lat').value = parts[0].trim();
        $('f-lng').value = parts[1].trim();
      } else {
        filled = false;
      }
      break;
    }
    default:
      filled = false;
  }
  return filled;
}

async function smartPaste(){
  try{
    if(!navigator.clipboard || !navigator.clipboard.readText){
      toast('Clipboard tidak didukung — tempel manual (Ctrl+V)', 'error');
      return;
    }
    const text = (await navigator.clipboard.readText()).trim();
    if(!text){
      toast('Clipboard kosong', 'error');
      return;
    }
    const type = detectPasteType(text);
    if(!type){
      $('f-text').value = text;
      selectType('text');
      toast('Dimasukkan sebagai teks', 'success');
      return;
    }
    const ok = fillSmartPaste(type, text);
    if(!ok){
      toast('Format tidak dikenali', 'error');
      return;
    }
    selectType(type);
    const label = { url:'URL', wa:'WhatsApp', email:'Email', tel:'Telepon', sms:'SMS', wifi:'WiFi', vcard:'vCard', loc:'Lokasi', text:'Teks' }[type] || type;
    toast('✓ Terdeteksi: ' + label, 'success');
  }catch(e){
    toast('Tidak bisa akses clipboard — tempel manual (Ctrl+V)', 'error');
  }
}

/* ================================================================== */
/*  Auto https:// untuk domain                                        */
/* ================================================================== */
function autoProtocol(input){
  const v = input.value.trim();
  if(!v) return;
  if(/^[a-z][a-z0-9+.-]*:/i.test(v)) return; // sudah ada skema
  if(/^[\w-]+(\.[\w-]+)+/.test(v)){
    input.value = 'https://' + v;
    input.dispatchEvent(new Event('input', { bubbles: true }));
    input.dispatchEvent(new Event('change', { bubbles: true }));
  }
}

/* ================================================================== */
/*  Persistence                                                        */
/* ================================================================== */
function collectFormData(){
  const ids = [
    'f-url','f-wa','f-wa-num','f-wa-msg','f-text','f-email-to','f-email-sub','f-email-body',
    'f-tel','f-sms-num','f-sms-msg','f-wifi-ssid','f-wifi-pass','f-wifi-enc',
    'f-vc-name','f-vc-tel','f-vc-email','f-vc-org','f-vc-title','f-vc-url','f-vc-addr',
    'f-lat','f-lng'
  ];
  const data = {};
  ids.forEach(id => { const el = $(id); if(el) data[id] = el.value; });
  data['f-wifi-hidden'] = $('f-wifi-hidden').checked;
  return data;
}

function applyFormData(data){
  if(!data) return;
  Object.keys(data).forEach(id => {
    if(id === 'f-wifi-hidden') return;
    const el = $(id);
    if(el) el.value = data[id];
  });
  if(typeof data['f-wifi-hidden'] === 'boolean') $('f-wifi-hidden').checked = data['f-wifi-hidden'];
}

function saveState(){
  try{
    const payload = {
      type: state.type,
      design: {
        c1: $('c1').value, c2: $('c2').value, c3: $('c3').value,
        gradOn: $('gradOn').checked,
        dotStyle: $('dotStyle').value,
        cornerSq: $('cornerSq').value,
        cornerDot: $('cornerDot').value,
        logo: $('logo').value,
        logoSize: $('logoSize').value,
        dlSize: $('dlSize').value
      },
      form: collectFormData()
    };
    localStorage.setItem(STORAGE_KEY, JSON.stringify(payload));
  }catch(e){ /* ignore quota errors */ }
}

function scheduleSave(){
  clearTimeout(saveTimer);
  saveTimer = setTimeout(saveState, 500);
}

function loadState(){
  try{
    const raw = localStorage.getItem(STORAGE_KEY);
    if(!raw) return;
    const st = JSON.parse(raw);
    if(st.type && TYPES.some(t => t.id === st.type)) state.type = st.type;
    if(st.design){
      const d = st.design;
      if(d.c1) $('c1').value = d.c1;
      if(d.c2) $('c2').value = d.c2;
      if(d.c3) $('c3').value = d.c3;
      if(typeof d.gradOn === 'boolean') $('gradOn').checked = d.gradOn;
      if(d.dotStyle) $('dotStyle').value = d.dotStyle;
      if(d.cornerSq) $('cornerSq').value = d.cornerSq;
      if(d.cornerDot) $('cornerDot').value = d.cornerDot;
      if(d.logo) $('logo').value = d.logo;
      if(d.logoSize) $('logoSize').value = d.logoSize;
      if(d.dlSize) $('dlSize').value = d.dlSize;
    }
    if(st.form) applyFormData(st.form);
  }catch(e){ /* ignore */ }
}

/* ================================================================== */
/*  Baca pengaturan desain                                            */
/* ================================================================== */
function readStyle(){
  const dark  = $('c1').value;
  const light = $('c2').value;
  const c3    = $('c3').value;
  const grad  = $('gradOn').checked;
  return {
    dark, light,
    gradient: grad ? {
      type: 'linear',
      rotation: Math.PI / 4,
      colorStops: [{ offset: 0, color: dark }, { offset: 1, color: c3 }]
    } : null,
    dots:      $('dotStyle').value,
    cornerSq:  $('cornerSq').value,
    cornerDot: $('cornerDot').value,
    logoSize:  parseInt($('logoSize').value, 10) / 100
  };
}

/* ================================================================== */
/*  Bangun data QR                                                    */
/* ================================================================== */
function buildData(){
  const v = id => ($(id) ? $(id).value.trim() : '');

  switch(state.type){
    case 'url': {
      const u = v('f-url');
      if(!u)             return { ok:false, msg:'Masukkan URL website.' };
      if(!isHttpUrl(u))  return { ok:false, msg:'URL harus diawali http:// atau https://' };
      return { ok:true, data:u };
    }
    case 'wa': {
      const u = v('f-wa');
      if(!u)             return { ok:false, msg:'Masukkan tautan WhatsApp.' };
      if(!isHttpUrl(u))  return { ok:false, msg:'Tautan harus diawali http:// atau https://' };
      return { ok:true, data:u };
    }
    case 'text': {
      const s = $('f-text').value;
      if(!s.trim()) return { ok:false, msg:'Tulis teks terlebih dahulu.' };
      return { ok:true, data:s };
    }
    case 'email': {
      const to = v('f-email-to');
      if(!to) return { ok:false, msg:'Masukkan alamat email tujuan.' };
      const sub = v('f-email-sub'), body = v('f-email-body');
      const p = [];
      if(sub)  p.push('subject=' + encodeURIComponent(sub));
      if(body) p.push('body=' + encodeURIComponent(body));
      return { ok:true, data:'mailto:' + to + (p.length ? '?' + p.join('&') : '') };
    }
    case 'tel': {
      const n = $('f-tel').value.replace(/[^\d+]/g, '');
      if(!n) return { ok:false, msg:'Masukkan nomor telepon.' };
      return { ok:true, data:'tel:' + n };
    }
    case 'sms': {
      const n = $('f-sms-num').value.replace(/[^\d+]/g, '');
      if(!n) return { ok:false, msg:'Masukkan nomor tujuan SMS.' };
      const m = v('f-sms-msg');
      return { ok:true, data:'sms:' + n + (m ? '?body=' + encodeURIComponent(m) : '') };
    }
    case 'wifi': {
      const ssid = $('f-wifi-ssid').value;
      if(!ssid) return { ok:false, msg:'Masukkan nama WiFi (SSID).' };
      const enc = $('f-wifi-enc').value;
      const pass = $('f-wifi-pass').value;
      const hidden = $('f-wifi-hidden').checked;
      let s = 'WIFI:T:' + enc + ';S:' + escWifi(ssid) + ';';
      if(enc !== 'nopass') s += 'P:' + escWifi(pass) + ';';
      if(hidden)           s += 'H:true;';
      s += ';';
      return { ok:true, data:s };
    }
    case 'vcard': {
      const name = v('f-vc-name');
      if(!name) return { ok:false, msg:'Masukkan nama lengkap.' };
      const lines = [
        'BEGIN:VCARD','VERSION:3.0',
        'FN:' + escVCard(name),
        'N:'  + escVCard(name) + ';;;;'
      ];
      const tel = v('f-vc-tel');    if(tel)   lines.push('TEL;TYPE=CELL:' + escVCard(tel));
      const mail = v('f-vc-email'); if(mail)  lines.push('EMAIL:' + escVCard(mail));
      const org = v('f-vc-org');    if(org)   lines.push('ORG:' + escVCard(org));
      const title = v('f-vc-title');if(title) lines.push('TITLE:' + escVCard(title));
      const url = v('f-vc-url');    if(url)   lines.push('URL:' + escVCard(url));
      const addr = v('f-vc-addr');  if(addr)  lines.push('ADR:;;' + escVCard(addr) + ';;;;');
      lines.push('END:VCARD');
      return { ok:true, data:lines.join('\r\n') };
    }
    case 'loc': {
      const lat = v('f-lat'), lng = v('f-lng');
      if(!lat || !lng) return { ok:false, msg:'Masukkan latitude dan longitude.' };
      if(isNaN(Number(lat)) || isNaN(Number(lng)))
        return { ok:false, msg:'Koordinat harus berupa angka.' };
      return { ok:true, data:'geo:' + lat + ',' + lng };
    }
  }
  return { ok:false, msg:'Jenis QR tidak dikenal.' };
}

/* ================================================================== */
/*  Opsi QR                                                           */
/* ================================================================== */
function makeOptions(cfg, size, type){
  const st = cfg.st;
  const scale = size / 300;
  const opts = {
    width: size, height: size,
    type: type || 'canvas',
    data: cfg.data,
    margin: Math.round(9 * scale),
    qrOptions: { errorCorrectionLevel:'H' },
    backgroundOptions: { color: st.light },
    dotsOptions: {
      type: st.dots,
      color: st.dark,
      ...(st.gradient ? { gradient: st.gradient } : {})
    },
    cornersSquareOptions: { type: st.cornerSq,  color: st.dark },
    cornersDotOptions:    { type: st.cornerDot, color: st.dark }
  };
  if(cfg.img){
    opts.image = cfg.img;
    opts.imageOptions = {
      hideBackgroundDots: true,
      imageSize: st.logoSize,
      margin: Math.round(4 * scale),
      crossOrigin: 'anonymous'
    };
  }
  return opts;
}

/* ================================================================== */
/*  Render                                                            */
/* ================================================================== */
function clearQR(msg){
  state.cfg = null;
  mainQR = null;
  $('qr').innerHTML = '<div class="ph">' + msg + '</div>';
  if($('rawData')) $('rawData').value = '';
}

function paint(cfg){
  try{
    const mainOpts = makeOptions(cfg, 420, 'canvas');
    if(!mainQR){
      $('qr').innerHTML = '';
      mainQR = new QRCodeStyling(mainOpts);
      mainQR.append($('qr'));
    }else{
      mainQR.update(mainOpts);
    }
  }catch(err){
    console.error('Paint (main) error:', err);
    $('qr').innerHTML = '<div class="ph">Gagal membuat QR.</div>';
  }

  if($('rawData')) $('rawData').value = cfg.data || '';
}

async function render(){
  const mySeq = ++renderSeq;

  if(!window.QRCodeStyling){
    clearQR('Pustaka QR belum siap…');
    setStatus('Pustaka QR belum siap.', 'err');
    setBadge('Gagal', 'err');
    return;
  }

  const st  = readStyle();
  const res = buildData();

  if(!res.ok){
    clearQR(res.msg);
    setStatus(res.msg, 'err');
    setBadge('Error', 'err');
    return;
  }

  let key = $('logo').value;
  if(key === 'auto') key = AUTO_LOGO[state.type] || 'globe';

  const img = await getLogoDataURL(key, st.light, state.customLogo);
  if(mySeq !== renderSeq) return;

  const cfg = { data: res.data, img, st };
  state.cfg = cfg;
  paint(cfg);
  setStatus('✓ QR siap dipindai', 'ok');
  setBadge('Siap', 'ok');
}

function schedule(){
  clearTimeout(timer);
  timer = setTimeout(() => { saveState(); render(); }, 200);
}

/* Tombol Generate: render → scroll & fokus ke area hasil */
async function generateAndFocus(){
  clearTimeout(timer);
  await render();

  if(!state.cfg){
    toast('Lengkapi data QR terlebih dahulu', 'error');
    return;
  }

  const preview = $('preview');
  preview.scrollIntoView({ behavior: 'smooth', block: 'start' });
  preview.focus({ preventScroll: true });

  preview.classList.add('flash');
  clearTimeout(flashTimer);
  flashTimer = setTimeout(() => preview.classList.remove('flash'), 1400);

  toast('QR Code siap dipindai', 'success');
}

/* ================================================================== */
/*  Unduh                                                             */
/* ================================================================== */
/* Buka Direct Link iklan bila diatur di dashboard CMS. */
function openDirectLink(){
  if(!DIRECT_URL) return;
  try{
    const win = window.open(DIRECT_URL, '_blank', 'noopener');
    if(win) win.opener = null;
  }catch(e){
    console.warn('Direct link tidak bisa dibuka:', e);
  }
}

/* Simpan blob sebagai unduhan langsung; fallback untuk peramban yang
   mengabaikan atribut `download` (mis. Safari iOS lama). */
function saveBlob(blob, filename){
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = filename;
  a.rel = 'noopener';
  a.style.display = 'none';
  document.body.appendChild(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 4000);
}

async function download(fmt){
  if(!window.QRCodeStyling){
    toast('Pustaka QR belum siap', 'error');
    return;
  }

  await render();

  if(!state.cfg){
    toast('Lengkapi data QR terlebih dahulu', 'error');
    return;
  }

  const size  = parseInt($('dlSize').value, 10) || 512;
  const label = fmt === 'jpeg' ? 'JPG' : fmt.toUpperCase();

  /* Direct Link iklan (opsional): dibuka sinkron di dalam klik pengguna agar
     tidak diblokir pop-up blocker. Kosong = tidak ada perubahan perilaku. */
  openDirectLink();

  setStatus('Menyiapkan ' + label + '…');
  setBadge('Menyiapkan', '');

  try{
    const type = (fmt === 'svg') ? 'svg' : 'canvas';
    const inst = new QRCodeStyling(makeOptions(state.cfg, size, type));
    const blob = await inst.getRawData(fmt);
    if(!blob) throw new Error('blob kosong');

    saveBlob(blob, 'qr-' + state.type + '-' + size + '.' + (fmt === 'jpeg' ? 'jpg' : fmt));

    setStatus('✓ ' + label + ' berhasil diunduh', 'ok');
    setBadge('Siap', 'ok');
    toast(label + ' berhasil diunduh', 'success');
  }catch(err){
    console.error(err);
    setStatus('Gagal mengunduh, coba lagi.', 'err');
    setBadge('Error', 'err');
    toast('Gagal mengunduh, coba lagi', 'error');
  }
}

/* ================================================================== */
/*  Helper WhatsApp                                                   */
/* ================================================================== */
function buildWaLink(){
  let n = $('f-wa-num').value.replace(/\D/g, '');
  if(!n) return;
  if(n.startsWith('0'))      n = '62' + n.slice(1);
  else if(n.startsWith('8')) n = '62' + n;
  if(n.length < 9 || n.length > 15){
    $('f-wa').value = '';
    setStatus('Nomor tidak valid.', 'err');
    schedule();
    return;
  }
  const msg = $('f-wa-msg').value.trim();
  $('f-wa').value = 'https://wa.me/' + n + (msg ? '?text=' + encodeURIComponent(msg) : '');
  schedule();
}

/* ================================================================== */
/*  UI: tabs, preset, sync                                            */
/* ================================================================== */
function buildTabs(){
  const wrap = $('typeTabs');
  wrap.innerHTML = '';
  TYPES.forEach(t => {
    const b = document.createElement('button');
    b.type = 'button';
    b.className = 'tab' + (t.id === state.type ? ' active' : '');
    b.dataset.type = t.id;
    b.setAttribute('role', 'tab');
    b.setAttribute('aria-selected', t.id === state.type ? 'true' : 'false');
    b.textContent = t.label;
    b.addEventListener('click', () => selectType(t.id));
    wrap.appendChild(b);
  });
}

function selectType(id){
  state.type = id;
  $$('.tab').forEach(b => {
    const on = b.dataset.type === id;
    b.classList.toggle('active', on);
    b.setAttribute('aria-selected', on ? 'true' : 'false');
    if(on) b.scrollIntoView({ behavior:'smooth', inline:'center', block:'nearest' });
  });
  $$('.panel').forEach(p => p.classList.toggle('active', p.dataset.type === id));
  saveState();
  render();

  // Fokus field pertama (desktop saja, hindari keyboard mobile)
  if(window.matchMedia('(min-width: 1024px)').matches){
    const panel = document.querySelector('.panel.active');
    if(panel){
      const input = panel.querySelector('input:not([type=color]):not([type=checkbox]):not([type=range]):not([type=file]), textarea');
      if(input) input.focus({ preventScroll: true });
    }
  }
}

function findActivePreset(){
  const c1 = $('c1').value.toLowerCase();
  const c2 = $('c2').value.toLowerCase();
  const c3 = $('c3').value.toLowerCase();
  const grad = $('gradOn').checked;
  const dots = $('dotStyle').value;
  const sq = $('cornerSq').value;
  const dot = $('cornerDot').value;
  return PRESETS.findIndex(p =>
    p.dark.toLowerCase() === c1 &&
    p.light.toLowerCase() === c2 &&
    p.c3.toLowerCase() === c3 &&
    p.grad === grad &&
    p.dots === dots &&
    p.sq === sq &&
    p.dot === dot
  );
}

function buildPresets(){
  const wrap = $('presets');
  wrap.innerHTML = '';
  const activeIdx = findActivePreset();
  PRESETS.forEach((p, i) => {
    const b = document.createElement('button');
    b.type = 'button';
    b.className = 'chip' + (i === activeIdx ? ' active' : '');
    const swBg = p.grad
      ? `linear-gradient(135deg, ${p.dark}, ${p.c3})`
      : `linear-gradient(135deg, ${p.dark}, ${p.light})`;
    b.innerHTML = `<span class="sw" style="background:${swBg}"></span>${p.name}`;
    b.addEventListener('click', () => {
      $$('#presets .chip').forEach(c => c.classList.remove('active'));
      b.classList.add('active');
      applyPreset(p);
    });
    wrap.appendChild(b);
  });
}

function applyPreset(p){
  $('c1').value = p.dark;
  $('c2').value = p.light;
  $('c3').value = p.c3;
  $('gradOn').checked = p.grad;
  $('dotStyle').value  = p.dots;
  $('cornerSq').value  = p.sq;
  $('cornerDot').value = p.dot;
  syncGradient();
  schedule();
}

function syncGradient(){ $('c3').disabled = !$('gradOn').checked; }
function syncUploads(){ $('uploadWrap').classList.toggle('hidden', $('logo').value !== 'custom'); }

function readFileInto(file){
  if(!file) return;
  if(!file.type.startsWith('image/')){
    toast('Hanya file gambar yang didukung', 'error');
    return;
  }
  const reader = new FileReader();
  reader.onload = () => { state.customLogo = reader.result; schedule(); };
  reader.readAsDataURL(file);
}

/* ================================================================== */
/*  Event                                                             */
/* ================================================================== */
function bindEvents(){
  const form = $('form');
  form.addEventListener('input',  () => { schedule(); scheduleSave(); });
  form.addEventListener('change', () => { schedule(); scheduleSave(); });

  $('logoSize').addEventListener('input', e => {
    $('logoSizeVal').textContent = e.target.value + '%';
  });
  $('gradOn').addEventListener('change', syncGradient);
  $('logo').addEventListener('change', syncUploads);
  $('logoFile').addEventListener('change', e => readFileInto(e.target.files[0]));
  $('buildWa').addEventListener('click', buildWaLink);
  $('smartPaste').addEventListener('click', smartPaste);
  $('generateBtn').addEventListener('click', generateAndFocus);
  // Auto protocol untuk URL
  $('f-url').addEventListener('blur', e => autoProtocol(e.target));

  $$('.fmt').forEach(b => {
    b.addEventListener('click', () => download(b.dataset.fmt));
  });

  // Copy raw data
  if($('copyRaw')){
    $('copyRaw').addEventListener('click', async () => {
      const raw = $('rawData').value;
      if(!raw) return;
      try{
        await navigator.clipboard.writeText(raw);
        toast('Isi QR disalin', 'success');
      }catch(e){
        $('rawData').select();
        document.execCommand('copy');
        toast('Isi QR disalin', 'success');
      }
    });
  }

  // Logo drag & drop
  const uploadWrap = $('uploadWrap');
  if(uploadWrap){
    ['dragover','dragenter'].forEach(ev => uploadWrap.addEventListener(ev, e => {
      e.preventDefault(); uploadWrap.classList.add('dragover');
    }));
    ['dragleave','drop'].forEach(ev => uploadWrap.addEventListener(ev, e => {
      e.preventDefault(); uploadWrap.classList.remove('dragover');
    }));
    uploadWrap.addEventListener('drop', e => {
      const f = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
      if(f) readFileInto(f);
    });
  }

  // Persistence events untuk kontrol desain & unduhan
  ['c1','c2','c3','gradOn','dotStyle','cornerSq','cornerDot','logo','logoSize','dlSize']
    .forEach(id => {
      const el = $(id);
      if(!el) return;
      el.addEventListener('change', scheduleSave);
      if(el.type === 'range' || el.type === 'color') el.addEventListener('input', scheduleSave);
    });
}

/* ================================================================== */
/*  Loader cadangan                                                   */
/* ================================================================== */
function loadScript(src, timeout){
  return new Promise((resolve, reject) => {
    const s = document.createElement('script');
    s.src = src;
    const t = setTimeout(() => { s.remove(); reject(new Error('timeout')); }, timeout || 6000);
    s.onload  = () => { clearTimeout(t); resolve(); };
    s.onerror = () => { clearTimeout(t); s.remove(); reject(new Error('error')); };
    document.head.appendChild(s);
  });
}

/* ================================================================== */
/*  Init                                                              */
/* ================================================================== */
(async function init(){
  if(!document.getElementById('form') || !ROOT) return;
  try{
    loadState();
    buildTabs();
    buildPresets();
    enhanceAllInputs();
    bindEvents();
    syncGradient();
    syncUploads();
    $('logoSizeVal').textContent = $('logoSize').value + '%';

    if(!window.QRCodeStyling){
      try{
        await loadScript('https://unpkg.com/qr-code-styling@1.6.0-rc.1/lib/qr-code-styling.js', 6000);
      }catch(e){ console.warn('CDN cadangan gagal:', e); }
    }

    if(!window.QRCodeStyling){
      clearQR('Gagal memuat pustaka QR.<br>Periksa koneksi internet lalu muat ulang halaman.');
      setStatus('Pustaka QR tidak tersedia.', 'err');
      setBadge('Gagal', 'err');
      return;
    }

    render();
  }catch(err){
    console.error('Init error:', err);
    clearQR('Terjadi kesalahan saat memuat.');
    setStatus('Terjadi kesalahan saat memuat.', 'err');
    setBadge('Gagal', 'err');
  }
})();
