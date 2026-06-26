<?php
/**
 * Tenant host management (tenancy::hosts/index). Hydrates over AJAX from
 * GET /ajx/tenant/hosts. Registers a host (returns a DNS TXT challenge),
 * verifies it, promotes a verified host to primary, or removes it.
 */
?>
<div class="card">
    <h2>Add a custom domain</h2>
    <p class="muted">Register a hostname for the current tenant, publish the DNS
       challenge we return, then verify it.</p>

    <form id="add-form" autocomplete="off">
        <label for="hostname">Hostname</label>
        <input id="hostname" name="hostname" placeholder="app.example.com" required>
        <div class="field-error" data-for="hostname"></div>

        <label for="ip_address">Expected A record (optional)</label>
        <input id="ip_address" name="ip_address" placeholder="203.0.113.10">
        <div class="field-error" data-for="ip_address"></div>

        <div class="actions">
            <button class="btn btn-primary" type="submit">Register host</button>
        </div>
    </form>

    <div id="dns-instructions" hidden>
        <h3>Publish this DNS record</h3>
        <pre id="dns-record"></pre>
        <p class="muted" id="dns-help"></p>
    </div>
</div>

<div class="card">
    <h2>Hosts</h2>
    <p class="muted">Loaded from <code>GET /ajx/tenant/hosts</code>.</p>

    <table>
        <thead>
            <tr><th>Hostname</th><th>Status</th><th>Primary</th><th>Verified</th><th></th></tr>
        </thead>
        <tbody id="rows">
            <tr><td colspan="5" class="muted">Loading…</td></tr>
        </tbody>
    </table>

    <div class="actions">
        <button class="btn" type="button" id="reload">Reload</button>
    </div>
</div>

<template id="row-tpl">
    <tr>
        <td class="c-host"></td>
        <td><span class="badge c-status"></span></td>
        <td class="c-primary"></td>
        <td class="c-verified muted"></td>
        <td>
            <button class="btn btn-sm c-verify" type="button">Verify</button>
            <button class="btn btn-sm c-primary-btn" type="button">Make primary</button>
            <button class="btn btn-sm btn-danger c-del" type="button">Delete</button>
        </td>
    </tr>
</template>

<script>
(function () {
    const App = window.TenancyApp;
    const tbody = document.getElementById('rows');
    const tpl = document.getElementById('row-tpl');
    const form = document.getElementById('add-form');
    const dnsBox = document.getElementById('dns-instructions');

    function clearErrors() {
        document.querySelectorAll('.field-error').forEach(el => el.textContent = '');
    }

    function showInstructions(ins) {
        const rec = ins.dns_record || {};
        document.getElementById('dns-record').textContent =
            rec.type + '  ' + rec.name + '  "' + rec.value + '"  (TTL ' + rec.ttl + ')';
        document.getElementById('dns-help').textContent = ins.instructions || '';
        dnsBox.hidden = false;
    }

    function render(hosts) {
        tbody.innerHTML = '';
        if (!hosts.length) {
            tbody.innerHTML = '<tr><td colspan="5" class="muted">No hosts registered yet.</td></tr>';
            return;
        }
        for (const h of hosts) {
            const row = tpl.content.cloneNode(true);
            row.querySelector('.c-host').textContent = h.hostname;
            const status = row.querySelector('.c-status');
            status.textContent = h.status;
            status.classList.add(String(h.status).toLowerCase());
            row.querySelector('.c-primary').innerHTML = h.is_primary
                ? '<span class="badge primary">primary</span>' : '';
            row.querySelector('.c-verified').textContent =
                h.verified_at ? new Date(h.verified_at).toLocaleString() : '—';
            row.querySelector('.c-verify').addEventListener('click', () => verify(h));
            row.querySelector('.c-primary-btn').addEventListener('click', () => makePrimary(h));
            row.querySelector('.c-del').addEventListener('click', () => destroy(h));
            tbody.appendChild(row);
        }
    }

    async function load() {
        tbody.innerHTML = '<tr><td colspan="5" class="muted">Loading…</td></tr>';
        try {
            const res = await App.hosts();
            render(res.data || []);
        } catch (e) {
            tbody.innerHTML = '<tr><td colspan="5"></td></tr>';
            App.flash(e.message, 'error');
        }
    }

    form.addEventListener('submit', async (ev) => {
        ev.preventDefault();
        clearErrors();
        const payload = { hostname: form.hostname.value.trim() };
        if (form.ip_address.value.trim()) payload.ip_address = form.ip_address.value.trim();
        try {
            const ins = await App.addHost(payload);
            showInstructions(ins);
            App.flash('Host registered. Publish the DNS record, then verify.');
            form.reset();
            load();
        } catch (e) {
            for (const [field, msg] of Object.entries(e.fields || {})) {
                const el = document.querySelector('.field-error[data-for="' + field + '"]');
                if (el) el.textContent = msg;
            }
            App.flash(e.message, 'error');
        }
    });

    async function verify(h) {
        try {
            const res = await App.verifyHost(h.host_id);
            if (res.verified) {
                App.flash(h.hostname + ' verified.');
            } else {
                App.flash('Verification failed: ' + (res.reason || 'DNS record not found yet.'), 'error');
            }
            load();
        } catch (e) {
            App.flash(e.message, 'error');
        }
    }

    async function makePrimary(h) {
        try {
            await App.makePrimary(h.host_id);
            App.flash(h.hostname + ' is now the primary host.');
            load();
        } catch (e) {
            App.flash(e.message, 'error');
        }
    }

    async function destroy(h) {
        if (!confirm('Stop routing ' + h.hostname + '?')) return;
        try {
            await App.removeHost(h.host_id);
            App.flash('Removed ' + h.hostname + '.');
            load();
        } catch (e) {
            App.flash(e.message, 'error');
        }
    }

    document.getElementById('reload').addEventListener('click', load);
    load();
})();
</script>
