/**
 * Admin Panel — Main Entry Point
 *
 * Imports:
 *  - Alpine.js  (UI reactivity: sidebar, dropdowns, accordion)
 *  - All admin modules (notifications, loader, confirm, dashboard)
 *
 * This file is compiled by Vite and loaded only in admin pages
 * via @vite(['resources/js/admin/app.js']) in js_scripts.blade.php.
 */

import Alpine from 'alpinejs';
import { initNotifications } from './notifications.js';
import { initLoader }        from './loader.js';
import { initConfirm }       from './confirm.js';

/* ─────────────────────────────────────────────
   Alpine Root Component: adminApp
   Powers sidebar toggle, user dropdown.
───────────────────────────────────────────── */
Alpine.data('adminApp', () => ({
    /** Desktop: sidebar collapsed state (persisted to localStorage) */
    sidebarCollapsed: localStorage.getItem('admin_sidebar_collapsed') === 'true',

    /** Mobile: sidebar visible state */
    sidebarMobileOpen: false,

    init() {
        // Persist collapsed state across page loads
        this.$watch('sidebarCollapsed', (val) =>
            localStorage.setItem('admin_sidebar_collapsed', val)
        );

        // Close mobile sidebar on ESC
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') this.sidebarMobileOpen = false;
        });
    },

    toggleSidebar() {
        if (window.innerWidth <= 1024) {
            this.sidebarMobileOpen = !this.sidebarMobileOpen;
        } else {
            this.sidebarCollapsed = !this.sidebarCollapsed;
        }
    },

    closeMobileSidebar() {
        this.sidebarMobileOpen = false;
    },
}));

/* ─────────────────────────────────────────────
   Boot Alpine
───────────────────────────────────────────── */
window.Alpine = Alpine;
Alpine.start();

/* ─────────────────────────────────────────────
   Initialize utility modules after DOM ready
───────────────────────────────────────────── */
document.addEventListener('DOMContentLoaded', () => {
    initNotifications();
    initLoader();
    initConfirm();
});

/* ─────────────────────────────────────────────
   Global axios CSRF setup (matching bootstrap.js)
───────────────────────────────────────────── */
import axios from 'axios';
axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
const token = document.head.querySelector('meta[name="csrf-token"]');
if (token) {
    axios.defaults.headers.common['X-CSRF-TOKEN'] = token.content;
}
window.axios = axios;
