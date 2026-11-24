<?php
session_start();

// ==========================================
// 1. CHECK LOGIN
// ==========================================

if (!isset($_SESSION["user_id"])) {
    header("Location: ../../login_feature/index.html");
    exit;
}

// Check Patient role
if (
    !isset($_SESSION["role"]) ||
    strtolower($_SESSION["role"]) !== "patient"
) {
    header("Location: ../../login_feature/index.html");
    exit;
}

// ==========================================
// 2. DATABASE CONNECTION
// ==========================================

$conn = new mysqli(
    "localhost",
    "root",
    "",
    "hospital_management_system"
);

if ($conn->connect_error) {
    die("Database connection failed.");
}

$conn->set_charset("utf8mb4");

$userID = $_SESSION["user_id"];

// ==========================================
// 3. GET PATIENT ID
// ==========================================

$patientSQL = "
    SELECT patient_id
    FROM patients
    WHERE user_id = ?
    LIMIT 1
";

$patientStmt = $conn->prepare($patientSQL);
$patientStmt->bind_param("s", $userID);
$patientStmt->execute();

$patientResult = $patientStmt->get_result();
$patient = $patientResult->fetch_assoc();

$patientStmt->close();

if (!$patient) {
    $conn->close();
    die("Patient information not found.");
}

$patientID = $patient["patient_id"];

// ==========================================
// 4. GET APPOINTMENTS
// ==========================================

$sql = "
    SELECT
        a.appointment_id,
        a.appointment_datetime,
        a.visit_reason,
        a.appointment_status,
        a.doctor_response,
        a.doctor_reject_reason,

        u.full_name AS doctor_name,
        d.specialization

    FROM appointments a

    LEFT JOIN doctors d
        ON a.doctor_id = d.doctor_id

    LEFT JOIN users u
        ON d.user_id = u.user_id

    WHERE a.patient_id = ?

    ORDER BY a.appointment_datetime DESC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $patientID);
$stmt->execute();

$result = $stmt->get_result();

$appointments = [];

while ($row = $result->fetch_assoc()) {
    $appointments[] = $row;
}

$stmt->close();
$conn->close();

// ==========================================
// 5. STATUS CLASS
// ==========================================

function getStatusClass($status)
{

    switch (strtolower((string)$status)) {

        case "confirmed":
            return "confirmed";

        case "completed":
            return "completed";

        case "rejected":
            return "rejected";

        case "cancelled":
            return "cancelled";

        case "no_show":
            return "no-show";

        default:
            return "pending";
    }
}

// ==========================================
// 6. PATIENT INFORMATION
// ==========================================

$patientName = $_SESSION["username"] ?? "Patient";

