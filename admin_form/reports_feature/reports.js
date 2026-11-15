(function (root) {
    'use strict';
    const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
    const METRICS = ['appointments', 'revenue', 'newPatients'];
    const LABELS = { appointments: 'Appointments', revenue: 'Revenue', newPatients: 'New patients' };
    const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const number = value => value === null ? '—' : value.toLocaleString('en-US', { maximumFractionDigits: 8 });
    const money = value => {
        if (value === null) return '—';
        const cents = BigInt(value);
        return (cents / 100n).toLocaleString('en-US') + '.' + String(cents % 100n).padStart(2, '0');
    };
    const decimal = value => value === null ? '' : `${BigInt(value) / 100n}.${String(BigInt(value) % 100n).padStart(2, '0')}`;
    const formatMetric = (metric, value) => metric === 'revenue' ? money(value) : number(value);
    function revenueScale(report) {
        const max = Math.max(0, ...report.months.map(row => row.revenue ?? 0));
        return max >= 100000000 ? { divisor: 100000000, unit: 'Million' } :
            max >= 100000 ? { divisor: 100000, unit: 'Thousand' } : { divisor: 100, unit: 'Amount' };
    }

    function validateData(rows) {
        const seen = new Set();
        for (const row of rows) {
            const key = `${row.year}-${row.month}`;
            if (!Number.isInteger(row.year) || !Number.isInteger(row.month) || row.month < 1 || row.month > 12 || seen.has(key)) {
                throw new Error('Invalid or duplicated month in report data.');
            }
            seen.add(key);
            if (METRICS.some(metric => row[metric] !== null && (!Number.isSafeInteger(row[metric]) || row[metric] < 0))) {
                throw new Error('Report values must be non-negative whole numbers or null.');
            }
        }
    }

    function buildReport(rows, year, from, to) {
        if (!Number.isInteger(year) || !Number.isInteger(from) || !Number.isInteger(to) || from < 1 || to > 12 || from > to) {
            throw new Error('Choose an end month on or after the start month.');
        }
        const byMonth = new Map(rows.filter(row => row.year === year).map(row => [row.month, row]));
        const months = Array.from({ length: to - from + 1 }, (_, index) => {
            const month = from + index;
            return byMonth.get(month) || { year, month, appointments: null, revenue: null, newPatients: null };
        });
        const totals = {};
        for (const metric of METRICS) {
            const values = months.map(row => row[metric]).filter(value => value !== null);
            totals[metric] = values.length ? values.reduce((sum, value) => sum + value, 0) : null;
            if (totals[metric] !== null && !Number.isSafeInteger(totals[metric])) throw new Error('Report total exceeds safe numeric precision.');
        }
        return { year, from, to, months, totals, complete: months.every(row => METRICS.every(metric => row[metric] !== null)) };
    }

    function comparison(current, previous, metric, asOf = null) {
        if (asOf && (current.year > asOf.current_year || (current.year === asOf.current_year && current.to >= asOf.current_month))) {
            return 'Comparison unavailable: selected period includes an incomplete or future month.';
        }
        if (!current.complete || !previous.complete) return 'Prior-year comparison unavailable for this period.';
        const value = current.totals[metric], base = previous.totals[metric];
        if (base === 0) return value === 0 ? `Unchanged from ${previous.year} (0).` : `${previous.year}: 0; percentage change unavailable.`;
        const change = (value - base) / base * 100;
        const rounded = Math.abs(change).toFixed(1);
        return `${change > 0 ? '+' : change < 0 ? '−' : ''}${rounded}% vs. the same months in ${previous.year}`;
    }

    function reportCSV(report) {
        const csvValue = (metric, value) => metric === 'revenue' ? decimal(value) : (value ?? '');
        const rows = [['Year', 'Month', 'Appointments', 'Revenue by bill month (bill currency)', 'New patients'],
            ...report.months.map(row => [row.year, MONTHS[row.month - 1], ...METRICS.map(metric => csvValue(metric, row[metric]))]),
            [report.year, report.complete ? 'Total' : 'Known total', ...METRICS.map(metric => csvValue(metric, report.totals[metric]))]];
        return '\uFEFF' + rows.map(row => row.join(',')).join('\r\n');
    }

    function chartSVG(report, metric, availableWidth, selectedMonth = null) {
        const width = Math.max(310, Math.round(availableWidth || 310));
        const height = 250, right = 18, top = 20, bottom = 34;
        const divisor = metric === 'revenue' ? revenueScale(report).divisor : 1;
        const observed = report.months.map(row => row[metric]).filter(value => value !== null);
        if (!observed.length) return '<p class="chart-empty">No data available for this period.</p>';
        const max = Math.max(...observed) / divisor;
        const rawStep = Math.max(max / 4, metric === 'revenue' ? 1 / divisor : 1);
        const power = Math.pow(10, Math.floor(Math.log10(rawStep)));
        const step = [1, 2, 5, 10].find(value => value * power >= rawStep) * power;
        const upper = max > 0 ? Math.ceil(max / step) * step : step;
        const left = Math.max(48, number(upper).length * 7 + 14);
        const plotWidth = width - left - right, plotHeight = height - top - bottom;
        const x = index => left + (report.months.length === 1 ? plotWidth / 2 : index * plotWidth / (report.months.length - 1));
        const y = value => top + plotHeight - value / divisor / upper * plotHeight;
        let grid = '';
        for (let i = 0; i <= Math.round(upper / step); i++) {
            const value = Number((i * step).toPrecision(12));
            const position = top + plotHeight - value / upper * plotHeight;
            grid += `<line class="${i === 0 ? 'chart-axis' : 'chart-grid'}" x1="${left}" x2="${width - right}" y1="${position}" y2="${position}"/><text class="chart-tick" x="${left - 10}" y="${position + 4}" text-anchor="end">${number(value)}</text>`;
        }
        const maxLabels = Math.max(2, Math.floor(plotWidth / 48));
        const labelStep = Math.max(1, Math.ceil((report.months.length - 1) / (maxLabels - 1)));
        const labels = report.months.map((row, index) => index % labelStep === 0 || index === report.months.length - 1 ?
            `<text class="chart-tick" x="${x(index)}" y="${height - 10}" text-anchor="middle">${MONTHS[row.month - 1].slice(0, 3)}</text>` : '').join('');
        const segments = [];
        let segment = [];
        const points = report.months.map((row, index) => {
            if (row[metric] === null) { if (segment.length) segments.push(segment); segment = []; return ''; }
            const px = x(index), py = y(row[metric]);
            segment.push(`${px},${py}`);
            const label = `${MONTHS[row.month - 1]} ${row.year}: ${formatMetric(metric, row[metric])}${metric === 'revenue' ? ' (bill currency)' : ' ' + LABELS[metric].toLowerCase()}`;
            return `<g class="chart-point${selectedMonth === row.month ? ' is-selected' : ''}" tabindex="0" role="button" aria-label="${esc(label)}" data-month="${row.month}"><title>${esc(label)}</title><circle class="point-hit" cx="${px}" cy="${py}" r="9"/><circle class="point-dot" cx="${px}" cy="${py}" r="3.5"/></g>`;
        }).join('');
        if (segment.length) segments.push(segment);
        const lines = segments.map(values => `<polyline class="chart-line" points="${values.join(' ')}"/>`).join('');
        return `<svg viewBox="0 0 ${width} ${height}" role="group" aria-labelledby="${metric}SvgTitle"><title id="${metric}SvgTitle">${LABELS[metric]} by month, ${report.year}.</title>${grid}${labels}${lines}${points}</svg>`;
    }

    const model = { MONTHS, METRICS, validateData, buildReport, comparison, reportCSV, chartSVG, money, revenueScale };
    if (typeof module !== 'undefined' && module.exports) module.exports = model;
    if (typeof document === 'undefined') return;

    const $ = id => document.getElementById(id);
    const hosts = { appointments: 'appointmentsChart', revenue: 'revenueChart', newPatients: 'patientsChart' };
    let report = null, selectedMonth = null, data = null, loading = false;
    const lastWidths = {};
    for (const id of ['fromMonth', 'toMonth']) {
        $(id).innerHTML = MONTHS.map((month, index) => `<option value="${index + 1}">${month}</option>`).join('');
    }

    function renderCharts() {
        if (!report) return;
        $('revenueUnit').textContent = `${revenueScale(report).unit} (same currency as Bills)`;
        for (const metric of METRICS) {
            const host = $(hosts[metric]);
            lastWidths[metric] = host.clientWidth;
            host.innerHTML = chartSVG(report, metric, host.clientWidth, selectedMonth);
        }
    }

    function render() {
        const previous = buildReport(data.rows, report.year - 1, report.from, report.to);
        selectedMonth = null;
        $('monthDetail').hidden = true;
        const available = report.months.filter(row => METRICS.every(metric => row[metric] !== null)).length;
        $('periodLabel').textContent = `${MONTHS[report.from - 1]}–${MONTHS[report.to - 1]} ${report.year} · ${available} of ${report.months.length} months available · Updated ${data.as_of}`;
        const notes = [];
        if (report.year === data.current_year && report.to >= data.current_month) notes.push('The current month is in progress; future revenue and new-patient months are unavailable.');
        if (report.year > data.current_year) notes.push('Future period: only currently scheduled appointments are shown.');
        if (data.undated_paid_bills) notes.push(`${data.undated_paid_bills} paid bill(s) without a bill date are excluded from monthly revenue.`);
        $('dataNote').textContent = notes.join(' ');
        $('totalAppointments').textContent = number(report.totals.appointments);
        $('totalRevenue').textContent = money(report.totals.revenue);
        $('totalPatients').textContent = number(report.totals.newPatients);
        $('appointmentsComparison').textContent = comparison(report, previous, 'appointments', data);
        $('revenueComparison').textContent = comparison(report, previous, 'revenue', data);
        $('patientsComparison').textContent = comparison(report, previous, 'newPatients', data);
        $('rowCount').textContent = `${report.months.length} months`;
        $('monthlyTableBody').innerHTML = report.months.map(row => `<tr><td>${MONTHS[row.month - 1]} ${row.year}</td>${METRICS.map(metric => `<td class="number">${formatMetric(metric, row[metric])}</td>`).join('')}</tr>`).join('');
        $('monthlyTableFoot').innerHTML = `<tr><th scope="row">${report.complete ? 'Total' : 'Known total'}</th>${METRICS.map(metric => `<td class="number">${formatMetric(metric, report.totals[metric])}</td>`).join('')}</tr>`;
        renderCharts();
    }

    function selectMonth(month) {
        const row = report && report.months.find(item => item.month === month);
        if (!row) return;
        selectedMonth = month;
        $('monthDetail').hidden = false;
        $('monthDetail').innerHTML = `<strong>${MONTHS[month - 1]} ${report.year}</strong>${number(row.appointments)} appointments · ${money(row.revenue)} revenue (bill currency) · ${number(row.newPatients)} new patients`;
        document.querySelectorAll('.chart-point').forEach(point => point.classList.toggle('is-selected', Number(point.dataset.month) === month));
    }

    for (const id of Object.values(hosts)) {
        $(id).addEventListener('click', event => {
            const point = event.target.closest('.chart-point');
            if (point) selectMonth(Number(point.dataset.month));
        });
        $(id).addEventListener('keydown', event => {
            const point = event.target.closest('.chart-point');
            if (!point) return;
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault(); selectMonth(Number(point.dataset.month));
            } else if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
                event.preventDefault();
                const points = [...$(id).querySelectorAll('.chart-point')];
                const next = points[points.indexOf(point) + (event.key === 'ArrowRight' ? 1 : -1)];
                if (next) { next.focus(); selectMonth(Number(next.dataset.month)); }
            }
        });
    }

    async function loadReport(year = null, from = 1, to = null) {
        if (loading) return;
        loading = true;
        $('reportFilters').querySelectorAll('button, select').forEach(element => { element.disabled = true; });
        $('btnExport').disabled = true;
        $('filterError').hidden = true;
        try {
            const response = await fetch('reports_api.php' + (year === null ? '' : '?year=' + year), { cache: 'no-store' });
            const body = await response.json().catch(() => null);
            if (response.status === 401) { if (root.hmsKick) root.hmsKick(body || {}); return; }
            if (!response.ok || !body || !body.success) throw new Error(body?.message || 'Cannot load reports. Please try again.');
            if (body.kind !== 'database' || !Array.isArray(body.years) || !body.years.every(Number.isInteger)) throw new Error('Invalid report response.');
            validateData(body.rows);
            const candidate = buildReport(body.rows, body.year, from, to ?? (body.year === body.current_year ? body.current_month : 12));
            data = body;
            report = candidate;
            $('reportYear').innerHTML = body.years.map(value => `<option value="${value}">${value}</option>`).join('');
            $('reportYear').value = String(report.year);
            $('fromMonth').value = String(report.from);
            $('toMonth').value = String(report.to);
            render();
        } catch (error) {
            report = null;
            $('filterError').textContent = error.message;
            $('filterError').hidden = false;
            $('periodLabel').textContent = 'Reports are unavailable. Use Refresh to try again.';
            $('dataNote').textContent = '';
            $('monthDetail').hidden = true;
            ['totalAppointments', 'totalRevenue', 'totalPatients'].forEach(id => { $(id).textContent = '—'; });
            ['appointmentsComparison', 'revenueComparison', 'patientsComparison', 'rowCount'].forEach(id => { $(id).textContent = ''; });
            for (const id of Object.values(hosts)) $(id).innerHTML = '<p class="chart-empty">Data unavailable.</p>';
            $('monthlyTableBody').innerHTML = '<tr><td colspan="4">Data unavailable.</td></tr>';
            $('monthlyTableFoot').innerHTML = '';
        } finally {
            loading = false;
            $('reportFilters').querySelectorAll('button, select').forEach(element => { element.disabled = false; });
            $('btnExport').disabled = !report;
        }
    }

    $('reportFilters').onsubmit = event => {
        event.preventDefault();
        try {
            const year = Number($('reportYear').value), from = Number($('fromMonth').value), to = Number($('toMonth').value);
            if (year < 1900 || year > 2100) throw new Error('Choose a valid year.');
            buildReport([], year, from, to);
            loadReport(year, from, to);
        } catch (error) {
            $('filterError').textContent = error.message;
            $('filterError').hidden = false;
        }
    };
    $('btnReset').onclick = () => loadReport();
    $('btnRefresh').onclick = () => report ? loadReport(report.year, report.from, report.to) : loadReport();
    $('btnExport').onclick = () => {
        if (!report) return;
        const blob = new Blob([reportCSV(report)], { type: 'text/csv;charset=utf-8' });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = `hms-reports-${report.year}-${String(report.from).padStart(2, '0')}-${String(report.to).padStart(2, '0')}.csv`;
        link.click();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
    };
    loadReport();
    if (typeof ResizeObserver !== 'undefined') {
        const observer = new ResizeObserver(() => {
            if (METRICS.some(metric => $(hosts[metric]).clientWidth !== lastWidths[metric])) renderCharts();
        });
        for (const id of Object.values(hosts)) observer.observe($(id));
    }
})(globalThis);
