/*==============================================================
  treatment.js - Logic trang Treatment Records của Doctor
==============================================================*/
(function () {
    const API = "treatment_api.php";

    let records       = [];
    let confirmedAppts = [];
    let currentRecordId = null;

    const $ = id => document.getElementById(id);

    const esc = s => String(s ?? "").replace(/[&<>"']/g,
        c => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));

    const cell   = v => v ? esc(v) : `<span style="color:#aaa">&mdash;</span>`;
    const fmt    = s => s ? s.slice(0, 16) : "";
    const fmtDay = s => s ? s.slice(0, 10) : "";

    function kickIfUnauthorized(res, body) {
        if (res.status === 401) { window.hmsKick && window.hmsKick(body || {}); return true; }
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
            if (!body || !body.success) {
                alert((body && body.message) || "Cannot load records.");
                return;
            }
            records        = body.data || [];
            confirmedAppts = body.confirmed_appts || [];
            renderTable();
        } catch (e) {
            alert("Cannot load: " + e.message);
        }
    }

    function renderTable() {
        const key = $("txtSearch").value.trim().toLowerCase();
        const rows = records.filter(r => {
            if (!key) return true;
            return [r.patient_name, r.diagnosis].join(" ").toLowerCase().includes(key);
        }).map((r, i) => `
            <tr>
                <td>${i + 1}</td>
                <td class="nowrap">${cell(fmtDay(r.record_date))}</td>
                <td>${cell(r.patient_name)}</td>
                <td class="clip" title="${esc(r.diagnosis)}">${cell(r.diagnosis)}</td>
                <td style="text-align:center;">${r.rx_count || 0}</td>
                <td style="text-align:center;">${r.test_count || 0}</td>
                <td>
                    <button class="btn-icon view" type="button" title="View details"
                            data-action="view" data-id="${esc(r.record_id)}">
                        <svg class="icon"><use href="#i-eye"/></svg>
                    </button>
                    <button class="btn-icon edit" type="button" title="Add Prescription"
                            data-action="rx" data-id="${esc(r.record_id)}">
                        <svg class="icon"><use href="#i-pill"/></svg>
                    </button>
                    <button class="btn-icon" style="background:#0ea5e9;" type="button" title="Add Test"
                            data-action="test" data-id="${esc(r.record_id)}">
                        <svg class="icon"><use href="#i-flask"/></svg>
                    </button>
                </td>
            </tr>`).join("");

        $("recordsBody").innerHTML = rows ||
            `<tr class="empty-row"><td colspan="7">No medical records found.</td></tr>`;
    }

    // Modal helpers
    const openModal  = id => $(id).classList.add("open");
    const closeModal = id => $(id).classList.remove("open");

    document.addEventListener("click", e => {
        const b = e.target.closest("[data-close]");
        if (b) closeModal(b.dataset.close);
    });
    document.addEventListener("keydown", e => {
        if (e.key === "Escape") {
            ["newRecordModal","detailModal","rxModal","testModal"].forEach(closeModal);
        }
    });

    //============================
    // New Record
    //============================
    $("btnNewRecord").onclick = () => {
        // Điền danh sách lịch Confirmed vào dropdown
        $("apptSelect").innerHTML =
            `<option value="" disabled selected>Select appointment...</option>` +
            confirmedAppts.map(a =>
                `<option value="${esc(a.appointment_id)}">
                    ${esc(a.full_name || "(no account)")} — ${a.appointment_datetime ? a.appointment_datetime.slice(0,16) : ""}
                 </option>`
            ).join("");

        $("diagnosisInput").value = "";
        $("symptomsInput").value  = "";
        $("treatmentInput").value = "";
        $("recordNotesInput").value = "";
        openModal("newRecordModal");
    };

    $("btnSaveRecord").onclick = async () => {
        const apptId   = $("apptSelect").value;
        const diagnosis = $("diagnosisInput").value.trim();
        if (!apptId) { alert("Please select an appointment."); return; }
        if (!diagnosis) { alert("Diagnosis is required."); return; }

        const data = new FormData();
        data.append("action", "create_record");
        data.append("appointment_id", apptId);
        data.append("diagnosis", diagnosis);
        data.append("symptoms", $("symptomsInput").value.trim());
        data.append("treatment", $("treatmentInput").value.trim());
        data.append("notes", $("recordNotesInput").value.trim());

        if (await callApi(data)) {
            closeModal("newRecordModal");
            await loadAll();
        }
    };

    //============================
    // View Detail
    //============================
    async function openDetail(recordId) {
        currentRecordId = recordId;
        $("detailTitle").textContent = "Medical Record Details";
        $("detailBody").innerHTML = `<p style="text-align:center;color:#888;">Loading...</p>`;
        openModal("detailModal");

        try {
            const res  = await fetch(API + "?action=detail&id=" + encodeURIComponent(recordId));
            const body = await res.json().catch(() => null);
            if (!body || !body.success) {
                $("detailBody").innerHTML = `<p style="color:#dc2626;">${esc(body?.message || "Error")}</p>`;
                return;
            }

            const r  = body.data;
            const rx = body.prescriptions || [];
            const ts = body.tests || [];

            const rxHtml = rx.length === 0
                ? `<p style="color:#888;">No prescriptions yet.</p>`
                : rx.map(p => `
                    <div class="rx-item">
                        <strong>${esc(p.medicine_name)}</strong>
                        ${p.dosage    ? `<span>Dosage: ${esc(p.dosage)}</span>` : ""}
                        ${p.frequency ? `<span>Frequency: ${esc(p.frequency)}</span>` : ""}
                        ${p.duration  ? `<span>Duration: ${esc(p.duration)}</span>` : ""}
                        <span>Qty: ${esc(p.quantity)}</span>
                        ${p.instructions ? `<span>Instructions: ${esc(p.instructions)}</span>` : ""}
                    </div>`).join("");

            const testHtml = ts.length === 0
                ? `<p style="color:#888;">No test results yet.</p>`
                : ts.map(t => `
                    <div class="test-item">
                        <strong>${esc(t.test_name)}</strong>
                        <span class="badge st-${t.Status === "Completed" ? "confirmed" : t.Status === "Cancelled" ? "cancelled" : "pending"}"
                              style="font-size:11px;padding:3px 8px;">${esc(t.Status)}</span>
                        ${t.result ? `<span>Result: ${esc(t.result)}</span>` : ""}
                        ${t.reference_range ? `<span>Reference: ${esc(t.reference_range)}</span>` : ""}
                    </div>`).join("");

            $("detailBody").innerHTML = `
                <dl class="detail-grid">
                    <div><dt>Patient</dt><dd>${cell(r.patient_name)}</dd></div>
                    <div><dt>Date</dt><dd>${cell(fmt(r.record_date))}</dd></div>
                    <div class="span-2"><dt>Diagnosis</dt><dd style="white-space:pre-wrap;">${cell(r.diagnosis)}</dd></div>
                    <div class="span-2"><dt>Symptoms</dt><dd style="white-space:pre-wrap;">${cell(r.symptoms)}</dd></div>
                    <div class="span-2"><dt>Treatment</dt><dd style="white-space:pre-wrap;">${cell(r.treatment)}</dd></div>
                    <div class="span-2"><dt>Notes</dt><dd style="white-space:pre-wrap;">${cell(r.notes)}</dd></div>
                </dl>

                <div style="display:flex;align-items:center;justify-content:space-between;margin:16px 0 10px;">
                    <h4 class="section-title" style="margin:0;">Prescriptions</h4>
                    <button class="btn btn-primary" type="button" style="padding:7px 14px;font-size:13px;"
                            data-action="open-rx" data-id="${esc(r.record_id)}">+ Add Prescription</button>
                </div>
                <div class="rx-list">${rxHtml}</div>

                <div style="display:flex;align-items:center;justify-content:space-between;margin:16px 0 10px;">
                    <h4 class="section-title" style="margin:0;">Test Results</h4>
                    <button class="btn btn-primary" type="button" style="padding:7px 14px;font-size:13px;"
                            data-action="open-test" data-id="${esc(r.record_id)}">+ Add Test</button>
                </div>
                <div class="test-list">${testHtml}</div>`;

        } catch (e) {
            $("detailBody").innerHTML = `<p style="color:#dc2626;">${esc(e.message)}</p>`;
        }
    }

    // Click actions trong modal detail
    $("detailBody").onclick = e => {
        const b = e.target.closest("button[data-action]");
        if (!b) return;
        if (b.dataset.action === "open-rx")   openRxModal(b.dataset.id);
        if (b.dataset.action === "open-test") openTestModal(b.dataset.id);
    };

    //============================
    // Prescription Modal
    //============================
    function openRxModal(recordId) {
        $("rxRecordId").value  = recordId;
        $("medName").value     = "";
        $("medDosage").value   = "";
        $("medFrequency").value= "";
        $("medDuration").value = "";
        $("medQty").value      = "1";
        $("medInstructions").value = "";
        $("rxNotes").value     = "";
        openModal("rxModal");
    }

    $("btnSaveRx").onclick = async () => {
        const recordId = $("rxRecordId").value;
        const medName  = $("medName").value.trim();
        const qty      = parseInt($("medQty").value, 10);

        if (!medName) { alert("Medicine name is required."); return; }
        if (!qty || qty <= 0) { alert("Quantity must be greater than 0."); return; }

        const data = new FormData();
        data.append("action", "add_prescription");
        data.append("record_id", recordId);
        data.append("medicine_name", medName);
        data.append("dosage", $("medDosage").value.trim());
        data.append("frequency", $("medFrequency").value.trim());
        data.append("duration", $("medDuration").value.trim());
        data.append("quantity", qty);
        data.append("instructions", $("medInstructions").value.trim());
        data.append("rx_notes", $("rxNotes").value.trim());

        if (await callApi(data)) {
            closeModal("rxModal");
            openDetail(recordId);  // Tải lại detail
            await loadAll();
        }
    };

    //============================
    // Test Result Modal
    //============================
    function openTestModal(recordId) {
        $("testRecordId").value = recordId;
        $("testName").value   = "";
        $("testResult").value = "";
        $("testRef").value    = "";
        $("testNotes").value  = "";
        openModal("testModal");
    }

    $("btnSaveTest").onclick = async () => {
        const recordId = $("testRecordId").value;
        const tName    = $("testName").value.trim();
        if (!tName) { alert("Test name is required."); return; }

        const data = new FormData();
        data.append("action", "add_test");
        data.append("record_id", recordId);
        data.append("test_name", tName);
        data.append("result", $("testResult").value.trim());
        data.append("reference_range", $("testRef").value.trim());
        data.append("test_notes", $("testNotes").value.trim());

        if (await callApi(data)) {
            closeModal("testModal");
            openDetail(recordId);
            await loadAll();
        }
    };

    //============================
    // Table click events
    //============================
    $("recordsBody").onclick = e => {
        const b = e.target.closest("button[data-action]");
        if (!b) return;
        const id = b.dataset.id;
        if (b.dataset.action === "view") openDetail(id);
        if (b.dataset.action === "rx")   { closeModal("detailModal"); openRxModal(id); }
        if (b.dataset.action === "test") { closeModal("detailModal"); openTestModal(id); }
    };

    $("txtSearch").oninput = renderTable;
    $("btnClear").onclick  = () => { $("txtSearch").value = ""; renderTable(); };

    loadAll();
})();
