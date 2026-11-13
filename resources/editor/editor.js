/**
 * CMS rich text editor — Tiptap v3 bundle.
 *
 * Self-hosted, dependency-free at runtime. Mounts on every
 * `form[data-editor]` that contains a `[data-editor-mount]` host and keeps a
 * hidden `textarea[name="body"]` in sync so the form still submits without JS.
 *
 * The toolbar markup is rendered server-side (AdminUi::editorToolbar) and wired
 * up here through `data-editor-cmd` / `data-editor-color` attributes. This keeps
 * every icon in one place (the PHP SVG icon set).
 *
 * Exposed globally as `window.CMSRichEditor`.
 */

import { Editor } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';
import { TableKit } from '@tiptap/extension-table';
import { Image } from '@tiptap/extension-image';
import { TextAlign } from '@tiptap/extension-text-align';
import { TextStyle } from '@tiptap/extension-text-style';
import { Color } from '@tiptap/extension-color';
import { Highlight } from '@tiptap/extension-highlight';
import { Placeholder } from '@tiptap/extension-placeholder';
import { CharacterCount } from '@tiptap/extension-character-count';
import { CodeBlockLowlight } from '@tiptap/extension-code-block-lowlight';
import { createLowlight } from 'lowlight';
import javascript from 'highlight.js/lib/languages/javascript';
import typescript from 'highlight.js/lib/languages/typescript';
import xml from 'highlight.js/lib/languages/xml';
import css from 'highlight.js/lib/languages/css';
import json from 'highlight.js/lib/languages/json';
import php from 'highlight.js/lib/languages/php';
import bash from 'highlight.js/lib/languages/bash';
import python from 'highlight.js/lib/languages/python';
import sql from 'highlight.js/lib/languages/sql';

const lowlight = createLowlight();
lowlight.register('javascript', javascript);
lowlight.register('typescript', typescript);
lowlight.register('html', xml);
lowlight.register('xml', xml);
lowlight.register('css', css);
lowlight.register('json', json);
lowlight.register('php', php);
lowlight.register('bash', bash);
lowlight.register('shell', bash);
lowlight.register('python', python);
lowlight.register('sql', sql);

/** Editors keyed by their mount element, so external code can reach them. */
const instances = new Map();
let lastActive = null;

const ICONS_ATTR = {
  bold: 'Bold',
  italic: 'Italic',
  underline: 'Underline',
  strike: 'Strikethrough',
  code: 'Inline code',
  bulletList: 'Bullet list',
  orderedList: 'Numbered list',
  blockquote: 'Blockquote',
  codeBlock: 'Code block',
  link: 'Insert link',
  image: 'Insert image',
  table: 'Table',
  horizontalRule: 'Divider',
  undo: 'Undo',
  redo: 'Redo',
};

/**
 * Strip the worst offenders when pasting from Microsoft Word / Google Docs.
 * Tiptap already drops unknown nodes through the schema; this trims the
 * inline `mso-*` styles and namespaced tags that survive parsing.
 */
const cleanPastedHtml = (html) => {
  let output = html;
  output = output.replace(/<\/?(o:p|w:[^>]+|m:[^>]+|v:[^>]+)[^>]*>/gi, '');
  output = output.replace(/<!--[\s\S]*?-->/g, '');
  output = output.replace(/<(\w+)([^>]*?)\sclass="?Mso[^">]*"?/gi, '<$1$2');
  output = output.replace(/\s(style|lang|xml:lang)="[^"]*"/gi, '');
  return output;
};

const buildExtensions = (placeholder, limit) => {
  const extensions = [
    StarterKit.configure({
      codeBlock: false,
      heading: { levels: [1, 2, 3, 4, 5, 6] },
      link: {
        openOnClick: false,
        autolink: true,
        HTMLAttributes: { rel: 'noopener nofollow' },
      },
    }),
    TextStyle,
    Color,
    Highlight.configure({ multicolor: true }),
    TextAlign.configure({ types: ['heading', 'paragraph'] }),
    Image.configure({ inline: false, allowBase64: false }),
    TableKit.configure({ table: { resizable: true } }),
    CodeBlockLowlight.configure({ lowlight }),
    Placeholder.configure({ placeholder }),
    CharacterCount.configure(limit ? { limit } : {}),
  ];
  return extensions;
};

