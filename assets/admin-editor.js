document.querySelectorAll('[data-editor]').forEach((form) => {
  const source = form.querySelector('textarea[name="body"]');
  const editor = form.querySelector('[data-wysiwyg]');
  const toggle = form.querySelector('[data-toggle-html]');
  if (!source || !editor || !toggle) return;

  editor.innerHTML = source.value;
  source.hidden = true;
  editor.hidden = false;

  form.querySelectorAll('[data-command]').forEach((button) => {
    button.addEventListener('click', () => {
      editor.focus();
      document.execCommand(button.dataset.command, false, null);
      source.value = editor.innerHTML;
    });
  });

  toggle.addEventListener('click', () => {
    if (source.hidden) {
      source.value = editor.innerHTML;
      source.hidden = false;
      editor.hidden = true;
      toggle.textContent = 'Tampilan visual';
      source.focus();
    } else {
      editor.innerHTML = source.value;
      source.hidden = true;
      editor.hidden = false;
      toggle.textContent = 'Edit HTML';
      editor.focus();
    }
  });

  form.addEventListener('submit', () => {
    if (source.hidden) source.value = editor.innerHTML;
  });
});
