/*==============================================================
  users.js - logic cho module Users Management
  (hiện dùng dữ liệu mẫu; sau này thay bằng gọi API PHP)
==============================================================*/
(function () {
    let users = [];     // thay cho mảng dữ liệu mẫu
    const API = "users_api.php";

    async function callApi(formData) {
        try {
            const res = await fetch(API, { method: "POST", body: formData });
            if (res.status === 401) {
                window.hmsKick && window.hmsKick(await res.json().catch(() => ({})));
                return null;
            }
            const result = await res.json();
            if (!res.ok || !result.success) {
                alert(result.message || "Request failed.");
                return null;
            }
            return result;
        } catch (err) {
            alert("Cannot connect to the server.");
            return null;
        }
    }


    async function loadUsers() {
        try {
            const res = await fetch(API + "?action=list");
            if (res.status === 401) {
                window.hmsKick && window.hmsKick(await res.json().catch(() => ({})));
                return;
            }
            const result = await res.json();
            if (!result.success) {
                alert(result.message || "Cannot load users.");
                return;
            }
            users = result.data;
            renderTable();
        } catch (err) {
            alert("Cannot load users: " + err.message);
        }
    }


    let editIndex   = -1;
    let deleteIndex = -1;

    //============================
    // Elements
    // (dùng getElementById, KHÔNG dùng id làm biến global:
    //  "status" trùng với window.status nên status.value không hoạt động)
    //============================
    const $ = id => document.getElementById(id);

    const tableBody    = $("userTableBody");
    const userModal    = $("userModal");
    const deleteModal  = $("deleteModal");
    const modalTitle   = $("modalTitle");
    const txtSearch    = $("txtSearch");
    const filterRole   = $("filterRole");
    const filterStatus = $("filterStatus");

    const f = {
        fullName: $("fullName"),
        username: $("username"),
        password: $("password"),
        email:    $("email"),
        phone:    $("phone"),
        role:     $("role"),
        status:   $("status")
    };

    const esc = s => String(s ?? "").replace(/[&<>"']/g,
        c => ({ "&":"&amp;", "<":"&lt;", ">":"&gt;", '"':"&quot;", "'":"&#39;" }[c]));

    //============================
    // Render Table (kèm search + filter)
    //============================
    function renderTable() {

        const key    = txtSearch.value.trim().toLowerCase();
        const role   = filterRole.value;
        const status = filterStatus.value;

        const rows = users.map((u, index) => {

            const match =
                (!key    || (u.full_name + " " + u.username).toLowerCase().includes(key)) &&
                (!role   || u.role === role) &&
                (!status || String(u.status) === status);

            if (!match) return "";

            return `
            <tr>
                <td>${index + 1}</td>
                <td>${esc(u.full_name)}</td>
                <td>${esc(u.username)}</td>
                <td>${esc(u.email)}</td>
                <td>${esc(u.phone)}</td>
                <td>${esc(u.role)}</td>
                <td>
                    <span class="badge ${u.status == 1 ? "active" : "disable"}">
                        ${u.status == 1 ? "Active" : "Disabled"}
                    </span>
                </td>
                <td>${esc(u.created_at)}</td>
                <td>
                    <button class="btn-icon edit" type="button" title="Edit"
                            data-action="edit" data-index="${index}">
                        <svg class="icon"><use href="#i-pen"/></svg>
                    </button>
                    <button class="btn-icon delete" type="button" title="Delete"
                            data-action="delete" data-index="${index}">
                        <svg class="icon"><use href="#i-trash"/></svg>
                    </button>
                </td>
            </tr>`;
        }).join("");

        tableBody.innerHTML = rows ||
            `<tr class="empty-row"><td colspan="9">No accounts found.</td></tr>`;
    }

    //============================
    // Form helpers
    //============================
    function clearForm() {
        f.fullName.value = "";
        f.username.value = "";
        f.password.value = "";
        f.email.value    = "";
        f.phone.value    = "";
        f.role.value     = "Admin";
        f.status.value   = "1";
    }

    //============================
    // Add / Edit
    //============================
    function openAdd() {
        editIndex = -1;
        modalTitle.textContent = "Add Account";
        clearForm();
        userModal.classList.add("open");
        f.fullName.focus();
    }

    function openEdit(index) {
        editIndex = index;
        const u = users[index];

        modalTitle.textContent = "Edit Account";

        f.fullName.value = u.full_name;
        f.username.value = u.username;
        f.password.value = "";          // để trống = giữ mật khẩu cũ
        f.email.value    = u.email;
        f.phone.value    = u.phone;
        f.role.value     = u.role;
        f.status.value   = String(u.status);

        userModal.classList.add("open");
    }

    function closeModal()  { userModal.classList.remove("open"); }
    function closeDelete() { deleteModal.classList.remove("open"); }

    async function save() {

        const fullName = f.fullName.value.trim();
        const username = f.username.value.trim();

        if (!fullName || !username) {
            alert("Please enter Full Name and Username.");
            return;
        }

        if (editIndex === -1 && !f.password.value) {
            alert("Please enter a password for the new account.");
            return;
        }

        if (f.password.value && f.password.value.length < 6) {
            alert("Password must be at least 6 characters long.");
            return;
        }

        if (f.phone.value && !/^\+?\d{7,10}$/.test(f.phone.value)) {
            alert("Invalid phone number format. It should be 7 to 10 digits.");
            return;
        }

        if (f.email.value && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(f.email.value)) {
            alert("Invalid email format.");
            return;
        }

        const duplicated = users.some((u, i) =>
            i !== editIndex && u.username.toLowerCase() === username.toLowerCase());

        if (duplicated) {
            alert("This username already exists.");
            return;
        }

        const data = new FormData();
        data.append("action",    editIndex === -1 ? "create" : "update");
        if (editIndex !== -1) data.append("id", users[editIndex].id);
        data.append("full_name", f.fullName.value.trim());
        data.append("username",  f.username.value.trim());
        data.append("password",  f.password.value);        // rỗng khi update = giữ mật khẩu cũ
        data.append("email",     f.email.value.trim());
        data.append("phone",     f.phone.value.trim());
        data.append("role",      f.role.value);
        data.append("status",    f.status.value);

        if (await callApi(data)) {
            await loadUsers();    // lấy lại danh sách mới từ DB
            closeModal();
        }
    }

    //============================
    // Events
    //============================
    $("btnAddUser").onclick = openAdd;
    $("closeModal").onclick = closeModal;
    $("btnCancel").onclick  = closeModal;
    $("btnSave").onclick    = save;

    $("cancelDelete").onclick  = closeDelete;
    $("confirmDelete").onclick = async () => {
        const data = new FormData();
        data.append("action", "delete");
        data.append("id", users[deleteIndex].id);
        if (await callApi(data)) {
            await loadUsers();
            closeDelete();
        }
    };

    // Nút Edit/Delete được render động -> dùng event delegation
    tableBody.onclick = e => {
        const btn = e.target.closest("button[data-action]");
        if (!btn) return;

        const index = Number(btn.dataset.index);

        if (btn.dataset.action === "edit") {
            openEdit(index);
        } else {
            deleteIndex = index;
            deleteModal.classList.add("open");
        }
    };

    txtSearch.oninput     = renderTable;
    filterRole.onchange   = renderTable;
    filterStatus.onchange = renderTable;

    document.addEventListener("keydown", e => {
        if (e.key === "Escape") { closeModal(); closeDelete(); }
    });

    // renderTable();
    loadUsers();  

})();
