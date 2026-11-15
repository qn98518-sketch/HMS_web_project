/*==============================================================
  users_avatar.js - admin đổi / gỡ ảnh đại diện của NGƯỜI KHÁC trong Users Management
  Gọi php/account_api.php (kèm id của người cần đổi; chỉ admin mới được phép).

  Dùng:  window.hmsUserAvatar.src(user)          -> URL ảnh để gán cho <img>
         await hmsUserAvatar.upload(userId, file) -> { ok, message }
         await hmsUserAvatar.remove(userId)       -> { ok, message }
==============================================================*/
(function () {

    const API = "../php/account_api.php";
    const AV  = "../php/avatar.php";

    const MAX_BYTES = 2 * 1024 * 1024;
    const TYPES     = ["image/jpeg", "image/png", "image/webp", "image/gif"];

    async function post(fd) {
        try {
            const res  = await fetch(API, {
                method: "POST",
                body: fd,
                credentials: "same-origin",
                headers: { "X-Requested-With": "XMLHttpRequest" }   // KHÔNG tự đặt Content-Type cho FormData
            });
            const body = await res.json().catch(() => null);
            if (res.status === 401) {
                window.hmsKick && window.hmsKick(body || {});
                return { ok: false, message: "" };
            }
            return { ok: !!(res.ok && body && body.success), message: (body && body.message) || "Request failed." };
        } catch (e) {
            return { ok: false, message: "Cannot connect to the server." };
        }
    }

    window.hmsUserAvatar = {

        // user cần có id và avatar_v (do users_api.php trả về trong danh sách)
        src(u) {
            return AV + "?id=" + encodeURIComponent(u.id) + "&v=" + encodeURIComponent(u.avatar_v || "0");
        },

        async upload(userId, file) {
            if (!file)                     return { ok: false, message: "Please choose an image." };
            if (!TYPES.includes(file.type)) return { ok: false, message: "Only JPG, PNG, WEBP or GIF images are allowed." };
            if (file.size > MAX_BYTES)      return { ok: false, message: "Image is too large (max 2 MB)." };

            const fd = new FormData();
            fd.append("action", "avatar_upload");
            fd.append("id", userId);
            fd.append("avatar", file);
            return post(fd);
        },

        async remove(userId) {
            const fd = new FormData();
            fd.append("action", "avatar_remove");
            fd.append("id", userId);
            return post(fd);
        }
    };

})();