function escapeHTML($value)
{
    return htmlspecialchars(
        (string)($value ?? ""),
        ENT_QUOTES,
        "UTF-8"
    );
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>My Appointments | HMS</title>

    <!-- SHARED PATIENT LAYOUT -->
    <link rel="stylesheet" href="../css/layout.css">

    <!-- FONT AWESOME -->
    <link rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">


    <style>
        /* =====================================
           APPOINTMENTS CONTENT
        ===================================== */

        .appointments-content {
            padding: 30px;
            max-width: 1400px;
            margin: 0 auto;
            width: 100%;
        }

        .page-heading {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 25px;
        }

        .page-heading h1 {
            color: #24445F;
            font-size: 26px;
            margin-bottom: 8px;
        }

        .page-heading p {
            color: #8492A3;
            font-size: 14px;
        }

        .back-button {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 11px 17px;
            background: #367FB5;
            color: #FFFFFF;
            border-radius: 7px;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            transition: 0.2s;
        }

        .back-button:hover {
            background: #286895;
        }


        /* =====================================
           APPOINTMENT CARD
        ===================================== */

        .appointment-card {
            background: #FFFFFF;
            padding: 25px;
            border-radius: 12px;
            border: 1px solid #E5EDF5;
            box-shadow: 0 3px 14px rgba(23, 59, 99, 0.05);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 22px;
        }

        .card-header h2 {
            color: #24445F;
            font-size: 19px;
            margin-bottom: 7px;
        }

        .card-header p {
            color: #8492A3;
            font-size: 13px;
        }

        .appointment-count {
            background: #E5F1FB;
            color: #367FB5;
            padding: 7px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }


        /* =====================================
           TABLE
        ===================================== */

        .appointment-table-wrapper {
            width: 100%;
            overflow-x: auto;
        }

        .appointment-table {
            width: 100%;
            min-width: 950px;
            border-collapse: collapse;
        }

        .appointment-table thead {
            background: #F4F7FB;
        }

        .appointment-table th {
            color: #75869A;
            font-size: 12px;
            font-weight: 600;
            text-align: left;
            padding: 15px 12px;
            white-space: nowrap;
        }

        .appointment-table td {
            padding: 16px 12px;
            font-size: 13px;
            color: #52677D;
            border-bottom: 1px solid #EDF0F4;
            vertical-align: middle;
        }

        .appointment-table tbody tr:hover {
            background: #F8FBFE;
        }

        .doctor-name {
            display: block;
            color: #26384A;
            font-weight: 600;
        }

        .specialization {
            display: block;
            margin-top: 5px;
            color: #929EAA;
            font-size: 11px;
        }

        .response-text {
            color: #65788C;
            font-size: 12px;
            line-height: 1.5;
        }


        /* =====================================
           STATUS
        ===================================== */

        .status {
            display: inline-block;
            padding: 6px 11px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            text-transform: capitalize;
            white-space: nowrap;
        }

        .pending {
            background: #FFF0DF;
            color: #D88A2D;
        }

        .confirmed {
            background: #E5F1FB;
            color: #367FB5;
        }

        .completed {
            background: #E3F6EB;
            color: #2E9B63;
        }

        .rejected,
        .cancelled,
        .no-show {
            background: #FCE8E8;
            color: #D9534F;
        }


        /* =====================================
           VIEW DETAILS BUTTON
        ===================================== */

        .details-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            background: #367FB5;
            color: #FFFFFF;
            padding: 9px 13px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            white-space: nowrap;
            text-decoration: none;
            transition: 0.2s;
        }

        .details-button:hover {
            background: #286895;
        }


        /* =====================================
           EMPTY STATE
        ===================================== */

        .empty-state {
            text-align: center;
            padding: 65px 15px;
            color: #929EAA;
        }

        .empty-state i {
            font-size: 43px;
            margin-bottom: 18px;
            color: #AAB8C6;
        }

        .empty-state h3 {
            color: #40566C;
            font-size: 18px;
            margin-bottom: 9px;
        }

        .empty-state p {
            font-size: 13px;
            line-height: 1.5;
        }


        /* =====================================
           FOOTER
        ===================================== */

        .appointments-footer {
            text-align: center;
            padding: 22px 10px;
            color: #8492A3;
            font-size: 12px;
        }


        /* =====================================
           RESPONSIVE
        ===================================== */

        @media (max-width: 768px) {

            .appointments-content {
                padding: 20px 15px;
            }

            .appointment-card {
                padding: 20px 15px;
            }

            .page-heading {
                align-items: flex-start;
                flex-direction: column;
            }

        }

        .profile-avatar,
        .dropdown-avatar {
            position: relative;
            overflow: hidden;
            flex-shrink: 0;
        }

        .profile-avatar img,
        .dropdown-avatar img {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 50%;
            z-index: 2;
        }

        .profile-avatar i,
        .dropdown-avatar i {
            position: relative;
            z-index: 1;
        }
    </style>

</head>


