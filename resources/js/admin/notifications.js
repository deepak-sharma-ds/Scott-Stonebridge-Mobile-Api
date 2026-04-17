/**
 * Admin Notification System
 * Custom toast notifications that replace Toastr.
 * Provides a global `window.toastr` shim for backward compatibility.
 */

let container = null;

function getContainer() {
    if (!container) {
        container = document.createElement('div');
        container.id = 'adminToastContainer';
        container.className = 'admin-toast-container';
        document.body.appendChild(container);
    }
    return container;
}

const ICONS = {
    success: `<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"></polyline></svg>`,
    error:   `<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>`,
    warning: `<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>`,
    info:    `<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>`,
};

const STYLES = {
    success: { icon: 'background:#d1fae5;color:#059669', bar: 'background:#10b981' },
    error:   { icon: 'background:#fee2e2;color:#dc2626', bar: 'background:#ef4444' },
    warning: { icon: 'background:#fef3c7;color:#d97706', bar: 'background:#f59e0b' },
    info:    { icon: 'background:#dbeafe;color:#2563eb', bar: 'background:#3b82f6' },
};

export function showToast(message, type = 'success', duration = 5000) {
    const c = getContainer();
    const s = STYLES[type] || STYLES.success;
    const icon = ICONS[type] || ICONS.success;

    const toast = document.createElement('div');
    toast.className = 'admin-toast';
    toast.innerHTML = `
        <div class="admin-toast-icon" style="${s.icon}">${icon}</div>
        <div style="flex:1;min-width:0;">
            <p style="margin:0;font-size:0.875rem;font-weight:500;color:var(--text-primary);line-height:1.5;">${message}</p>
        </div>
        <button onclick="this.closest('.admin-toast').remove()"
                style="border:none;background:none;cursor:pointer;color:var(--text-muted);padding:0;display:flex;align-items:center;flex-shrink:0;margin-left:0.5rem;">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <line x1="18" y1="6" x2="6" y2="18"></line>
                <line x1="6" y1="6" x2="18" y2="18"></line>
            </svg>
        </button>
        <div class="admin-toast-progress" style="${s.bar}"></div>
    `;

    c.appendChild(toast);

    const timer = setTimeout(() => {
        if (!toast.parentNode) return;
        toast.classList.add('removing');
        setTimeout(() => toast.remove(), 280);
    }, duration);

    // Allow manual dismiss to cancel the timer
    toast.querySelector('button').addEventListener('click', () => clearTimeout(timer));
}

export function initNotifications() {
    window.addEventListener('admin-notify', (e) => {
        const { message, type, duration } = e.detail;
        showToast(message, type, duration);
    });
}

// Global helper
window.adminNotify = (message, type = 'success') =>
    showToast(message, type);

// Toastr backward-compat shim
window.toastr = {
    success: (msg) => showToast(msg, 'success'),
    error:   (msg) => showToast(msg, 'error'),
    warning: (msg) => showToast(msg, 'warning'),
    info:    (msg) => showToast(msg, 'info'),
    options: {},
};
