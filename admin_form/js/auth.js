(function () {
    const BASE      = new URL("../../", document.currentScript.src);
    const personalAccount = document.currentScript.dataset.auth === 'account';
    const CHECK_URL = new URL(personalAccount ? "admin_form/php/account_api.php?action=me" : "admin_form/php/check_auth.php", BASE).href;
    const LOGIN_URL = new URL("login_feature/index.html", BASE).href;
    const INTERVAL  = 5000;          // 5 giây hỏi server một lần
    const REQUEST_TIMEOUT = 8000;

    let kicked = false;
    let checking = false;

    function goLogin(message) {
        if (kicked) return;
        kicked = true;
        if (message) alert(message);
        window.location.href = LOGIN_URL;
    }

    // users.js gọi hàm này khi API trả 401
    window.hmsKick = (data) => goLogin(
        data && data.code === "ACCOUNT_REVOKED" ? data.message : ""
    );

    async function check(isFirst) {
        if (checking || kicked) return;
        checking = true;
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), REQUEST_TIMEOUT);
        try {
            const res = await fetch(CHECK_URL, {
                method: "GET",
                credentials: "same-origin",
                cache: "no-store",
                signal: controller.signal
            });

            if (res.status === 401) {
                const data = await res.json().catch(() => ({}));
                window.hmsKick(data);         // chưa đăng nhập: im lặng, bị thu hồi: có thông báo
                return;
            }

            if (!res.ok) throw new Error("HTTP " + res.status);

            const data = await res.json();
            if (!data.success) { goLogin(""); return; }

        } catch (error) {
            console.error("Authentication error:", error);
            if (isFirst) goLogin("");         // giữ hành vi cũ ở lần kiểm tra đầu tiên
            // các lần sau: bỏ qua lỗi mạng tạm thời, 5 giây sau thử lại
        } finally {
            clearTimeout(timeout);
            checking = false;
        }
    }

    // Ẩn trang tới khi xác nhận xong, tránh nhấp nháy giao diện admin cho người chưa đăng nhập.
    // (Chỉ để tránh nhấp nháy; bảo mật thật nằm ở PHP.)
    document.documentElement.style.visibility = "hidden";

    check(true).then(() => {
        if (!kicked) document.documentElement.style.visibility = "";
    });

    setInterval(() => check(false), INTERVAL);

    document.addEventListener("visibilitychange", () => {
        if (!document.hidden) check(false);   // quay lại tab thì kiểm tra ngay
    });
})();
