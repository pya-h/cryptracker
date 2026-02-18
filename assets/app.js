document.addEventListener('DOMContentLoaded', () => {

    document.querySelectorAll('.alert').forEach(alert => {
        setTimeout(() => {
            alert.style.opacity = '0';
            alert.style.transform = 'translateY(-10px)';
            setTimeout(() => alert.remove(), 400);
        }, 6000);
    });


    document.querySelectorAll('.clickable-row').forEach(row => {
        row.addEventListener('click', (e) => {
            if (e.target.closest('a, button, form')) return;
            const href = row.dataset.href;
            if (href) window.location.href = href;
        });
    });


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
        searchResults.innerHTML = '<div class="search-loading">Searching…</div>';

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

            data.forEach(coin => {
                const item = document.createElement('div');
                item.className = 'search-result-item';

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
