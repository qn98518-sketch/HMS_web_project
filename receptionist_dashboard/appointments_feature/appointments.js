document.addEventListener("DOMContentLoaded", function () {
    const API = "appointments_api.php";

    let appointmentsData = [];
    let doctorsList = [];
    let patientsList = [];
    let serverNow = "";
    let activeStatusFilter = "";

    const tableBody = document.getElementById("appointmentTableBody");
    const txtSearch = document.getElementById("txtSearch");
    const filterDoctor = document.getElementById("filterDoctor");
    const filterDate = document.getElementById("filterDate");
    const btnRefresh = document.getElementById("btnRefresh");
    const btnBook = document.getElementById("btnBookAppointment");

    // Modals
    const createModal = document.getElementById("createModal");
    const createForm = document.getElementById("createForm");
    const createPatient = document.getElementById("createPatient");
    const createDoctor = document.getElementById("createDoctor");
    const createDatetime = document.getElementById("createDatetime");
    const slotHint = document.getElementById("slotHint");
    const createAlert = document.getElementById("createAlert");

    const reviewModal = document.getElementById("reviewModal");
    const appointmentDetailGrid = document.getElementById("appointmentDetailGrid");
    const actionContainer = document.getElementById("actionContainer");
    const reviewAlert = document.getElementById("reviewAlert");

    const editModal = document.getElementById("editModal");
    const editForm = document.getElementById("editForm");
    const editAppointmentId = document.getElementById("editAppointmentId");
    const editDoctor = document.getElementById("editDoctor");
    const editDatetime = document.getElementById("editDatetime");
    const editReason = document.getElementById("editReason");
    const editAlert = document.getElementById("editAlert");

    // Close buttons for modals
    document.querySelectorAll("[data-close]").forEach(btn => {
        btn.addEventListener("click", () => {
            const modalId = btn.getAttribute("data-close");
            document.getElementById(modalId).classList.remove("open");
        });
    });

    // Chips filter
    document.querySelectorAll(".status-chips .chip").forEach(chip => {
        chip.addEventListener("click", () => {
            document.querySelectorAll(".status-chips .chip").forEach(c => c.classList.remove("on"));
            chip.classList.add("on");
            activeStatusFilter = chip.getAttribute("data-status");
            renderTable();
        });
    });

    async function loadAppointments() {
        tableBody.innerHTML = `<tr class="empty-row"><td colspan="8">Loading appointments...</td></tr>`;

        try {
            const res = await fetch(`${API}?action=list`);
            if (!res.ok) throw new Error("Failed to load appointments");
            const result = await res.json();

            if (result.success) {
                appointmentsData = result.data || [];
                doctorsList = result.doctors || [];
                patientsList = result.patients || [];
                serverNow = result.now || "";

                populateDropdowns();
                updateCounts();
                renderTable();
            } else {
                tableBody.innerHTML = `<tr class="empty-row"><td colspan="8" style="color:red">${result.message}</td></tr>`;
            }
        } catch (err) {
            console.error(err);
            tableBody.innerHTML = `<tr class="empty-row"><td colspan="8" style="color:red">Error connecting to server.</td></tr>`;
        }
    }

    function populateDropdowns() {
        // Filter doctor
        if (filterDoctor.options.length <= 1) {
            doctorsList.forEach(d => {
                const opt = document.createElement("option");
                opt.value = d.id;
                opt.textContent = `${d.name} (${d.specialization || 'General'})`;
                filterDoctor.appendChild(opt);
            });
        }

        // Create modal patient
        createPatient.innerHTML = `<option value="">-- Choose Patient --</option>`;
        patientsList.forEach(p => {
            const opt = document.createElement("option");
            opt.value = p.id;
            opt.textContent = `${p.name} ${p.phone ? `(${p.phone})` : ''} [${p.username || 'No user'}]`;
            createPatient.appendChild(opt);
        });

        // Create modal doctor
        createDoctor.innerHTML = `<option value="">-- Choose Doctor --</option>`;
        doctorsList.forEach(d => {
            const opt = document.createElement("option");
            opt.value = d.id;
            opt.textContent = `${d.name} - ${d.specialization || 'General'}`;
            createDoctor.appendChild(opt);
        });

        // Edit modal doctor
        editDoctor.innerHTML = "";
        doctorsList.forEach(d => {
            const opt = document.createElement("option");
            opt.value = d.id;
            opt.textContent = `${d.name} - ${d.specialization || 'General'}`;
            editDoctor.appendChild(opt);
        });
    }

    function updateCounts() {
        const counts = { all: appointmentsData.length, pending: 0, confirmed: 0, rejected: 0, completed: 0, cancelled: 0, no_show: 0 };
        appointmentsData.forEach(a => {
            const st = (a.status || "").toLowerCase();
            if (counts[st] !== undefined) counts[st]++;
        });

        document.getElementById("cntAll").textContent = counts.all;
        document.getElementById("cntPending").textContent = counts.pending;
        document.getElementById("cntConfirmed").textContent = counts.confirmed;
        document.getElementById("cntRejected").textContent = counts.rejected;
        document.getElementById("cntCompleted").textContent = counts.completed;
        document.getElementById("cntCancelled").textContent = counts.cancelled;
        document.getElementById("cntNoShow").textContent = counts.no_show;
    }

    function renderTable() {
        const search = txtSearch.value.trim().toLowerCase();
        const docId = filterDoctor.value;
        const dateVal = filterDate.value;

        const filtered = appointmentsData.filter(a => {
            if (activeStatusFilter && a.status !== activeStatusFilter) return false;
            if (docId && a.doctor_id !== docId) return false;
            if (dateVal && (!a.datetime || !a.datetime.startsWith(dateVal))) return false;

            if (search) {
                const combined = `${a.patient_name} ${a.patient_phone} ${a.doctor_name} ${a.visit_reason}`.toLowerCase();
                if (!combined.includes(search)) return false;
            }
            return true;
        });

        if (filtered.length === 0) {
            tableBody.innerHTML = `<tr class="empty-row"><td colspan="8">No appointments matching filters.</td></tr>`;
            return;
        }

        tableBody.innerHTML = filtered.map(a => {
            const statusClass = `st-${a.status}`;
            const drClass = `dr-${a.doctor_response}`;

            const overrideHtml = a.is_overridden ? `<span class="override-tag">Overridden by reception</span>` : "";

            let reviewSummary = "Not reviewed";
            if (a.reviewer_name) {
                reviewSummary = `By: ${escapeHtml(a.reviewer_name)}`;
                if (a.review_note) reviewSummary += `<br><small class="sub">Note: ${escapeHtml(a.review_note)}</small>`;
            }

            const canReschedule = (a.status === "pending" || a.status === "confirmed");

            return `
                <tr>
                    <td>
                        <strong>${escapeHtml(formatDateTime(a.datetime))}</strong>
                        <span class="sub">ID: ${escapeHtml(a.id.substring(0, 8))}...</span>
                    </td>
                    <td>
                        <strong>${escapeHtml(a.patient_name || 'N/A')}</strong>
                        <span class="sub">${escapeHtml(a.patient_phone || '')}</span>
                        ${a.patient_deleted ? `<span class="badge" style="background:#ef4444; font-size:10px; margin-top:2px;">Account Deleted</span>` : ''}
                    </td>
                    <td>
                        ${escapeHtml(a.doctor_name || 'N/A')}
                        <span class="sub">${escapeHtml(a.specialization || 'General')}</span>
                        ${a.doctor_deleted ? `<span class="badge" style="background:#ef4444; font-size:10px; margin-top:2px;">Doctor Inactive</span>` : ''}
                    </td>
                    <td>
                        <div class="clip" title="${escapeHtml(a.visit_reason || 'No reason specified')}">
                            ${escapeHtml(a.visit_reason || '—')}
                        </div>
                    </td>
                    <td>
                        <span class="badge ${drClass}">${escapeHtml(a.doctor_response || 'pending')}</span>
                        ${a.doctor_reject_reason ? `<span class="sub" style="color:#dc2626">Reason: ${escapeHtml(a.doctor_reject_reason)}</span>` : ''}
                    </td>
                    <td>
                        <span class="badge ${statusClass}">${escapeHtml(a.status || 'pending')}</span>
                        ${overrideHtml}
                    </td>
                    <td>${reviewSummary}</td>
                    <td>
                        <div class="btn-group">
                            <button class="btn btn-sm btn-primary btn-review" data-id="${a.id}">Manage</button>
                            ${canReschedule ? `<button class="btn btn-sm btn-secondary btn-resched" data-id="${a.id}">Reschedule</button>` : ''}
                        </div>
                    </td>
                </tr>
            `;
        }).join("");

        document.querySelectorAll(".btn-review").forEach(btn => {
            btn.addEventListener("click", () => openReviewModal(btn.dataset.id));
        });

        document.querySelectorAll(".btn-resched").forEach(btn => {
            btn.addEventListener("click", () => openRescheduleModal(btn.dataset.id));
        });
    }

    function openReviewModal(id) {
        const a = appointmentsData.find(item => item.id === id);
        if (!a) return;

        reviewAlert.style.display = "none";
        document.getElementById("reviewModalTitle").textContent = `Manage Appointment - ${a.patient_name}`;

        // Summary details
        appointmentDetailGrid.innerHTML = `
            <div class="detail-item">
                <label>Patient</label>
                <div class="val">${escapeHtml(a.patient_name)} ${a.patient_phone ? `(${escapeHtml(a.patient_phone)})` : ''}</div>
            </div>
            <div class="detail-item">
                <label>Assigned Doctor</label>
                <div class="val">${escapeHtml(a.doctor_name)} (${escapeHtml(a.specialization || 'General')})</div>
            </div>
            <div class="detail-item">
                <label>Date & Time</label>
                <div class="val">${escapeHtml(formatDateTime(a.datetime))}</div>
            </div>
            <div class="detail-item">
                <label>Visit Reason</label>
                <div class="val">${escapeHtml(a.visit_reason || 'None')}</div>
            </div>
            <div class="detail-item">
                <label>Appointment Status</label>
                <div class="val"><span class="badge st-${a.status}">${escapeHtml(a.status)}</span></div>
            </div>
            <div class="detail-item">
                <label>Doctor Response</label>
                <div class="val"><span class="badge dr-${a.doctor_response}">${escapeHtml(a.doctor_response)}</span></div>
                ${a.doctor_reject_reason ? `<small style="color:#dc2626; display:block; margin-top:4px;">Doctor's reason: ${escapeHtml(a.doctor_reject_reason)}</small>` : ''}
            </div>
        `;

        // Render actions based on status
        actionContainer.innerHTML = buildActionCards(a);
        attachActionListeners(a);

        reviewModal.classList.add("open");
    }

    function buildActionCards(a) {
        let html = "";

        // Case 1: Pending + Doctor Accepted -> Normal Approve
        if (a.status === "pending" && a.doctor_response === "accepted") {
            html += `
                <div class="action-card" style="border-left: 4px solid #16a34a;">
                    <h4>Approve Appointment (Standard Review)</h4>
                    <p>The assigned doctor has accepted this visit request. Confirm this appointment for the patient.</p>
                    <div class="form-group">
                        <label>Review Note (Optional):</label>
                        <input type="text" id="actionApproveNote" placeholder="e.g. Verified with patient by phone">
                    </div>
                    <button class="btn btn-primary" id="btnApproveConfirm">Confirm Appointment</button>
                </div>
            `;
        }

        // Case 2: Pending + Doctor Rejected -> Override Exception OR Reject
        if (a.status === "pending" && a.doctor_response === "rejected") {
            html += `
                <div class="action-card" style="border-left: 4px solid #f59e0b;">
                    <h4>Doctor Rejection - Receptionist Override (Exception)</h4>
                    <p style="color:#b45309">
                        The doctor rejected with reason: <strong>"${escapeHtml(a.doctor_reject_reason || 'Not specified')}"</strong>.<br>
                        If you have arranged an exception or coordinated with the doctor, you may confirm this appointment. <strong>Review note is mandatory.</strong>
                    </p>
                    <div class="form-group">
                        <label>Justification Note <span class="required">*</span>:</label>
                        <textarea id="actionOverrideNote" rows="2" placeholder="Required explanation for overriding doctor's rejection"></textarea>
                    </div>
                    <button class="btn btn-primary" id="btnOverrideConfirm" style="background:#b45309; border-color:#b45309;">Override & Confirm Appointment</button>
                </div>
            `;
        }

        // Case 3: Pending (any) -> Receptionist can Reject
        if (a.status === "pending") {
            html += `
                <div class="action-card" style="border-left: 4px solid #dc2626;">
                    <h4>Reject Appointment Request</h4>
                    <p>Reject this appointment request and release the time slot for other patients.</p>
                    <div class="form-group">
                        <label>Rejection Reason <span class="required">*</span>:</label>
                        <input type="text" id="actionRejectNote" placeholder="e.g. Patient cancelled request / Out of clinic hours">
                    </div>
                    <button class="btn btn-danger" id="btnRejectConfirm">Reject Appointment</button>
                </div>
            `;
        }

        // Case 4: Confirmed -> Mark No-Show (if past datetime) OR Cancel
        if (a.status === "confirmed") {
            const isPast = (new Date(a.datetime.replace(" ", "T")) < new Date());
            if (isPast) {
                html += `
                    <div class="action-card" style="border-left: 4px solid #7c3aed;">
                        <h4>Mark Patient No-Show</h4>
                        <p>The scheduled appointment time has passed and the patient did not present at the clinic.</p>
                        <button class="btn btn-secondary" id="btnNoShowConfirm" style="background:#7c3aed; color:#fff; border-color:#7c3aed;">Mark as No-Show</button>
                    </div>
                `;
            }

            html += `
                <div class="action-card" style="border-left: 4px solid #6b7280;">
                    <h4>Cancel Confirmed Appointment</h4>
                    <p>Cancel this appointment if the patient called to cancel.</p>
                    <button class="btn btn-secondary" id="btnCancelAppointment" style="color:#dc2626; border-color:#dc2626;">Cancel Appointment</button>
                </div>
            `;
        }

        if (!html) {
            html = `<p style="color:#64748b; font-style:italic;">This appointment is in a terminal status (<strong>${escapeHtml(a.status)}</strong>) and no further review actions are available.</p>`;
        }

        return html;
    }

    function attachActionListeners(a) {
        const btnApprove = document.getElementById("btnApproveConfirm");
        if (btnApprove) {
            btnApprove.addEventListener("click", async () => {
                const note = (document.getElementById("actionApproveNote")?.value || "").trim();
                await postAction("approve", { id: a.id, review_note: note });
            });
        }

        const btnOverride = document.getElementById("btnOverrideConfirm");
        if (btnOverride) {
            btnOverride.addEventListener("click", async () => {
                const note = (document.getElementById("actionOverrideNote")?.value || "").trim();
                if (!note) {
                    showReviewAlert("Review note is mandatory for an override.", true);
                    return;
                }
                await postAction("override_approve", { id: a.id, review_note: note });
            });
        }

        const btnReject = document.getElementById("btnRejectConfirm");
        if (btnReject) {
            btnReject.addEventListener("click", async () => {
                const note = (document.getElementById("actionRejectNote")?.value || "").trim();
                if (!note) {
                    showReviewAlert("Please enter a rejection reason.", true);
                    return;
                }
                if (!confirm("Are you sure you want to reject this appointment?")) return;
                await postAction("reject", { id: a.id, review_note: note });
            });
        }

        const btnNoShow = document.getElementById("btnNoShowConfirm");
        if (btnNoShow) {
            btnNoShow.addEventListener("click", async () => {
                if (!confirm("Confirm marking this patient as No-Show?")) return;
                await postAction("mark_no_show", { id: a.id });
            });
        }

        const btnCancel = document.getElementById("btnCancelAppointment");
        if (btnCancel) {
            btnCancel.addEventListener("click", async () => {
                if (!confirm("Are you sure you want to cancel this appointment?")) return;
                await postAction("cancel", { id: a.id });
            });
        }
    }

    async function postAction(actionName, data) {
        reviewAlert.style.display = "none";
        const formData = new FormData();
        formData.append("action", actionName);
        for (const k in data) formData.append(k, data[k]);

        try {
            const res = await fetch(API, { method: "POST", body: formData });
            const result = await res.json();
            if (result.success) {
                alert(result.message || "Action completed!");
                reviewModal.classList.remove("open");
                loadAppointments();
            } else {
                showReviewAlert(result.message || "Failed to perform action.", true);
            }
        } catch (err) {
            console.error(err);
            showReviewAlert("Connection error.", true);
        }
    }

    function showReviewAlert(msg, isError) {
        reviewAlert.textContent = msg;
        reviewAlert.className = `alert-box ${isError ? 'error' : 'success'}`;
        reviewAlert.style.display = "block";
    }

    // Reschedule modal
    function openRescheduleModal(id) {
        const a = appointmentsData.find(item => item.id === id);
        if (!a) return;

        editAlert.style.display = "none";
        editAppointmentId.value = a.id;
        editDoctor.value = a.doctor_id;
        editDatetime.value = (a.datetime || "").replace(" ", "T").substring(0, 16);
        editReason.value = a.visit_reason || "";

        editModal.classList.add("open");
    }

    editForm.addEventListener("submit", async function (e) {
        e.preventDefault();
        editAlert.style.display = "none";

        const formData = new FormData(editForm);
        formData.append("action", "update");

        try {
            const res = await fetch(API, { method: "POST", body: formData });
            const result = await res.json();

            if (result.success) {
                alert(result.message || "Appointment rescheduled.");
                editModal.classList.remove("open");
                loadAppointments();
            } else {
                editAlert.textContent = result.message || "Error rescheduling.";
                editAlert.className = "alert-box error";
                editAlert.style.display = "block";
            }
        } catch (err) {
            console.error(err);
            editAlert.textContent = "Server connection error.";
            editAlert.className = "alert-box error";
            editAlert.style.display = "block";
        }
    });

    // Create walk-in modal
    btnBook.addEventListener("click", () => {
        createForm.reset();
        createAlert.style.display = "none";
        slotHint.textContent = "";

        // Default datetime: tomorrow at 09:00
        const d = new Date();
        d.setDate(d.getDate() + 1);
        d.setHours(9, 0, 0, 0);
        createDatetime.value = d.toISOString().substring(0, 16);

        createModal.classList.add("open");
    });

    // Check doctor availability on datetime/doctor change
    async function checkAvailability() {
        const docId = createDoctor.value;
        const dtVal = createDatetime.value;
        if (!docId || !dtVal) {
            slotHint.textContent = "";
            return;
        }

        const datePart = dtVal.split("T")[0];
        try {
            const res = await fetch(`${API}?action=availability&doctor_id=${encodeURIComponent(docId)}&date=${encodeURIComponent(datePart)}`);
            const json = await res.json();
            if (json.success && json.busy_slots) {
                const count = json.busy_slots.length;
                if (count === 0) {
                    slotHint.textContent = `✓ Doctor has no other bookings on ${datePart}.`;
                    slotHint.style.color = "#16a34a";
                } else {
                    const times = json.busy_slots.map(s => s.split(" ")[1]?.substring(0, 5)).join(", ");
                    slotHint.textContent = `ℹ Busy times on ${datePart}: ${times}`;
                    slotHint.style.color = "#d97706";
                }
            }
        } catch (e) {
            slotHint.textContent = "";
        }
    }

    createDoctor.addEventListener("change", checkAvailability);
    createDatetime.addEventListener("change", checkAvailability);

    createForm.addEventListener("submit", async function (e) {
        e.preventDefault();
        createAlert.style.display = "none";

        const formData = new FormData(createForm);
        formData.append("action", "create");

        try {
            const res = await fetch(API, { method: "POST", body: formData });
            const result = await res.json();

            if (result.success) {
                alert(result.message || "Appointment booked successfully.");
                createModal.classList.remove("open");
                loadAppointments();
            } else {
                createAlert.textContent = result.message || "Error booking appointment.";
                createAlert.className = "alert-box error";
                createAlert.style.display = "block";
            }
        } catch (err) {
            console.error(err);
            createAlert.textContent = "Server connection error.";
            createAlert.className = "alert-box error";
            createAlert.style.display = "block";
        }
    });

    // Search and filters
    let searchTimeout;
    txtSearch.addEventListener("input", () => {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(renderTable, 250);
    });

    filterDoctor.addEventListener("change", renderTable);
    filterDate.addEventListener("change", renderTable);
    btnRefresh.addEventListener("click", loadAppointments);

    function formatDateTime(str) {
        if (!str) return "N/A";
        const [d, t] = str.split(" ");
        if (!t) return d;
        return `${d} ${t.substring(0, 5)}`;
    }

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
    loadAppointments();
});