<body>

    <div class="dashboard-container">

        <!-- =====================================
         SIDEBAR
    ===================================== -->

        <aside class="sidebar" id="sidebar">

            <div class="sidebar-logo">

                <div class="logo-icon">
                    <img src="../images/logo.png" alt="HMS Logo">
                </div>

            </div>


            <nav class="sidebar-menu">

                <p class="menu-title">MENU</p>

                <a href="../patient_dashboard.html" class="menu-item">
                    <i class="fas fa-house"></i>
                    <span>Dashboard</span>
                </a>

                <a href="../profile_feature/profile.php" class="menu-item">
                    <i class="fas fa-user"></i>
                    <span>My Profile</span>
                </a>

                <a href="appointments.php" class="menu-item active">
                    <i class="fas fa-calendar-check"></i>
                    <span>My Appointments</span>
                </a>

                <a href="../history_feature/history.php" class="menu-item">
                    <i class="fas fa-file-medical"></i>
                    <span>Medical History</span>
                </a>

                <a href="../bills_feature/bills.php" class="menu-item">
                    <i class="fas fa-file-invoice-dollar"></i>
                    <span>My Bills</span>
                </a>

            </nav>


            <div class="sidebar-bottom">

                <div class="sidebar-help">
                    <i class="fas fa-circle-question"></i>
                    <span>Patient Support</span>
                </div>

            </div>

        </aside>


        <!-- =====================================
         MAIN CONTENT
    ===================================== -->

        <main class="main-content">


            <!-- HEADER -->
            <header class="top-header">

                <div class="header-left">

                    <button class="menu-toggle"
                        id="menuToggle"
                        type="button">

                        <i class="fas fa-bars"></i>

                    </button>

                    <div>
                        <h3>My Appointments</h3>
                        <p>Manage and track your appointments</p>
                    </div>

                </div>


                <div class="header-right">

                    <div class="header-date">
                        <i class="far fa-calendar"></i>
                        <span id="currentDate"></span>
                    </div>


                    <!-- ACCOUNT MENU -->
                    <div class="account-menu">

                        <button type="button"
                            class="account-trigger"
                            id="accountTrigger"
                            aria-expanded="false"
                            aria-controls="profileDropdown">

                            <div class="profile-avatar">
                                <img src="../avatar.php?v=<?php echo time(); ?>"
                                    alt="Patient Avatar"
                                    onerror="this.remove()">
                                <i class="fa-solid fa-user"></i>
                            </div>

                            <div class="profile-text">

                                <strong>
                                    <?= escapeHTML($patientName) ?>
                                </strong>

                                <span>Patient</span>

                            </div>

                            <i class="fas fa-chevron-down account-arrow"></i>

                        </button>


                        <!-- DROPDOWN -->
                        <div class="profile-dropdown"
                            id="profileDropdown"
                            hidden>

                            <div class="dropdown-user">

                                <div class="dropdown-avatar">
                                    <img src="../avatar.php?v=<?php echo time(); ?>"
                                        alt="Patient Avatar"
                                        onerror="this.remove()">
                                    <i class="fa-solid fa-user"></i>
                                </div>

                                <div class="dropdown-user-text">

                                    <strong>
                                        <?= escapeHTML($patientName) ?>
                                    </strong>

                                    <span>Patient account</span>

                                </div>

                            </div>


                            <a href="../profile_feature/profile.php"
                                class="dropdown-item">

                                <i class="fas fa-user-gear"></i>
                                <span>Account management</span>

                            </a>


                            <a href="../logout.php"
                                class="dropdown-item logout-link">

                                <i class="fas fa-right-from-bracket"></i>
                                <span>Log out</span>

                            </a>

                        </div>

                    </div>

                </div>

            </header>


            <!-- =====================================
             APPOINTMENTS CONTENT
        ===================================== -->

            <div class="appointments-content">


                <!-- PAGE HEADING -->
                <section class="page-heading">

                    <div>

                        <h1>My Appointments</h1>

                        <p>
                            View and keep track of your appointments.
                        </p>

                    </div>


                    <a href="../patient_dashboard.html"
                        class="back-button">

                        <i class="fas fa-arrow-left"></i>
                        Back to Dashboard

                    </a>

                </section>


                <!-- APPOINTMENTS CARD -->
                <section class="appointment-card">


                    <div class="card-header">

                        <div>

                            <h2>Appointment History</h2>

                            <p>
                                View your appointment information and doctor responses.
                            </p>

                        </div>


                        <span class="appointment-count">

                            <?= count($appointments) ?>
                            Appointment(s)

                        </span>

                    </div>


                    <?php if (count($appointments) > 0): ?>


                        <!-- APPOINTMENTS TABLE -->
                        <div class="appointment-table-wrapper">

                            <table class="appointment-table">

                                <thead>

                                    <tr>

                                        <th>Doctor</th>

                                        <th>Date & Time</th>

                                        <th>Reason</th>

                                        <th>Status</th>

                                        <th>Doctor Response</th>

                                        <th>Action</th>

                                    </tr>

                                </thead>


                                <tbody>

                                    <?php foreach ($appointments as $appointment): ?>

                                        <tr>


                                            <!-- DOCTOR -->
                                            <td>

                                                <span class="doctor-name">

                                                    <?= escapeHTML(
                                                        $appointment["doctor_name"] ?? "Not assigned"
                                                    ) ?>

                                                </span>


                                                <span class="specialization">

                                                    <?= escapeHTML(
                                                        $appointment["specialization"] ?? "N/A"
                                                    ) ?>

                                                </span>

                                            </td>


                                            <!-- DATE AND TIME -->
                                            <td>

                                                <?php

                                                if (!empty($appointment["appointment_datetime"])) {

                                                    echo escapeHTML(
                                                        date(
                                                            "d/m/Y H:i",
                                                            strtotime($appointment["appointment_datetime"])
                                                        )
                                                    );
                                                } else {

                                                    echo "Not available";
                                                }

                                                ?>

                                            </td>


                                            <!-- VISIT REASON -->
                                            <td>

                                                <?= escapeHTML(
                                                    $appointment["visit_reason"] ?? "Not provided"
                                                ) ?>

                                            </td>


                                            <!-- APPOINTMENT STATUS -->
                                            <td>

                                                <span class="status <?= getStatusClass(
                                                                        $appointment["appointment_status"] ?? ""
                                                                    ) ?>">

                                                    <?= escapeHTML(
                                                        str_replace(
                                                            "_",
                                                            " ",
                                                            $appointment["appointment_status"] ?? "pending"
                                                        )
                                                    ) ?>

                                                </span>

                                            </td>


                                            <!-- DOCTOR RESPONSE -->
                                            <td class="response-text">

                                                <?php

                                                if (
                                                    ($appointment["doctor_response"] ?? "") === "accepted"
                                                ) {

                                                    echo "Accepted";
                                                } elseif (
                                                    ($appointment["doctor_response"] ?? "") === "rejected"
                                                ) {

                                                    echo "Rejected";

                                                    if (!empty($appointment["doctor_reject_reason"])) {

                                                        echo ": " . escapeHTML(
                                                            $appointment["doctor_reject_reason"]
                                                        );
                                                    }
                                                } else {

                                                    echo "Waiting for response";
                                                }

                                                ?>

                                            </td>


                                            <!-- VIEW DETAILS -->
                                            <td>

                                                <a
                                                    href="appointment_details.php?id=<?= urlencode(
                                                                                            $appointment["appointment_id"]
                                                                                        ) ?>"
                                                    class="details-button">

                                                    <i class="fas fa-eye"></i>
                                                    View Details

                                                </a>

                                            </td>


                                        </tr>

                                    <?php endforeach; ?>

                                </tbody>

                            </table>

                        </div>


                    <?php else: ?>


                        <!-- EMPTY STATE -->
                        <div class="empty-state">

                            <i class="fas fa-calendar-xmark"></i>

                            <h3>No Appointments Yet</h3>

                            <p>
                                You do not have any appointments at the moment.
                            </p>

                        </div>


                    <?php endif; ?>


                </section>


                <!-- FOOTER -->
                <footer class="appointments-footer">

                    Hospital Management System - Patient Portal

                </footer>


            </div>

        </main>

    </div>


    <!-- =====================================
     HEADER AND SIDEBAR JAVASCRIPT
