(function () {
    const API = 'bills_api.php';
    const STATUSES = ['Unpaid', 'Partially Paid', 'Paid', 'Cancelled'];
    const STATUS_CLASS = { Unpaid: 'unpaid', 'Partially Paid': 'partial', Paid: 'paid', Cancelled: 'cancelled' };
    const ACTION_ICONS = { view: ['view', 'i-eye'], edit: ['edit', 'i-pen'], pay: ['pay', 'i-payment'], cancel: ['delete', 'i-x'] };
    const $ = id => document.getElementById(id);
    const esc = value => String(value ?? '').replace(/[&<>"']/g,
        c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    // BigInt keeps both individual amounts and summary totals exact.
    const cents = value => {
        const [whole, fraction = ''] = String(value).split('.');
        return BigInt(whole) * 100n + BigInt(fraction.padEnd(2, '0'));
    };
    const money = value => {
        const amount = typeof value === 'bigint' ? value : cents(value);
        return (amount / 100n).toLocaleString('en-US') + '.' + String(amount % 100n).padStart(2, '0');
    };
    const parseAmount = value => /^\d{1,10}(?:\.\d{1,2})?$/.test(value) ? cents(value) : null;
    const badge = bill => `<span class="badge st-${STATUS_CLASS[bill.payment_status] || 'cancelled'}">${esc(bill.payment_status || 'Unknown')}</span>`;
    const patientName = bill => bill.patient_name || '(no linked account)';
    const isOverdue = bill => bill.payment_status !== 'Cancelled' && cents(bill.balance) > 0n && bill.due_date && bill.due_date < today;

    let bills = [], patients = [], appointments = [], csrf = '', today = '';
    let statusFilter = '', editing = null, paying = null, cancelling = null;
    let busy = false, loading = false, previousFocus = null;

    function message(id, text) {
        $(id).textContent = text;
        $(id).hidden = !text;
    }

    function openModal(id) {
        previousFocus = document.activeElement;
        $(id).classList.add('open');
        const field = $(id).querySelector('input:not(:disabled), select:not(:disabled), textarea, .modal-footer button');
        if (field) field.focus();
    }

    function closeModal(id) {
        if (busy) return;
        $(id).classList.remove('open');
        if (previousFocus && previousFocus.isConnected) previousFocus.focus();
    }

    function unauthorized(response, body) {
        if (response.status !== 401) return false;
        if (window.hmsKick) window.hmsKick(body);
        return true;
    }

    async function loadAll() {
        if (loading) return false;
        loading = true;
        $('btnRefresh').disabled = true;
        message('pageError', '');
        try {
            const response = await fetch(API + '?action=list', { cache: 'no-store' });
            const body = await response.json();
            if (unauthorized(response, body)) return false;
            if (!response.ok || !body.success) throw new Error(body.message || 'Cannot load bills.');
            bills = body.data;
            patients = body.patients;
            appointments = body.appointments;
            csrf = body.csrf_token;
            today = body.today;
            $('btnAddBill').disabled = false;
            render();
            return true;
        } catch (error) {
            message('pageError', error.message || 'Cannot connect to the server.');
            $('listHint').textContent = 'Could not refresh bills. Use Refresh to try again.';
            if (!csrf) $('billTableBody').innerHTML = '<tr><td colspan="11" class="empty">Bills are unavailable. Please retry.</td></tr>';
            return false;
        } finally {
            loading = false;
            $('btnRefresh').disabled = false;
        }
    }

    async function mutate(data, errorId, modalId) {
        if (busy) return;
        busy = true;
        message(errorId, '');
        message('pageNotice', '');
        const button = $(modalId).querySelector('button[type="submit"]');
        button.disabled = true;
        let success = null, conflict = false;
        try {
            data.append('csrf_token', csrf);
            const response = await fetch(API, { method: 'POST', body: data });
            const body = await response.json();
            if (unauthorized(response, body)) return;
            if (!response.ok || !body.success) {
                conflict = response.status === 409;
                throw new Error(body.message || 'Request failed.');
            }
            success = body;
        } catch (error) {
            message(errorId, error.message || 'Cannot connect to the server. Refresh bills before retrying.');
        } finally {
            busy = false;
            button.disabled = false;
        }
        if (success) {
            closeModal(modalId);
            await loadAll();
            message('pageNotice', success.message);
        } else if (conflict) {
            const text = $(errorId).textContent;
            closeModal(modalId);
            await loadAll();
            message('pageError', text);
        }
    }

    function render() {
        const key = $('txtSearch').value.trim().toLowerCase();
        const from = $('dateFrom').value, to = $('dateTo').value;
        const invalidDates = from && to && from > to;
        $('dateTo').setCustomValidity(invalidDates ? 'End date must be on or after the start date.' : '');
        const filtered = bills.filter(bill => {
            const day = (bill.bill_date || '').slice(0, 10);
            const haystack = [bill.bill_id, bill.patient_name, bill.patient_username, bill.doctor_name, bill.appointment_id, bill.notes].join(' ').toLowerCase();
            return !invalidDates && (!statusFilter || bill.payment_status === statusFilter) &&
                (!$('filterMethod').value || bill.payment_method === $('filterMethod').value) &&
                (!from || day >= from) && (!to || day <= to) &&
                (!$('filterOverdue').checked || isOverdue(bill)) && (!key || haystack.includes(key));
        });
        $('statusChips').innerHTML = ['', ...STATUSES].map(status => {
            const count = status ? bills.filter(bill => bill.payment_status === status).length : bills.length;
            return `<button type="button" class="chip ${statusFilter === status ? 'on' : ''}" aria-pressed="${statusFilter === status}" data-status="${esc(status)}">${esc(status || 'All')} <span class="count">${count}</span></button>`;
        }).join('');
        const totals = filtered.filter(bill => bill.payment_status !== 'Cancelled').reduce((sum, bill) => {
            sum.total += cents(bill.total_amount);
            sum.paid += cents(bill.paid_amount);
            return sum;
        }, { total: 0n, paid: 0n });
        $('summaryTotal').textContent = money(totals.total);
        $('summaryPaid').textContent = money(totals.paid);
        $('summaryBalance').textContent = money(totals.total - totals.paid);
        $('listHint').textContent = invalidDates ? 'Choose an end date on or after the start date.' :
            `${filtered.length} of ${bills.length} bills. Amounts above reflect the current filters and exclude cancelled bills.`;
        $('billTableBody').innerHTML = filtered.map((bill, index) => {
            const active = bill.payment_status !== 'Cancelled';
            const action = (name, label) => {
                const [style, icon] = ACTION_ICONS[name];
                return `<button type="button" class="btn-icon ${style}" title="${label}" data-action="${name}" data-id="${esc(bill.bill_id)}" aria-label="${label} ${esc(bill.bill_id)}"><svg class="icon" aria-hidden="true"><use href="#${icon}"/></svg></button>`;
            };
            return `<tr>
                <td>${index + 1}</td>
                <td>${esc(patientName(bill))}${bill.patient_deleted ? '<span class="sub gone">Account removed</span>' : (bill.patient_username ? `<span class="sub">@${esc(bill.patient_username)}</span>` : '')}</td>
                <td>${bill.appointment_id ? `${esc((bill.appointment_datetime || '').slice(0, 16))}<span class="sub">${esc(bill.doctor_name || 'Unknown doctor')}</span>` : '&mdash;'}</td>
                <td class="amount">${money(bill.total_amount)}</td><td class="amount">${money(bill.paid_amount)}</td><td class="amount">${money(bill.balance)}</td>
                <td>${esc(bill.payment_method || '—')}</td><td>${badge(bill)}</td>
                <td>${esc((bill.bill_date || '').slice(0, 10))}</td>
                <td>${esc(bill.due_date || '—')}${isOverdue(bill) ? '<span class="sub overdue">Overdue</span>' : ''}</td>
                <td>${action('view', 'View details')}${active ? action('edit', 'Edit') : ''}${active && cents(bill.balance) > 0n ? action('pay', 'Record payment') : ''}${active && cents(bill.paid_amount) === 0n ? action('cancel', 'Cancel bill') : ''}</td>
            </tr>`;
        }).join('') || '<tr><td colspan="11" class="empty">No bills match these filters.</td></tr>';
    }

    function fillAppointments() {
        const matches = appointments.filter(a => a.patient_id === $('billPatient').value);
        $('billAppointment').innerHTML = '<option value="">No linked appointment</option>' + matches.map(a =>
            `<option value="${esc(a.appointment_id)}">${esc(a.appointment_datetime.slice(0, 16))} — ${esc(a.doctor_name || 'Unknown doctor')} (${esc(a.appointment_status)})</option>`).join('');
        $('billAppointment').disabled = !$('billPatient').value;
    }

    function openBill(bill = null) {
        editing = bill;
        $('billForm').reset();
        message('billError', '');
        $('billModalTitle').textContent = bill ? 'Edit Bill' : 'Add Bill';
        document.querySelectorAll('#billModal .create-only').forEach(el => {
            el.hidden = !!bill;
            el.querySelectorAll('input, select').forEach(field => { field.disabled = !!bill; });
        });
        $('editInfo').hidden = !bill;
        if (bill) {
            $('editInfo').textContent = `${patientName(bill)} · Bill ${bill.bill_id} · Already paid: ${money(bill.paid_amount)}. Patient and appointment are fixed.`;
            $('billTotal').value = bill.total_amount;
            $('billTotal').min = bill.paid_amount;
            $('billDue').value = bill.due_date || '';
            $('billDue').min = (bill.bill_date || '').slice(0, 10);
            $('billNotes').value = bill.notes || '';
        } else {
            $('billPatient').innerHTML = '<option value="">Select patient</option>' + patients.map(patient =>
                `<option value="${esc(patient.patient_id)}">${esc(patient.full_name || '(no linked account)')}${patient.username ? ' (@' + esc(patient.username) + ')' : ''}</option>`).join('');
            $('billTotal').min = '0';
            $('billDue').min = today;
            fillAppointments();
        }
        openModal('billModal');
    }

    function openView(bill) {
        const detail = (label, value, wide = false) => `<div class="${wide ? 'span-2' : ''}"><dt>${label}</dt><dd>${esc(value || '—')}</dd></div>`;
        $('viewBody').innerHTML = `<dl class="detail-grid">
            ${detail('Bill ID', bill.bill_id, true)}${detail('Patient', patientName(bill))}${detail('Account', bill.patient_deleted ? 'Account removed' : bill.patient_username)}
            ${detail('Appointment ID', bill.appointment_id, true)}${detail('Appointment time', bill.appointment_datetime)}${detail('Doctor', bill.doctor_name)}
            ${detail('Appointment status', bill.appointment_status)}<div><dt>Payment status</dt><dd>${badge(bill)}</dd></div>
            ${detail('Total amount', money(bill.total_amount))}${detail('Paid amount', money(bill.paid_amount))}${detail('Balance', money(bill.balance))}${detail('Most recent payment method', bill.payment_method)}
            ${detail('Bill date', bill.bill_date)}${detail('Due date', bill.due_date)}<div class="span-2"><dt>Notes</dt><dd class="notes">${esc(bill.notes || '—')}</dd></div></dl>`;
        openModal('viewModal');
    }

    $('btnAddBill').onclick = () => openBill();
    $('btnRefresh').onclick = () => loadAll();
    $('billPatient').onchange = fillAppointments;
    $('billTableBody').onclick = event => {
        const button = event.target.closest('button[data-action]');
        if (!button) return;
        const bill = bills.find(item => item.bill_id === button.dataset.id);
        if (!bill) return;
        if (button.dataset.action === 'view') openView(bill);
        if (button.dataset.action === 'edit') openBill(bill);
        if (button.dataset.action === 'pay') {
            paying = bill;
            message('paymentError', '');
            $('paymentInfo').textContent = `${patientName(bill)} · Bill ${bill.bill_id} · Outstanding: ${money(bill.balance)}`;
            $('paymentAmount').value = bill.balance;
            $('paymentAmount').max = bill.balance;
            $('paymentMethod').value = bill.payment_method || 'Cash';
            openModal('paymentModal');
        }
        if (button.dataset.action === 'cancel') {
            cancelling = bill;
            message('cancelError', '');
            $('cancelInfo').textContent = `${patientName(bill)} · Bill ${bill.bill_id} · Total: ${money(bill.total_amount)}`;
            openModal('cancelModal');
        }
    };
    $('billForm').onsubmit = event => {
        event.preventDefault();
        const total = parseAmount($('billTotal').value);
        const paid = editing ? cents(editing.paid_amount) : parseAmount($('billPaid').value);
        if (total === null || paid === null || paid > total) {
            message('billError', 'Enter valid amounts. The total cannot be less than the amount already paid.');
            return;
        }
        const data = new FormData();
        data.append('action', editing ? 'update' : 'create');
        data.append('total_amount', $('billTotal').value);
        data.append('due_date', $('billDue').value);
        data.append('notes', $('billNotes').value.trim());
        if (editing) {
            data.append('id', editing.bill_id);
            data.append('revision', editing.revision);
        } else {
            data.append('patient_id', $('billPatient').value);
            data.append('appointment_id', $('billAppointment').value);
            data.append('paid_amount', $('billPaid').value);
            data.append('payment_method', $('billMethod').value);
        }
        mutate(data, 'billError', 'billModal');
    };
    $('paymentForm').onsubmit = event => {
        event.preventDefault();
        if (!paying) return;
        const amount = parseAmount($('paymentAmount').value);
        if (amount === null || amount <= 0n || amount > cents(paying.balance)) {
            message('paymentError', 'Enter a positive payment within the outstanding balance.');
            return;
        }
        const data = new FormData();
        data.append('action', 'pay');
        data.append('id', paying.bill_id);
        data.append('revision', paying.revision);
        data.append('amount', $('paymentAmount').value);
        data.append('payment_method', $('paymentMethod').value);
        mutate(data, 'paymentError', 'paymentModal');
    };
    $('cancelForm').onsubmit = event => {
        event.preventDefault();
        if (!cancelling) return;
        const data = new FormData();
        data.append('action', 'cancel');
        data.append('id', cancelling.bill_id);
        data.append('revision', cancelling.revision);
        mutate(data, 'cancelError', 'cancelModal');
    };
    $('statusChips').onclick = event => {
        const button = event.target.closest('button[data-status]');
        if (button) { statusFilter = button.dataset.status; render(); }
    };
    ['txtSearch', 'filterMethod', 'dateFrom', 'dateTo', 'filterOverdue'].forEach(id => {
        $(id).addEventListener(id === 'txtSearch' ? 'input' : 'change', render);
    });
    $('btnClear').onclick = () => {
        ['txtSearch', 'filterMethod', 'dateFrom', 'dateTo'].forEach(id => { $(id).value = ''; });
        $('filterOverdue').checked = false;
        statusFilter = '';
        render();
    };
    document.addEventListener('click', event => {
        const button = event.target.closest('[data-close]');
        if (button) closeModal(button.dataset.close);
    });
    document.addEventListener('keydown', event => {
        const modal = document.querySelector('.modal.open');
        if (!modal) return;
        if (event.key === 'Escape') closeModal(modal.id);
        if (event.key === 'Tab') {
            const fields = [...modal.querySelectorAll('button, input, select, textarea')].filter(el => !el.disabled && el.getClientRects().length);
            const first = fields[0], last = fields[fields.length - 1];
            if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
            if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
        }
    });
    loadAll();
})();
