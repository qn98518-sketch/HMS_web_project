/*==============================================================
  auth.js - Kiểm tra xác thực cho Doctor Dashboard
  Gọi check_auth.php mỗi 5 giây để đảm bảo session còn hợp lệ
  Nếu không hợp lệ: redirect về trang login
==============================================================*/
(function () {
    // BASE là thư mục doctor_dashboard/ (file này nằm ở doctor_dashboard/js/auth.js)
    const BASE      = new URL("../../", document.currentScript.src).href;
    const CHECK_URL = new URL("doctor_dashboard/php/check_auth.php", BASE).href;
    const LOGIN_URL = new URL("login_feature/index.html", BASE).href;
    const INTERVAL  = 5000;        // 5 giây kiểm tra một lần
    const TIMEOUT   = 8000;

    let kicked   = false;
    let checking = false;

    function goLogin(message) {
        if (kicked) return;
        kicked = true;
        if (message) alert(message);
        window.location.href = LOGIN_URL;
    }

    // API modules gọi hàm này khi nhận 401
    window.hmsKick = (data) => goLogin(
        data && data.code === "ACCOUNT_REVOKED" ? data.message : ""
    );

    async function check(isFirst) {
        if (checking || kicked) return;
        checking = true;
        const controller = new AbortController();
        const tid = setTimeout(() => controller.abort(), TIMEOUT);
        try {
            const res = await fetch(CHECK_URL, {
                method: "GET",
                credentials: "same-origin",
                cache: "no-store",
                signal: controller.signal
            });

            if (res.status === 401) {
                const data = await res.json().catch(() => ({}));
                window.hmsKick(data);
                return;
            }

            if (!res.ok) throw new Error("HTTP " + res.status);

            const data = await res.json();
            if (!data.success) { goLogin(""); return; }

        } catch (error) {
            console.error("Auth check error:", error);
            if (isFirst) goLogin("");
            // Lần sau bỏ qua lỗi mạng tạm thời
        } finally {
            clearTimeout(tid);
            checking = false;
        }
    }

    // Ẩn trang cho đến khi xác nhận session hợp lệ
    document.documentElement.style.visibility = "hidden";

    check(true).then(() => {
        if (!kicked) document.documentElement.style.visibility = "";
    });

    setInterval(() => check(false), INTERVAL);

    document.addEventListener("visibilitychange", () => {
        if (!document.hidden) check(false);
    });
})();
