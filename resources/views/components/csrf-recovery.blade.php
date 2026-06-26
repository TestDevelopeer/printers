@once
    <script>
        (function () {
            if (window.__csrfRecoveryInstalled) {
                return;
            }
            window.__csrfRecoveryInstalled = true;

            let reloading = false;

            const showReloadToast = function () {
                if (document.getElementById('csrf-recovery-toast')) {
                    return;
                }
                const toast = document.createElement('div');
                toast.id = 'csrf-recovery-toast';
                toast.setAttribute('role', 'status');
                toast.style.cssText = [
                    'position: fixed',
                    'right: 1rem',
                    'bottom: 1rem',
                    'z-index: 99999',
                    'max-width: 28rem',
                    'padding: 0.875rem 1rem',
                    'border-radius: 0.5rem',
                    'background: #1f2937',
                    'color: #f9fafb',
                    'box-shadow: 0 10px 25px -5px rgba(0,0,0,0.3)',
                    'font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif',
                    'font-size: 0.875rem',
                    'line-height: 1.4',
                ].join(';');
                toast.textContent = 'Сессия устарела — страница перезагружается…';
                document.body.appendChild(toast);
            };

            const recover = function (source) {
                if (reloading) {
                    return;
                }
                reloading = true;
                showReloadToast();
                try {
                    window.sessionStorage.setItem('csrf_recovery', String(Date.now()));
                } catch (e) {
                    // ignore
                }
                if (typeof window.Livewire !== 'undefined' && typeof window.Livewire.restart === 'function') {
                    try { window.Livewire.restart(); } catch (e) { /* noop */ }
                }
                const url = new URL(window.location.href);
                url.searchParams.set('_t', String(Date.now()));
                window.location.replace(url.toString());
            };

            const isLivewireRequest = function (url) {
                return typeof url === 'string' && url.indexOf('/livewire') !== -1;
            };

            // Intercept fetch (Livewire uses fetch under the hood).
            const originalFetch = window.fetch.bind(window);
            window.fetch = function (input, init) {
                return originalFetch(input, init).then(function (response) {
                    if (response && response.status === 419) {
                        const requestUrl = (typeof input === 'string')
                            ? input
                            : (input && input.url) ? input.url : '';
                        if (isLivewireRequest(requestUrl)) {
                            recover('fetch:' + requestUrl);
                        }
                    }
                    return response;
                });
            };

            // Intercept XMLHttpRequest (Livewire falls back to XHR for uploads / some flows).
            const originalXhrOpen = XMLHttpRequest.prototype.open;
            const originalXhrSend = XMLHttpRequest.prototype.send;
            XMLHttpRequest.prototype.open = function (method, url) {
                this.__csrfWatchedUrl = url;
                return originalXhrOpen.apply(this, arguments);
            };
            XMLHttpRequest.prototype.send = function () {
                this.addEventListener('loadend', function () {
                    if (this.status === 419 && isLivewireRequest(this.__csrfWatchedUrl)) {
                        recover('xhr:' + this.__csrfWatchedUrl);
                    }
                });
                return originalXhrSend.apply(this, arguments);
            };

            // Livewire v4 event hook (best-effort): handle a hard 419 on the next user action.
            document.addEventListener('livewire:init', function () {
                if (window.Livewire && typeof window.Livewire.on === 'function') {
                    window.Livewire.on('csrfMismatch', function () {
                        recover('event:csrfMismatch');
                    });
                }
            });
        })();
    </script>
@endonce