===================================== -->

    <script>
        document.addEventListener("DOMContentLoaded", function() {


            // =================================
            // CURRENT DATE
            // =================================

            const dateElement = document.getElementById("currentDate");

            if (dateElement) {

                const today = new Date();

                dateElement.textContent = today.toLocaleDateString(
                    "en-US", {
                        weekday: "long",
                        year: "numeric",
                        month: "long",
                        day: "numeric"
                    }
                );

            }


            // =================================
            // SIDEBAR TOGGLE
            // =================================

            const menuToggle = document.getElementById("menuToggle");

            const sidebar = document.getElementById("sidebar");

            if (menuToggle && sidebar) {

                menuToggle.addEventListener("click", function() {

                    sidebar.classList.toggle("show");

                });

            }


            // =================================
            // ACCOUNT DROPDOWN
            // =================================

            const accountTrigger = document.getElementById("accountTrigger");

            const profileDropdown = document.getElementById("profileDropdown");


            if (accountTrigger && profileDropdown) {

                accountTrigger.addEventListener("click", function(event) {

                    event.stopPropagation();

                    const isOpen = !profileDropdown.hidden;

                    profileDropdown.hidden = isOpen;

                    accountTrigger.setAttribute(
                        "aria-expanded",
                        String(!isOpen)
                    );

                });


                // Close when clicking outside
                document.addEventListener("click", function(event) {

                    if (!event.target.closest(".account-menu")) {

                        profileDropdown.hidden = true;

                        accountTrigger.setAttribute(
                            "aria-expanded",
                            "false"
                        );

                    }

                });


                // Close when pressing Escape
                document.addEventListener("keydown", function(event) {

                    if (event.key === "Escape") {

                        profileDropdown.hidden = true;

                        accountTrigger.setAttribute(
                            "aria-expanded",
                            "false"
                        );

                    }

                });

            }

        });
    </script>

</body>

</html>