const initForm = (form) => {
  const mount = form.querySelector('[data-editor-mount]');
  const source = form.querySelector('textarea[name="body"]');
  const toolbar = form.querySelector('[data-editor-toolbar]');
  if (!mount || !source) {
    return;
  }

  const placeholder = mount.dataset.placeholder || 'Mulai menulis…';
  const limit = Number(mount.dataset.limit || 0);

  // Reveal the visual host (it starts hidden so the plain textarea is the
  // no-JS fallback) before Tiptap measures the layout.
  mount.hidden = false;

  const editor = new Editor({
    element: mount,
    extensions: buildExtensions(placeholder, limit),
    content: source.value || '',
    editorProps: {
      attributes: {
        class: 'tiptap-content',
        'aria-label': mount.dataset.ariaLabel || 'Isi artikel',
      },
      transformPastedHTML: cleanPastedHtml,
    },
    onUpdate: () => {
      source.value = editor.getHTML();
      refreshCount(editor, form);
    },
    onSelectionUpdate: () => {
      lastActive = editor;
      refreshToolbar(editor, toolbar, form);
    },
    onFocus: () => {
      lastActive = editor;
    },
  });

  // Hide the plain textarea now that the visual editor is ready.
  source.hidden = true;

  // Double-click an image to edit its source/alt text (or remove it).
  mount.addEventListener('dblclick', (event) => {
    const image = event.target.closest('img');
    if (!image) {
      return;
    }
    event.preventDefault();
    const currentSrc = image.getAttribute('src') || '';
    const currentAlt = image.getAttribute('alt') || '';
    const nextSrc = window.prompt('URL gambar (kosongkan untuk menghapus):', currentSrc);
    if (nextSrc === null) {
      return;
    }
    if (nextSrc.trim() === '') {
      editor.chain().focus().setNodeSelection(editor.view.posAtDOM(image, 0)).deleteSelection().run();
      return;
    }
    const nextAlt = window.prompt('Teks alternatif (alt):', currentAlt);
    if (nextAlt === null) {
      return;
    }
    editor
      .chain()
      .focus()
      .setNodeSelection(editor.view.posAtDOM(image, 0))
      .updateAttributes('image', { src: nextSrc.trim(), alt: nextAlt.trim() })
      .run();
  });

  instances.set(mount, editor);
  lastActive = editor;

  wireToolbar(editor, toolbar, form, mount);
  wireSourcePanel(editor, form, mount, source, toolbar);
  refreshToolbar(editor, toolbar, form);
  refreshCount(editor, form);

  return editor;
};

/* ------------------------------------------------------------------ */
/* Toolbar state                                                       */
/* ------------------------------------------------------------------ */
const refreshToolbar = (editor, toolbar, form) => {
  if (!toolbar) {
    return;
  }
  toolbar.querySelectorAll('[data-editor-cmd]').forEach((button) => {
    const cmd = button.dataset.editorCmd;
    if (cmd === 'source' || cmd === 'undo' || cmd === 'redo' || cmd === 'table') {
      return;
    }
    let active = false;
    if (cmd === 'bold') active = editor.isActive('bold');
    else if (cmd === 'italic') active = editor.isActive('italic');
    else if (cmd === 'underline') active = editor.isActive('underline');
    else if (cmd === 'strike') active = editor.isActive('strike');
    else if (cmd === 'code') active = editor.isActive('code');
    else if (cmd === 'bulletList') active = editor.isActive('bulletList');
    else if (cmd === 'orderedList') active = editor.isActive('orderedList');
    else if (cmd === 'blockquote') active = editor.isActive('blockquote');
    else if (cmd === 'codeBlock') active = editor.isActive('codeBlock');
    else if (cmd === 'link') active = editor.isActive('link');
    else if (cmd === 'align') active = editor.isActive({ textAlign: button.dataset.value });
    button.classList.toggle('is-active', active);
    if (cmd === 'link' || cmd === 'bold' || cmd === 'italic' || cmd === 'underline' || cmd === 'strike') {
      button.setAttribute('aria-pressed', active ? 'true' : 'false');
    }
  });

  const blockSelect = toolbar.querySelector('[data-editor-block]');
  if (blockSelect) {
    if (editor.isActive('heading', { level: 1 })) blockSelect.value = '1';
    else if (editor.isActive('heading', { level: 2 })) blockSelect.value = '2';
    else if (editor.isActive('heading', { level: 3 })) blockSelect.value = '3';
    else if (editor.isActive('heading', { level: 4 })) blockSelect.value = '4';
    else if (editor.isActive('heading', { level: 5 })) blockSelect.value = '5';
    else if (editor.isActive('heading', { level: 6 })) blockSelect.value = '6';
    else blockSelect.value = 'p';
  }

  const tableToggle = toolbar.querySelector('[data-editor-table-toggle]');
  if (tableToggle) {
    const inTable = editor.isActive('table');
    tableToggle.classList.toggle('is-active', inTable);
    toolbar.querySelectorAll('[data-editor-table-op]').forEach((item) => {
      const needsTable = item.dataset.editorTableOp !== 'insert';
      item.disabled = needsTable && !inTable;
    });
  }
};

