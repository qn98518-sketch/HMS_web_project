(function () {
    const BASE = new URL("../", document.currentScript.src).href;
    const ADMIN_BASE = new URL("../../admin_form/", document.currentScript.src).href;

    const MENU = [
        { key: "dashboard", label: "Dashboard", icon: "home", href: "receptionist_dashboard.html" },
        { key: "patients", label: "Patients Reception", icon: "patient", href: "patients_feature/index.html" },
        { key: "appointments", label: "Appointments", icon: "calendar", href: "appointments_feature/index.html" },
        { key: "bills", label: "Bills & Payments", icon: "bill", href: "bills_feature/index.html" },
        { key: "account", label: "My Account", icon: "users", href: "../admin_form/account_feature/index.html" }
    ];

    const SPRITE = `
    <svg width="0" height="0" style="position:absolute" aria-hidden="true">
      <defs>
        <symbol id="i-bill" viewBox="0 0 24 24"><path d="M5 2h14v20l-3-2-4 2-4-2-3 2V2zm2 2v14.3l1-.7 4 2 4-2 1 .7V4H7zm2 3h6v2H9V7zm0 4h6v2H9v-2zm0 4h4v2H9v-2z"/></symbol>
        <symbol id="i-home" viewBox="0 0 24 24"><path d="M12 3 2 12h3v9h5v-6h4v6h5v-9h3z"/></symbol>
        <symbol id="i-users" viewBox="0 0 24 24"><path d="M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zm8 0a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM9 13c-3.3 0-7 1.7-7 4v3h14v-3c0-2.3-3.7-4-7-4zm8 .1c-.4 0-.8 0-1.2.1 1.4 1 2.2 2.3 2.2 3.8v3h6v-3c0-2-3.4-3.9-7-3.9z"/></symbol>
        <symbol id="i-patient" viewBox="0 0 24 24"><path d="M2 5h2v14H2zM4 15h18v2H4zM20 17h2v2h-2zM8 9.5a2.2 2.2 0 1 0 0 4.4 2.2 2.2 0 0 0 0-4.4zM11 11h7a3 3 0 0 1 3 3v1H11z"/></symbol>
        <symbol id="i-calendar" viewBox="0 0 24 24"><path fill-rule="evenodd" d="M7 2h2v2h6V2h2v2h3a1 1 0 0 1 1 1v15a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1h3V2zM5 9v10h14V9H5zm2 2h4v4H7v-4z"/></symbol>
        <symbol id="i-plus" viewBox="0 0 24 24"><path d="M11 4h2v7h7v2h-7v7h-2v-7H4v-2h7V4z"/></symbol>
        <symbol id="i-search" viewBox="0 0 24 24"><path d="M10 3a7 7 0 1 0 4.3 12.5l4.6 4.6 1.4-1.4-4.6-4.6A7 7 0 0 0 10 3zm0 2a5 5 0 1 1 0 10 5 5 0 0 1 0-10z"/></symbol>
        <symbol id="i-pen" viewBox="0 0 24 24"><path d="m3 17.3 10.8-10.8 3.7 3.7L6.7 21H3v-3.7zM15.2 5.1l1.9-1.9a1 1 0 0 1 1.4 0l2.3 2.3a1 1 0 0 1 0 1.4l-1.9 1.9-3.7-3.7z"/></symbol>
      </defs>
    </svg>`;

    const STORE_KEY = "hms_receptionist_sidebar_collapsed";

    try {
        if (localStorage.getItem(STORE_KEY) === "1") document.body.classList.add("collapse");
    } catch (e) {}

    document.body.insertAdjacentHTML("afterbegin", SPRITE);

    const current = document.body.dataset.page;

    const sidebar = document.getElementById("sidebar");
    if (sidebar) {
        sidebar.innerHTML = `
            <div class="logo">
                <a href="${BASE}receptionist_dashboard.html"><img src="${ADMIN_BASE}images/logo.png" alt="Hospital Logo"></a>
            </div>
            <ul>
                ${MENU.map(m => `
                <li class="${m.key === current ? "nav-active" : ""}">
                    <a href="${BASE}${m.href}">
                        <svg class="icon" width="18" height="18"><use href="#i-${m.icon}"/></svg>
                        <span>${m.label}</span>
                    </a>
                </li>`).join("")}
            </ul>`;
    }

    const topbar = document.getElementById("topbar");
    if (topbar) {
        topbar.innerHTML = `
            <button id="toggleBtn" class="toggle-btn" type="button">&#9776;</button>
            <h2>Receptionist Panel</h2>`;

        document.getElementById("toggleBtn").onclick = () => {
            const collapsed = document.body.classList.toggle("collapse");
            try { localStorage.setItem(STORE_KEY, collapsed ? "1" : "0"); } catch (e) {}
        };
    }

    const userMenuScript = document.createElement('script');
    userMenuScript.src = BASE + 'js/usermenu.js?v=2';
    document.head.appendChild(userMenuScript);
})();
