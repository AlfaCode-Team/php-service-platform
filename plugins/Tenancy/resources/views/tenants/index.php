<?php
/**
 * Tenant picker (tenancy::tenants/index). Hydrates over AJAX from
 * GET /ajx/me/tenants; selecting a tenant re-mints a tenant-scoped token via
 * POST /ajx/tenants/{id}/select.
 */
?>
<div class="card">
    <h2>Your tenants</h2>
    <p class="muted">Loaded from <code>GET /ajx/me/tenants</code> (requires the <code>auth</code> filter).
       Select one to scope your session to that tenant.</p>

    <table>
        <thead>
            <tr><th>Name</th><th>Slug</th><th>Role</th><th>Status</th><th></th></tr>
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
        <td class="c-name"></td>
        <td class="c-slug muted"></td>
        <td class="c-role"></td>
        <td><span class="badge c-status"></span></td>
        <td><button class="btn btn-sm btn-primary c-select" type="button">Select</button></td>
    </tr>
</template>

<script>
(function () {
    const tbody = document.getElementById('rows');
    const tpl = document.getElementById('row-tpl');

    function render(tenants) {
        tbody.innerHTML = '';
        if (!tenants.length) {
            tbody.innerHTML = '<tr><td colspan="5" class="muted">You are not a member of any tenant yet.</td></tr>';
            return;
        }
        for (const t of tenants) {
            const row = tpl.content.cloneNode(true);
            row.querySelector('.c-name').textContent = t.name;
            row.querySelector('.c-slug').textContent = t.slug;
            row.querySelector('.c-role').textContent = t.role;
            const status = row.querySelector('.c-status');
            status.textContent = t.status;
            if (t.status === 'active') status.classList.add('active');
            row.querySelector('.c-select').addEventListener('click', () => select(t));
            tbody.appendChild(row);
        }
    }

    async function load() {
        tbody.innerHTML = '<tr><td colspan="5" class="muted">Loading…</td></tr>';
        try {
            const res = await window.TenancyApp.myTenants();
            render(res.data || []);
        } catch (e) {
            tbody.innerHTML = '<tr><td colspan="5"></td></tr>';
            window.TenancyApp.flash(e.message, 'error');
        }
    }

    async function select(t) {
        try {
            const res = await window.TenancyApp.selectTenant(t.tenantId);
            // The new tenant-scoped access token is returned once; persist it so
            // subsequent bearer-auth calls (if any) carry the tnt claim.
            if (res && res.token) {
                try { sessionStorage.setItem('tenancy.token', res.token); } catch (_) {}
            }
            window.TenancyApp.flash('Now scoped to ' + t.name + ' as ' + (res.role || t.role) + '.');
        } catch (e) {
            window.TenancyApp.flash(e.message, 'error');
        }
    }

    document.getElementById('reload').addEventListener('click', load);
    load();
})();
</script>
