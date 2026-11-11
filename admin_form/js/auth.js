(function () {
    const BASE      = "/SDLC/Hospital_Management_System_new/Hospital_Management_System";
    const CHECK_URL = BASE + "/admin_form/php/check_auth.php";
    const LOGIN_URL = BASE + "/login_feature/index.html";
    const INTERVAL  = 5000;          // 5 giây hỏi server một lần

    let kicked = false;

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
        try {
            const res = await fetch(CHECK_URL, {
                method: "GET",
                credentials: "same-origin",
                cache: "no-store"
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