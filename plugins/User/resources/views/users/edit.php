<?php
/**
 * Edit-user page (user::users/edit). Loads via GET, saves via PUT /api/users/{id}.
 *
 * @var string $userId
 * @var string $csrf
 */
?>
<div class="card">
    <h2>Edit user</h2>
    <p class="muted">Partial update — leave password blank to keep it unchanged.</p>

    <form id="edit-form" novalidate>
        <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf ?? '', ENT_QUOTES, 'UTF-8') ?>">

        <label for="username">Username</label>
        <input id="username" name="username" autocomplete="username">
        <div class="field-error" data-for="username"></div>

        <label for="email">Email</label>
        <input id="email" name="email" type="email" autocomplete="email">
        <div class="field-error" data-for="email"></div>

        <label for="password">New password</label>
        <input id="password" name="password" type="password" autocomplete="new-password" placeholder="(unchanged)">
        <div class="field-error" data-for="password"></div>

        <div class="actions">
            <button class="btn btn-primary" type="submit">Save changes</button>
            <a class="btn" id="back" href="#">Cancel</a>
        </div>
    </form>
</div>

<script>
(function () {
    const userId = <?= json_encode((string) ($userId ?? ''), JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    const form = document.getElementById('edit-form');
    let original = { username: '', email: '' };

    document.getElementById('back').href = '/users/' + encodeURIComponent(userId);

    function clearErrors() {
        form.querySelectorAll('.field-error').forEach(el => el.textContent = '');
    }
    function showErrors(fields) {
        for (const [name, msg] of Object.entries(fields || {})) {
            const el = form.querySelector('.field-error[data-for="' + name + '"]');
            if (el) el.textContent = Array.isArray(msg) ? msg.join(' ') : msg;
        }
    }

    async function load() {
        try {
            const res = await window.UserApp.get(userId);
            original = { username: res.data.username, email: res.data.email };
            form.username.value = res.data.username;
            form.email.value = res.data.email;
        } catch (e) {
            window.UserApp.flash(e.message, 'error');
        }
    }

    form.addEventListener('submit', async (ev) => {
        ev.preventDefault();
        clearErrors();

        // Send only the fields that actually changed (PATCH semantics).
        const payload = {};
        if (form.username.value.trim() !== original.username) payload.username = form.username.value.trim();
        if (form.email.value.trim() !== original.email) payload.email = form.email.value.trim();
        if (form.password.value !== '') payload.password = form.password.value;

        if (Object.keys(payload).length === 0) {
            window.UserApp.flash('Nothing changed.');
            return;
        }

        try {
            const res = await window.UserApp.update(userId, payload);
            window.UserApp.flash('Saved.');
            original = { username: res.data.username, email: res.data.email };
            form.password.value = '';
        } catch (e) {
            showErrors(e.fields);
            window.UserApp.flash(e.message, 'error');
        }
    });

    load();
})();
</script>
