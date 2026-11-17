
// =====================================
// PATIENT DASHBOARD JAVASCRIPT
// =====================================


// =====================================
// 1. LOAD WHEN PAGE IS READY
// =====================================

document.addEventListener("DOMContentLoaded", function () {

    displayCurrentDate();

    setupSidebar();

    setActiveMenu();

    loadPatientInformation();

});

document.addEventListener("DOMContentLoaded", function () {

    displayCurrentDate();

    setupSidebar();

    setActiveMenu();

    loadPatientInformation();

    loadDashboardData();

});

// =====================================
// 2. DISPLAY CURRENT DATE
// =====================================

function displayCurrentDate() {

    const dateElement = document.getElementById("currentDate");

    if (!dateElement) {
        return;
    }

    const today = new Date();

    const options = {
        weekday: "long",
        year: "numeric",
        month: "long",
        day: "numeric"
    };

    dateElement.textContent = today.toLocaleDateString(
        "en-US",
        options
    );
}


// =====================================
// 3. SIDEBAR MENU TOGGLE
// =====================================

function setupSidebar() {

    const menuToggle = document.getElementById("menuToggle");
    const sidebar = document.getElementById("sidebar");

    if (!menuToggle || !sidebar) {
        return;
    }

    menuToggle.addEventListener("click", function () {

        sidebar.classList.toggle("active");

    });

}


// =====================================
// 4. SET ACTIVE SIDEBAR MENU
// =====================================

function setActiveMenu() {

    const menuItems = document.querySelectorAll(
        ".sidebar-menu .menu-item"
    );

    const currentPage = window.location.pathname;

    menuItems.forEach(function (item) {

        const link = item.getAttribute("href");

        if (!link || link === "#") {
            return;
        }

        const linkPath = new URL(
            link,
            window.location.href
        ).pathname;

        if (linkPath === currentPage) {
            item.classList.add("active");
        }

    });

}


// =====================================
// 5. LOAD PATIENT INFORMATION
// =====================================

async function loadPatientInformation() {

    try {

        const response = await fetch(
            "check_patient_session.php",
            {
                method: "GET",
                credentials: "same-origin",
                headers: {
                    "Accept": "application/json"
                }
            }
        );

        const data = await response.json();

        // Check login and permission

        if (!response.ok || !data.success) {

            if (response.status === 401 || response.status === 403) {

                alert(
                    "Your session has expired or your account is unavailable. Please login again."
                );

                window.location.replace(
                    "../login_feature/index.html"
                );

                return;
            }

            console.error(data.message || "Unable to load patient information.");

            return;
        }

        // Get information from PHP

        const user = data.user;
        const patient = data.patient;

        // Get patient name

        const patientName =
            user.full_name ||
            user.username ||
            "Patient";

        // Display patient name

        const nameElement = document.getElementById("patientName");

        const welcomeElement = document.getElementById("welcomeName");

        if (nameElement) {
            nameElement.textContent = patientName;
        }

        if (welcomeElement) {
            welcomeElement.textContent = patientName;
        }

        // Store patient information for future use

        console.log("Patient information loaded successfully.");

        console.log("User:", user);

        console.log("Patient:", patient);

    } catch (error) {

        console.error(
            "Error loading patient information:",
            error
        );

    }

}


// =====================================
// 6. LOAD DASHBOARD STATISTICS
// =====================================

async function loadDashboardData() {

    try {

        const response = await fetch(
            "dashboard_data.php",
            {
                method: "GET",
                credentials: "same-origin",
                headers: {
                    "Accept": "application/json"
                }
            }
        );

        const data = await response.json();

        if (!response.ok || !data.success) {
            console.error(data.message);
            return;
        }

        // Display statistics

        const stats = data.statistics;

        document.getElementById("totalAppointments").textContent =
            stats.total_appointments;

        document.getElementById("upcomingAppointments").textContent =
            stats.upcoming_appointments;

        document.getElementById("completedVisits").textContent =
            stats.completed_visits;

        document.getElementById("unpaidBills").textContent =
            stats.unpaid_bills;

        // Display upcoming appointments

        displayUpcomingAppointments(data.appointments);

    } catch (error) {

        console.error(
            "Error loading dashboard data:",
            error
        );

    }

}


// =====================================
// 7. DISPLAY UPCOMING APPOINTMENTS
// =====================================

function displayUpcomingAppointments(appointments) {

    const table = document.getElementById("appointmentTable");

    if (!table) {
        return;
    }

    table.innerHTML = "";

    if (!appointments || appointments.length === 0) {

        table.innerHTML = `
            <tr>
                <td colspan="4" class="empty-message">
                    <i class="fa-regular fa-calendar-xmark"></i>
                    <p>No upcoming appointments.</p>
                </td>
            </tr>
        `;

        return;
    }

    appointments.forEach(function (appointment) {

        const row = document.createElement("tr");

        const doctorCell = document.createElement("td");
        doctorCell.textContent = appointment.doctor_name || "Unknown";

        const dateCell = document.createElement("td");

        const appointmentDate = new Date(
            appointment.appointment_datetime.replace(" ", "T")
        );

        dateCell.textContent = appointmentDate.toLocaleString(
            "en-GB",
            {
                day: "2-digit",
                month: "short",
                year: "numeric",
                hour: "2-digit",
                minute: "2-digit"
            }
        );

        const reasonCell = document.createElement("td");
        reasonCell.textContent = appointment.visit_reason || "General check-up";

        const statusCell = document.createElement("td");

        const status = document.createElement("span");

        status.textContent = appointment.appointment_status;

        status.className =
            "appointment-status " +
            appointment.appointment_status.toLowerCase();

        statusCell.appendChild(status);

        row.appendChild(doctorCell);
        row.appendChild(dateCell);
        row.appendChild(reasonCell);
        row.appendChild(statusCell);

        table.appendChild(row);

    });

}
