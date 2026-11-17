/*==============================================================
  appointments.js - Logic trang Appointments của Doctor
  
  Chức năng:
  - Hiển thị danh sách lịch hẹn của bác sĩ (filter, search)
  - Xem chi tiết lịch
  - Accept / Reject (respond)
  - Complete (khi confirmed + đã đến giờ + có medical record)
==============================================================*/
(function () {

    const API = "appointments_api.php";

    const STATUS_LABEL = {
        pending: "Pending", confirmed: "Confirmed", rejected: "Rejected",
        cancelled: "Cancelled", completed: "Completed", no_show: "No-show"
    };
    const STATUS_ORDER = ["pending", "confirmed", "rejected", "cancelled", "completed", "no_show"];
    const RESP_LABEL   = { pending: "Awaiting", accepted: "Accepted", rejected: "Rejected" };

    let items        = [];
    let statusFilter = "";
    let acting       = null;    // Lịch đang được thao tác

    const $ = id => document.getElementById(id);

    // Escape HTML để tránh XSS
    const esc = s => String(s ?? "").replace(/[&<>"']/g,
        c => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));

    const fmt     = s => s ? s.slice(0, 16) : "";
    const fmtFull = s => s ? s.slice(0, 19) : "";
    const cell    = v => v ? esc(v) : `<span style="color:#aaa">&mdash;</span>`;

    // Kiểm tra lịch có thể Complete chưa (confirmed + đã qua giờ)
    const canComplete = a =>
        a.status === "confirmed" &&
        new Date(a.datetime.replace(" ", "T")) <= new Date();

    // Kiểm tra lịch cần phản hồi (pending + chưa phản hồi + chưa qua giờ)
    const needsResponse = a =>
        a.status === "pending" &&
        a.doctor_response === "pending" &&
        new Date(a.datetime.replace(" ", "T")) > new Date();

    //============================
    // API helpers
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
            items = body.data || [];
            renderAll();
        } catch (err) {
            alert("Cannot load appointments: " + err.message);
        }
    }

    //============================
    // Filters + render
    //============================
    function visible(a) {
        if (statusFilter && a.status !== statusFilter) return false;

        const r = $("filterResponse").value;
        if (r && a.doctor_response !== r) return false;

        const day = a.datetime ? a.datetime.slice(0, 10) : "";
        if ($("dateFrom").value && day < $("dateFrom").value) return false;
        if ($("dateTo").value   && day > $("dateTo").value)   return false;

        const key = $("txtSearch").value.trim().toLowerCase();
        if (key) {
            const hay = [a.patient_name, a.patient_username, a.visit_reason].join(" ").toLowerCase();
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

        $("statusChips").innerHTML =
            chip("", "All", items.length) +
            STATUS_ORDER.map(s => chip(s, STATUS_LABEL[s], counts[s] || 0)).join("");
    }

    function renderTable() {
        const rows = items.filter(visible).map((a, i) => {

            // Nhãn phản hồi của bác sĩ
            const respBadge =
                `<span class="badge resp-${esc(a.doctor_response)}" title="${
                    a.doctor_response === "rejected" ? esc(a.doctor_reject_reason) : ""
                }">${esc(RESP_LABEL[a.doctor_response] || a.doctor_response)}</span>`;

            // Các nút hành động
            let actions = `
                <button class="btn-icon view" type="button" title="View details"
                        data-action="view" data-id="${esc(a.id)}">
                    <svg class="icon"><use href="#i-eye"/></svg>
                </button>`;

            if (needsResponse(a)) {
                actions += `
                <button class="btn-icon accept" type="button" title="Accept"
                        data-action="accept" data-id="${esc(a.id)}">
                    <svg class="icon"><use href="#i-check"/></svg>
                </button>
                <button class="btn-icon reject" type="button" title="Reject"
                        data-action="reject" data-id="${esc(a.id)}">
                    <svg class="icon"><use href="#i-x"/></svg>
                </button>`;
            }

            if (canComplete(a)) {
                actions += `
                <button class="btn-icon complete" type="button" title="Mark as Completed"
                        data-action="complete" data-id="${esc(a.id)}">
                    <svg class="icon"><use href="#i-done"/></svg>
                </button>`;
            }

            // Nhãn trạng thái chờ lễ tân (đã accept nhưng chưa confirmed)
            let statusNote = "";
            if (a.status === "pending" && a.doctor_response === "accepted") {
                statusNote = `<span class="waiting-label">Waiting for reception</span>`;
            }

            return `
            <tr>
                <td>${i + 1}</td>
                <td class="nowrap">${esc(fmt(a.datetime))}</td>
                <td>
                    ${cell(a.patient_name)}
                    ${a.patient_username ? `<span class="sub">@${esc(a.patient_username)}</span>` : ""}
                </td>
                <td class="clip" title="${esc(a.visit_reason)}">${cell(a.visit_reason)}</td>
                <td>
                    <span class="badge st-${esc(a.status)}">${esc(STATUS_LABEL[a.status] || a.status)}</span>
                    ${statusNote}
                </td>
                <td>${respBadge}</td>
                <td class="actions-col">${actions}</td>
            </tr>`;
        }).join("");

        $("apptBody").innerHTML = rows ||
            `<tr class="empty-row"><td colspan="7">No appointments found.</td></tr>`;
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

    // Đóng khi click nút có data-close
    document.addEventListener("click", e => {
        const b = e.target.closest("[data-close]");
        if (b) closeModal(b.dataset.close);
    });

    // Đóng khi nhấn Escape
    document.addEventListener("keydown", e => {
        if (e.key === "Escape") {
            ["viewModal", "acceptModal", "rejectModal", "completeModal"].forEach(closeModal);
        }
    });

    // Tóm tắt lịch hẹn
    const summary = a =>
        `<strong>${esc(a.patient_name || "Unknown patient")}</strong>
         <small style="display:block;margin-top:4px;color:#64748b;">${esc(fmt(a.datetime))}</small>`;

    //============================
    // View chi tiết
    //============================
    function openView(id) {
        const a = items.find(x => x.id === id);
        if (!a) return;

        const tl = [];

        tl.push(`<li><strong>Requested</strong><span>${esc(fmtFull(a.created_at))}</span></li>`);

        if (a.doctor_response === "pending") {
            tl.push(`<li class="waiting"><strong>Your response</strong><span>Not yet responded</span></li>`);
        } else if (a.doctor_response === "accepted") {
            tl.push(`<li class="good"><strong>You accepted</strong><span>${esc(fmtFull(a.doctor_responded_at))}</span></li>`);
        } else {
            tl.push(`<li class="bad">
                        <strong>You rejected</strong>
                        <span>${esc(fmtFull(a.doctor_responded_at))}</span>
                        <p>Reason: ${esc(a.doctor_reject_reason)}</p>
                     </li>`);
        }

        if (a.reviewed_at) {
            const isOverride = a.doctor_response === "rejected" &&
                               ["confirmed", "completed", "no_show"].includes(a.status);
            tl.push(`<li class="${isOverride ? "warn" : "good"}">
                        <strong>Reception decision</strong>
                        <span>${esc(a.reviewer_name || "—")} &middot; ${esc(fmtFull(a.reviewed_at))}</span>
                        ${a.review_note ? `<p>Note: ${esc(a.review_note)}</p>` : ""}
                        ${isOverride ? `<p class="override-label">Reception overrode your rejection.</p>` : ""}
                     </li>`);
        } else if (a.status === "pending") {
            tl.push(`<li class="waiting"><strong>Reception decision</strong><span>Awaiting decision</span></li>`);
        }

        if (a.status === "cancelled") {
            tl.push(`<li class="bad"><strong>Cancelled</strong></li>`);
        }
        if (a.status === "completed") {
            tl.push(`<li class="good"><strong>Completed</strong></li>`);
        }
        if (a.status === "no_show") {
            tl.push(`<li class="bad"><strong>Patient did not show up</strong></li>`);
        }

        $("viewBody").innerHTML = `
        <dl class="detail-grid">
            <div><dt>Patient</dt><dd>${cell(a.patient_name)}${a.patient_username ? ` <span style="color:#94a3b8">@${esc(a.patient_username)}</span>` : ""}</dd></div>
            <div><dt>Date &amp; Time</dt><dd>${esc(fmt(a.datetime))}</dd></div>
            <div><dt>Status</dt><dd><span class="badge st-${esc(a.status)}">${esc(STATUS_LABEL[a.status])}</span></dd></div>
            <div><dt>Your Response</dt><dd><span class="badge resp-${esc(a.doctor_response)}">${esc(RESP_LABEL[a.doctor_response])}</span></dd></div>
            <div class="span-2"><dt>Visit Reason</dt><dd>${cell(a.visit_reason)}</dd></div>
        </dl>
        <h4 class="section-title">Progress</h4>
        <ul class="timeline">${tl.join("")}</ul>`;

        // Nút trong footer modal
        let footer = "";
        if (needsResponse(a)) {
            footer += `
                <button class="btn btn-success" type="button" data-act="accept" data-id="${esc(a.id)}">Accept</button>
                <button class="btn btn-danger" type="button" data-act="reject" data-id="${esc(a.id)}">Reject</button>`;
        }
        if (canComplete(a)) {
            footer += `<button class="btn btn-primary" type="button" data-act="complete" data-id="${esc(a.id)}">Mark Completed</button>`;
        }
        footer += `<button class="btn btn-secondary" type="button" data-close="viewModal">Close</button>`;
        $("viewFooter").innerHTML = footer;

        openModal("viewModal");
    }

    // Nút trong footer modal view
    $("viewFooter").onclick = e => {
        const b = e.target.closest("button[data-act]");
        if (!b) return;
        closeModal("viewModal");
        const id = b.dataset.id;
        if (b.dataset.act === "accept")   openAccept(id);
        if (b.dataset.act === "reject")   openReject(id);
        if (b.dataset.act === "complete") openComplete(id);
    };

    //============================
    // Accept
    //============================
    function openAccept(id) {
        const a = items.find(x => x.id === id);
        if (!a || !needsResponse(a)) return;
        acting = a;
        $("acceptInfo").innerHTML = summary(a);
        openModal("acceptModal");
    }

    $("btnConfirmAccept").onclick = async () => {
        if (!acting) return;
        const data = new FormData();
        data.append("action", "respond");
        data.append("id", acting.id);
        data.append("decision", "accepted");

        if (await callApi(data)) {
            closeModal("acceptModal");
            await loadAll();
        }
    };

    //============================
    // Reject
    //============================
    function openReject(id) {
        const a = items.find(x => x.id === id);
        if (!a || !needsResponse(a)) return;
        acting = a;
        $("rejectInfo").innerHTML = summary(a);
        $("rejectReason").value   = "";
        openModal("rejectModal");
        $("rejectReason").focus();
    }

    $("btnConfirmReject").onclick = async () => {
        if (!acting) return;
        const reason = $("rejectReason").value.trim();
        if (!reason) {
            alert("Please provide a reason for rejection.");
            $("rejectReason").focus();
            return;
        }

        const data = new FormData();
        data.append("action", "respond");
        data.append("id", acting.id);
        data.append("decision", "rejected");
        data.append("reject_reason", reason);

        if (await callApi(data)) {
            closeModal("rejectModal");
            await loadAll();
        }
    };

    //============================
    // Complete
    //============================
    function openComplete(id) {
        const a = items.find(x => x.id === id);
        if (!a || !canComplete(a)) return;
        acting = a;

        $("completeInfo").innerHTML = `
            Appointment with <strong>${esc(a.patient_name || "Unknown")}</strong>
            at <strong>${esc(fmt(a.datetime))}</strong>`;
        $("completeWarn").hidden = true;
        openModal("completeModal");
    }

    $("btnConfirmComplete").onclick = async () => {
        if (!acting) return;

        const data = new FormData();
        data.append("action", "complete");
        data.append("id", acting.id);

        const result = await callApi(data);
        if (result) {
            closeModal("completeModal");
            await loadAll();
        } else {
            // Hiện cảnh báo nếu chưa có medical record
            $("completeWarn").hidden = false;
            $("completeWarn").textContent =
                "Make sure you have saved a medical record with a diagnosis before completing.";
        }
    };

    //============================
    // Events
    //============================
    $("apptBody").onclick = e => {
        const b = e.target.closest("button[data-action]");
        if (!b) return;
        const id = b.dataset.id;
        if (b.dataset.action === "view")     openView(id);
        if (b.dataset.action === "accept")   openAccept(id);
        if (b.dataset.action === "reject")   openReject(id);
        if (b.dataset.action === "complete") openComplete(id);
    };

    $("statusChips").onclick = e => {
        const b = e.target.closest(".chip");
        if (!b) return;
        statusFilter = (b.dataset.status === statusFilter) ? "" : b.dataset.status;
        renderAll();
    };

    $("txtSearch").oninput       = renderTable;
    $("filterResponse").onchange = renderTable;
    $("dateFrom").onchange       = renderTable;
    $("dateTo").onchange         = renderTable;

    $("btnClear").onclick = () => {
        $("txtSearch").value = "";
        $("filterResponse").value = "";
        $("dateFrom").value = "";
        $("dateTo").value = "";
        statusFilter = "";
        renderAll();
    };

    // Load lần đầu
    loadAll();

})();
