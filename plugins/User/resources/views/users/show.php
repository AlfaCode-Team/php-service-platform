<?php
/**
 * User detail page (user::users/show). Loads via GET /ajx/users/{id}.
 *
 * @var string $userId
 */
?>
<div class="card">
    <h2>User detail</h2>

    <div id="detail" class="muted">Loading…</div>

    <div class="actions">
        <a class="btn" href="/users">Back to list</a>
        <a class="btn btn-primary" id="edit" href="#">Edit</a>
        <button class="btn btn-danger" type="button" id="delete">Delete</button>
    </div>
</div>

<script>
(function () {
    const userId = <?= json_encode((string) ($userId ?? ''), JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    const detail = document.getElementById('detail');
    document.getElementById('edit').href = '/users/' + encodeURIComponent(userId) + '/edit';

    function row(label, value) {
        const dt = document.createElement('div');
        dt.style.padding = '.4rem 0';
        const strong = document.createElement('span');
        strong.className = 'muted';
        strong.style.display = 'inline-block';
        strong.style.width = '120px';
        strong.textContent = label;
        dt.appendChild(strong);
        dt.appendChild(document.createTextNode(value));
        return dt;
    }

    async function load() {
        try {
            const res = await window.UserApp.get(userId);
            const u = res.data;
            detail.classList.remove('muted');
            detail.innerHTML = '';
            detail.appendChild(row('ID', u.id));
            detail.appendChild(row('Username', u.username));
            detail.appendChild(row('Email', u.email));
            detail.appendChild(row('Email verified', u.emailVerified ? 'yes' : 'no'));
            detail.appendChild(row('Created', new Date(u.createdAt).toLocaleString()));
        } catch (e) {
            detail.textContent = e.message;
        }
    }

    document.getElementById('delete').addEventListener('click', async () => {
        if (!confirm('Delete this user?')) return;
        try {
            await window.UserApp.remove(userId);
            window.location.href = '/users';
        } catch (e) {
            window.UserApp.flash(e.message, 'error');
        }
    });

    load();
})();
</script>