const refreshCount = (editor, form) => {
  const output = form.querySelector('[data-editor-count]');
  if (!output || !editor.storage.characterCount) {
    return;
  }
  const words = editor.storage.characterCount.words();
  const chars = editor.storage.characterCount.characters();
  output.textContent = `${words} kata · ${chars} karakter`;
};

/* ------------------------------------------------------------------ */
/* Commands                                                            */
/* ------------------------------------------------------------------ */
const applyCommand = (editor, cmd, value, origin) => {
  const chain = editor.chain().focus();
  switch (cmd) {
    case 'bold': return chain.toggleBold().run();
    case 'italic': return chain.toggleItalic().run();
    case 'underline': return chain.toggleUnderline().run();
    case 'strike': return chain.toggleStrike().run();
    case 'code': return chain.toggleCode().run();
    case 'bulletList': return chain.toggleBulletList().run();
    case 'orderedList': return chain.toggleOrderedList().run();
    case 'blockquote': return chain.toggleBlockquote().run();
    case 'codeBlock': return chain.toggleCodeBlock().run();
    case 'horizontalRule': return chain.setHorizontalRule().run();
    case 'undo': return chain.undo().run();
    case 'redo': return chain.redo().run();
    case 'clear': return chain.unsetAllMarks().clearNodes().run();
    case 'link': {
      const previous = editor.getAttributes('link').href || '';
      const url = window.prompt('Alamat tautan (URL):', previous);
      if (url === null) return false;
      if (url === '') return chain.extendMarkRange('link').unsetLink().run();
      const href = /^https?:\/\//i.test(url) || url.startsWith('/') || url.startsWith('#') || url.startsWith('mailto:') ? url : `https://${url}`;
      return chain.extendMarkRange('link').setLink({ href }).run();
    }
    case 'unlink': return chain.extendMarkRange('link').unsetLink().run();
    case 'align': return chain.setTextAlign(value).run();
    case 'block': {
      if (value === 'p') return chain.setParagraph().run();
      return chain.toggleHeading({ level: Number(value) }).run();
    }
    case 'color': return chain.setColor(value).run();
    case 'unsetColor': return chain.unsetColor().run();
    case 'highlight': return chain.toggleHighlight({ color: value }).run();
    case 'unhighlight': return chain.unsetHighlight().run();
    default:
      if (typeof origin === 'string' && typeof editor.commands[cmd] === 'function') {
        return editor.chain().focus()[cmd]().run();
      }
      return false;
  }
};

const wireToolbar = (editor, toolbar, form, mount) => {
  if (!toolbar) {
    return;
  }

  toolbar.addEventListener('click', (event) => {
    const button = event.target.closest('[data-editor-cmd]');
    if (!button || button.disabled) {
      return;
    }
    const cmd = button.dataset.editorCmd;
    if (cmd === 'source') {
      return; // handled by the source panel wiring
    }
    if (cmd === 'table' && !button.dataset.editorTableOp) {
      return; // the <summary> toggles the dropdown natively
    }
    event.preventDefault();

    const tableOp = button.dataset.editorTableOp;
    if (cmd === 'table' && tableOp) {
      applyTableCommand(editor, tableOp);
      closeDropdowns(toolbar);
      return;
    }

    applyCommand(editor, cmd, button.dataset.value, null);
    closeDropdowns(toolbar);
  });

  toolbar.querySelectorAll('[data-editor-color]').forEach((input) => {
    const commit = () => {
      const kind = input.dataset.editorColor;
      if (kind === 'highlight') {
        applyCommand(editor, 'highlight', input.value, null);
      } else {
        applyCommand(editor, 'color', input.value, null);
      }
    };
    input.addEventListener('input', commit);
  });

  const blockSelect = toolbar.querySelector('[data-editor-block]');
  if (blockSelect) {
    blockSelect.addEventListener('change', () => {
      applyCommand(editor, 'block', blockSelect.value, null);
    });
  }

  // Keep native <details> dropdowns from staying open after a choice.
  toolbar.querySelectorAll('details[data-editor-dropdown]').forEach((details) => {
    details.addEventListener('toggle', () => {
      if (details.open) {
        toolbar.querySelectorAll('details[data-editor-dropdown]').forEach((other) => {
          if (other !== details) other.open = false;
        });
      }
    });
  });

  document.addEventListener('click', (event) => {
    if (toolbar.contains(event.target)) {
      return;
    }
    closeDropdowns(toolbar);
  });
};

