/*==============================================================
  appointments.js - logic cho module Appointments Management (admin)
  Dữ liệu lấy từ appointments_api.php
==============================================================*/
(function () {

    const API = "appointments_api.php";

    const STATUS_ORDER = ["pending", "confirmed", "rejected", "cancelled", "completed", "no_show"];
    const STATUS_LABEL = { pending: "Pending", confirmed: "Confirmed", rejected: "Rejected",
                           cancelled: "Cancelled", completed: "Completed", no_show: "No-show" };
    const RESP_LABEL   = { pending: "Awaiting", accepted: "Accepted", rejected: "Rejected" };
    const OPEN         = ["pending", "confirmed"];          // còn được sửa / hủy

    let items        = [];
    let doctors      = [];      // bác sĩ có thể nhận lịch
    let patients     = [];      // bệnh nhân có thể đặt lịch
    let statusFilter = "";
    let editing      = null;    // null = đang thêm mới
    let cancelling   = null;

    const $ = id => document.getElementById(id);

    const tableBody = $("appointmentTableBody");
    const chipsEl   = $("statusChips");
    const txtSearch = $("txtSearch");
    const fDoctor   = $("filterDoctor");
    const fResponse = $("filterResponse");
    const dateFrom  = $("dateFrom");
    const dateTo    = $("dateTo");

    const esc = s => String(s ?? "").replace(/[&<>"']/g,
        c => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));

    const cell    = v => v ? esc(v) : `<span class="muted">&mdash;</span>`;
    const fmt     = s => s ? s.slice(0, 16) : "";            // "YYYY-MM-DD HH:MM"
    const fmtFull = s => s ? s.slice(0, 19) : "";

    // Lễ tân đã duyệt dù bác sĩ từ chối
    const isOverridden = a => a.doctor_response === "rejected" &&
                              ["confirmed", "completed", "no_show"].includes(a.status);

    // Tên + dòng phụ; tài khoản đã bị ẩn vẫn hiện tên (chỉ ở module này) kèm nhãn
    const personCell = (name, sub, gone) =>
        cell(name) + (gone ? `<span class="sub gone">Account removed</span>`
                           : (sub ? `<span class="sub">${esc(sub)}</span>` : ""));

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

    async function loadAll() {
        try {
            const res  = await fetch(API + "?action=list");
            const body = await res.json().catch(() => null);
            if (kickIfUnauthorized(res, body)) return;
            if (!res.ok || !body || !body.success) {
                alert((body && body.message) || "Cannot load appointments.");
                return;
            }
            items    = body.data;
            doctors  = body.doctors  || [];
            patients = body.patients || [];
            fillDoctorFilter();
            renderAll();
        } catch (err) {
            alert("Cannot load appointments: " + err.message);
        }
    }

    //============================
    // Filters + render
    //============================
    // Bộ lọc bác sĩ lấy từ chính các lịch hẹn (gồm cả bác sĩ đã bị ẩn)
    function fillDoctorFilter() {
        const keep = fDoctor.value;
        const seen = new Map();
        items.forEach(a => { if (a.doctor_id && !seen.has(a.doctor_id)) seen.set(a.doctor_id, a.doctor_name || "Unknown doctor"); });
        const list = [...seen].sort((x, y) => x[1].localeCompare(y[1]));

        fDoctor.innerHTML = `<option value="">All Doctors</option>` +
            list.map(([id, name]) => `<option value="${esc(id)}">${esc(name)}</option>`).join("");
        fDoctor.value = seen.has(keep) ? keep : "";
    }

    function visible(a) {
        if (statusFilter && a.status !== statusFilter) return false;
        if (fDoctor.value && a.doctor_id !== fDoctor.value) return false;

        const r = fResponse.value;
        if (r === "overridden") { if (!isOverridden(a)) return false; }
        else if (r && a.doctor_response !== r) return false;

        const day = a.datetime.slice(0, 10);
        if (dateFrom.value && day < dateFrom.value) return false;
        if (dateTo.value   && day > dateTo.value)   return false;

        const key = txtSearch.value.trim().toLowerCase();
        if (key) {
            const hay = [a.patient_name, a.patient_username, a.doctor_name,
                         a.specialization, a.visit_reason].join(" ").toLowerCase();
            if (!hay.includes(key)) return false;
        }
        return true;
    }

    function renderChips() {
        const counts = {};
        items.forEach(a => { counts[a.status] = (counts[a.status] || 0) + 1; });

        const chip = (value, label, n) =>
            `<button type="button" class="chip ${statusFilter === value ? "on" : ""}" data-status="${value}">
                ${label} <span class="count">${n}</span>
             </button>`;

        chipsEl.innerHTML = chip("", "All", items.length) +
            STATUS_ORDER.map(s => chip(s, STATUS_LABEL[s], counts[s] || 0)).join("");
    }

    function renderTable() {
        const rows = items.filter(visible).map((a, i) => {

            const resp =
                `<span class="badge resp-${esc(a.doctor_response)}"
                       title="${a.doctor_response === "rejected" ? esc(a.doctor_reject_reason) : ""}">
                    ${esc(RESP_LABEL[a.doctor_response] || a.doctor_response)}
                 </span>` +
                (isOverridden(a) ? `<span class="override">Overridden by reception</span>` : "");

            const canAct = OPEN.includes(a.status);

            return `
            <tr>
                <td>${i + 1}</td>
                <td>${esc(fmt(a.datetime))}</td>
                <td>${personCell(a.patient_name, a.patient_username ? "@" + a.patient_username : "", a.patient_deleted)}</td>
                <td>${personCell(a.doctor_name, a.specialization, a.doctor_deleted)}</td>
                <td class="clip" title="${esc(a.visit_reason)}">${cell(a.visit_reason)}</td>
                <td><span class="badge st-${esc(a.status)}">${esc(STATUS_LABEL[a.status] || a.status)}</span></td>
                <td>${resp}</td>
                <td>${esc(a.created_at.slice(0, 10))}</td>
                <td>
                    <button class="btn-icon view" type="button" title="View details"
                            data-action="view" data-id="${esc(a.id)}"><svg class="icon"><use href="#i-eye"/></svg></button>
                    ${canAct ? `
                    <button class="btn-icon edit" type="button" title="Edit"
                            data-action="edit" data-id="${esc(a.id)}"><svg class="icon"><use href="#i-pen"/></svg></button>
                    <button class="btn-icon delete" type="button" title="Cancel appointment"
                            data-action="cancel" data-id="${esc(a.id)}"><svg class="icon"><use href="#i-x"/></svg></button>` : ""}
                </td>
            </tr>`;
        }).join("");

        tableBody.innerHTML = rows ||
            `<tr class="empty-row"><td colspan="9">No appointments found.</td></tr>`;
    }

    function renderAll() {
        renderChips();
        renderTable();
    }

    //============================
    // Modal helpers
    //============================
    const openModal  = id => $(id).classList.add("open");
    const closeModal = id => $(id).classList.remove("open");

    document.addEventListener("click", e => {
        const b = e.target.closest("[data-close]");
        if (b) closeModal(b.dataset.close);
    });

    document.addEventListener("keydown", e => {
        if (e.key === "Escape") ["viewModal", "apptModal", "cancelModal"].forEach(closeModal);
    });

    const summary = a =>
        `<strong>${esc(a.patient_name || "Unknown patient")}</strong> with
         <strong>${esc(a.doctor_name || "Unknown doctor")}</strong>
         <small>${esc(fmt(a.datetime))}</small>`;

    //============================
    // View (dùng luôn dữ liệu đã tải, không gọi thêm API)
    //============================
    function renderDetail(a) {
        const tl = [];

        tl.push(`<li><strong>Requested</strong><span>${esc(fmtFull(a.created_at))}</span></li>`);

        if (a.doctor_response === "pending") {
            tl.push(`<li class="waiting"><strong>Doctor response</strong><span>Awaiting response</span></li>`);
        } else {
            const rej = a.doctor_response === "rejected";
            tl.push(`<li class="${rej ? "bad" : "good"}">
                        <strong>Doctor ${rej ? "rejected" : "accepted"}</strong>
                        <span>${esc(fmtFull(a.doctor_responded_at))}</span>
                        ${rej ? `<p>Reason: ${esc(a.doctor_reject_reason)}</p>` : ""}
                     </li>`);
        }

        if (a.reviewed_at) {
            tl.push(`<li class="${isOverridden(a) ? "warn" : "good"}">
                        <strong>Reviewed by reception</strong>
                        <span>${esc(a.reviewer_name || "—")} &middot; ${esc(fmtFull(a.reviewed_at))}</span>
                        ${a.review_note ? `<p>Note: ${esc(a.review_note)}</p>` : ""}
                        ${isOverridden(a) ? `<p class="override">The doctor rejected this appointment, but reception confirmed it anyway.</p>` : ""}
                     </li>`);
        } else if (a.status === "pending") {
            tl.push(`<li class="waiting"><strong>Reception decision</strong><span>Awaiting decision</span></li>`);
        }

        if (a.status === "cancelled") {
            tl.push(`<li class="bad"><strong>Cancelled</strong></li>`);
        }

        const gone = `<span class="gone">&middot; account removed</span>`;

        return `
        <dl class="detail-grid">
            <div><dt>Patient</dt><dd>${cell(a.patient_name)}${a.patient_deleted ? " " + gone
                : (a.patient_username ? ` <span class="muted">@${esc(a.patient_username)}</span>` : "")}</dd></div>
            <div><dt>Doctor</dt><dd>${cell(a.doctor_name)}${a.doctor_deleted ? " " + gone
                : (a.specialization ? ` <span class="muted">&middot; ${esc(a.specialization)}</span>` : "")}</dd></div>
            <div><dt>Date &amp; time</dt><dd>${esc(fmt(a.datetime))}</dd></div>
            <div><dt>Status</dt><dd><span class="badge st-${esc(a.status)}">${esc(STATUS_LABEL[a.status] || a.status)}</span></dd></div>
            <div class="span-2"><dt>Visit reason</dt><dd>${cell(a.visit_reason)}</dd></div>
        </dl>
        <h4 class="section-title">Progress</h4>
        <ul class="timeline">${tl.join("")}</ul>`;
    }

    function openView(id) {
        const a = items.find(x => x.id === id);
        if (!a) return;

        $("viewBody").innerHTML = renderDetail(a);
        $("viewFooter").innerHTML =
            (OPEN.includes(a.status) ? `
                <button class="btn btn-danger" type="button" data-act="cancel" data-id="${esc(a.id)}">Cancel Appointment</button>
                <button class="btn btn-primary" type="button" data-act="edit" data-id="${esc(a.id)}">Edit</button>` : "") +
            `<button class="btn btn-secondary" type="button" data-close="viewModal">Close</button>`;
        openModal("viewModal");
    }

    // Nút Edit / Cancel trong khung chi tiết
    $("viewFooter").onclick = e => {
        const b = e.target.closest("button[data-act]");
        if (!b) return;
        closeModal("viewModal");
        if (b.dataset.act === "edit") openEdit(b.dataset.id);
        else openCancel(b.dataset.id);
    };

    //============================
    // Add / Edit (dùng chung một modal)
    //============================
    const pad = n => String(n).padStart(2, "0");
    const toLocalInput = s => s.slice(0, 16).replace(" ", "T");
    const nowLocalInput = () => {
        const d = new Date();
        return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
    };

    function doctorOptions(currentId, currentLabel) {
        let html = doctors.map(d =>
            `<option value="${esc(d.id)}">${esc(d.name)}${d.specialization ? " — " + esc(d.specialization) : ""}</option>`).join("");
        // Bác sĩ hiện tại của lịch không còn nhận lịch mới được (đã tắt / bị ẩn): vẫn hiện để giữ nguyên
        if (currentId && !doctors.some(d => d.id === currentId)) {
            html = `<option value="${esc(currentId)}">${esc(currentLabel)} (unavailable)</option>` + html;
        }
        return html;
    }

    function updateEditWarning() {
        const warn = $("editWarn");
        if (!editing) { warn.hidden = true; return; }

        const changed = $("apptDoctor").value !== editing.doctor_id ||
                        $("apptDatetime").value !== toLocalInput(editing.datetime);
        if (!changed) { warn.hidden = true; return; }

        warn.hidden = false;
        warn.textContent = editing.status === "confirmed"
            ? "Changing the doctor or time resets the doctor's response and sends this appointment back to Pending, so reception must approve it again."
            : "Changing the doctor or time resets the doctor's response.";
    }

    function openAdd() {
        editing = null;
        $("apptModalTitle").textContent = "Add Appointment";
        $("editInfo").hidden = true;
        $("patientGroup").hidden = false;

        $("apptPatient").innerHTML = `<option value="" disabled selected hidden>Select patient</option>` +
            patients.map(p => `<option value="${esc(p.id)}">${esc(p.name || "(no account)")}${p.username ? " (@" + esc(p.username) + ")" : ""}</option>`).join("");
        $("apptDoctor").innerHTML = `<option value="" disabled selected hidden>Select doctor</option>` + doctorOptions("", "");

        $("apptDatetime").value = "";
        $("apptDatetime").min   = nowLocalInput();
        $("apptReason").value   = "";
        $("editWarn").hidden    = true;

        openModal("apptModal");
        $("apptPatient").focus();
    }

    function openEdit(id) {
        const a = items.find(x => x.id === id);
        if (!a || !OPEN.includes(a.status)) return;
        editing = a;

        $("apptModalTitle").textContent = "Edit Appointment";
        $("patientGroup").hidden = true;
        $("editInfo").hidden = false;
        $("editInfo").innerHTML = summary(a) +
            `<small>Status: ${esc(STATUS_LABEL[a.status])}. To change the patient, cancel this appointment and create a new one.</small>`;

        $("apptDoctor").innerHTML = doctorOptions(a.doctor_id, a.doctor_name || "Unknown doctor");
        $("apptDoctor").value     = a.doctor_id;
        $("apptDatetime").value   = toLocalInput(a.datetime);
        $("apptDatetime").removeAttribute("min");
        $("apptReason").value     = a.visit_reason;

        updateEditWarning();
        openModal("apptModal");
    }

    async function saveAppt() {
        const isCreate = editing === null;
        const dt = $("apptDatetime").value;

        if (isCreate && !$("apptPatient").value) { alert("Please choose a patient."); return; }
        if (!$("apptDoctor").value)              { alert("Please choose a doctor.");  return; }
        if (!dt)                                 { alert("Please choose a date and time."); return; }

        if ((isCreate || dt !== toLocalInput(editing.datetime)) && new Date(dt) <= new Date()) {
            alert("The date and time must be in the future.");
            return;
        }

        const data = new FormData();
        data.append("action", isCreate ? "create" : "update");
        if (isCreate) data.append("patient_id", $("apptPatient").value);
        else          data.append("id", editing.id);
        data.append("doctor_id", $("apptDoctor").value);
        data.append("appointment_datetime", dt);
        data.append("visit_reason", $("apptReason").value.trim());

        if (await callApi(data)) {
            closeModal("apptModal");
            await loadAll();
        }
    }

    //============================
    // Cancel
    //============================
    function openCancel(id) {
        const a = items.find(x => x.id === id);
        if (!a || !OPEN.includes(a.status)) return;
        cancelling = a;

        $("cancelInfo").innerHTML = summary(a) + `<small>Status: ${esc(STATUS_LABEL[a.status])}</small>`;
        openModal("cancelModal");
    }

    async function confirmCancel() {
        if (!cancelling) return;

        const data = new FormData();
        data.append("action", "cancel");
        data.append("id", cancelling.id);

        if (await callApi(data)) {
            closeModal("cancelModal");
            await loadAll();
        }
    }

    //============================
    // Events
    //============================
    $("btnAddAppointment").onclick = openAdd;

    tableBody.onclick = e => {
        const b = e.target.closest("button[data-action]");
        if (!b) return;
        const id = b.dataset.id;
        if (b.dataset.action === "view")   openView(id);
        if (b.dataset.action === "edit")   openEdit(id);
        if (b.dataset.action === "cancel") openCancel(id);
    };

    chipsEl.onclick = e => {
        const b = e.target.closest(".chip");
        if (!b) return;
        statusFilter = (b.dataset.status === statusFilter) ? "" : b.dataset.status;
        renderAll();
    };

    txtSearch.oninput  = renderTable;
    fDoctor.onchange   = renderTable;
    fResponse.onchange = renderTable;
    dateFrom.onchange  = renderTable;
    dateTo.onchange    = renderTable;

    $("btnClear").onclick = () => {
        txtSearch.value = ""; fDoctor.value = ""; fResponse.value = "";
        dateFrom.value = ""; dateTo.value = ""; statusFilter = "";
        renderAll();
    };

    $("apptDoctor").onchange   = updateEditWarning;
    $("apptDatetime").oninput  = updateEditWarning;
    $("btnSaveAppt").onclick   = saveAppt;
    $("btnConfirmCancel").onclick = confirmCancel;

    loadAll();

})();
