/**
 * Admin Loader Module
 * Shows full-screen overlay when forms are submitted.
 * Disables submit buttons to prevent double submission.
 */
export function initLoader() {
    const overlay = document.getElementById('loaderOverlay');
    if (!overlay) return;

    document.addEventListener('submit', (e) => {
        const form = e.target;
        if (!(form instanceof HTMLFormElement)) return;

        overlay.classList.add('active');

        form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach((btn) => {
            btn.disabled = true;
        });
    });

    // Safety: hide overlay on page show (browser back/forward cache)
    window.addEventListener('pageshow', (e) => {
        if (e.persisted) overlay.classList.remove('active');
    });
}