const closeDropdowns = (toolbar) => {
  toolbar.querySelectorAll('details[data-editor-dropdown]').forEach((details) => {
    details.open = false;
  });
};

const applyTableCommand = (editor, op) => {
  const chain = editor.chain().focus();
  switch (op) {
    case 'insert': return chain.insertTable({ rows: 3, cols: 3, withHeaderRow: true }).run();
    case 'addRowBefore': return chain.addRowBefore().run();
    case 'addRowAfter': return chain.addRowAfter().run();
    case 'addColumnBefore': return chain.addColumnBefore().run();
    case 'addColumnAfter': return chain.addColumnAfter().run();
    case 'deleteRow': return chain.deleteRow().run();
    case 'deleteColumn': return chain.deleteColumn().run();
    case 'mergeCells': return chain.mergeCells().run();
    case 'splitCell': return chain.splitCell().run();
    case 'toggleHeaderRow': return chain.toggleHeaderRow().run();
    case 'delete': return chain.deleteTable().run();
    default: return false;
  }
};

/* ------------------------------------------------------------------ */
/* HTML source toggle                                                  */
/* ------------------------------------------------------------------ */
const wireSourcePanel = (editor, form, mount, source, toolbar) => {
  const toggle = toolbar ? toolbar.querySelector('[data-editor-cmd="source"]') : null;
  const panel = document.createElement('textarea');
  panel.className = 'editor-source';
  panel.spellcheck = false;
  panel.hidden = true;
  panel.setAttribute('aria-label', 'HTML sumber');
  mount.after(panel);

  const wrap = panel;

  const setMode = (sourceMode) => {
    if (sourceMode) {
      panel.value = editor.getHTML();
      panel.hidden = false;
      mount.hidden = true;
      if (toggle) {
        toggle.classList.add('is-active');
        toggle.setAttribute('aria-pressed', 'true');
        toggle.dataset.mode = 'source';
      }
      panel.focus();
    } else {
      editor.commands.setContent(panel.value, true);
      source.value = editor.getHTML();
      panel.hidden = true;
      mount.hidden = false;
      if (toggle) {
        toggle.classList.remove('is-active');
        toggle.setAttribute('aria-pressed', 'false');
        toggle.dataset.mode = 'visual';
      }
      editor.commands.focus();
    }
  };

  if (toggle) {
    toggle.addEventListener('click', (event) => {
      event.preventDefault();
      setMode(toggle.dataset.mode !== 'source');
    });
  }

  // Keep the hidden textarea accurate if the source panel is submitted untouched.
  form.addEventListener('submit', () => {
    if (!panel.hidden) {
      source.value = panel.value;
    } else {
      source.value = editor.getHTML();
    }
  });
  void wrap;
};

/* ------------------------------------------------------------------ */
/* Public API                                                          */
/* ------------------------------------------------------------------ */
const activeEditor = () => lastActive || instances.values().next().value || null;

/** Insert an image at the current selection (used by the media picker). */
export function insertImage(url, alt = '') {
  const editor = activeEditor();
  if (!editor || !url) {
    return false;
  }
  return editor.chain().focus().setImage({ src: url, alt }).run();
}

/** Insert an arbitrary HTML snippet at the current selection. */
export function insertHtml(html) {
  const editor = activeEditor();
  if (!editor || !html) {
    return false;
  }
  return editor.chain().focus().insertContent(html).run();
}

export function getHTML() {
  const editor = activeEditor();
  return editor ? editor.getHTML() : '';
}

export function setHTML(html) {
  const editor = activeEditor();
  if (editor) {
    editor.commands.setContent(html || '', true);
  }
}

export function focus() {
  const editor = activeEditor();
  if (editor) {
    editor.commands.focus();
  }
}

export function init(root = document) {
  root.querySelectorAll('form[data-editor]').forEach((form) => {
    if (form.dataset.editorReady === '1') {
      return;
    }
    form.dataset.editorReady = '1';
    try {
      initForm(form);
    } catch (error) {
      // Never block the page: fall back to the plain textarea.
      const source = form.querySelector('textarea[name="body"]');
      if (source) source.hidden = false;
      // eslint-disable-next-line no-console
      console.error('[CMSRichEditor] init failed', error);
    }
  });
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => init());
} else {
  init();
}

export default { insertImage, insertHtml, getHTML, setHTML, focus, init, ICONS_ATTR };
