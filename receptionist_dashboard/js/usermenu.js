/*==============================================================
  usermenu.js for Receptionist Dashboard
  Avatar + dropdown menu at topbar right
  (Account Management, Logout)
==============================================================*/
(function () {

    const BASE        = new URL("../", document.currentScript.src).href;           // .../receptionist_dashboard/
    const ADMIN_BASE  = new URL("../../admin_form/", document.currentScript.src).href; // .../admin_form/
    const ME_URL      = ADMIN_BASE + "php/account_api.php?action=me";
    const LOGOUT      = ADMIN_BASE + "php/logout.php";
    const ACCOUNT     = ADMIN_BASE + "account_feature/index.html";
    const LOGIN_URL   = new URL("../../login_feature/index.html", document.currentScript.src).href;

    const topbar = document.getElementById("topbar");
    if (!topbar || document.getElementById("userMenu")) return;

    // Load usermenu CSS from admin_form
    if (!document.querySelector('link[data-usermenu]')) {
        const l = document.createElement("link");
        l.rel = "stylesheet";
        l.href = ADMIN_BASE + "css/usermenu.css";
        l.dataset.usermenu = "1";
        document.head.appendChild(l);
    }

    const esc = s => String(s ?? "").replace(/[&<>"']/g,
        c => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));

    topbar.insertAdjacentHTML("beforeend", `
        <svg width="0" height="0" style="position:absolute" aria-hidden="true">
          <defs>
            <symbol id="um-user" viewBox="0 0 24 24"><path d="M12 12a5 5 0 1 0 0-10 5 5 0 0 0 0 10zm0 2c-4.4 0-8 2.2-8 5v3h16v-3c0-2.8-3.6-5-8-5z"/></symbol>
            <symbol id="um-logout" viewBox="0 0 24 24"><path d="M10 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h5v-2H5V5h5V3zm6.6 4.6L15.2 9l2 2H9v2h8.2l-2 2 1.4 1.4L21 12l-4.4-4.4z"/></symbol>
            <symbol id="um-chev" viewBox="0 0 24 24"><path d="M7 10l5 5 5-5z"/></symbol>
          </defs>
        </svg>

        <div class="user-menu" id="userMenu">
            <button type="button" class="avatar-btn" id="userMenuBtn" aria-haspopup="true" aria-expanded="false">
                <img class="avatar-img" id="umAvatar" alt="" src="data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==">
                <span class="user-meta"><strong id="umName">&nbsp;</strong><small id="umRole">&nbsp;</small></span>
                <svg class="chev"><use href="#um-chev"/></svg>
            </button>

            <div class="user-dropdown" id="userDropdown" role="menu">
                <div class="dd-head">
                    <img class="avatar-img lg" id="umAvatarLg" alt="" src="data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==">
                    <div class="who"><strong id="umNameLg">&nbsp;</strong><span id="umUser">&nbsp;</span></div>
                </div>
                <a class="dd-item" role="menuitem" href="${ACCOUNT}">
                    <svg><use href="#um-user"/></svg> My Account
                </a>
                <div class="dd-sep"></div>
                <button type="button" class="dd-item danger" role="menuitem" id="umLogout">
                    <svg><use href="#um-logout"/></svg> Log out
                </button>
            </div>
        </div>`);

    const $ = id => document.getElementById(id);
    const btn = $("userMenuBtn"), dd = $("userDropdown");

    function setOpen(open) {
        dd.classList.toggle("open", open);
        btn.setAttribute("aria-expanded", open ? "true" : "false");
    }

    btn.onclick = e => { e.stopPropagation(); setOpen(!dd.classList.contains("open")); };
    document.addEventListener("click", e => { if (!$("userMenu")?.contains(e.target)) setOpen(false); });
    document.addEventListener("keydown", e => { if (e.key === "Escape") setOpen(false); });

    function goLogin(message) {
        if (message) alert(message);
        location.href = LOGIN_URL;
    }

    async function logout() {
        if ($('umLogout').disabled) return;
        $('umLogout').disabled = true;
        try {
            const response = await fetch(LOGOUT, {
                method: "POST",
                credentials: "same-origin",
                headers: { "X-Requested-With": "XMLHttpRequest" }
            });
            const result = await response.json();
            if (!response.ok || !result.success) throw new Error('Logout failed.');
            goLogin();
        } catch (error) {
            alert('Cannot log out. Please try again.');
            $('umLogout').disabled = false;
        }
    }
    $("umLogout").onclick = logout;

    async function load() {
        try {
            const res  = await fetch(ME_URL, { credentials: "same-origin", cache: "no-store" });
            const body = await res.json().catch(() => null);

            if (res.status === 401) {
                if (window.hmsKick) window.hmsKick(body || {});
                return;
            }
            if (!res.ok || !body || !body.success) return;

            const me = body.data;
            const src = ADMIN_BASE + me.avatar_url;
            $("umAvatar").src = src;
            $("umAvatarLg").src = src;
            $("umName").textContent   = me.full_name;
            $("umNameLg").textContent = me.full_name;
            $("umRole").textContent   = me.role.charAt(0).toUpperCase() + me.role.slice(1);
            $("umUser").textContent   = "@" + me.username;
            window.hmsMe = me;
        } catch (e) {
            console.error("User menu error:", e);
        }
    }

    window.hmsRefreshMe = load;
    load();

})();
