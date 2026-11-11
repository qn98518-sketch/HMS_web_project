/*==============================================================
  layout.js  -  dựng Sidebar + Header + bộ icon cho MỌI trang admin.

  Cách dùng trong mỗi trang module:
      <body data-page="users">
          <div class="wrapper">
              <aside id="sidebar"></aside>
              <div class="main">
                  <header id="topbar"></header>
                  <main id="content"> ...nội dung module... </main>
              </div>
          </div>
          <script src="../js/layout.js"></script>

  - data-page : key của menu cần tô sáng (dashboard | users | products | reports)
  - Thêm module mới: thêm 1 dòng vào MENU bên dưới.
  - Không dùng fetch / innerHTML nạp trang, nên chạy được cả khi mở file://
==============================================================*/
(function () {

    // Thư mục admin_form/ tính từ chính đường dẫn của file này (…/admin_form/js/layout.js)
    const BASE = new URL("../", document.currentScript.src).href;

    const MENU = [
        { key: "dashboard", label: "Dashboard",        icon: "home",  href: "homepage.html" },
        { key: "users",     label: "Users management", icon: "users", href: "users_management_feature/index.html" },
        // { key: "products",  label: "Products",         icon: "box",   href: "products_feature/index.html" },
        { key: "doctors",  label: "Doctors management",  icon: "doctor",  href: "doctors_managements_feature/index.html" },
        { key: "patients", label: "Patients management", icon: "patient", href: "patients_management_feature/index.html" },
        { key: "reports",   label: "Reports",          icon: "chart", href: "reports_feature/index.html" }
    ];

    // Icon SVG nhúng sẵn (không phụ thuộc Font Awesome / CDN)
    const SPRITE = `
    <svg width="0" height="0" style="position:absolute" aria-hidden="true">
      <defs>
        <symbol id="i-home"   viewBox="0 0 24 24"><path d="M12 3 2 12h3v9h5v-6h4v6h5v-9h3z"/></symbol>
        <symbol id="i-users"  viewBox="0 0 24 24"><path d="M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zm8 0a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM9 13c-3.3 0-7 1.7-7 4v3h14v-3c0-2.3-3.7-4-7-4zm8 .1c-.4 0-.8 0-1.2.1 1.4 1 2.2 2.3 2.2 3.8v3h6v-3c0-2-3.4-3.9-7-3.9z"/></symbol>
        <symbol id="i-box"    viewBox="0 0 24 24"><path d="M12 2 3 6.5v11L12 22l9-4.5v-11L12 2zm0 2.2 5.9 2.9L12 10 6.1 7.1 12 4.2zM5 8.8l6 3v7.4l-6-3V8.8zm8 10.4v-7.4l6-3v7.4l-6 3z"/></symbol>
        <symbol id="i-chart"  viewBox="0 0 24 24"><path d="M3 3h2v16h16v2H3V3zm5 8h3v6H8v-6zm5-5h3v11h-3V6zm5 8h3v3h-3v-3z"/></symbol>
        <symbol id="i-plus"   viewBox="0 0 24 24"><path d="M11 4h2v7h7v2h-7v7h-2v-7H4v-2h7V4z"/></symbol>
        <symbol id="i-search" viewBox="0 0 24 24"><path d="M10 3a7 7 0 1 0 4.3 12.5l4.6 4.6 1.4-1.4-4.6-4.6A7 7 0 0 0 10 3zm0 2a5 5 0 1 1 0 10 5 5 0 0 1 0-10z"/></symbol>
        <symbol id="i-pen"    viewBox="0 0 24 24"><path d="m3 17.3 10.8-10.8 3.7 3.7L6.7 21H3v-3.7zM15.2 5.1l1.9-1.9a1 1 0 0 1 1.4 0l2.3 2.3a1 1 0 0 1 0 1.4l-1.9 1.9-3.7-3.7z"/></symbol>
        <symbol id="i-trash"  viewBox="0 0 24 24"><path d="M9 3h6l1 2h4v2H4V5h4l1-2zM5 9h14l-1 12H6L5 9zm4 2v8h2v-8H9zm4 0v8h2v-8h-2z"/></symbol>
        <symbol id="i-doctor"  viewBox="0 0 24 24"><path fill-rule="evenodd" d="M12 3a4 4 0 1 0 0 8 4 4 0 0 0 0-8zM4 21v-1.5c0-2.9 3.6-4.5 8-4.5s8 1.6 8 4.5V21H4zm7.3-5.6v1.5H9.8v1.4h1.5v1.5h1.4v-1.5h1.5v-1.4h-1.5v-1.5h-1.4z"/></symbol>
        <symbol id="i-patient" viewBox="0 0 24 24"><path d="M2 5h2v14H2zM4 15h18v2H4zM20 17h2v2h-2zM8 9.5a2.2 2.2 0 1 0 0 4.4 2.2 2.2 0 0 0 0-4.4zM11 11h7a3 3 0 0 1 3 3v1H11z"/></symbol>
      </defs>
    </svg>`;

    const STORE_KEY = "hms_sidebar_collapsed";

    // Khôi phục trạng thái thu gọn sidebar (bọc try/catch phòng khi trình duyệt chặn storage)
    try {
        if (localStorage.getItem(STORE_KEY) === "1") document.body.classList.add("collapse");
    } catch (e) {}

    document.body.insertAdjacentHTML("afterbegin", SPRITE);

    // ---- Sidebar ----
    const current = document.body.dataset.page;

    document.getElementById("sidebar").innerHTML = `
        <div class="logo">
            <a href="${BASE}homepage.html"><img src="${BASE}images/logo.png" alt="Hospital Logo"></a>
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
        <h2>Admin Panel</h2>`;

    document.getElementById("toggleBtn").onclick = () => {
        const collapsed = document.body.classList.toggle("collapse");
        try { localStorage.setItem(STORE_KEY, collapsed ? "1" : "0"); } catch (e) {}
    };

})();
