/**
 * Admin Confirm Module
 * Handles [data-confirm] attribute for delete confirmations.
 * Replaces inline onsubmit="return confirm(...)" patterns.
 */
export function initConfirm() {
    document.addEventListener('click', (e) => {
        const trigger = e.target.closest('[data-confirm]');
        if (!trigger) return;

        e.preventDefault();
        e.stopPropagation();

        const message = trigger.dataset.confirm || 'Are you sure? This action cannot be undone.';

        if (!window.confirm(message)) return;

        const form = trigger.closest('form');
        if (form) {
            // Remove data-confirm to prevent loop, then submit
            trigger.removeAttribute('data-confirm');
            form.requestSubmit ? form.requestSubmit() : form.submit();
        } else if (trigger.tagName === 'A' && trigger.href) {
            window.location.href = trigger.href;
        }
    });
}
