/*==============================================================
  layout.js - Dựng Sidebar + Header cho Doctor Dashboard

  Cách dùng trong mỗi trang:
      <body data-page="dashboard">
          <div class="wrapper">
              <aside id="sidebar"></aside>
              <div class="main">
                  <header id="topbar"></header>
                  <main id="content"> ...nội dung... </main>
              </div>
          </div>
          <script src="../js/layout.js"></script>

  - data-page: key của menu cần tô sáng (dashboard | appointments | patients | treatment | account)
==============================================================*/
(function () {

    // Thư mục doctor_dashboard/ tính từ file này (…/doctor_dashboard/js/layout.js)
    const BASE = new URL("../", document.currentScript.src).href;
    // Thư mục gốc dự án (để link logo, login)
    const ROOT = new URL("../../", document.currentScript.src).href;

    const MENU = [
        { key: "dashboard",    label: "Dashboard",         icon: "home",       href: "doctor_dashboard.html" },
        { key: "appointments", label: "My Appointments",   icon: "calendar",   href: "appointments_feature/index.html" },
        { key: "patients",     label: "My Patients",       icon: "patient",    href: "patients_feature/index.html" },
        { key: "treatment",    label: "Treatment Records",  icon: "record",     href: "treatment_feature/index.html" },
    ];

    // Icon SVG nhúng sẵn (giống Admin, không cần CDN)
    const SPRITE = `
    <svg width="0" height="0" style="position:absolute" aria-hidden="true">
      <defs>
        <symbol id="i-home"     viewBox="0 0 24 24"><path d="M12 3 2 12h3v9h5v-6h4v6h5v-9h3z"/></symbol>
        <symbol id="i-calendar" viewBox="0 0 24 24"><path fill-rule="evenodd" d="M7 2h2v2h6V2h2v2h3a1 1 0 0 1 1 1v15a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1h3V2zM5 9v10h14V9H5zm2 2h4v4H7v-4z"/></symbol>
        <symbol id="i-patient"  viewBox="0 0 24 24"><path d="M2 5h2v14H2zM4 15h18v2H4zM20 17h2v2h-2zM8 9.5a2.2 2.2 0 1 0 0 4.4 2.2 2.2 0 0 0 0-4.4zM11 11h7a3 3 0 0 1 3 3v1H11z"/></symbol>
        <symbol id="i-record"   viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6zm-1 1.5L18.5 9H13V3.5zM8 13h8v2H8v-2zm0-4h5v2H8V9zm0 8h8v2H8v-2z"/></symbol>
        <symbol id="i-users"    viewBox="0 0 24 24"><path d="M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zm8 0a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM9 13c-3.3 0-7 1.7-7 4v3h14v-3c0-2.3-3.7-4-7-4zm8 .1c-.4 0-.8 0-1.2.1 1.4 1 2.2 2.3 2.2 3.8v3h6v-3c0-2-3.4-3.9-7-3.9z"/></symbol>
        <symbol id="i-search"   viewBox="0 0 24 24"><path d="M10 3a7 7 0 1 0 4.3 12.5l4.6 4.6 1.4-1.4-4.6-4.6A7 7 0 0 0 10 3zm0 2a5 5 0 1 1 0 10 5 5 0 0 1 0-10z"/></symbol>
        <symbol id="i-eye"      viewBox="0 0 24 24"><path fill-rule="evenodd" d="M12 5C6.5 5 2.7 9 1.5 12c1.2 3 5 7 10.5 7s9.3-4 10.5-7C21.3 9 17.5 5 12 5zM12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8zM12 10a2 2 0 1 0 0 4 2 2 0 0 0 0-4z"/></symbol>
        <symbol id="i-check"    viewBox="0 0 24 24"><path d="M9 16.17 4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></symbol>
        <symbol id="i-x"        viewBox="0 0 24 24"><path d="M6.4 5 5 6.4 10.6 12 5 17.6 6.4 19 12 13.4 17.6 19 19 17.6 13.4 12 19 6.4 17.6 5 12 10.6z"/></symbol>
        <symbol id="i-pen"      viewBox="0 0 24 24"><path d="m3 17.3 10.8-10.8 3.7 3.7L6.7 21H3v-3.7zM15.2 5.1l1.9-1.9a1 1 0 0 1 1.4 0l2.3 2.3a1 1 0 0 1 0 1.4l-1.9 1.9-3.7-3.7z"/></symbol>
        <symbol id="i-plus"     viewBox="0 0 24 24"><path d="M11 4h2v7h7v2h-7v7h-2v-7H4v-2h7V4z"/></symbol>
        <symbol id="i-logout"   viewBox="0 0 24 24"><path d="M17 7l-1.41 1.41L18.17 11H8v2h10.17l-2.58 2.58L17 17l5-5-5-5zM4 5h8V3H4c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h8v-2H4V5z"/></symbol>
        <symbol id="i-pill"     viewBox="0 0 24 24"><path d="M4.22 11.29 11.29 4.22a4.5 4.5 0 0 1 6.36 6.36l-7.07 7.07a4.5 4.5 0 0 1-6.36-6.36zm1.42 1.42a2.5 2.5 0 0 0 3.53 3.53l3.18-3.18-3.53-3.53-3.18 3.18zm5-5 3.18 3.18 1.77-1.77a2.5 2.5 0 0 0-3.53-3.53l-1.42 1.42z"/></symbol>
        <symbol id="i-flask"    viewBox="0 0 24 24"><path d="M14 2v8l4 8H6l4-8V2h4zm-2 0h-2v8.17L6.08 18h11.84L14 10.17V2z"/></symbol>
        <symbol id="i-doctor"   viewBox="0 0 24 24"><path fill-rule="evenodd" d="M12 3a4 4 0 1 0 0 8 4 4 0 0 0 0-8zM4 21v-1.5c0-2.9 3.6-4.5 8-4.5s8 1.6 8 4.5V21H4zm7.3-5.6v1.5H9.8v1.4h1.5v1.5h1.4v-1.5h1.5v-1.4h-1.5v-1.5h-1.4z"/></symbol>
      </defs>
    </svg>`;

    const STORE_KEY = "hms_doc_sidebar_collapsed";

    // Khôi phục trạng thái thu gọn sidebar
    try {
        if (localStorage.getItem(STORE_KEY) === "1") document.body.classList.add("collapse");
    } catch (e) {}

    document.body.insertAdjacentHTML("afterbegin", SPRITE);

    // ---- Sidebar ----
    const current = document.body.dataset.page;

    document.getElementById("sidebar").innerHTML = `
        <div class="logo">
            <a href="${BASE}doctor_dashboard.html"><img src="${ROOT}admin_form/images/logo.png" alt="Hospital Logo"></a>
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

    // ---- Header ----
    document.getElementById("topbar").innerHTML = `
        <button id="toggleBtn" class="toggle-btn" type="button">&#9776;</button>
        <h2>Doctor Panel</h2>`;

    document.getElementById("toggleBtn").onclick = () => {
        const collapsed = document.body.classList.toggle("collapse");
        try { localStorage.setItem(STORE_KEY, collapsed ? "1" : "0"); } catch (e) {}
    };

    // Load user menu (logout button)
    const userMenuScript = document.createElement('script');
    userMenuScript.src = BASE + 'js/usermenu.js';
    document.head.appendChild(userMenuScript);

})();
