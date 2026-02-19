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

    const exportJsonBtn = document.getElementById('exportJson');

    function triggerDownload(url) {
        const iframe = document.createElement('iframe');
        iframe.style.display = 'none';
        iframe.src = url;
        document.body.appendChild(iframe);
        setTimeout(() => iframe.remove(), 15000);
    }

    if (exportJsonBtn) {
        exportJsonBtn.addEventListener('click', () => {
            if (menuWrap) menuWrap.classList.remove('open');
            const main = document.querySelector('main[data-page]');
            if (!main) return;

            const page = main.dataset.page;
            if (page === 'dashboard') {
                const url = 'export_json.php?page=dashboard';
                triggerDownload(url);
                return;
            }

            if (page === 'token') {
                const tokenId = encodeURIComponent(main.dataset.tokenId || '');
                if (!tokenId) return;
                triggerDownload('export_json.php?page=token&id=' + tokenId + '&part=transactions');
                setTimeout(() => {
                    triggerDownload('export_json.php?page=token&id=' + tokenId + '&part=analytics');
                }, 350);
            }
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

    function showToast(message, type = 'info') {
        if (!message) return;

        let stack = document.getElementById('toastStack');
        if (!stack) {
            stack = document.createElement('div');
            stack.id = 'toastStack';
            stack.className = 'toast-stack';
            document.body.appendChild(stack);
        }

        const toast = document.createElement('div');
        toast.className = 'app-toast app-toast-' + type;
        toast.textContent = message;
        stack.appendChild(toast);

        requestAnimationFrame(() => toast.classList.add('show'));

        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => toast.remove(), 250);
        }, 4200);
    }

    function sourceLabel(source) {
        const normalized = (source || '').toLowerCase();
        if (normalized === 'coinlore') return 'CoinLore';
        if (normalized === 'coingecko') return 'CoinGecko';
        return 'CoinMarketCap';
    }

    function updateSourceIndicator(meta) {
        if (!meta) return;

        const indicator = document.getElementById('sourceIndicator');
        if (!indicator) return;

        const preferredAfter = (meta.preferred_source_after || '').toLowerCase();
        const usedSource = (meta.used_source || '').toLowerCase();
        const fallbackActive = !!meta.fallback_used && usedSource !== '' && preferredAfter !== '' && usedSource !== preferredAfter;

        const textEl = indicator.querySelector('.source-text');
        if (textEl) {
            textEl.textContent = sourceLabel(preferredAfter || indicator.dataset.selectedSource || 'coinmarketcap');
        }

        indicator.dataset.selectedSource = preferredAfter || indicator.dataset.selectedSource || 'coinmarketcap';
        indicator.classList.toggle('fallback-active', fallbackActive);
        indicator.title = fallbackActive
            ? ('Using fallback: ' + sourceLabel(usedSource) + ' (preferred: ' + sourceLabel(preferredAfter) + ')')
            : ('Preferred source: ' + sourceLabel(preferredAfter || indicator.dataset.selectedSource));
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

        // Keep the current price data attribute updated for future calculations
        main.dataset.currentPrice = newPrice;
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
            // Find the NOW point (may not be the last point if future points exist)
            const nowPoint = gd.find(d => d.is_now);
            if (nowPoint) {
                nowPoint.date = new Date().toISOString().slice(0, 19).replace('T', ' ');
                nowPoint.total_pl = Math.round(newTotal * 100) / 100;
                nowPoint.unrealized = Math.round(newUnreal * 100) / 100;
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
    let lastAutoSwitchToken = '';

    async function refreshPrices() {
        const ids = gatherCmcIds();
        if (!ids.length || refreshInFlight) return;
        refreshInFlight = true;
        try {
            const res = await fetch('api_prices.php?ids=' + ids.join(','), { cache: 'no-store' });
            if (!res.ok) return;
            const payload = await res.json();
            const quotes = payload && payload.quotes ? payload.quotes : payload;
            const meta = payload && payload.meta ? payload.meta : null;

            if (meta && meta.auto_switched) {
                const token = (meta.preferred_source_before || '') + '>' + (meta.auto_switched_to || '') + ':' + (meta.toast_message || '');
                if (token !== lastAutoSwitchToken) {
                    lastAutoSwitchToken = token;
                    showToast(meta.toast_message || 'Price source auto-switched after repeated failures.', 'info');
                }
            }

            updateSourceIndicator(meta);

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

    if (searchInput && searchResults) {
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
                     ['name', coin.name], ['slug', coin.slug || ''],
                     ['coinlore_id', coin.coinlore_id || ''], ['coingecko_id', coin.coingecko_id || '']].forEach(([n, v]) => {
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
    }

    /* ═══════════════════════════════════════════════════════
       ── Future P/L Preview & Future Graph/Table Points ───
       Calculates and displays what-if scenarios when user
       enters values in Buy/Sell forms on the token page.
       ═══════════════════════════════════════════════════════ */

    if (page === 'token') {
        const buyAmountInput  = document.getElementById('buyAmount');
        const buyPriceInput   = document.getElementById('buyPrice');
        const sellAmountInput = document.getElementById('sellAmount');
        const sellPriceInput  = document.getElementById('sellPrice');
        const buyPreview      = document.getElementById('buyPreview');
        const sellPreview     = document.getElementById('sellPreview');

        if (buyAmountInput && buyPriceInput && sellAmountInput && sellPriceInput) {
            const plMode     = main.dataset.plMode || 'fifo';
            const symbol     = main.dataset.symbol || '???';
            const rawTxs     = JSON.parse(main.dataset.transactions || '[]');

            // Track the order futures were created
            // Each entry: { type: 'buy'|'sell', order: number }
            let futureSequence = []; // ordered list of active future types
            let buyInputOrder  = 0;   // sequence counter when buy amount first entered
            let sellInputOrder = 0;   // sequence counter when sell amount first entered
            let sequenceCounter = 0;

            function getBuyAmount()  { return parseFloat(buyAmountInput.value) || 0; }
            function getBuyPrice()   { return parseFloat(buyPriceInput.value) || 0; }
            function getSellAmount() { return parseFloat(sellAmountInput.value) || 0; }
            function getSellPrice()  { return parseFloat(sellPriceInput.value) || 0; }

            function getCurrentPrice() { return parseFloat(main.dataset.currentPrice) || 0; }

            // ── P/L Calculation Engine (mirrors PHP logic) ──────────

            function calcAvg(txs, currentPrice) {
                let totalBought = 0, totalSpent = 0, totalSold = 0, realizedPL = 0;
                let runBuyAmt = 0, runBuyCost = 0, runHoldings = 0, runRealized = 0;
                const timeline = [];

                for (const tx of txs) {
                    let txRealized = 0;
                    if (tx.type === 'buy') {
                        totalBought += tx.amount;
                        totalSpent  += tx.total_value;
                        runBuyAmt   += tx.amount;
                        runBuyCost  += tx.total_value;
                        runHoldings += tx.amount;
                    } else {
                        totalSold   += tx.amount;
                        const runAvg = runBuyAmt > 0 ? runBuyCost / runBuyAmt : 0;
                        txRealized   = tx.amount * (tx.price_per_unit - runAvg);
                        realizedPL  += txRealized;
                        runRealized += txRealized;
                        runHoldings -= tx.amount;
                    }

                    const runAvg       = runBuyAmt > 0 ? runBuyCost / runBuyAmt : 0;
                    const runCostBasis = runHoldings * runAvg;
                    const runCurrValue = runHoldings * tx.price_per_unit;
                    const runUnreal    = runCurrValue - runCostBasis;
                    const runTotalPL   = runRealized + runUnreal;

                    timeline.push({
                        date: tx.created_at, type: tx.type, amount: tx.amount,
                        ppu: tx.price_per_unit, total: tx.total_value,
                        realized: txRealized, holdings: runHoldings, avg_cost: runAvg,
                        cum_realized: runRealized, unrealized: runUnreal, total_pl: runTotalPL,
                    });
                }

                const holdings = Math.max(0, totalBought - totalSold);
                const avgBuy   = totalBought > 0 ? totalSpent / totalBought : 0;
                const costBasis= holdings * avgBuy;
                const currValue= holdings * currentPrice;
                const unrealPL = currValue - costBasis;

                return { holdings, avg_buy: avgBuy, cost_basis: costBasis,
                         current_value: currValue, realized_pl: realizedPL,
                         unrealized_pl: unrealPL, total_pl: realizedPL + unrealPL,
                         total_spent: totalSpent, timeline };
            }

            function calcFifo(txs, currentPrice) {
                const lots = [];
                let realizedPL = 0, totalSpent = 0, runRealized = 0;
                const timeline = [];

                for (const tx of txs) {
                    let txRealized = 0;

                    if (tx.type === 'buy') {
                        lots.push({ amount: tx.amount, price: tx.price_per_unit });
                        totalSpent += tx.total_value;
                    } else {
                        let remaining = tx.amount;
                        let sellCost  = 0;

                        while (remaining > 1e-10 && lots.length > 0) {
                            const take = Math.min(remaining, lots[0].amount);
                            sellCost += take * lots[0].price;
                            lots[0].amount -= take;
                            remaining -= take;
                            if (lots[0].amount < 1e-10) lots.shift();
                        }

                        txRealized  = (tx.amount * tx.price_per_unit) - sellCost;
                        realizedPL += txRealized;
                        runRealized += txRealized;
                    }

                    const holdings = lots.reduce((s, l) => s + l.amount, 0);
                    let costBasis  = lots.reduce((s, l) => s + l.amount * l.price, 0);
                    const avgCost  = holdings > 1e-10 ? costBasis / holdings : 0;
                    const runCurrValue  = holdings * tx.price_per_unit;
                    const runUnreal     = runCurrValue - costBasis;
                    const runTotalPL    = runRealized + runUnreal;

                    timeline.push({
                        date: tx.created_at, type: tx.type, amount: tx.amount,
                        ppu: tx.price_per_unit, total: tx.total_value,
                        realized: txRealized, holdings, avg_cost: avgCost,
                        cum_realized: runRealized, unrealized: runUnreal, total_pl: runTotalPL,
                    });
                }

                const holdings = lots.reduce((s, l) => s + l.amount, 0);
                const costBasis= lots.reduce((s, l) => s + l.amount * l.price, 0);
                const avgBuy   = holdings > 1e-10 ? costBasis / holdings : 0;
                const currValue= holdings * currentPrice;
                const unrealPL = currValue - costBasis;

                return { holdings, avg_buy: avgBuy, cost_basis: costBasis,
                         current_value: currValue, realized_pl: realizedPL,
                         unrealized_pl: unrealPL, total_pl: realizedPL + unrealPL,
                         total_spent: totalSpent, timeline };
            }

            function calcPL(txs, currentPrice) {
                return plMode === 'fifo' ? calcFifo(txs, currentPrice) : calcAvg(txs, currentPrice);
            }

            // ── Build synthetic transactions ──────────────────────

            function normalizeTxs(rawArr) {
                return rawArr.map(tx => ({
                    type: tx.type,
                    amount: parseFloat(tx.amount) || 0,
                    price_per_unit: parseFloat(tx.price_per_unit) || 0,
                    total_value: parseFloat(tx.total_value) || 0,
                    realized_pl: parseFloat(tx.realized_pl) || 0,
                    created_at: tx.created_at || '',
                }));
            }

            const baseTxs = normalizeTxs(rawTxs);

            function buildFutureTxList() {
                const futureTxs = [];

                for (const ft of futureSequence) {
                    if (ft === 'buy') {
                        const amt = getBuyAmount();
                        const ppu = getBuyPrice();
                        if (amt > 0 && ppu > 0) {
                            futureTxs.push({
                                type: 'buy', amount: amt, price_per_unit: ppu,
                                total_value: amt * ppu, realized_pl: 0,
                                created_at: new Date().toISOString().slice(0, 19).replace('T', ' '),
                                is_future: true, future_type: 'buy',
                            });
                        }
                    } else {
                        const amt = getSellAmount();
                        const ppu = getSellPrice();
                        if (amt > 0 && ppu > 0) {
                            futureTxs.push({
                                type: 'sell', amount: amt, price_per_unit: ppu,
                                total_value: amt * ppu, realized_pl: 0,
                                created_at: new Date().toISOString().slice(0, 19).replace('T', ' '),
                                is_future: true, future_type: 'sell',
                            });
                        }
                    }
                }

                return futureTxs;
            }

            // ── Update previews and future table/graph points ─────

            function updateFuturePreviews() {
                const buyAmt  = getBuyAmount();
                const buyPpu  = getBuyPrice();
                const sellAmt = getSellAmount();
                const sellPpu = getSellPrice();

                const currentPrice = getCurrentPrice();

                // Base state (no futures)
                const baseResult = calcPL(baseTxs, currentPrice);

                // ── Buy preview ──
                if (buyAmt > 0 && buyPpu > 0) {
                    // Calculate state after all futures up to and including this buy
                    const txsWithBuy = [...baseTxs];
                    // Add all futures before this buy in sequence
                    for (const ft of futureSequence) {
                        if (ft === 'buy') {
                            txsWithBuy.push({
                                type: 'buy', amount: buyAmt, price_per_unit: buyPpu,
                                total_value: buyAmt * buyPpu, realized_pl: 0,
                                created_at: new Date().toISOString().slice(0, 19).replace('T', ' '),
                            });
                            break; // Stop at this buy
                        } else if (ft === 'sell' && sellAmt > 0 && sellPpu > 0) {
                            txsWithBuy.push({
                                type: 'sell', amount: sellAmt, price_per_unit: sellPpu,
                                total_value: sellAmt * sellPpu, realized_pl: 0,
                                created_at: new Date().toISOString().slice(0, 19).replace('T', ' '),
                            });
                        }
                    }
                    const afterBuy = calcPL(txsWithBuy, currentPrice);

                    buyPreview.style.display = '';
                    document.getElementById('buyPreviewUnrealized').textContent = formatPLJS(afterBuy.unrealized_pl, 2);
                    document.getElementById('buyPreviewUnrealized').className = 'val ' + plClassJS(afterBuy.unrealized_pl);
                    document.getElementById('buyPreviewHoldings').textContent = afterBuy.holdings.toFixed(6) + ' ' + symbol;
                    document.getElementById('buyPreviewAvgCost').textContent = '$' + afterBuy.avg_buy.toFixed(6);
                } else {
                    buyPreview.style.display = 'none';
                }

                // ── Sell preview ──
                if (sellAmt > 0 && sellPpu > 0) {
                    // Calculate state after all futures up to and including this sell
                    const txsWithSell = [...baseTxs];
                    for (const ft of futureSequence) {
                        if (ft === 'sell') {
                            txsWithSell.push({
                                type: 'sell', amount: sellAmt, price_per_unit: sellPpu,
                                total_value: sellAmt * sellPpu, realized_pl: 0,
                                created_at: new Date().toISOString().slice(0, 19).replace('T', ' '),
                            });
                            break;
                        } else if (ft === 'buy' && buyAmt > 0 && buyPpu > 0) {
                            txsWithSell.push({
                                type: 'buy', amount: buyAmt, price_per_unit: buyPpu,
                                total_value: buyAmt * buyPpu, realized_pl: 0,
                                created_at: new Date().toISOString().slice(0, 19).replace('T', ' '),
                            });
                        }
                    }
                    const afterSell = calcPL(txsWithSell, currentPrice);

                    const sellRealizedDelta = afterSell.realized_pl - baseResult.realized_pl;

                    sellPreview.style.display = '';
                    document.getElementById('sellPreviewRealized').textContent = formatPLJS(sellRealizedDelta, 2);
                    document.getElementById('sellPreviewRealized').className = 'val ' + plClassJS(sellRealizedDelta);
                    document.getElementById('sellPreviewHoldings').textContent = afterSell.holdings.toFixed(6) + ' ' + symbol;
                    document.getElementById('sellPreviewCumRealized').textContent = formatPLJS(afterSell.realized_pl, 2);
                    document.getElementById('sellPreviewCumRealized').className = 'val ' + plClassJS(afterSell.realized_pl);
                } else {
                    sellPreview.style.display = 'none';
                }

                // ── Update future table rows and graph ──
                updateFutureTableAndGraph();
            }

            function updateFutureTableAndGraph() {
                const currentPrice = getCurrentPrice();
                const futureTxs = buildFutureTxList();

                // Remove old future rows from analytics table
                document.querySelectorAll('.future-row').forEach(r => r.remove());

                // Remove old future points from graph data
                if (window._plGraphData) {
                    window._plGraphData = window._plGraphData.filter(p => !p.is_future);
                }

                if (futureTxs.length === 0) {
                    if (window._drawPLGraph) window._drawPLGraph();
                    return;
                }

                // Calculate the full state with all future transactions
                const allTxs = [...baseTxs, ...futureTxs];
                const fullResult = calcPL(allTxs, currentPrice);
                const fullTimeline = fullResult.timeline;

                // The future entries are the last N entries in the timeline
                const futureTimelineEntries = fullTimeline.slice(baseTxs.length);

                // Insert future rows into the analytics table after the Now row
                const nowRow = document.querySelector('tr[data-live-now="1"]');
                const tableBody = nowRow ? nowRow.parentElement : null;

                if (tableBody && nowRow) {
                    let insertAfter = nowRow;

                    futureTimelineEntries.forEach((entry, idx) => {
                        const tr = document.createElement('tr');
                        tr.className = 'future-row';
                        const futureLabel = futureTxs[idx].future_type === 'buy' ? 'Future Buy' : 'Future Sell';
                        const badgeClass = futureTxs[idx].future_type === 'buy' ? 'badge-future-buy' : 'badge-future-sell';

                        tr.innerHTML =
                            '<td>' + futureLabel + '</td>' +
                            '<td><span class="badge ' + badgeClass + '">'
                                + (futureTxs[idx].future_type === 'buy' ? '+' : '-')
                                + entry.amount.toFixed(6) + '</span></td>' +
                            '<td>$' + entry.ppu.toFixed(6) + '</td>' +
                            '<td>' + formatUSD(entry.total, 2) + '</td>' +
                            '<td>' + entry.holdings.toFixed(6) + '</td>' +
                            '<td>$' + entry.avg_cost.toFixed(6) + '</td>' +
                            '<td class="' + plClassJS(entry.realized) + '">'
                                + (entry.type === 'sell' ? formatPLJS(entry.realized, 2) : '\u2013') + '</td>' +
                            '<td class="' + plClassJS(entry.cum_realized) + '">' + formatPLJS(entry.cum_realized, 2) + '</td>' +
                            '<td class="' + plClassJS(entry.unrealized) + '">' + formatPLJS(entry.unrealized, 2) + '</td>' +
                            '<td class="' + plClassJS(entry.total_pl) + '">' + formatPLJS(entry.total_pl, 2) + '</td>';

                        insertAfter.after(tr);
                        insertAfter = tr;
                    });
                }

                // Update graph data with future points
                if (window._plGraphData) {
                    futureTimelineEntries.forEach(entry => {
                        window._plGraphData.push({
                            date: entry.date,
                            total_pl: Math.round(entry.total_pl * 100) / 100,
                            unrealized: Math.round(entry.unrealized * 100) / 100,
                            cum_realized: Math.round(entry.cum_realized * 100) / 100,
                            is_future: true,
                        });
                    });

                    if (window._drawPLGraph) window._drawPLGraph();
                }
            }

            // ── Sequence management ───────────────────────────────
            // Track the order that user enters values in forms

            function updateSequence(type, hasValue) {
                if (hasValue) {
                    // If this type is not in the sequence, add it
                    if (!futureSequence.includes(type)) {
                        futureSequence.push(type);
                    }
                } else {
                    // Remove this type from the sequence
                    futureSequence = futureSequence.filter(t => t !== type);
                }
            }

            function onBuyInputChange() {
                const amt = getBuyAmount();
                const ppu = getBuyPrice();
                updateSequence('buy', amt > 0 && ppu > 0);
                updateFuturePreviews();
            }

            function onSellInputChange() {
                const amt = getSellAmount();
                const ppu = getSellPrice();
                updateSequence('sell', amt > 0 && ppu > 0);
                updateFuturePreviews();
            }

            buyAmountInput.addEventListener('input', onBuyInputChange);
            buyPriceInput.addEventListener('input', onBuyInputChange);
            sellAmountInput.addEventListener('input', onSellInputChange);
            sellPriceInput.addEventListener('input', onSellInputChange);
        }
    }
});
