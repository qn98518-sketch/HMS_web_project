/*==============================================================
  doctors.js - logic cho module Doctors Management
  Dữ liệu lấy từ doctors_api.php (JOIN bảng users để hiện tên + tài khoản)
==============================================================*/
(function () {

    const API = "doctors_api.php";
    const DEFAULT_SPEC = "Internal Medicine";

    let doctors   = [];
    let editIndex = -1;

    const $ = id => document.getElementById(id);

    const tableBody    = $("doctorTableBody");
    const doctorModal  = $("doctorModal");
    const modalTitle   = $("modalTitle");
    const accountInfo  = $("accountInfo");
    const txtSearch    = $("txtSearch");
    const filterSpec   = $("filterSpec");
    const filterStatus = $("filterStatus");
    const specList     = $("specList");

    const f = {
        fullName:       $("fullName"),
        username:       $("username"),
        password:       $("password"),
        email:          $("email"),
        phone:          $("phone"),
        specialization: $("specialization"),
        qualification:  $("qualification"),
        room:           $("roomNumber"),
        status:         $("status")
    };

    const esc = s => String(s ?? "").replace(/[&<>"']/g,
        c => ({ "&":"&amp;", "<":"&lt;", ">":"&gt;", '"':"&quot;", "'":"&#39;" }[c]));

    const cell = v => (v !== "" && v != null) ? esc(v) : `<span class="muted">&mdash;</span>`;

    //============================
    // API
    //============================
    function kickIfUnauthorized(res, body) {
        if (res.status === 401) {
            window.hmsKick && window.hmsKick(body || {});
            return true;
        }
        return false;
    }

    async function callApi(formData) {
        try {
            const res  = await fetch(API, { method: "POST", body: formData });
            const body = await res.json().catch(() => null);
            if (kickIfUnauthorized(res, body)) return null;
            if (!res.ok || !body || !body.success) {
                alert((body && body.message) || "Request failed.");
                return null;
            }
            return body;
        } catch (err) {
            alert("Cannot connect to the server.");
            return null;
        }
    }

    async function loadDoctors() {
        try {
            const res  = await fetch(API + "?action=list");
            const body = await res.json().catch(() => null);
            if (kickIfUnauthorized(res, body)) return;
            if (!res.ok || !body || !body.success) {
                alert((body && body.message) || "Cannot load doctors.");
                return;
            }
            doctors = body.data;
            fillSpecOptions();
            renderTable();
        } catch (err) {
            alert("Cannot load doctors: " + err.message);
        }
    }

    //============================
    // Specialization dropdown + datalist (lấy từ dữ liệu thực tế)
    //============================
    function fillSpecOptions() {
        const keep  = filterSpec.value;
        const specs = [...new Set([DEFAULT_SPEC, ...doctors.map(d => d.specialization).filter(Boolean)])]
            .sort((a, b) => a.localeCompare(b));

        filterSpec.innerHTML = `<option value="">All Specializations</option>` +
            specs.map(s => `<option value="${esc(s)}">${esc(s)}</option>`).join("");
        specList.innerHTML = specs.map(s => `<option value="${esc(s)}"></option>`).join("");

        filterSpec.value = specs.includes(keep) ? keep : "";
    }

    //============================
    // Render Table (kèm search + filter)
    //============================
    function renderTable() {

        const key    = txtSearch.value.trim().toLowerCase();
        const spec   = filterSpec.value;
        const status = filterStatus.value;

        const rows = doctors.map((d, index) => {

            const haystack = [d.full_name, d.username, d.email, d.phone,
                              d.specialization, d.qualification, d.room_number]
                .join(" ").toLowerCase();

            const match = (!key    || haystack.includes(key)) &&
                          (!spec   || d.specialization === spec) &&
                          (status === "" || String(d.status) === status);

            if (!match) return "";

            const active = Number(d.status) === 1;

            return `
            <tr>
                <td>${index + 1}</td>
                <td>${cell(d.full_name)}</td>
                <td>${cell(d.username)}</td>
                <td>${cell(d.email)}</td>
                <td>${cell(d.phone)}</td>
                <td class="clip" title="${esc(d.specialization)}">${cell(d.specialization)}</td>
                <td class="clip" title="${esc(d.qualification)}">${cell(d.qualification)}</td>
                <td>${cell(d.room_number)}</td>
                <td><span class="badge ${active ? "active" : "disable"}">${active ? "Active" : "Disabled"}</span></td>
                <td>
                    <button class="btn-icon edit" type="button" title="Edit"
                            data-index="${index}">
                        <svg class="icon"><use href="#i-pen"/></svg>
                    </button>
                </td>
            </tr>`;
        }).join("");

        tableBody.innerHTML = rows ||
            `<tr class="empty-row"><td colspan="10">No doctors found.</td></tr>`;
    }

    //============================
    // Modal
    //============================
    function setAccountFieldsVisible(visible) {
        document.querySelectorAll("#doctorModal .acc").forEach(el => { el.hidden = !visible; });
    }

    function clearForm() {
        Object.values(f).forEach(el => { el.value = ""; });
    }

    function openAdd() {
        editIndex = -1;
        modalTitle.textContent = "Add Doctor";
        clearForm();
        f.specialization.value = DEFAULT_SPEC;
        f.room.value           = 1;
        f.status.value         = "1";
        accountInfo.hidden = true;
        setAccountFieldsVisible(true);
        doctorModal.classList.add("open");
        f.fullName.focus();
    }

    function openEdit(index) {
        editIndex = index;
        const d = doctors[index];

        modalTitle.textContent = "Edit Doctor";
        clearForm();

        setAccountFieldsVisible(false);
        accountInfo.hidden = false;
        accountInfo.innerHTML = d.username
            ? `<strong>${esc(d.full_name)}</strong> (@${esc(d.username)})
               <small>Account details are edited in Users Management.</small>`
            : `<strong>No linked account</strong>
               <small>This doctor record is not linked to any user account.</small>`;

        f.specialization.value = d.specialization || "";
        f.qualification.value  = d.qualification  || "";
        f.room.value           = d.room_number;
        f.status.value         = String(Number(d.status) === 1 ? 1 : 0);

        doctorModal.classList.add("open");
        f.specialization.focus();
    }

    function closeModal() { doctorModal.classList.remove("open"); }

    //============================
    // Save
    //============================
    async function save() {

        const isCreate = editIndex === -1;

        if (isCreate) {
            if (!f.fullName.value.trim() || !f.username.value.trim()) {
                alert("Please enter Full Name and Username.");
                return;
            }
            if (!f.password.value) {
                alert("Please enter a password for the new account.");
                return;
            }
            if (f.password.value.length < 6) {
                alert("Password must be at least 6 characters long.");
                return;
            }
            if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(f.email.value.trim())) {
                alert("Invalid email format.");
                return;
            }
            if (!/^\+?\d{7,15}$/.test(f.phone.value.trim())) {
                alert("Invalid phone number format. It should be 7 to 15 digits.");
                return;
            }
        }

        if (!f.specialization.value.trim()) {
            alert("Please enter a specialization.");
            return;
        }
        if (!/^\d+$/.test(String(f.room.value).trim()) || Number(f.room.value) < 1) {
            alert("Room number must be a positive whole number.");
            return;
        }

        const data = new FormData();
        data.append("action", isCreate ? "create" : "update");

        if (isCreate) {
            data.append("full_name", f.fullName.value.trim());
            data.append("username",  f.username.value.trim());
            data.append("password",  f.password.value);
            data.append("email",     f.email.value.trim());
            data.append("phone",     f.phone.value.trim());
        } else {
            data.append("id", doctors[editIndex].id);
        }

        data.append("specialization", f.specialization.value.trim());
        data.append("qualification",  f.qualification.value.trim());
        data.append("room_number",    f.room.value);
        data.append("status",         f.status.value);

        if (await callApi(data)) {
            await loadDoctors();
            closeModal();
        }
    }

    //============================
    // Events
    //============================
    // $("btnAddDoctor").onclick = openAdd;
    $("closeModal").onclick   = closeModal;
    $("btnCancel").onclick    = closeModal;
    $("btnSave").onclick      = save;

    // Nút Edit được render động -> dùng event delegation
    tableBody.onclick = e => {
        const btn = e.target.closest("button.edit");
        if (btn) openEdit(Number(btn.dataset.index));
    };

    txtSearch.oninput      = renderTable;
    filterSpec.onchange    = renderTable;
    filterStatus.onchange  = renderTable;

    document.addEventListener("keydown", e => {
        if (e.key === "Escape") closeModal();
    });

    loadDoctors();

})();