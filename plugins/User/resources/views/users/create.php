<?php
/**
 * Create-user page (user::users/create). POSTs to /ajx/users.
 *
 * @var string $csrf
 */
?>
<div class="card">
    <h2>Create account</h2>
    <p class="muted">Submits to <code>POST /ajx/users</code> (rate-limited, public signup).</p>

    <form id="create-form" novalidate>
        <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrf ?? '', ENT_QUOTES, 'UTF-8') ?>">

        <label for="username">Username</label>
        <input id="username" name="username" autocomplete="username" required>
        <div class="field-error" data-for="username"></div>

        <label for="email">Email</label>
        <input id="email" name="email" type="email" autocomplete="email" required>
        <div class="field-error" data-for="email"></div>

        <label for="password">Password</label>
        <input id="password" name="password" type="password" autocomplete="new-password" required>
        <div class="field-error" data-for="password"></div>

        <div class="actions">
            <button class="btn btn-primary" type="submit">Create</button>
            <a class="btn" href="/users">Cancel</a>
        </div>
    </form>
</div>

<script>
(function () {
    const form = document.getElementById('create-form');

    function clearErrors() {
        form.querySelectorAll('.field-error').forEach(el => el.textContent = '');
    }
    function showErrors(fields) {
        for (const [name, msg] of Object.entries(fields || {})) {
            const el = form.querySelector('.field-error[data-for="' + name + '"]');
            if (el) el.textContent = Array.isArray(msg) ? msg.join(' ') : msg;
        }
    }

    form.addEventListener('submit', async (ev) => {
        ev.preventDefault();
        clearErrors();
        const payload = {
            username: form.username.value.trim(),
            email: form.email.value.trim(),
            password: form.password.value,
        };
        try {
            const res = await window.UserApp.create(payload);
            window.UserApp.flash('Created ' + res.data.username + '.');
            window.location.href = '/users/' + encodeURIComponent(res.data.id);
        } catch (e) {
            showErrors(e.fields);
            window.UserApp.flash(e.message, 'error');
        }
    });
})();
</script>
