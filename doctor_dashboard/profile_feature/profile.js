/*==============================================================
  profile.js - Trang My Profile cho Doctor Dashboard
  Giao tiếp với: doctor_dashboard/php/profile_api.php
==============================================================*/
(function () {

    // Đường dẫn tới profile_api.php từ thư mục profile_feature/
    const API  = "../php/profile_api.php";
    // Đường dẫn gốc dự án (để build URL avatar)
    const ROOT = new URL("../../", location.href).href;

    const MAX_BYTES = 2 * 1024 * 1024; // 2MB
    const ALLOWED_TYPES = ["image/jpeg", "image/png", "image/webp", "image/gif"];

    // Lấy element theo ID
    const $ = id => document.getElementById(id);

    // ===========================
    // Hàm gọi API (POST FormData)
    // ===========================
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
                // Session hết hạn → về login
                location.href = new URL("login_feature/index.html", ROOT).href;
                return null;
            }
            return { ok: res.ok && body && body.success, body: body || {} };
        } catch (e) {
            return { ok: false, body: { message: "Cannot connect to server." } };
        }
    }

    // Hiện thông báo
    function say(elId, text, isOk) {
        const el = $(elId);
        if (!el) return;
        el.textContent = text || "";
        el.className   = "msg" + (text ? (isOk ? " ok" : " err") : "");
    }

    // ===========================
    // Load & hiển thị profile
    // ===========================
    async function loadProfile() {
        try {
            const res  = await fetch(API + "?action=me", { credentials: "same-origin", cache: "no-store" });
            const body = await res.json().catch(() => null);

            if (res.status === 401) {
                location.href = new URL("login_feature/index.html", ROOT).href;
                return;
            }
            if (!res.ok || !body || !body.success) {
                $("loadingMsg").textContent = "Could not load profile. " + ((body && body.message) || "");
                return;
            }

            fillProfile(body.data);

        } catch (e) {
            $("loadingMsg").textContent = "Server connection error.";
        }
    }

    function fillProfile(d) {
        // Hiện nội dung, ẩn loading
        $("loadingMsg").style.display    = "none";
        $("profileContent").style.display = "";

        // Avatar
        $("profileAvatar").src = ROOT + d.avatar_url;

        // Info nhanh ở card trên
        $("profileName").textContent = "Dr. " + (d.full_name || "");
        $("profileSpec").textContent = d.specialization || "No specialization";
        $("profileRoom").textContent  = d.room_number   || "-";
        $("profileEmail").textContent = d.email         || "-";
        $("profilePhone").textContent = d.phone         || "-";

        // Form chỉnh sửa
        $("fldFullName").value      = d.full_name       || "";
        $("fldQualification").value = d.qualification   || "";
        $("fldRoom").value          = d.room_number     || "";
        $("fldEmail").value         = d.email           || "";
        $("fldPhone").value         = d.phone           || "";

        // Specialization select
        const sel = $("fldSpec");
        // Thử chọn đúng option
        let matched = false;
        for (let opt of sel.options) {
            if (opt.value === d.specialization) { opt.selected = true; matched = true; break; }
        }
        // Nếu không có trong list → thêm option "Other" hoặc dùng giá trị hiện có
        if (!matched && d.specialization) {
            const extra = document.createElement("option");
            extra.value = d.specialization;
            extra.textContent = d.specialization;
            extra.selected = true;
            sel.appendChild(extra);
        }

        // Nút xóa avatar
        $("btnRemoveAvatar").disabled = !d.has_avatar;
    }

    // ===========================
    // Avatar: chọn ảnh
    // ===========================
    $("btnPickAvatar").onclick = () => $("avatarFile").click();

    $("avatarFile").onchange = async () => {
        const file = $("avatarFile").files[0];
        $("avatarFile").value = ""; // reset để chọn lại cùng file nếu cần
        if (!file) return;

        if (!ALLOWED_TYPES.includes(file.type)) {
            say("avatarMsg", "Only JPG, PNG, WEBP or GIF allowed.", false);
            return;
        }
        if (file.size > MAX_BYTES) {
            say("avatarMsg", "Image too large (max 2 MB).", false);
            return;
        }

        say("avatarMsg", "Uploading...", true);
        const fd = new FormData();
        fd.append("action", "avatar_upload");
        fd.append("avatar", file);

        const r = await api(fd);
        if (!r) return;
        if (!r.ok) { say("avatarMsg", r.body.message || "Upload failed.", false); return; }

        say("avatarMsg", "Photo updated!", true);
        await loadProfile();
    };

    // ===========================
    // Avatar: xóa ảnh
    // ===========================
    $("btnRemoveAvatar").onclick = async () => {
        const fd = new FormData();
        fd.append("action", "avatar_remove");

        const r = await api(fd);
        if (!r) return;
        if (!r.ok) { say("avatarMsg", r.body.message || "Failed.", false); return; }

        say("avatarMsg", "Photo removed.", true);
        await loadProfile();
    };

    // ===========================
    // Lưu thông tin cá nhân
    // ===========================
    $("btnSaveProfile").onclick = async () => {
        const fullName      = $("fldFullName").value.trim();
        const specialization = $("fldSpec").value;
        const qualification  = $("fldQualification").value.trim();
        const roomNumber     = $("fldRoom").value.trim();

        if (!fullName) {
            say("profileMsg", "Please enter your full name.", false);
            return;
        }
        if (!specialization) {
            say("profileMsg", "Please select a specialization.", false);
            return;
        }

        const fd = new FormData();
        fd.append("action",         "update_info");
        fd.append("full_name",      fullName);
        fd.append("specialization", specialization);
        fd.append("qualification",  qualification);
        fd.append("room_number",    roomNumber);  // Gửi dạng string, PHP sẽ validate thành INT

        const r = await api(fd);
        if (!r) return;
        if (!r.ok) { say("profileMsg", r.body.message || "Update failed.", false); return; }

        say("profileMsg", "Profile saved successfully!", true);
        if (r.body.data) fillProfile(r.body.data);
    };

    // ===========================
    // Đổi mật khẩu
    // ===========================
    $("btnChangePw").onclick = async () => {
        const cur = $("pwCurrent").value;
        const nw  = $("pwNew").value;
        const cf  = $("pwConfirm").value;

        if (!cur) { say("pwMsg", "Please enter your current password.", false); return; }
        if (nw.length < 6) { say("pwMsg", "New password must be at least 6 characters.", false); return; }
        if (nw !== cf)     { say("pwMsg", "Passwords do not match.", false); return; }

        const fd = new FormData();
        fd.append("action",           "change_password");
        fd.append("current_password", cur);
        fd.append("new_password",     nw);

        const r = await api(fd);
        if (!r) return;
        if (!r.ok) { say("pwMsg", r.body.message || "Change failed.", false); return; }

        $("pwCurrent").value = $("pwNew").value = $("pwConfirm").value = "";
        say("pwMsg", "Password changed successfully!", true);
    };

    // ===========================
    // Khởi động
    // ===========================
    loadProfile();

})();
