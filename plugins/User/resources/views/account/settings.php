<?php
/**
 * Account settings demo (user::account/settings).
 *
 * Demonstrates READ + UPDATE CRUD for the four self-scoped settings resources:
 *   GET/PUT /ajx/profile, /ajx/preferences, /ajx/privacy,
 *   /ajx/notification-preferences
 *
 * Same-site: the browser sends the session cookie; unsafe requests carry the
 * CSRF token from the <meta> tag (added by the layout) in X-CSRF-Token.
 */
?>
<div class="card" style="margin-bottom:1.5rem">
    <h2>Profile</h2>
    <p class="muted">GET / PUT <code>/ajx/profile</code></p>
    <form id="profile-form">
        <label>First name</label><input name="firstName">
        <label>Last name</label><input name="lastName">
        <label>Avatar URL (http/https)</label><input name="avatarUrl">
        <label>Timezone</label><input name="timezone" placeholder="Africa/Kampala">
        <label>Locale</label><input name="locale" placeholder="en_US">
        <label>Phone</label><input name="phone">
        <div class="field-error" data-err="profile"></div>
        <div class="actions"><button class="btn btn-primary">Save profile</button></div>
    </form>
</div>

<div class="card" style="margin-bottom:1.5rem">
    <h2>Preferences</h2>
    <p class="muted">GET / PUT <code>/ajx/preferences</code></p>
    <form id="preferences-form">
        <label>Language</label><input name="language" placeholder="en">
        <label>Currency</label><input name="currency" placeholder="UGX">
        <label>Theme</label>
        <select name="theme"><option>system</option><option>light</option><option>dark</option></select>
        <label><input type="checkbox" name="reduceMotion"> Reduce motion</label>
        <label><input type="checkbox" name="largerText"> Larger text</label>
        <label><input type="checkbox" name="highContrast"> High contrast</label>
        <label><input type="checkbox" name="screenReaderHints"> Screen-reader hints</label>
        <div class="field-error" data-err="preferences"></div>
        <div class="actions"><button class="btn btn-primary">Save preferences</button></div>
    </form>
</div>

<div class="card" style="margin-bottom:1.5rem">
    <h2>Privacy</h2>
    <p class="muted">GET / PUT <code>/ajx/privacy</code></p>
    <form id="privacy-form">
        <label>Profile visibility</label>
        <select name="profileVisibility"><option>public</option><option>private</option><option>contacts</option></select>
        <label><input type="checkbox" name="showPhone"> Show phone</label>
        <label><input type="checkbox" name="showEmail"> Show email</label>
        <label><input type="checkbox" name="marketingOptIn"> Marketing opt-in</label>
        <label><input type="checkbox" name="analyticsOptIn"> Analytics opt-in</label>
        <div class="field-error" data-err="privacy"></div>
        <div class="actions"><button class="btn btn-primary">Save privacy</button></div>
    </form>
</div>

<div class="card">
    <h2>Notifications</h2>
    <p class="muted">GET / PUT <code>/ajx/notification-preferences</code></p>
    <table id="notif-table"><tbody><tr><td class="muted">Loading…</td></tr></tbody></table>
    <div class="actions"><button class="btn btn-primary" id="notif-save">Save notifications</button></div>
</div>

<script>
(function () {
    const SAFE = { GET:1, HEAD:1, OPTIONS:1 };
    function csrf() { const m = document.querySelector('meta[name="csrf-token"]'); return m ? m.content : ''; }
    async function api(method, path, body) {
        const h = { 'Accept':'application/json', 'X-Requested-With':'XMLHttpRequest' };
        if (body !== undefined) h['Content-Type'] = 'application/json';
        if (!SAFE[method]) h['X-CSRF-Token'] = csrf();
        const res = await fetch(path, { method, headers:h, credentials:'same-origin',
            body: body !== undefined ? JSON.stringify(body) : undefined });
        const t = await res.text(); const d = t ? JSON.parse(t) : null;
        if (!res.ok) { const e = new Error((d&&d.error&&d.error.message)||('HTTP '+res.status)); e.fields=(d&&d.error&&d.error.fields)||{}; throw e; }
        return d;
    }
    function flash(msg, type='ok') { window.UserApp && window.UserApp.flash(msg, type); }
    const val = (form, name) => form.elements[name];

    function fillForm(form, data, errKey) {
        for (const el of form.elements) {
            if (!el.name) continue;
            if (el.type === 'checkbox') el.checked = !!data[el.name];
            else if (data[el.name] != null) el.value = data[el.name];
        }
        const err = form.querySelector('[data-err="'+errKey+'"]'); if (err) err.textContent = '';
    }
    function collect(form) {
        const out = {};
        for (const el of form.elements) {
            if (!el.name) continue;
            out[el.name] = el.type === 'checkbox' ? el.checked : el.value;
        }
        return out;
    }
    function wire(formId, path, errKey, label) {
        const form = document.getElementById(formId);
        api('GET', path).then(d => fillForm(form, d, errKey)).catch(e => flash(e.message, 'error'));
        form.addEventListener('submit', async (ev) => {
            ev.preventDefault();
            try { const d = await api('PUT', path, collect(form)); fillForm(form, d, errKey); flash(label + ' saved'); }
            catch (e) {
                const box = form.querySelector('[data-err="'+errKey+'"]');
                if (box) box.textContent = Object.values(e.fields)[0] || e.message;
                flash(e.message, 'error');
            }
        });
    }

    wire('profile-form',     '/ajx/profile',     'profile',     'Profile');
    wire('preferences-form', '/ajx/preferences', 'preferences', 'Preferences');
    wire('privacy-form',     '/ajx/privacy',     'privacy',     'Privacy');

    // Notifications: a dynamic channel × topic checkbox matrix built from the
    // nested { flags: { channel: { topic: bool } } } response.
    const NOTIF = '/ajx/notification-preferences';
    const notifBody = document.querySelector('#notif-table tbody');
    function renderNotif(flags) {
        notifBody.innerHTML = '';
        for (const channel of Object.keys(flags)) {
            const tr = document.createElement('tr');
            const th = document.createElement('th'); th.textContent = channel; tr.appendChild(th);
            for (const topic of Object.keys(flags[channel])) {
                const td = document.createElement('td');
                const cb = document.createElement('input');
                cb.type = 'checkbox'; cb.checked = !!flags[channel][topic];
                cb.dataset.channel = channel; cb.dataset.topic = topic;
                const lab = document.createElement('label'); lab.style.margin = '0';
                lab.append(cb, ' ' + topic); td.appendChild(lab); tr.appendChild(td);
            }
            notifBody.appendChild(tr);
        }
    }
    api('GET', NOTIF).then(d => renderNotif(d.flags)).catch(e => flash(e.message, 'error'));
    document.getElementById('notif-save').addEventListener('click', async () => {
        const flags = {};
        notifBody.querySelectorAll('input[type=checkbox]').forEach(cb => {
            (flags[cb.dataset.channel] ||= {})[cb.dataset.topic] = cb.checked;
        });
        try { const d = await api('PUT', NOTIF, { flags }); renderNotif(d.flags); flash('Notifications saved'); }
        catch (e) { flash(e.message, 'error'); }
    });
})();
</script>
