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
    // CSS owns the open state through `.nav-open` (see base.css); it is also
    // applied to <body> so the page behind the dropdown can be locked.
    const isOpen = () => navPanel.classList.contains('is-open');
    const setOpen = (open) => {
      navPanel.classList.toggle('is-open', open);
      navPanel.classList.toggle('nav-open', open);
      navToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      navToggle.setAttribute('aria-label', open ? 'Tutup menu' : 'Buka menu');
      doc.body.classList.toggle('nav-open', open);
    };
    navToggle.addEventListener('click', (event) => {
      event.stopPropagation();
      setOpen(!isOpen());
    });
    doc.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && isOpen()) {
        setOpen(false);
        navToggle.focus();
      }
    });
    navPanel.addEventListener('click', (event) => {
      if (event.target.closest('a') && window.matchMedia('(max-width: 860px)').matches) {
        setOpen(false);
      }
    });
    // Tapping outside the dropdown closes it (mobile only).
    doc.addEventListener('click', (event) => {
      if (!isOpen() || !window.matchMedia('(max-width: 860px)').matches) return;
      if (navPanel.contains(event.target) || navToggle.contains(event.target)) return;
      setOpen(false);
    });
    window.addEventListener('resize', () => {
      if (isOpen() && window.innerWidth > 860) setOpen(false);
    }, { passive: true });
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
  // Back-to-top button (single article uses .scroll-top; legacy themes .to-top)
  // ---------------------------------------------------------------
  doc.querySelectorAll('.scroll-top, .to-top').forEach((toTop) => {
    const threshold = toTop.classList.contains('scroll-top') ? 400 : 600;
    const sync = () => {
      const visible = window.scrollY > threshold;
      toTop.classList.toggle('is-visible', visible);
      toTop.hidden = !visible;
    };
    window.addEventListener('scroll', sync, { passive: true });
    toTop.addEventListener('click', () => {
      const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      window.scrollTo({ top: 0, behavior: reduce ? 'auto' : 'smooth' });
      // Move focus back to the top of the document for keyboard users.
      const skip = doc.getElementById('main');
      if (skip) {
        skip.setAttribute('tabindex', '-1');
        skip.focus({ preventScroll: true });
      }
    });
    sync();
  });

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
        note.textContent = response.ok
          ? (form.dataset.ok || '')
          : (form.dataset.error || '');
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
  // Featured posts carousel
  //
  // The track scrolls natively, so this only adds arrow buttons and the
  // state classes the CSS uses for the edge fade. Without JS the row is
  // still swipeable and every link remains reachable by keyboard.
  // ---------------------------------------------------------------
  const reduceMotionEarly = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  doc.querySelectorAll('[data-carousel]').forEach((widget) => {
    const track = widget.querySelector('[data-carousel-track]');
    const prev = widget.querySelector('[data-carousel-prev]');
    const next = widget.querySelector('[data-carousel-next]');
    if (!track || !prev || !next) {
      return;
    }

    // One card width plus the flex gap, so a click lands on a card boundary.
    const step = () => {
      const item = track.querySelector('.featured-item');
      if (!item) {
        return track.clientWidth;
      }
      const styles = window.getComputedStyle(track);
      const gap = parseFloat(styles.columnGap || styles.gap) || 0;
      return item.getBoundingClientRect().width + gap;
    };

    const sync = () => {
      const max = track.scrollWidth - track.clientWidth;
      // A sub-pixel rounding gap would otherwise leave an arrow stuck on.
      const overflowing = max > 2;
      widget.classList.toggle('is-overflowing', overflowing);
      widget.classList.toggle('is-at-start', track.scrollLeft <= 2);
      widget.classList.toggle('is-at-end', track.scrollLeft >= max - 2);
      prev.hidden = !overflowing || track.scrollLeft <= 2;
      next.hidden = !overflowing || track.scrollLeft >= max - 2;
    };

    const scrollByStep = (direction) => {
      track.scrollBy({
        left: direction * step(),
        behavior: reduceMotionEarly ? 'auto' : 'smooth',
      });
    };

    prev.addEventListener('click', () => scrollByStep(-1));
    next.addEventListener('click', () => scrollByStep(1));

    // Arrow keys when the track itself has focus.
    track.addEventListener('keydown', (event) => {
      if (event.key === 'ArrowRight') {
        event.preventDefault();
        scrollByStep(1);
      } else if (event.key === 'ArrowLeft') {
        event.preventDefault();
        scrollByStep(-1);
      }
    });

    let ticking = false;
    track.addEventListener('scroll', () => {
      if (ticking) {
        return;
      }
      ticking = true;
      window.requestAnimationFrame(() => {
        ticking = false;
        sync();
      });
    });

    if (window.ResizeObserver) {
      new ResizeObserver(sync).observe(track);
    } else {
      window.addEventListener('resize', sync);
    }

    sync();
  });

  // ---------------------------------------------------------------
  // Table of contents anchors
  //
  // The TOC widget links to `#h-<slug>`, so headings need matching ids.
  // Duplicate titles get a numeric suffix on both sides to stay unique.
  // ---------------------------------------------------------------
  if (doc.querySelector('.toc')) {
    const seen = new Map();
    doc.querySelectorAll('.article-body h2, .article-body h3').forEach((heading) => {
      if (!heading.id) {
        let slug = 'h-' + heading.textContent.trim().toLowerCase()
          .replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '').slice(0, 60);
        if (slug === 'h-') slug = 'h-section';
        // First heading keeps the bare slug; later duplicates get -2, -3, …
        // matching what Widget::renderToc() links to.
        const count = seen.get(slug) || 0;
        seen.set(slug, count + 1);
        heading.id = count === 0 ? slug : slug + '-' + (count + 1);
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
