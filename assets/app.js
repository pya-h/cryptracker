document.addEventListener('DOMContentLoaded', () => {

    /* ── Alert auto-dismiss with smooth exit ──────────────── */

    document.querySelectorAll('.alert').forEach(alert => {
        setTimeout(() => {
            alert.style.opacity = '0';
            alert.style.transform = 'translateY(-12px)';
            setTimeout(() => alert.remove(), 400);
        }, 5000);
    });

    /* ── Clickable table rows ─────────────────────────────── */

    document.querySelectorAll('.clickable-row').forEach(row => {
        row.addEventListener('click', (e) => {
            if (e.target.closest('a, button, form')) return;
            const href = row.dataset.href;
            if (href) {
                row.style.transform = 'scale(.985)';
                row.style.opacity = '.7';
                setTimeout(() => { window.location.href = href; }, 120);
            }
        });
    });

    /* ── Button ripple position tracking ──────────────────── */

    document.querySelectorAll('.btn').forEach(btn => {
        btn.addEventListener('mousedown', (e) => {
            const r = btn.getBoundingClientRect();
            const x = ((e.clientX - r.left) / r.width * 100).toFixed(0);
            const y = ((e.clientY - r.top) / r.height * 100).toFixed(0);
            btn.style.setProperty('--ripple-x', x + '%');
            btn.style.setProperty('--ripple-y', y + '%');
        });
    });

    /* ── User Context Menu ────────────────────────────────── */

    const menuBtn  = document.getElementById('userMenuBtn');
    const menuWrap = menuBtn ? menuBtn.closest('.user-menu-wrapper') : null;

    if (menuBtn && menuWrap) {
        menuBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            menuWrap.classList.toggle('open');
        });
        document.addEventListener('click', (e) => {
            if (!menuWrap.contains(e.target)) menuWrap.classList.remove('open');
        });
    }

    /* ── Customize Modal ──────────────────────────────────── */

    const overlay  = document.getElementById('customizeOverlay');
    const openBtn  = document.getElementById('openCustomize');
    const closeBtn = document.getElementById('closeCustomize');

    if (overlay && openBtn) {
        openBtn.addEventListener('click', () => {
            overlay.classList.add('open');
            if (menuWrap) menuWrap.classList.remove('open');
            requestAnimationFrame(() => { overlay.style.opacity = '1'; });
        });

        const closeModal = () => {
            overlay.style.opacity = '0';
            setTimeout(() => overlay.classList.remove('open'), 250);
        };

        closeBtn.addEventListener('click', closeModal);
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) closeModal();
        });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && overlay.classList.contains('open')) closeModal();
        });
    }

    /* ── Precision Slider ─────────────────────────────────── */

    const precSlider = document.getElementById('precSlider');
    const precVal    = document.getElementById('precVal');

    if (precSlider && precVal) {
        precSlider.addEventListener('input', () => {
            precVal.textContent = precSlider.value;
            precVal.style.transform = 'scale(1.25)';
            precVal.style.transition = 'transform .2s ease';
            setTimeout(() => { precVal.style.transform = 'scale(1)'; }, 200);
        });
    }

    /* ── Export to CSV ─────────────────────────────────────── */

    const exportBtn = document.getElementById('exportCsv');

    if (exportBtn) {
        exportBtn.addEventListener('click', () => {
            if (menuWrap) menuWrap.classList.remove('open');
            const main = document.querySelector('main[data-page]');
            if (!main) return;

            const page = main.dataset.page;
            let url = 'export_csv.php?page=' + encodeURIComponent(page);
            if (page === 'token') {
                url += '&id=' + encodeURIComponent(main.dataset.tokenId || '');
            }
            window.location.href = url;
        });
    }

    /* ── Stagger-animate cards on load ────────────────────── */

    const staggerElements = document.querySelectorAll(
        '.summary-card, .pl-card, .token-table tbody tr, .info-grid > div, .market-item'
    );
    staggerElements.forEach((el, i) => {
        el.style.animationDelay = (i * 0.04) + 's';
    });

    /* ── Intersection Observer: fade-in on scroll ─────────── */

    if ('IntersectionObserver' in window) {
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.style.opacity = '1';
                    entry.target.style.transform = 'translateY(0)';
                    observer.unobserve(entry.target);
                }
            });
        }, { threshold: 0.08, rootMargin: '0px 0px -30px 0px' });

        document.querySelectorAll(
            '.trade-card, .holdings-info, .tx-history, .danger-zone, .graph-container'
        ).forEach(el => {
            el.style.opacity = '0';
            el.style.transform = 'translateY(16px)';
            el.style.transition = 'opacity .5s ease, transform .5s ease';
            observer.observe(el);
        });
    }

    /* ── Token Search ─────────────────────────────────────── */

    const searchInput   = document.getElementById('tokenSearch');
    const searchResults = document.getElementById('searchResults');

    if (!searchInput || !searchResults) return;

    const csrfMeta = document.querySelector('meta[name="csrf-token"]');
    const csrfToken = csrfMeta ? csrfMeta.getAttribute('content') : '';

    let debounceTimer = null;

    searchInput.addEventListener('input', () => {
        clearTimeout(debounceTimer);
        const q = searchInput.value.trim();

        if (q.length < 1) {
            searchResults.classList.remove('open');
            searchResults.innerHTML = '';
            return;
        }

        searchResults.classList.add('open');
        searchResults.innerHTML = '<div class="search-loading">Searching\u2026</div>';

        debounceTimer = setTimeout(() => fetchTokens(q), 350);
    });

    document.addEventListener('click', e => {
        if (!searchInput.contains(e.target) && !searchResults.contains(e.target)) {
            searchResults.classList.remove('open');
        }
    });

    searchInput.addEventListener('keydown', e => {
        if (e.key === 'Escape') {
            searchResults.classList.remove('open');
            searchInput.blur();
        }
    });

    async function fetchTokens(query) {
        try {
            const res  = await fetch(`search_tokens.php?q=${encodeURIComponent(query)}`);
            const data = await res.json();

            if (!data.length) {
                searchResults.innerHTML = '<div class="search-no-results">No tokens found.</div>';
                return;
            }

            searchResults.innerHTML = '';

            data.forEach((coin, idx) => {
                const item = document.createElement('div');
                item.className = 'search-result-item';
                item.style.animationDelay = (idx * 0.03) + 's';

                const info = document.createElement('div');
                const nameSpan = document.createElement('span');
                nameSpan.className = 'coin-name';
                nameSpan.textContent = coin.name;
                const symSpan = document.createElement('span');
                symSpan.className = 'coin-symbol';
                symSpan.textContent = coin.symbol;
                info.appendChild(nameSpan);
                info.appendChild(symSpan);

                const form = document.createElement('form');
                form.method = 'POST';
                form.action = 'add_token.php';
                form.style.margin = '0';

                const fields = [
                    ['_csrf', csrfToken],
                    ['cmc_id', coin.id],
                    ['symbol', coin.symbol],
                    ['name', coin.name],
                    ['slug', coin.slug || '']
                ];

                fields.forEach(([n, v]) => {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = n;
                    input.value = v;
                    form.appendChild(input);
                });

                const btn = document.createElement('button');
                btn.type = 'submit';
                btn.className = 'btn btn-primary btn-sm';
                btn.textContent = '+ Add';
                form.appendChild(btn);

                btn.addEventListener('click', (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    form.submit();
                });

                item.appendChild(info);
                item.appendChild(form);

                item.addEventListener('click', (e) => {
                    if (e.target.closest('button')) return;
                    form.submit();
                });

                searchResults.appendChild(item);
            });
        } catch (err) {
            searchResults.innerHTML = '<div class="search-no-results">Search failed. Try again.</div>';
        }
    }
});
