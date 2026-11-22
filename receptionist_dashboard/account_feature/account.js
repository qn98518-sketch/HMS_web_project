/*==============================================================
  account.js - Receptionist Account Management
  Connects to admin_form/php/account_api.php
==============================================================*/
(function () {

    const API        = "../../admin_form/php/account_api.php";
    const ADMIN_BASE = new URL("../../admin_form/", location.href).href;
    const LOGIN_URL  = new URL("../../login_feature/index.html", location.href).href;

    const MAX_BYTES = 2 * 1024 * 1024;
    const TYPES     = ["image/jpeg", "image/png", "image/webp", "image/gif"];

    let me = null;
    let pendingAction = null;                                  // "lock" | "delete"

    const $ = id => document.getElementById(id);

    //============================
    // API
    //============================
    async function api(formData) {
        try {
            const res  = await fetch(API, {
                method: "POST",
                body: formData,
                credentials: "same-origin",
                headers: { "X-Requested-With": "XMLHttpRequest" }
            });
            const body = await res.json().catch(() => null);
            if (res.status === 401) {
                window.hmsKick ? window.hmsKick(body || {}) : (location.href = LOGIN_URL);
                return null;
            }
            return { ok: res.ok && body && body.success, body: body || {}, status: res.status };
        } catch (e) {
            return { ok: false, body: { message: "Cannot connect to the server." }, status: 0 };
        }
    }

    function say(id, text, ok) {
        const el = $(id);
        el.textContent = text || "";
        el.className = "msg " + (text ? (ok ? "ok" : "err") : "");
    }

    //============================
    // Load
    //============================
    function fill(data) {
        me = data;
        $("accAvatar").src      = ADMIN_BASE + me.avatar_url;
        $("accUsername").value  = me.username;
        $("accRole").value      = me.role;
        $("accFullName").value  = me.full_name;
        $("accEmail").value     = me.email;
        $("accPhone").value     = me.phone;
        $("btnRemoveAvatar").disabled = !me.has_avatar;

        $("dangerZone").hidden    = false;
        $("adminNote").hidden     = me.can_lock_delete;
        $("dangerActions").hidden = !me.can_lock_delete;

        window.hmsRefreshMe && window.hmsRefreshMe();
    }

    async function load() {
        try {
            const res  = await fetch(API + "?action=me", { credentials: "same-origin", cache: "no-store" });
            const body = await res.json().catch(() => null);
            if (res.status === 401) { window.hmsKick && window.hmsKick(body || {}); return; }
            if (!res.ok || !body || !body.success) {
                say("profileMsg", (body && body.message) || "Cannot load your account.", false);
                return;
            }
            fill(body.data);
        } catch (e) {
            say("profileMsg", "Cannot load your account.", false);
        }
    }

    //============================
    // Avatar
    //============================
    $("btnPickAvatar").onclick = () => $("avatarFile").click();

    $("avatarFile").onchange = async () => {
        const file = $("avatarFile").files[0];
        $("avatarFile").value = "";
        if (!file) return;

        if (!TYPES.includes(file.type)) { say("avatarMsg", "Only JPG, PNG, WEBP or GIF images are allowed.", false); return; }
        if (file.size > MAX_BYTES)      { say("avatarMsg", "Image is too large (max 2 MB).", false); return; }

        say("avatarMsg", "Uploading...", true);
        const fd = new FormData();
        fd.append("action", "avatar_upload");
        fd.append("avatar", file);

        const r = await api(fd);
        if (!r) return;
        if (!r.ok) { say("avatarMsg", r.body.message || "Upload failed.", false); return; }

        say("avatarMsg", "Photo updated.", true);
        await load();
    };

    $("btnRemoveAvatar").onclick = async () => {
        const fd = new FormData();
        fd.append("action", "avatar_remove");
        const r = await api(fd);
        if (!r) return;
        if (!r.ok) { say("avatarMsg", r.body.message || "Request failed.", false); return; }
        say("avatarMsg", "Photo removed. Your default avatar is shown.", true);
        await load();
    };

    //============================
    // Profile
    //============================
    $("btnSaveProfile").onclick = async () => {
        const name  = $("accFullName").value.trim();
        const email = $("accEmail").value.trim();
        const phone = $("accPhone").value.trim();

        if (!name)                                             { say("profileMsg", "Please enter your full name.", false); return; }
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email))         { say("profileMsg", "Invalid email format.", false); return; }
        if (!/^\+?\d{7,15}$/.test(phone) || phone.length > 10) { say("profileMsg", "Invalid phone number (max 10 characters).", false); return; }

        const fd = new FormData();
        fd.append("action", "update_profile");
        fd.append("full_name", name);
        fd.append("email", email);
        fd.append("phone", phone);

        const r = await api(fd);
        if (!r) return;
        if (!r.ok) { say("profileMsg", r.body.message || "Request failed.", false); return; }

        say("profileMsg", "Profile updated.", true);
        fill(r.body.data);
    };

    //============================
    // Password
    //============================
    $("btnChangePw").onclick = async () => {
        const cur = $("pwCurrent").value, nw = $("pwNew").value, cf = $("pwConfirm").value;

        if (!cur)           { say("pwMsg", "Please enter your current password.", false); return; }
        if (nw.length < 6)  { say("pwMsg", "New password must be at least 6 characters long.", false); return; }
        if (nw !== cf)      { say("pwMsg", "The new passwords do not match.", false); return; }

        const fd = new FormData();
        fd.append("action", "change_password");
        fd.append("current_password", cur);
        fd.append("new_password", nw);

        const r = await api(fd);
        if (!r) return;
        if (!r.ok) { say("pwMsg", r.body.message || "Request failed.", false); return; }

        $("pwCurrent").value = $("pwNew").value = $("pwConfirm").value = "";
        say("pwMsg", "Password changed.", true);
    };

    //============================
    // Lock / delete
    //============================
    function openConfirm(action) {
        pendingAction = action;
        $("confirmTitle").textContent = action === "lock" ? "Lock account" : "Delete account";
        $("confirmText").textContent  = action === "lock"
            ? "You will be signed out and will not be able to sign in again until an administrator reactivates your account."
            : "Accounts with appointment or bill history are hidden and their records retained. Otherwise the account is permanently deleted. You will be signed out.";
        $("confirmOk").textContent = action === "lock" ? "Lock account" : "Delete account";
        $("confirmPassword").value = "";
        $("confirmModal").classList.add("open");
        $("confirmPassword").focus();
    }
    const closeConfirm = () => { $("confirmModal").classList.remove("open"); pendingAction = null; };

    $("btnLock").onclick   = () => openConfirm("lock");
    $("btnDelete").onclick = () => openConfirm("delete");
    $("confirmCancel").onclick = closeConfirm;
    document.addEventListener("keydown", e => { if (e.key === "Escape") closeConfirm(); });

    $("confirmOk").onclick = async () => {
        const pw = $("confirmPassword").value;
        if (!pw) { alert("Please enter your password."); return; }

        const fd = new FormData();
        fd.append("action", pendingAction);
        fd.append("password", pw);

        const r = await api(fd);
        if (!r) return;
        if (!r.ok) { alert(r.body.message || "Request failed."); return; }

        alert(r.body.message);
        location.href = LOGIN_URL;
    };

    load();

})();
