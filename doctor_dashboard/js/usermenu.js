/*==============================================================
  usermenu.js - Menu người dùng (tên + nút đăng xuất) cho Doctor Dashboard
  Tự thêm vào header sau khi layout.js load
==============================================================*/
(function () {
    // Đường dẫn tới admin_form/php/account_api.php từ gốc dự án
    const BASE        = new URL("../../", document.currentScript.src).href;
    const ACCOUNT_API = new URL("admin_form/php/account_api.php", BASE).href;
    const LOGIN_URL   = new URL("login_feature/index.html", BASE).href;

    // Tạo phần tử usermenu trong header
    const menu = document.createElement("div");
    menu.id = "userMenu";
    menu.style.cssText = "margin-left:auto;display:flex;align-items:center;gap:14px;";
    menu.innerHTML = `
        <span id="umName" style="font-size:14px;color:#374151;font-weight:600;">...</span>
        <button id="umLogout" type="button"
            style="border:none;background:#1a6fa3;color:white;padding:8px 16px;border-radius:8px;cursor:pointer;font-size:14px;display:flex;align-items:center;gap:6px;">
            <svg width="16" height="16" fill="currentColor" viewBox="0 0 24 24">
                <path d="M17 7l-1.41 1.41L18.17 11H8v2h10.17l-2.58 2.58L17 17l5-5-5-5zM4 5h8V3H4c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h8v-2H4V5z"/>
            </svg>
            Logout
        </button>`;

    const topbar = document.getElementById("topbar");
    if (topbar) topbar.appendChild(menu);

    // Lấy thông tin tài khoản
    fetch(ACCOUNT_API + "?action=me", { credentials: "same-origin", cache: "no-store" })
        .then(r => r.json())
        .then(d => {
            if (d.success && d.data) {
                const el = document.getElementById("umName");
                if (el) el.textContent = "Dr. " + (d.data.full_name || d.data.username);
            }
        })
        .catch(() => {});

    // Đăng xuất
    document.getElementById("umLogout").onclick = async () => {
        try {
            // Gọi API logout (account_api.php không có action logout riêng,
            // ta chỉ cần xóa session phía server bằng cách gọi một endpoint đặc biệt
            // hoặc redirect về login)
            // Hiện tại: redirect về login (session sẽ hết khi trình duyệt đóng hoặc timeout)
            // Đơn giản nhất: gọi PHP logout
            await fetch(new URL("doctor_dashboard/php/logout.php", BASE).href, {
                method: "POST",
                credentials: "same-origin"
            });
        } catch (e) {}
        window.location.href = LOGIN_URL;
    };
})();
