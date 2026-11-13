/**
 * Front-end runtime. Small, dependency-free and progressively enhanced:
 * never required for navigation or reading.
 */
(() => {
  'use strict';

  const doc = document;
  const root = doc.documentElement;

  // ---------------------------------------------------------------
  // Mobile navigation
  // ---------------------------------------------------------------
  const navToggle = doc.querySelector('.nav-toggle');
  const navPanel = doc.querySelector('.nav-panel');
  if (navToggle && navPanel) {
    const setOpen = (open) => {
      navPanel.classList.toggle('is-open', open);
      navToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      doc.body.classList.toggle('nav-open', open);
    };
    navToggle.addEventListener('click', () => setOpen(!navPanel.classList.contains('is-open')));
    doc.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && navPanel.classList.contains('is-open')) {
        setOpen(false);
        navToggle.focus();
      }
    });
    navPanel.addEventListener('click', (event) => {
      if (event.target.closest('a') && window.matchMedia('(max-width: 860px)').matches) {
        setOpen(false);
      }
    });
  }

  // ---------------------------------------------------------------
  // Reading progress bar on single articles
  // ---------------------------------------------------------------
  const articleBody = doc.getElementById('article-body');
  if (articleBody) {
    const bar = doc.createElement('div');
    bar.className = 'reading-progress';
    bar.setAttribute('aria-hidden', 'true');
    doc.body.appendChild(bar);
    let frame = 0;
    const update = () => {
      frame = 0;
      const rect = articleBody.getBoundingClientRect();
      const height = rect.height || 1;
      const scrolled = Math.min(Math.max(-rect.top, 0), height);
      bar.style.transform = 'scaleX(' + (scrolled / height).toFixed(4) + ')';
    };
    window.addEventListener('scroll', () => {
      if (frame === 0) {
        frame = window.requestAnimationFrame(update);
      }
    }, { passive: true });
    update();
  }

  // ---------------------------------------------------------------
  // Sticky header shadow
  // ---------------------------------------------------------------
  const header = doc.querySelector('.site-header');
  if (header) {
    let ticking = false;
    const sync = () => {
      ticking = false;
      header.classList.toggle('is-scrolled', window.scrollY > 8);
    };
    window.addEventListener('scroll', () => {
      if (!ticking) {
        ticking = true;
        window.requestAnimationFrame(sync);
      }
    }, { passive: true });
    sync();
  }

  // ---------------------------------------------------------------
  // Back-to-top button
  // ---------------------------------------------------------------
  const toTop = doc.querySelector('.to-top');
  if (toTop) {
    const sync = () => toTop.classList.toggle('is-visible', window.scrollY > 600);
    window.addEventListener('scroll', sync, { passive: true });
    toTop.addEventListener('click', () => window.scrollTo({ top: 0, behavior: 'smooth' }));
    sync();
  }

  // ---------------------------------------------------------------
  // Newsletter forms: inline feedback without leaving the page
  // ---------------------------------------------------------------
  doc.querySelectorAll('form.newsletter-form[data-ajax]').forEach((form) => {
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      const button = form.querySelector('button[type="submit"]');
      if (button) {
        button.disabled = true;
      }
      try {
        const response = await fetch(form.action, {
          method: 'POST',
          body: new FormData(form),
          headers: { 'X-Requested-With': 'XMLHttpRequest' },
        });
        const note = form.querySelector('.newsletter-feedback') || doc.createElement('small');
        note.className = 'newsletter-feedback';
        note.textContent = response.ok ? 'Terima kasih, email terdaftar.' : 'Gagal mendaftar, coba lagi.';
        form.appendChild(note);
        if (response.ok) {
          form.reset();
        }
      } catch (error) {
        // Network failure: let the browser handle the form normally.
        form.removeAttribute('data-ajax');
        form.submit();
      } finally {
        if (button) {
          button.disabled = false;
        }
      }
    });
  });

  // ---------------------------------------------------------------
  // Table of contents offset for sticky headers
  // ---------------------------------------------------------------
  if (doc.querySelector('.toc')) {
    doc.querySelectorAll('.article-body h2, .article-body h3').forEach((heading) => {
      if (!heading.id) {
        heading.id = 'h-' + heading.textContent.trim().toLowerCase()
          .replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '').slice(0, 60);
      }
    });
  }

  // ---------------------------------------------------------------
  // Lazy-load external embeds only when visible
  // ---------------------------------------------------------------
  const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  if (!reduceMotion) {
    doc.documentElement.classList.add('motion-ok');
  }
})();
