/*==============================================================
  patients.js - logic cho module Patients Management
  Dữ liệu lấy từ patients_api.php (JOIN bảng users để hiện tên + tài khoản)
==============================================================*/
(function () {

    const API = "patients_api.php";

    let patients  = [];
    let genders   = [];
    let editIndex = -1;

    const $ = id => document.getElementById(id);

    const tableBody    = $("patientTableBody");
    const patientModal = $("patientModal");
    const modalTitle   = $("modalTitle");
    const accountInfo  = $("accountInfo");
    const txtSearch    = $("txtSearch");
    const filterGender = $("filterGender");

    const f = {
        fullName: $("fullName"),
        username: $("username"),
        password: $("password"),
        email:    $("email"),
        phone:    $("phone"),
        dob:      $("dob"),
        gender:   $("gender"),
        address:  $("address"),
        history:  $("medicalHistory")
    };

    const esc = s => String(s ?? "").replace(/[&<>"']/g,
        c => ({ "&":"&amp;", "<":"&lt;", ">":"&gt;", '"':"&quot;", "'":"&#39;" }[c]));

    const cap   = s => s ? s.charAt(0).toUpperCase() + s.slice(1) : "";
    const cell  = v => v ? esc(v) : `<span class="muted">&mdash;</span>`;

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

    async function loadPatients() {
        try {
            const res  = await fetch(API + "?action=list");
            const body = await res.json().catch(() => null);
            if (kickIfUnauthorized(res, body)) return;
            if (!res.ok || !body || !body.success) {
                alert((body && body.message) || "Cannot load patients.");
                return;
            }
            patients = body.data;
            genders  = body.genders || [];
            fillGenderOptions();
            renderTable();
        } catch (err) {
            alert("Cannot load patients: " + err.message);
        }
    }

    //============================
    // Gender dropdowns (lấy từ ENUM trong DB)
    //============================
    function fillGenderOptions() {
        const keepFilter = filterGender.value;
        const keepForm   = f.gender.value;

        filterGender.innerHTML = `<option value="">All Genders</option>` +
            genders.map(g => `<option value="${esc(g)}">${esc(cap(g))}</option>`).join("");
        f.gender.innerHTML = `<option value="" disabled hidden>Select gender</option>` +
            genders.map(g => `<option value="${esc(g)}">${esc(cap(g))}</option>`).join("");

        filterGender.value = keepFilter;
        f.gender.value     = keepForm;
    }

    //============================
    // Render Table (kèm search + filter)
    //============================
    function renderTable() {

        const key    = txtSearch.value.trim().toLowerCase();
        const gender = filterGender.value;

        const rows = patients.map((p, index) => {

            const haystack = [p.full_name, p.username, p.email, p.phone, p.address]
                .join(" ").toLowerCase();

            const match = (!key || haystack.includes(key)) &&
                          (!gender || p.gender === gender);

            if (!match) return "";

            return `
            <tr>
                <td>${index + 1}</td>
                <td>${cell(p.full_name)}</td>
                <td>${cell(p.username)}</td>
                <td>${cell(p.email)}</td>
                <td>${cell(p.phone)}</td>
                <td>${cell(p.dob)}</td>
                <td>${cell(cap(p.gender))}</td>
                <td class="clip" title="${esc(p.address)}">${cell(p.address)}</td>
                <td class="clip" title="${esc(p.medical_history)}">${cell(p.medical_history)}</td>
                <td>${esc(p.created_at)}</td>
                <td>
                    <button class="btn-icon edit" type="button" title="Edit"
                            data-index="${index}">
                        <svg class="icon"><use href="#i-pen"/></svg>
                    </button>
                </td>
            </tr>`;
        }).join("");

        tableBody.innerHTML = rows ||
            `<tr class="empty-row"><td colspan="11">No patients found.</td></tr>`;
    }

    //============================
    // Modal
    //============================
    function setAccountFieldsVisible(visible) {
        document.querySelectorAll("#patientModal .acc").forEach(el => { el.hidden = !visible; });
    }

    function clearForm() {
        Object.values(f).forEach(el => { el.value = ""; });
    }

    function openAdd() {
        editIndex = -1;
        modalTitle.textContent = "Add Patient";
        clearForm();
        accountInfo.hidden = true;
        setAccountFieldsVisible(true);
        patientModal.classList.add("open");
        f.fullName.focus();
    }

    function openEdit(index) {
        editIndex = index;
        const p = patients[index];

        modalTitle.textContent = "Edit Patient";
        clearForm();

        setAccountFieldsVisible(false);
        accountInfo.hidden = false;
        accountInfo.innerHTML = p.username
            ? `<strong>${esc(p.full_name)}</strong> (@${esc(p.username)})
               <small>Account details are edited in Users Management.</small>`
            : `<strong>No linked account</strong>
               <small>This patient record is not linked to any user account.</small>`;

        f.dob.value     = p.dob || "";
        f.gender.value  = p.gender || "";
        f.address.value = p.address || "";
        f.history.value = p.medical_history || "";

        patientModal.classList.add("open");
        f.dob.focus();
    }

    function closeModal() { patientModal.classList.remove("open"); }

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

        if (f.dob.value && f.dob.value > new Date().toISOString().split("T")[0]) {
            alert("Date of birth cannot be in the future.");
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
            data.append("id", patients[editIndex].id);
        }

        data.append("dob",             f.dob.value);
        data.append("gender",          f.gender.value);
        data.append("address",         f.address.value.trim());
        data.append("medical_history", f.history.value.trim());

        if (await callApi(data)) {
            await loadPatients();
            closeModal();
        }
    }

    //============================
    // Events
    //============================
    // $("btnAddPatient").onclick = openAdd;
    $("closeModal").onclick    = closeModal;
    $("btnCancel").onclick     = closeModal;
    $("btnSave").onclick       = save;

    // Nút Edit được render động -> dùng event delegation
    tableBody.onclick = e => {
        const btn = e.target.closest("button.edit");
        if (btn) openEdit(Number(btn.dataset.index));
    };

    txtSearch.oninput     = renderTable;
    filterGender.onchange = renderTable;

    document.addEventListener("keydown", e => {
        if (e.key === "Escape") closeModal();
    });

    loadPatients();

})();