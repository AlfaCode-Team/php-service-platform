<?php
/**
 * Edit-tenant form (tenancy::tenants/edit). Platform-admin only.
 *
 * @var string $tenantId  Tenant being edited (passed by TenantPageController::edit).
 *
 * Loads the current values from GET /ajx/admin/tenants/{id} and saves safe
 * metadata (name, slug, status) via PUT /ajx/admin/tenants/{id}. Connection
 * coordinates are shown read-only — they are never rewritten for a live tenant.
 */
$tenantId = $tenantId ?? '';
?>
<div class="card">
    <h2>Edit tenant</h2>
    <p class="muted">Only the name, slug and status can be changed here. Database
       coordinates are fixed once a tenant is provisioned.</p>

    <form id="form" novalidate>
        <label for="name">Display name</label>
        <input id="name" name="name" autocomplete="off">
        <div class="field-error" data-for="name"></div>

        <label for="slug">Slug <span class="muted">(^[a-z0-9-]+$)</span></label>
        <input id="slug" name="slug" autocomplete="off">
        <div class="field-error" data-for="slug"></div>

        <label for="status">Status</label>
        <select id="status" name="status">
            <option value="active">active</option>
            <option value="provisioning">provisioning</option>
            <option value="suspended">suspended</option>
            <option value="deleted">deleted</option>
        </select>
        <div class="field-error" data-for="status"></div>

        <label>Database <span class="muted">(read-only)</span></label>
        <pre id="db">…</pre>

        <div class="actions">
            <button class="btn btn-primary" type="submit" id="submit" disabled>Save changes</button>
            <a class="btn" href="/tenants/manage">Back</a>
        </div>
    </form>
</div>

<script>
(function () {
    const tenantId = <?= json_encode($tenantId, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    const form = document.getElementById('form');
    const submit = document.getElementById('submit');

    function clearErrors() {
        form.querySelectorAll('.field-error').forEach(el => { el.textContent = ''; });
    }
    function showErrors(fields) {
        for (const [k, v] of Object.entries(fields || {})) {
            const el = form.querySelector('.field-error[data-for="' + k + '"]');
            if (el) el.textContent = Array.isArray(v) ? v.join(' ') : v;
        }
    }

    async function load() {
        try {
            const t = await window.TenancyApp.adminTenant(tenantId);
            form.name.value = t.name;
            form.slug.value = t.slug;
            form.status.value = t.status;
            document.getElementById('db').textContent =
                t.dbDriver + '\n' + t.dbName + ' @ ' + t.dbHost + ':' + t.dbPort + '\nuser: ' + t.dbUsername;
            submit.disabled = false;
        } catch (e) {
            window.TenancyApp.flash(e.message, 'error');
        }
    }

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        clearErrors();
        submit.disabled = true;
        try {
            const res = await window.TenancyApp.adminUpdateTenant(tenantId, {
                name: form.name.value.trim(),
                slug: form.slug.value.trim(),
                status: form.status.value,
            });
            window.TenancyApp.flash('Saved "' + res.name + '".');
        } catch (err) {
            showErrors(err.fields);
            window.TenancyApp.flash(err.message, 'error');
        } finally {
            submit.disabled = false;
        }
    });

    load();
})();
</script>
