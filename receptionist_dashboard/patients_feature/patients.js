document.addEventListener("DOMContentLoaded", function () {
    const API = "patients_api.php";

    let patientsData = [];
    let gendersList = [];
    let currentMode = "create"; // "create" or "update"

    const tableBody = document.getElementById("patientTableBody");
    const txtSearch = document.getElementById("txtSearch");
    const filterGender = document.getElementById("filterGender");
    const btnRefresh = document.getElementById("btnRefresh");
    const btnRegister = document.getElementById("btnRegisterPatient");

    const modal = document.getElementById("patientModal");
    const modalTitle = document.getElementById("modalTitle");
    const closeModal = document.getElementById("closeModal");
    const btnCancel = document.getElementById("btnCancel");
    const patientForm = document.getElementById("patientForm");
    const modalAlert = document.getElementById("modalAlert");

    const patientId = document.getElementById("patientId");
    const fullName = document.getElementById("fullName");
    const phone = document.getElementById("phone");
    const email = document.getElementById("email");
    const dob = document.getElementById("dob");
    const genderSelect = document.getElementById("gender");
    const address = document.getElementById("address");

    const groupFullName = document.getElementById("groupFullName");
    const groupEmail = document.getElementById("groupEmail");
    const groupDob = document.getElementById("groupDob");
    const groupGender = document.getElementById("groupGender");

    function showAlert(msg, isError = true) {
        modalAlert.textContent = msg;
        modalAlert.className = `alert-box ${isError ? 'error' : 'success'}`;
        modalAlert.style.display = "block";
    }

    function hideAlert() {
        modalAlert.style.display = "none";
        modalAlert.textContent = "";
    }

    async function loadPatients() {
        tableBody.innerHTML = `<tr><td colspan="8" class="text-center">Loading patients...</td></tr>`;
        const search = txtSearch.value.trim();

        try {
            const url = `${API}?action=list&search=${encodeURIComponent(search)}`;
            const res = await fetch(url);
            if (!res.ok) throw new Error("Failed to load patients");
            const result = await res.json();

            if (result.success) {
                patientsData = result.data || [];
                gendersList = result.genders || ["male", "female", "others"];
                populateGenderDropdowns();
                renderTable();
            } else {
                tableBody.innerHTML = `<tr><td colspan="8" class="text-center" style="color:red">${result.message}</td></tr>`;
            }
        } catch (err) {
            console.error(err);
            tableBody.innerHTML = `<tr><td colspan="8" class="text-center" style="color:red">Error connecting to server.</td></tr>`;
        }
    }

    function populateGenderDropdowns() {
        if (filterGender.options.length <= 1) {
            gendersList.forEach(g => {
                const opt = document.createElement("option");
                opt.value = g;
                opt.textContent = g.charAt(0).toUpperCase() + g.slice(1);
                filterGender.appendChild(opt);
            });
        }

        if (genderSelect.options.length <= 1) {
            gendersList.forEach(g => {
                const opt = document.createElement("option");
                opt.value = g;
                opt.textContent = g.charAt(0).toUpperCase() + g.slice(1);
                genderSelect.appendChild(opt);
            });
        }
    }

    function renderTable() {
        const selectedGender = filterGender.value.toLowerCase();
        const filtered = patientsData.filter(p => {
            if (selectedGender && (p.gender || "").toLowerCase() !== selectedGender) return false;
            return true;
        });

        if (filtered.length === 0) {
            tableBody.innerHTML = `<tr><td colspan="8" class="text-center">No patients found.</td></tr>`;
            return;
        }

        tableBody.innerHTML = filtered.map((p, idx) => `
            <tr>
                <td>${idx + 1}</td>
                <td><strong>${escapeHtml(p.full_name || "N/A")}</strong></td>
                <td><code>${escapeHtml(p.username || "No account")}</code></td>
                <td>${escapeHtml(p.phone || "N/A")}</td>
                <td>${escapeHtml(p.dob || "N/A")}</td>
                <td>${escapeHtml(p.gender ? (p.gender.charAt(0).toUpperCase() + p.gender.slice(1)) : "N/A")}</td>
                <td>${escapeHtml(p.address || "N/A")}</td>
                <td>
                    <button class="btn btn-sm btn-secondary btn-edit" data-id="${p.id}" type="button">Edit</button>
                </td>
            </tr>
        `).join("");

        document.querySelectorAll(".btn-edit").forEach(btn => {
            btn.addEventListener("click", () => openEditModal(btn.dataset.id));
        });
    }

    function openCreateModal() {
        currentMode = "create";
        modalTitle.textContent = "Register New Patient";
        patientForm.reset();
        patientId.value = "";
        hideAlert();

        groupFullName.style.display = "block";
        fullName.required = true;
        groupEmail.style.display = "block";
        groupDob.style.display = "block";
        groupGender.style.display = "block";

        modal.classList.add("open");
    }

    function openEditModal(id) {
        const patient = patientsData.find(p => p.id === id);
        if (!patient) return;

        currentMode = "update";
        modalTitle.textContent = `Edit Patient: ${patient.full_name || 'Patient'}`;
        patientForm.reset();
        hideAlert();

        patientId.value = patient.id;
        phone.value = patient.phone || "";
        address.value = patient.address || "";

        // Hide create-only fields in edit mode
        groupFullName.style.display = "none";
        fullName.required = false;
        groupEmail.style.display = "none";
        groupDob.style.display = "none";
        groupGender.style.display = "none";

        modal.classList.add("open");
    }

    function closeModalHandler() {
        modal.classList.remove("open");
        patientForm.reset();
        hideAlert();
    }

    patientForm.addEventListener("submit", async function (e) {
        e.preventDefault();
        hideAlert();

        const formData = new FormData(patientForm);
        formData.append("action", currentMode);

        try {
            const res = await fetch(API, {
                method: "POST",
                body: formData
            });

            const result = await res.json();
            if (result.success) {
                alert(result.message || "Operation successful!");
                closeModalHandler();
                loadPatients();
            } else {
                showAlert(result.message || "An error occurred.", true);
            }
        } catch (err) {
            console.error(err);
            showAlert("Server connection error.", true);
        }
    });

    // Event listeners
    btnRegister.addEventListener("click", openCreateModal);
    closeModal.addEventListener("click", closeModalHandler);
    btnCancel.addEventListener("click", closeModalHandler);

    let searchTimeout;
    txtSearch.addEventListener("input", function () {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(loadPatients, 300);
    });

    filterGender.addEventListener("change", renderTable);
    btnRefresh.addEventListener("click", loadPatients);

    function escapeHtml(str) {
        return String(str || "").replace(/[&<>'"]/g, tag => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            "'": '&#39;',
            '"': '&quot;'
        }[tag] || tag));
    }

    // Initial load
    loadPatients();
});
