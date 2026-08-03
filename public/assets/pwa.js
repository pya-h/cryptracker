/**
 * PWA bootstrap: registers the service worker on every page (including the
 * login/register screens, so the app installs and works offline from first use).
 *
 * The registration path is relative to the page, and since all entry points
 * live in the same directory, it resolves to the app root — giving the service
 * worker scope over the whole app whether it's hosted at the domain root or in
 * a subfolder.
 */
(function () {
    if (!('serviceWorker' in navigator)) return;

    window.addEventListener('load', function () {
        navigator.serviceWorker.register('service-worker.js').catch(function (err) {
            console.warn('[CrypTracker] Service worker registration failed:', err);
        });
    });
})();
