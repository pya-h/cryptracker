document.addEventListener('DOMContentLoaded', () => {

    /* ── Alert auto-dismiss ───────────────────────────────── */

    document.querySelectorAll('.alert').forEach(a => {
        setTimeout(() => {
            a.style.opacity = '0';
            a.style.transform = 'translateY(-12px)';
            setTimeout(() => a.remove(), 400);
        }, 5000);
    });

    /* ── Clickable table rows ─────────────────────────────── */

    document.querySelectorAll('.clickable-row').forEach(row => {
        row.addEventListener('click', e => {
            if (e.target.closest('a, button, form')) return;
            const href = row.dataset.href;
            if (!href) return;
            row.style.transform = 'scale(.985)';
            row.style.opacity = '.7';
            setTimeout(() => { window.location.href = href; }, 120);
        });
    });

    /* ── Button ripple position tracking ──────────────────── */

    document.querySelectorAll('.btn').forEach(btn => {
        btn.addEventListener('mousedown', e => {
            const r = btn.getBoundingClientRect();
            btn.style.setProperty('--ripple-x', ((e.clientX - r.left) / r.width * 100).toFixed(0) + '%');
            btn.style.setProperty('--ripple-y', ((e.clientY - r.top) / r.height * 100).toFixed(0) + '%');
        });
    });

    /* ── User Context Menu ────────────────────────────────── */

    const menuBtn  = document.getElementById('userMenuBtn');
    const menuWrap = menuBtn ? menuBtn.closest('.user-menu-wrapper') : null;

    if (menuBtn && menuWrap) {
        menuBtn.addEventListener('click', e => {
            e.stopPropagation();
            menuWrap.classList.toggle('open');
        });
        document.addEventListener('click', e => {
            if (!menuWrap.contains(e.target)) menuWrap.classList.remove('open');
        });
    }

    /* ── Customize Modal ──────────────────────────────────── */

    const overlay  = document.getElementById('customizeOverlay');
    const openBtn  = document.getElementById('openCustomize');
    const closeBtn = document.getElementById('closeCustomize');

    if (overlay && openBtn && closeBtn) {
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
        overlay.addEventListener('click', e => { if (e.target === overlay) closeModal(); });
        document.addEventListener('keydown', e => {
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
            if (page === 'token') url += '&id=' + encodeURIComponent(main.dataset.tokenId || '');
            window.location.href = url;
        });
    }

    /* ── Stagger-animate cards on load ────────────────────── */

    document.querySelectorAll(
        '.summary-card, .pl-card, .token-table tbody tr, .info-grid > div, .market-item'
    ).forEach((el, i) => { el.style.animationDelay = (i * 0.04) + 's'; });

    /* ── Intersection Observer: fade-in on scroll ─────────── */

    if ('IntersectionObserver' in window) {
        const obs = new IntersectionObserver(entries => {
            entries.forEach(en => {
                if (en.isIntersecting) {
                    en.target.style.opacity = '1';
                    en.target.style.transform = 'translateY(0)';
                    obs.unobserve(en.target);
                }
            });
        }, { threshold: 0.08, rootMargin: '0px 0px -30px 0px' });

        document.querySelectorAll(
            '.trade-card, .holdings-info, .tx-history, .danger-zone, .graph-container'
        ).forEach(el => {
            el.style.opacity = '0';
            el.style.transform = 'translateY(16px)';
            el.style.transition = 'opacity .5s ease, transform .5s ease';
            obs.observe(el);
        });
    }

    /* ═══════════════════════════════════════════════════════
       ── Count-Up Animation for P/L values ────────────────
       Elements with [data-countup] animate from 0 to target.
       Duration ~2s with easeOutExpo for a satisfying finish.
       ═══════════════════════════════════════════════════════ */

    function countUp(el) {
        const raw = parseFloat(el.dataset.countup);
        if (isNaN(raw) || raw === 0) return;

        const isPL = el.hasAttribute('data-pl');
        const prefix = el.dataset.prefix || '';
        const decimals = detectDecimals(el.textContent);
        const percentEl = el.querySelector('.pl-percent');
        const percentText = percentEl ? percentEl.textContent : '';

        const duration = 2000;
        const start = performance.now();

        function easeOutExpo(t) { return t >= 1 ? 1 : 1 - Math.pow(2, -10 * t); }

        function tick(now) {
            const t = Math.min((now - start) / duration, 1);
            const val = raw * easeOutExpo(t);
            el.textContent = formatVal(val, isPL, prefix, decimals);
            if (percentEl) {
                el.appendChild(percentEl);
                percentEl.textContent = percentText;
            }
            if (t < 1) requestAnimationFrame(tick);
        }

        requestAnimationFrame(tick);
    }

    function detectDecimals(text) {
        const m = text.match(/\.(\d+)/);
        return m ? m[1].length : 2;
    }

    function formatVal(v, isPL, prefix, dec) {
        const abs = Math.abs(v);
        const formatted = abs.toLocaleString('en-US', { minimumFractionDigits: dec, maximumFractionDigits: dec });
        if (isPL) return (v >= 0 ? '+$' : '-$') + formatted;
        return prefix + formatted;
    }

    document.querySelectorAll('[data-countup]').forEach(countUp);

    /* ═══════════════════════════════════════════════════════
       ── Live Price Refresh (10s interval) ────────────────
       Fetches fresh prices from api_prices.php, updates:
       - Price, 24h change, current value, unrealized/total PL
       - Works on both dashboard table and single token page
       ═══════════════════════════════════════════════════════ */

    const main = document.querySelector('main[data-page]');
    if (!main) return;

    const page = main.dataset.page;

    function gatherCmcIds() {
        if (page === 'dashboard') {
            return [...new Set(
                [...document.querySelectorAll('tr[data-cmc-id]')].map(r => r.dataset.cmcId)
            )];
        }
        if (page === 'token' && main.dataset.cmcId) {
            return [main.dataset.cmcId];
        }
        return [];
    }

    function flashEl(el) {
        el.classList.add('live-flash');
        setTimeout(() => el.classList.remove('live-flash'), 600);
    }

    function plClassJS(v) { return v >= 0 ? 'profit' : 'loss'; }

    function formatUSD(v, dec) {
        return '$' + Math.abs(v).toLocaleString('en-US', { minimumFractionDigits: dec, maximumFractionDigits: dec });
    }

    function formatPLJS(v, dec) {
        const abs = Math.abs(v).toLocaleString('en-US', { minimumFractionDigits: dec, maximumFractionDigits: dec });
        return (v >= 0 ? '+$' : '-$') + abs;
    }

    function formatPercentJS(v, dec) {
        const sign = v >= 0 ? '+' : '';
        return sign + v.toLocaleString('en-US', { minimumFractionDigits: dec, maximumFractionDigits: dec }) + '%';
    }

    function updateDashboard(quotes) {
        const tblDec = Math.min(detectDecimals(
            document.querySelector('tr[data-cmc-id] td[data-live="price"]')?.textContent || '0.00'
        ), 6);

        let sumUnrealized = 0, sumTotal = 0;
        let sumRealized = 0;

        document.querySelectorAll('tr[data-cmc-id]').forEach(row => {
            const cmcId = row.dataset.cmcId;
            const q = quotes[cmcId];
            if (!q) return;

            const holdings = parseFloat(row.dataset.holdings) || 0;
            const costBasis = parseFloat(row.dataset.costBasis) || 0;
            const newPrice = q.price;
            const newChange = q.percent_change_24h;
            const newCurrVal = holdings * newPrice;
            const avgBuy = holdings > 0 ? costBasis / holdings : 0;
            const newUnreal = newCurrVal - costBasis;
            const realPLtd = row.querySelector('td:nth-child(7)');
            const realVal = parsePLText(realPLtd?.textContent || '+$0');
            const newTotal = realVal + newUnreal;

            const priceEl = row.querySelector('[data-live="price"]');
            const changeEl = row.querySelector('[data-live="change24"]');
            const currValEl = row.querySelector('[data-live="currentVal"]');
            const unrealEl = row.querySelector('[data-live="unrealizedPL"]');
            const totalEl = row.querySelector('[data-live="totalPL"]');

            if (priceEl) { priceEl.textContent = formatUSD(newPrice, tblDec); flashEl(priceEl); }
            if (changeEl) {
                changeEl.textContent = formatPercentJS(newChange, tblDec);
                changeEl.className = plClassJS(newChange);
                flashEl(changeEl);
            }
            if (currValEl) { currValEl.textContent = formatUSD(newCurrVal, tblDec); flashEl(currValEl); }
            if (unrealEl) {
                unrealEl.textContent = formatPLJS(newUnreal, tblDec);
                unrealEl.className = plClassJS(newUnreal);
                flashEl(unrealEl);
            }
            if (totalEl) {
                totalEl.textContent = formatPLJS(newTotal, tblDec);
                totalEl.className = plClassJS(newTotal);
                flashEl(totalEl);
            }

            sumRealized += realVal;
            sumUnrealized += newUnreal;
            sumTotal += newTotal;
        });

        const sumUnrealEl = document.querySelector('[data-live="unrealized-total"]');
        const sumTotalEl = document.querySelector('[data-live="total-pl-total"]');

        if (sumUnrealEl) {
            sumUnrealEl.textContent = formatPLJS(sumUnrealized, 2);
            sumUnrealEl.className = 'summary-value ' + plClassJS(sumUnrealized);
            flashEl(sumUnrealEl);
        }
        if (sumTotalEl) {
            sumTotalEl.textContent = formatPLJS(sumTotal, 2);
            sumTotalEl.className = 'summary-value ' + plClassJS(sumTotal);
            flashEl(sumTotalEl);
        }
    }

    function updateTokenPage(quotes) {
        const cmcId = main.dataset.cmcId;
        const q = quotes[cmcId];
        if (!q) return;

        const holdings = parseFloat(main.dataset.holdings) || 0;
        const costBasis = parseFloat(main.dataset.costBasis) || 0;
        const newPrice = q.price;
        const newChange = q.percent_change_24h;
        const newCurrVal = holdings * newPrice;
        const newUnreal = newCurrVal - costBasis;
        const dec = detectDecimals(
            document.querySelector('[data-live="currentVal"]')?.textContent || '0.00'
        );

        const realizedEl = document.querySelector('.pl-card:first-child .pl-value');
        const realVal = realizedEl ? parsePLText(realizedEl.textContent) : 0;
        const newTotal = realVal + newUnreal;
        const unrealPct = costBasis > 0 ? (newUnreal / costBasis) * 100 : 0;
        const totalSpent = costBasis;
        const totalPct = totalSpent > 0 ? (newTotal / totalSpent) * 100 : 0;

        const priceEl = document.querySelector('[data-live="price"]');
        const changeEl = document.querySelector('[data-live="change24"]');
        const currValEl = document.querySelector('[data-live="currentVal"]');
        const unrealEl = document.querySelector('[data-live="unrealizedPL"]');
        const unrealPctEl = document.querySelector('[data-live="unrealizedPercent"]');
        const totalEl = document.querySelector('[data-live="totalPL"]');
        const totalPctEl = document.querySelector('[data-live="totalPercent"]');

        if (priceEl) { priceEl.textContent = '$' + newPrice.toFixed(6); flashEl(priceEl); }
        if (changeEl) {
            changeEl.textContent = formatPercentJS(newChange, 2);
            changeEl.className = 'price-badge ' + plClassJS(newChange);
            flashEl(changeEl);
        }
        if (currValEl) { currValEl.textContent = formatUSD(newCurrVal, dec); flashEl(currValEl); }
        if (unrealEl) {
            const pctSpan = unrealEl.querySelector('.pl-percent');
            unrealEl.textContent = formatPLJS(newUnreal, dec) + ' ';
            unrealEl.className = 'pl-value ' + plClassJS(newUnreal);
            if (pctSpan) unrealEl.appendChild(pctSpan);
            flashEl(unrealEl);
        }
        if (unrealPctEl) { unrealPctEl.textContent = '(' + formatPercentJS(unrealPct, 2) + ')'; }
        if (totalEl) {
            const pctSpan = totalEl.querySelector('.pl-percent');
            totalEl.textContent = formatPLJS(newTotal, dec) + ' ';
            totalEl.className = 'pl-value ' + plClassJS(newTotal);
            if (pctSpan) totalEl.appendChild(pctSpan);
            flashEl(totalEl);
        }
        if (totalPctEl) { totalPctEl.textContent = '(' + formatPercentJS(totalPct, 2) + ')'; }

        // Update "Now" row in analytics table
        const nowRow = document.querySelector('tr[data-live-now="1"]');
        if (nowRow) {
            const cumReal = parseFloat(nowRow.dataset.cumRealized) || 0;
            const now = new Date();
            const dateStr = now.toLocaleDateString('en-US', { month: 'short', day: '2-digit', year: 'numeric' })
                + ' ' + now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', hour12: false });

            const nDate = nowRow.querySelector('[data-live-now="date"]');
            const nPrice = nowRow.querySelector('[data-live-now="price"]');
            const nHoldVal = nowRow.querySelector('[data-live-now="holdingVal"]');
            const nUnreal = nowRow.querySelector('[data-live-now="unrealized"]');
            const nTotal = nowRow.querySelector('[data-live-now="totalPL"]');

            if (nDate) nDate.textContent = dateStr;
            if (nPrice) { nPrice.textContent = '$' + newPrice.toFixed(6); flashEl(nPrice); }
            if (nHoldVal) { nHoldVal.textContent = formatUSD(newCurrVal, dec); flashEl(nHoldVal); }
            if (nUnreal) {
                nUnreal.textContent = formatPLJS(newUnreal, dec);
                nUnreal.className = plClassJS(newUnreal);
                flashEl(nUnreal);
            }
            if (nTotal) {
                nTotal.textContent = formatPLJS(newTotal, dec);
                nTotal.className = plClassJS(newTotal);
                flashEl(nTotal);
            }
        }

        // Update graph "Now" point and redraw
        if (window._plGraphData && window._drawPLGraph) {
            const gd = window._plGraphData;
            const last = gd[gd.length - 1];
            if (last && last.is_now) {
                last.date = new Date().toISOString().slice(0, 19).replace('T', ' ');
                last.total_pl = Math.round(newTotal * 100) / 100;
                last.unrealized = Math.round(newUnreal * 100) / 100;
            }
            window._drawPLGraph();
        }
    }

    function parsePLText(text) {
        const cleaned = text.replace(/[^0-9.+-]/g, '').trim();
        if (!cleaned) return 0;
        const val = parseFloat(cleaned);
        return text.includes('-$') ? -Math.abs(val) : val;
    }

    let refreshInFlight = false;

    async function refreshPrices() {
        const ids = gatherCmcIds();
        if (!ids.length || refreshInFlight) return;
        refreshInFlight = true;
        try {
            const res = await fetch('api_prices.php?ids=' + ids.join(','), { cache: 'no-store' });
            if (!res.ok) return;
            const quotes = await res.json();
            if (page === 'dashboard') updateDashboard(quotes);
            if (page === 'token') updateTokenPage(quotes);
        } catch (_) {
            /* silent fail — next tick retries */
        } finally {
            refreshInFlight = false;
        }
    }

    if (gatherCmcIds().length > 0) {
        refreshPrices();
        setInterval(refreshPrices, 10000);
    }

    /* ── Token Search ─────────────────────────────────────── */

    const searchInput   = document.getElementById('tokenSearch');
    const searchResults = document.getElementById('searchResults');

    if (!searchInput || !searchResults) return;

    const csrfMeta  = document.querySelector('meta[name="csrf-token"]');
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
        if (e.key === 'Escape') { searchResults.classList.remove('open'); searchInput.blur(); }
    });

    async function fetchTokens(query) {
        try {
            const res  = await fetch('search_tokens.php?q=' + encodeURIComponent(query));
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
                info.innerHTML = '<span class="coin-name">' + escapeHtml(coin.name) + '</span>'
                    + '<span class="coin-symbol">' + escapeHtml(coin.symbol) + '</span>';

                const form = document.createElement('form');
                form.method = 'POST';
                form.action = 'add_token.php';
                form.style.margin = '0';

                [['_csrf', csrfToken], ['cmc_id', coin.id], ['symbol', coin.symbol],
                 ['name', coin.name], ['slug', coin.slug || '']].forEach(([n, v]) => {
                    const inp = document.createElement('input');
                    inp.type = 'hidden'; inp.name = n; inp.value = v;
                    form.appendChild(inp);
                });

                const btn = document.createElement('button');
                btn.type = 'submit';
                btn.className = 'btn btn-primary btn-sm';
                btn.textContent = '+ Add';
                form.appendChild(btn);

                btn.addEventListener('click', e => { e.preventDefault(); e.stopPropagation(); form.submit(); });
                item.appendChild(info);
                item.appendChild(form);
                item.addEventListener('click', e => { if (!e.target.closest('button')) form.submit(); });
                searchResults.appendChild(item);
            });
        } catch (_) {
            searchResults.innerHTML = '<div class="search-no-results">Search failed. Try again.</div>';
        }
    }

    function escapeHtml(s) {
        const d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }
});
