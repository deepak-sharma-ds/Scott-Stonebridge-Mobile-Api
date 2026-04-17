/**
 * Admin Dashboard Module
 * Handles KPI counter animations and top-products rendering.
 * Exported functions are called from dashboard.blade.php via a data attribute.
 */

/**
 * Animates a numeric counter from 0 to end value.
 * @param {HTMLElement} el
 * @param {number} end
 * @param {number} duration  ms
 * @param {string} prefix    e.g. '$'
 * @param {string} suffix    e.g. '%'
 */
export function animateCounter(el, end, duration = 1200, prefix = '', suffix = '') {
    if (!el) return;
    const start = performance.now();

    const step = (now) => {
        const elapsed  = now - start;
        const progress = Math.min(elapsed / duration, 1);
        // Cubic ease-out
        const eased    = 1 - Math.pow(1 - progress, 3);
        const value    = Math.floor(eased * end);
        el.textContent = prefix + value.toLocaleString() + suffix;
        if (progress < 1) requestAnimationFrame(step);
    };

    requestAnimationFrame(step);
}

/**
 * Renders the top-products list into #top-products.
 * @param {Array} products
 */
function renderTopProducts(products) {
    const el = document.getElementById('top-products');
    if (!el) return;

    if (!products?.length) {
        el.innerHTML = `
            <div style="text-align:center;padding:3rem;color:var(--text-muted);">
                <svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="1.5" style="margin:0 auto 1rem;display:block;opacity:0.35;">
                    <path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"></path>
                    <line x1="7" y1="7" x2="7.01" y2="7"></line>
                </svg>
                <p style="font-size:0.9375rem;font-weight:500;margin:0;">No product data available</p>
            </div>`;
        return;
    }

    el.innerHTML = products.map((p, i) => `
        <div style="
            display:flex;justify-content:space-between;align-items:center;
            padding:0.875rem 1rem;border-radius:10px;
            background:#f8fafc;border:1px solid #e2e8f0;
            margin-bottom:0.5rem;
            animation:slideInUp 0.4s ${(i * 0.07).toFixed(2)}s both;
            transition:all 0.2s ease;cursor:default;
        "
        onmouseover="this.style.transform='translateX(4px)';this.style.boxShadow='var(--shadow-md)'"
        onmouseout="this.style.transform='';this.style.boxShadow=''">
            <div style="display:flex;align-items:center;gap:0.75rem;">
                <div style="
                    width:30px;height:30px;border-radius:8px;
                    background:var(--gradient-primary);
                    display:flex;align-items:center;justify-content:center;
                    color:#fff;font-weight:700;font-size:0.75rem;flex-shrink:0;
                ">${i + 1}</div>
                <div>
                    <div style="font-weight:600;color:var(--text-primary);font-size:0.9375rem;">
                        ${p.title ?? 'N/A'}
                    </div>
                    <div style="font-size:0.75rem;color:var(--text-muted);margin-top:0.125rem;">
                        ID: ${p.id ?? 'N/A'}
                    </div>
                </div>
            </div>
            <div style="text-align:right;">
                <div style="
                    font-size:1.25rem;font-weight:800;
                    background:var(--gradient-primary);
                    -webkit-background-clip:text;-webkit-text-fill-color:transparent;
                    background-clip:text;
                ">${p.sales ?? 0}</div>
                <div style="font-size:0.6875rem;color:var(--text-muted);text-transform:uppercase;
                            letter-spacing:0.05em;">Sales</div>
            </div>
        </div>
    `).join('');
}

/**
 * Main dashboard loader — fetches KPIs and top products.
 * @param {{ dashboard: string, topProducts: string }} urls
 */
export async function loadDashboard(urls) {
    try {
        const [kpiRes, topRes] = await Promise.all([
            fetch(urls.dashboard),
            fetch(urls.topProducts),
        ]);

        const kpiJson = await kpiRes.json();
        const topJson = await topRes.json();
        const k = kpiJson.data;

        // Animate KPIs after a short paint delay
        setTimeout(() => {
            animateCounter(document.getElementById('kpi-downloads'),
                k.downloads?.local   ?? k.downloads   ?? 0);
            animateCounter(document.getElementById('kpi-active-users'),
                k.active_users?.local ?? k.active_users ?? 0);
            animateCounter(document.getElementById('kpi-orders'),
                k.orders?.shopify    ?? k.orders      ?? 0);
            animateCounter(document.getElementById('kpi-sales'),
                k.sales?.shopify     ?? k.sales       ?? 0, 1200, '$');
        }, 200);

        renderTopProducts(topJson.data);
    } catch (err) {
        console.error('[Dashboard] Load failed:', err);
        window.adminNotify?.('Failed to load dashboard data', 'error');
    }
}

// Expose globally so Blade inline scripts can call it without a module import
window.loadDashboard = loadDashboard;
window.animateCounter = animateCounter;
