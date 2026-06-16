import { createApp } from 'vue';

const PALETTE = ['#6366f1', '#8b5cf6', '#22d3ee', '#f59e0b', '#34d399', '#f87171', '#38bdf8', '#d946ef'];

async function api(path, opts) {
    const res = await fetch(path, opts);
    let data = {};
    try { data = await res.json(); } catch (e) { /* non-json */ }
    if (!res.ok || data.ok === false) {
        throw new Error(data.error || ('Request failed (HTTP ' + res.status + ')'));
    }
    return data;
}

function fmt(n, d = 2) {
    return Number(n).toLocaleString('en-US', { minimumFractionDigits: d, maximumFractionDigits: d });
}

const App = {
    data() {
        return {
            view: 'dashboard',
            health: null,
            me: null,
            loading: false,
            toast: null,
            toastType: 'success',

            summary: { count: 0, total_price: 0, average_price: 0, currencies: {} },
            recent: [],

            products: [],
            pagination: { page: 1, limit: 20, total: 0, pages: 1 },
            filters: { currency: '', min_price: '', max_price: '', limit: 20 },

            duplicates: [],
            duplicateCount: 0,

            percentage: 10,
            importAsync: false,
            importFile: null,
            importResult: null,
            busy: false,

            chart: null,
        };
    },
    computed: {
        currencyCodes() { return Object.keys(this.summary.currencies || {}); },
        palette() { return PALETTE; },
    },
    methods: {
        fmt,
        notify(msg, type = 'success') {
            this.toast = msg;
            this.toastType = type;
            window.clearTimeout(this._t);
            this._t = window.setTimeout(() => { this.toast = null; }, 4200);
        },
        go(view) {
            this.view = view;
            if (view === 'dashboard') this.loadDashboard();
            if (view === 'products') this.loadProducts(1);
            if (view === 'duplicates') this.loadDuplicates();
        },
        async loadHealth() {
            try { this.health = await api('/health'); } catch (e) { this.health = { status: 'error', database: 'disconnected' }; }
        },
        async loadMe() {
            try { this.me = await api('/app-api/me'); } catch (e) { this.me = null; }
        },
        async loadDashboard() {
            this.loading = true;
            try {
                this.summary = await api('/app-api/summary');
                const p = await api('/app-api/products?limit=5');
                this.recent = p.data;
                const d = await api('/app-api/duplicates');
                this.duplicateCount = d.count;
                this.$nextTick(() => this.renderChart());
            } catch (e) { this.notify(e.message, 'error'); }
            finally { this.loading = false; }
        },
        async loadProducts(page) {
            this.loading = true;
            this.filters.page = page;
            const q = new URLSearchParams();
            q.set('page', page);
            q.set('limit', this.filters.limit);
            if (this.filters.currency) q.set('currency', this.filters.currency);
            if (this.filters.min_price !== '') q.set('min_price', this.filters.min_price);
            if (this.filters.max_price !== '') q.set('max_price', this.filters.max_price);
            try {
                const r = await api('/app-api/products?' + q.toString());
                this.products = r.data;
                this.pagination = r.pagination;
            } catch (e) { this.notify(e.message, 'error'); }
            finally { this.loading = false; }
        },
        resetFilters() {
            this.filters = { currency: '', min_price: '', max_price: '', limit: 20 };
            this.loadProducts(1);
        },
        async loadDuplicates() {
            this.loading = true;
            try {
                const d = await api('/app-api/duplicates');
                this.duplicates = d.data;
                this.duplicateCount = d.count;
            } catch (e) { this.notify(e.message, 'error'); }
            finally { this.loading = false; }
        },
        async ensureCurrencies() {
            if (!this.currencyCodes.length) {
                try { this.summary = await api('/app-api/summary'); } catch (e) { /* ignore */ }
            }
        },
        async adjustPrices() {
            if (this.percentage === '' || isNaN(Number(this.percentage))) {
                this.notify('Enter a numeric percentage.', 'error');
                return;
            }
            this.busy = true;
            try {
                const body = new URLSearchParams({ percentage: String(this.percentage) });
                const r = await api('/app-api/adjust-prices', { method: 'POST', body });
                this.notify('Adjusted ' + r.affected + ' product(s) by ' + (r.percentage > 0 ? '+' : '') + r.percentage + '%.');
                if (this.view === 'dashboard') this.loadDashboard();
            } catch (e) { this.notify(e.message, 'error'); }
            finally { this.busy = false; }
        },
        onFile(e) { this.importFile = e.target.files[0] || null; },
        async runImport() {
            this.busy = true;
            this.importResult = null;
            try {
                const fd = new FormData();
                if (this.importFile) fd.append('feed', this.importFile);
                if (this.importAsync) fd.append('async', '1');
                const r = await api('/app-api/import', { method: 'POST', body: fd });
                this.importResult = r;
                if (r.queued) {
                    this.notify('Import queued — the worker will process it.');
                } else {
                    this.notify('Imported ' + r.imported + ', updated ' + r.updated + ', failed ' + r.failed + '.');
                }
                await this.loadDashboard();
            } catch (e) { this.notify(e.message, 'error'); }
            finally { this.busy = false; }
        },
        setPct(v) { this.percentage = v; },
        renderChart() {
            const el = document.getElementById('currencyChart');
            if (!el || !window.Chart) return;
            const labels = this.currencyCodes;
            const values = labels.map((c) => this.summary.currencies[c]);
            if (this.chart) { this.chart.destroy(); this.chart = null; }
            if (!labels.length) return;
            this.chart = new window.Chart(el, {
                type: 'doughnut',
                data: {
                    labels,
                    datasets: [{
                        data: values,
                        backgroundColor: labels.map((_, i) => PALETTE[i % PALETTE.length]),
                        borderColor: 'rgba(0,0,0,0)',
                        borderWidth: 2,
                        hoverOffset: 6,
                    }],
                },
                options: { responsive: true, maintainAspectRatio: false, cutout: '64%', plugins: { legend: { display: false } } },
            });
        },
    },
    mounted() {
        this.loadHealth();
        this.loadMe();
        this.loadDashboard();
    },
    template: `
<div class="layout">
  <aside class="sidebar">
    <div class="brand">
      <span class="brand-mark">📦</span>
      <div class="brand-text"><strong>Delupe</strong><small>Product Feed · Vue</small></div>
    </div>
    <nav class="nav">
      <a class="nav-link" :class="{'is-active': view==='dashboard'}" @click="go('dashboard')"><span class="nav-ico">▲</span> Dashboard</a>
      <a class="nav-link" :class="{'is-active': view==='products'}" @click="go('products')"><span class="nav-ico">▦</span> Products</a>
      <a class="nav-link" :class="{'is-active': view==='duplicates'}" @click="go('duplicates')"><span class="nav-ico">⧉</span> Duplicates</a>
      <a class="nav-link" :class="{'is-active': view==='tools'}" @click="go('tools')"><span class="nav-ico">⚙</span> Tools</a>
    </nav>
    <div class="sidebar-foot">
      <span class="health-pill" :class="health ? (health.database==='connected' ? 'ok':'down') : ''">
        <span class="dot"></span> {{ health ? (health.database==='connected' ? 'Healthy':'DB down') : 'Health' }}
      </span>
      <a class="api-link" href="/docs" target="_blank" rel="noopener">API Docs (Swagger) ↗</a>
      <div v-if="me && me.username" class="user-box">
        <span class="user-name">👤 {{ me.username }}</span>
        <a class="logout" href="/logout">Sign out</a>
      </div>
    </div>
  </aside>

  <main class="content">
    <transition name="toast">
      <div v-if="toast" class="flash" :class="'flash-'+toastType">{{ toast }}</div>
    </transition>

    <!-- DASHBOARD -->
    <section v-if="view==='dashboard'">
      <header class="topbar"><h1>Dashboard</h1><div class="topbar-meta muted">Overview of the product catalog</div></header>
      <div class="stat-grid">
        <div class="stat-card"><span class="stat-label">Products</span><span class="stat-value">{{ summary.count }}</span><span class="stat-trend">total in catalog</span></div>
        <div class="stat-card accent-violet"><span class="stat-label">Total value</span><span class="stat-value">{{ fmt(summary.total_price) }}</span><span class="stat-trend">sum of all prices</span></div>
        <div class="stat-card accent-cyan"><span class="stat-label">Average price</span><span class="stat-value">{{ fmt(summary.average_price) }}</span><span class="stat-trend">mean across catalog</span></div>
        <div class="stat-card accent-amber"><span class="stat-label">Duplicates</span><span class="stat-value">{{ duplicateCount }}</span><span class="stat-trend"><a @click="go('duplicates')">view collisions →</a></span></div>
      </div>
      <div class="grid-2">
        <div class="card">
          <div class="card-head"><h2>Currency breakdown</h2><span class="muted">{{ currencyCodes.length }} currencies</span></div>
          <p v-if="!currencyCodes.length" class="empty">No products yet. Import a feed from <a @click="go('tools')">Tools</a>.</p>
          <template v-else>
            <div class="chart-wrap"><canvas id="currencyChart"></canvas></div>
            <ul class="legend">
              <li v-for="(code,i) in currencyCodes" :key="code"><span class="legend-dot" :style="{background: palette[i % palette.length]}"></span>{{ code }} <strong>{{ summary.currencies[code] }}</strong></li>
            </ul>
          </template>
        </div>
        <div class="card">
          <div class="card-head"><h2>Recent products</h2><a class="btn btn-ghost btn-sm" @click="go('products')">All products</a></div>
          <p v-if="!recent.length" class="empty">Nothing imported yet.</p>
          <table v-else class="table compact">
            <thead><tr><th>Name</th><th>Currency</th><th class="right">Price</th></tr></thead>
            <tbody>
              <tr v-for="p in recent" :key="p.id">
                <td><a :href="p.link" target="_blank" rel="noopener">{{ p.name }}</a></td>
                <td><span class="tag">{{ p.currency }}</span></td>
                <td class="right mono">{{ fmt(p.price) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </section>

    <!-- PRODUCTS -->
    <section v-else-if="view==='products'">
      <header class="topbar"><h1>Products</h1><div class="topbar-meta muted">{{ pagination.total }} matching · page {{ pagination.page }}/{{ pagination.pages || 1 }}</div></header>
      <form class="filters card" @submit.prevent="loadProducts(1)">
        <div class="field"><label>Currency</label>
          <select v-model="filters.currency">
            <option value="">All</option>
            <option v-for="c in currencyCodes" :key="c" :value="c">{{ c }}</option>
          </select>
        </div>
        <div class="field"><label>Min price</label><input type="number" step="0.01" v-model="filters.min_price" placeholder="0"></div>
        <div class="field"><label>Max price</label><input type="number" step="0.01" v-model="filters.max_price" placeholder="∞"></div>
        <div class="field"><label>Per page</label>
          <select v-model.number="filters.limit">
            <option :value="10">10</option><option :value="20">20</option><option :value="50">50</option><option :value="100">100</option>
          </select>
        </div>
        <div class="field field-actions">
          <button type="submit" class="btn btn-primary">Apply</button>
          <button type="button" class="btn btn-ghost" @click="resetFilters">Reset</button>
        </div>
      </form>
      <div class="card">
        <p v-if="!products.length" class="empty">No products match these filters.</p>
        <table v-else class="table">
          <thead><tr><th>#</th><th>Product</th><th>Merchant</th><th>Currency</th><th class="right">Price</th><th class="right">Original</th><th>Updated</th></tr></thead>
          <tbody>
            <tr v-for="p in products" :key="p.id">
              <td class="muted mono">{{ p.id }}</td>
              <td><a :href="p.link" target="_blank" rel="noopener">{{ p.name }}</a><small class="muted block">{{ p.external_id }}</small></td>
              <td class="mono">{{ p.merchant_id }}</td>
              <td><span class="tag">{{ p.currency }}</span></td>
              <td class="right mono strong">{{ fmt(p.price) }}</td>
              <td class="right mono muted">{{ p.original_price !== null ? fmt(p.original_price) : '—' }}</td>
              <td class="muted">{{ (p.updated_at || '').replace('T',' ').slice(0,16) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
      <nav v-if="pagination.pages > 1" class="pager">
        <button class="btn btn-ghost btn-sm" :disabled="pagination.page<=1" @click="loadProducts(pagination.page-1)">← Prev</button>
        <span class="pager-info">Page {{ pagination.page }} of {{ pagination.pages }}</span>
        <button class="btn btn-ghost btn-sm" :disabled="pagination.page>=pagination.pages" @click="loadProducts(pagination.page+1)">Next →</button>
      </nav>
    </section>

    <!-- DUPLICATES -->
    <section v-else-if="view==='duplicates'">
      <header class="topbar"><h1>Duplicate products</h1><div class="topbar-meta muted">Sharing the same name OR the same link</div></header>
      <div class="card">
        <div class="card-head"><h2>{{ duplicateCount }} product(s) involved in a collision</h2></div>
        <p v-if="!duplicates.length" class="empty">🎉 No duplicates found — every name and link is unique.</p>
        <table v-else class="table">
          <thead><tr><th>#</th><th>Name</th><th>Link</th><th>Merchant</th><th class="right">Price</th></tr></thead>
          <tbody>
            <tr v-for="p in duplicates" :key="p.id">
              <td class="muted mono">{{ p.id }}</td>
              <td class="strong">{{ p.name }}</td>
              <td class="mono small"><a :href="p.link" target="_blank" rel="noopener">{{ p.link }}</a></td>
              <td class="mono">{{ p.merchant_id }}</td>
              <td class="right mono">{{ fmt(p.price) }} {{ p.currency }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <!-- TOOLS -->
    <section v-else-if="view==='tools'">
      <header class="topbar"><h1>Tools</h1><div class="topbar-meta muted">Import feeds &amp; adjust prices</div></header>
      <div class="grid-2">
        <div class="card">
          <div class="card-head"><h2>Import products</h2><span class="tag">{{ summary.count }} in catalog</span></div>
          <p class="muted">Upload a JSON feed, or import the bundled <code>products.json</code> sample. New products are inserted, existing ones updated, invalid rows skipped.</p>
          <div class="stack">
            <div class="field"><label>JSON file <small class="muted">(optional — leave empty for the sample)</small></label>
              <input type="file" accept="application/json,.json" @change="onFile"></div>
            <label class="check"><input type="checkbox" v-model="importAsync"> Process asynchronously (queue → worker; sample only)</label>
            <button class="btn btn-primary" :disabled="busy" @click="runImport">{{ busy ? 'Working…' : 'Run import' }}</button>
          </div>
          <div v-if="importResult && !importResult.queued" class="import-summary">
            <span class="chip ok">{{ importResult.imported }} new</span>
            <span class="chip">{{ importResult.updated }} updated</span>
            <span class="chip bad">{{ importResult.failed }} failed</span>
          </div>
        </div>
        <div class="card">
          <div class="card-head"><h2>Adjust prices</h2></div>
          <p class="muted">Change every product price by a percentage. The pre-adjustment price is preserved in <code>original_price</code> (captured once).</p>
          <div class="stack">
            <div class="field"><label>Percentage change</label>
              <div class="input-affix"><input type="number" step="0.01" v-model="percentage" placeholder="10"><span class="affix">%</span></div>
              <small class="muted">Use a negative value to reduce, e.g. <code>-5</code>.</small>
            </div>
            <div class="quick-pills">
              <button v-for="v in [-10,-5,5,10,25]" :key="v" type="button" class="pill" @click="setPct(v)">{{ v>0?'+':'' }}{{ v }}%</button>
            </div>
            <button class="btn btn-primary" :disabled="busy" @click="adjustPrices">Apply to all products</button>
          </div>
        </div>
      </div>
      <div class="card">
        <div class="card-head"><h2>JSON API reference</h2></div>
        <p class="muted">External <code>/api/*</code> endpoints require the <code>X-API-Key</code> header. The dashboard above uses the same-origin <code>/app-api/*</code> BFF instead.</p>
        <table class="table compact">
          <thead><tr><th>Method</th><th>Endpoint</th><th>Purpose</th></tr></thead>
          <tbody>
            <tr><td><span class="tag">GET</span></td><td class="mono">/api/products</td><td>List + pagination + filters</td></tr>
            <tr><td><span class="tag">GET</span></td><td class="mono">/api/products/summary</td><td>Counts, totals, currencies</td></tr>
            <tr><td><span class="tag">GET</span></td><td class="mono">/api/products/duplicates</td><td>Same name or link</td></tr>
            <tr><td><span class="tag">GET</span></td><td class="mono">/health</td><td>Liveness + DB status (public)</td></tr>
          </tbody>
        </table>
      </div>
    </section>

    <footer class="foot"><span>{{ 'Delupe Product Feed' }} · Vue 3 SPA</span></footer>
  </main>
</div>`,
};

createApp(App).mount('#app');
