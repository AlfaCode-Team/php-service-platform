<?php
/**
 * Users list page (user::users/index). Hydrates over AJAX from GET /ajx/users.
 */
?>
<div class="card">
    <h2>Users</h2>
    <p class="muted">Loaded from <code>GET /ajx/users</code> (requires the <code>auth</code> filter).</p>

    <table>
        <thead>
            <tr><th>Username</th><th>Email</th><th>Status</th><th>Created</th><th></th></tr>
        </thead>
        <tbody id="rows">
            <tr><td colspan="5" class="muted">Loading…</td></tr>
        </tbody>
    </table>

    <div class="actions">
        <a class="btn btn-primary" href="/users/create">Create user</a>
        <button class="btn" type="button" id="reload">Reload</button>
    </div>
</div>

<template id="row-tpl">
    <tr>
        <td class="c-username"></td>
        <td class="c-email"></td>
        <td><span class="badge c-status"></span></td>
        <td class="c-created muted"></td>
        <td>
            <a class="btn btn-sm c-view" href="#">View</a>
            <a class="btn btn-sm c-edit" href="#">Edit</a>
            <button class="btn btn-sm btn-danger c-del" type="button">Delete</button>
        </td>
    </tr>
</template>

<script>
(function () {
    const tbody = document.getElementById('rows');
    const tpl = document.getElementById('row-tpl');

    function render(users) {
        tbody.innerHTML = '';
        if (!users.length) {
            tbody.innerHTML = '<tr><td colspan="5" class="muted">No users yet.</td></tr>';
            return;
        }
        for (const u of users) {
            const row = tpl.content.cloneNode(true);
            row.querySelector('.c-username').textContent = u.username;
            row.querySelector('.c-email').textContent = u.email;
            const status = row.querySelector('.c-status');
            status.textContent = u.emailVerified ? 'verified' : 'unverified';
            if (u.emailVerified) status.classList.add('active');
            row.querySelector('.c-created').textContent = new Date(u.createdAt).toLocaleString();
            row.querySelector('.c-view').href = '/users/' + encodeURIComponent(u.id);
            row.querySelector('.c-edit').href = '/users/' + encodeURIComponent(u.id) + '/edit';
            row.querySelector('.c-del').addEventListener('click', () => destroy(u));
            tbody.appendChild(row);
        }
    }

    async function load() {
        tbody.innerHTML = '<tr><td colspan="5" class="muted">Loading…</td></tr>';
        try {
            const res = await window.UserApp.list();
            render(res.data || []);
        } catch (e) {
            tbody.innerHTML = '<tr><td colspan="5"></td></tr>';
            window.UserApp.flash(e.message, 'error');
        }
    }

    async function destroy(u) {
        if (!confirm('Delete ' + u.username + '?')) return;
        try {
            await window.UserApp.remove(u.id);
            window.UserApp.flash('Deleted ' + u.username + '.');
            load();
        } catch (e) {
            window.UserApp.flash(e.message, 'error');
        }
    }

    document.getElementById('reload').addEventListener('click', load);
    load();
})();
</script>
