<?php
/**
 * New-tenant provisioning form (tenancy::tenants/create). Platform-admin only.
 * Submits to POST /ajx/admin/tenants which provisions the registry row, the
 * isolated database + user, and runs the tenant template migrations.
 */
?>
<div class="card">
    <h2>Provision a new tenant</h2>
    <p class="muted">Creates the registry row, an isolated database + user, and runs the
       template migrations. On any failure the partial work is rolled back.</p>

    <form id="form" novalidate>
        <label for="name">Display name</label>
        <input id="name" name="name" placeholder="Acme Inc" autocomplete="off">
        <div class="field-error" data-for="name"></div>

        <label for="slug">Slug <span class="muted">(^[a-z0-9-]+$)</span></label>
        <input id="slug" name="slug" placeholder="acme" autocomplete="off">
        <div class="field-error" data-for="slug"></div>

        <label for="driver">Database driver</label>
        <input id="driver" name="driver" list="drivers" value="mysql" autocomplete="off">
        <datalist id="drivers">
            <option value="mysql"><option value="pgsql"><option value="sqlsrv">
        </datalist>
        <div class="field-error" data-for="driver"></div>

        <label for="db_name">Physical database name <span class="muted">(letters/digits/_)</span></label>
        <input id="db_name" name="db_name" placeholder="tnt_acme" autocomplete="off">
        <div class="field-error" data-for="db_name"></div>

        <label for="db_user">Database username</label>
        <input id="db_user" name="db_user" placeholder="acme_user" autocomplete="off">
        <div class="field-error" data-for="db_user"></div>

        <label for="db_password">Database password <span class="muted">(stored encrypted)</span></label>
        <input id="db_password" name="db_password" type="password" autocomplete="new-password">
        <div class="field-error" data-for="db_password"></div>

        <label for="db_host">Database host</label>
        <input id="db_host" name="db_host" value="127.0.0.1" autocomplete="off">
        <div class="field-error" data-for="db_host"></div>

        <label for="db_port">Database port <span class="muted">(blank = driver default)</span></label>
        <input id="db_port" name="db_port" type="number" min="1" max="65535" placeholder="3306">
        <div class="field-error" data-for="db_port"></div>

        <div class="actions">
            <button class="btn btn-primary" type="submit" id="submit">Provision tenant</button>
            <a class="btn" href="/tenants/manage">Cancel</a>
        </div>
    </form>
</div>

<script>
(function () {
    const form = document.getElementById('form');
    const submit = document.getElementById('submit');

    // Auto-suggest slug-derived defaults from the display name.
    const name = form.name, slug = form.slug, dbName = form.db_name, dbUser = form.db_user;
    function slugify(v) { return v.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, ''); }
    function ident(v) { return v.toLowerCase().replace(/[^a-z0-9_]+/g, '_'); }
    name.addEventListener('input', () => {
        const s = slugify(name.value);
        if (!slug.dataset.touched) slug.value = s;
        if (!dbName.dataset.touched) dbName.value = 'tnt_' + ident(s);
        if (!dbUser.dataset.touched) dbUser.value = ident(s) + '_user';
    });
    [slug, dbName, dbUser].forEach(el => el.addEventListener('input', () => { el.dataset.touched = '1'; }));

    function clearErrors() {
        form.querySelectorAll('.field-error').forEach(el => { el.textContent = ''; });
    }
    function showErrors(fields) {
        for (const [k, v] of Object.entries(fields || {})) {
            const el = form.querySelector('.field-error[data-for="' + k + '"]');
            if (el) el.textContent = Array.isArray(v) ? v.join(' ') : v;
        }
    }

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        clearErrors();
        submit.disabled = true;
        submit.textContent = 'Provisioning…';
        const payload = {
            name: name.value.trim(),
            slug: slug.value.trim(),
            driver: form.driver.value.trim(),
            db_name: dbName.value.trim(),
            db_user: dbUser.value.trim(),
            db_password: form.db_password.value,
            db_host: form.db_host.value.trim(),
            db_port: form.db_port.value ? parseInt(form.db_port.value, 10) : 0,
        };
        try {
            const res = await window.TenancyApp.adminCreateTenant(payload);
            window.TenancyApp.flash('Tenant "' + res.name + '" provisioned.');
            setTimeout(() => { window.location.href = '/tenants/manage'; }, 600);
        } catch (err) {
            showErrors(err.fields);
            window.TenancyApp.flash(err.message, 'error');
            submit.disabled = false;
            submit.textContent = 'Provision tenant';
        }
    });
})();
</script>
