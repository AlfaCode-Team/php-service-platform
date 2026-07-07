<?php
/**
 * Tenant fleet management (tenancy::tenants/manage). Platform-admin only.
 * Hydrates from GET /ajx/admin/tenants; rows link to edit + delete the tenant.
 */
?>
<div class="card">
    <div class="actions" style="margin:0 0 1rem; justify-content:space-between; align-items:center;">
        <h2 style="margin:0;">Tenants</h2>
        <a class="btn btn-primary" href="/tenants/create">+ New tenant</a>
    </div>
    <p class="muted">Control plane for the whole fleet — backed by
       <code>GET /ajx/admin/tenants</code>. Requires platform-admin access.</p>

    <table>
        <thead>
            <tr><th>Name</th><th>Slug</th><th>Database</th><th>Status</th><th></th></tr>
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
        <td class="c-db muted"></td>
        <td><span class="badge c-status"></span></td>
        <td style="white-space:nowrap;">
            <a class="btn btn-sm c-edit">Edit</a>
            <button class="btn btn-sm btn-danger c-delete" type="button">Delete</button>
        </td>
    </tr>
</template>

<script>
(function () {
    const tbody = document.getElementById('rows');
    const tpl = document.getElementById('row-tpl');

    function render(tenants) {
        tbody.innerHTML = '';
        if (!tenants.length) {
            tbody.innerHTML = '<tr><td colspan="5" class="muted">No tenants yet. Create the first one.</td></tr>';
            return;
        }
        for (const t of tenants) {
            const row = tpl.content.cloneNode(true);
            row.querySelector('.c-name').textContent = t.name;
            row.querySelector('.c-slug').textContent = t.slug;
            row.querySelector('.c-db').textContent = t.dbDriver + ' · ' + t.dbName + ' @ ' + t.dbHost + ':' + t.dbPort;
            const status = row.querySelector('.c-status');
            status.textContent = t.status;
            if (t.status === 'active') status.classList.add('active');
            else if (t.status === 'provisioning') status.classList.add('pending');
            row.querySelector('.c-edit').setAttribute('href', '/tenants/' + encodeURIComponent(t.tenantId) + '/edit');
            row.querySelector('.c-delete').addEventListener('click', () => remove(t));
            tbody.appendChild(row);
        }
    }

    async function load() {
        tbody.innerHTML = '<tr><td colspan="5" class="muted">Loading…</td></tr>';
        try {
            const res = await window.TenancyApp.adminTenants();
            render(res.data || []);
        } catch (e) {
            tbody.innerHTML = '<tr><td colspan="5" class="muted"></td></tr>';
            window.TenancyApp.flash(e.message, 'error');
        }
    }

    async function remove(t) {
        if (!window.confirm('Delete tenant "' + t.name + '"? This drops its database user and registry row.')) {
            return;
        }
        const dropDatabase = window.confirm('Also DROP the tenant database "' + t.dbName + '"? All its data is lost. OK = drop, Cancel = keep.');
        try {
            await window.TenancyApp.adminDeleteTenant(t.tenantId, dropDatabase);
            window.TenancyApp.flash('Tenant "' + t.name + '" deleted.');
            load();
        } catch (e) {
            window.TenancyApp.flash(e.message, 'error');
        }
    }

    document.getElementById('reload').addEventListener('click', load);
    load();
})();
</script>
