<?php
session_start();

// Check login
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

// Check appointment ID
if (!isset($_GET["id"]) || empty($_GET["id"])) {
    die("Appointment ID is missing.");
}

// Database connection
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

// Get Patient ID
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
    die("Patient information not found.");
}

$patientID = $patient["patient_id"];
$appointmentID = $_GET["id"];

// Get appointment details
$sql = "
    SELECT
        a.appointment_id,
        a.appointment_datetime,
        a.visit_reason,
        a.appointment_status,
        a.doctor_response,
        a.doctor_reject_reason,
        a.doctor_responded_at,
        a.created_at,
        u.full_name AS doctor_name,
        d.specialization,
        d.room_number
    FROM appointments a
    LEFT JOIN doctors d
        ON a.doctor_id = d.doctor_id
    LEFT JOIN users u
        ON d.user_id = u.user_id
    WHERE a.appointment_id = ?
    AND a.patient_id = ?
    LIMIT 1
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ss", $appointmentID, $patientID);
$stmt->execute();

$result = $stmt->get_result();
$appointment = $result->fetch_assoc();

$stmt->close();
$conn->close();

if (!$appointment) {
    die("Appointment not found or you do not have permission to view it.");
}

function formatDate($date)
{
    if (empty($date)) {
        return "Not available";
    }

    return date("d/m/Y H:i", strtotime($date));
}

function getStatusClass($status)
{
    switch (strtolower($status ?? "")) {
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

function e($value)
{
    return htmlspecialchars((string)($value ?? ""), ENT_QUOTES, "UTF-8");
}

$patientName = $_SESSION["username"] ?? "Patient";
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Appointment Details | HMS</title>

    <link rel="stylesheet" href="../css/layout.css">

    <link rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">

    <style>
        .details-content {
            padding: 30px;
            max-width: 1100px;
            margin: 0 auto;
        }

        .page-heading {
            margin-bottom: 25px;
        }

        .page-heading h1 {
            font-size: 25px;
            color: #26384A;
            margin-bottom: 8px;
        }

        .page-heading p {
            color: #8795A5;
            font-size: 14px;
        }

        .details-card {
            background: #FFFFFF;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 3px 12px rgba(20, 50, 80, 0.05);
        }

        .card-title {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 18px;
            color: #26384A;
            margin-bottom: 20px;
        }

        .card-title i {
            color: #367FB5;
        }

        .detail-row {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 20px;
            padding: 17px 0;
            border-bottom: 1px solid #EDF0F4;
        }

        .detail-row:last-child {
            border-bottom: none;
        }

        .detail-label {
            color: #8190A0;
            font-size: 14px;
            min-width: 180px;
        }

        .detail-value {
            text-align: right;
            font-size: 14px;
            font-weight: 600;
            color: #26384A;
            max-width: 65%;
            overflow-wrap: anywhere;
        }

        .status {
            display: inline-block;
            padding: 6px 13px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: capitalize;
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

        .back-button {
            display: inline-flex;
            align-items: center;
            gap: 9px;
            margin-top: 22px;
            padding: 11px 18px;
            background: #367FB5;
            color: #FFFFFF;
            border-radius: 8px;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            transition: 0.2s;
        }

        .back-button:hover {
            background: #286895;
        }

        @media (max-width: 768px) {
            .details-content {
                padding: 20px 15px;
            }

            .details-card {
                padding: 22px 18px;
            }
        }

        @media (max-width: 550px) {
            .detail-row {
                flex-direction: column;
                gap: 8px;
            }

            .detail-value {
                text-align: left;
                max-width: 100%;
            }

            .detail-label {
                min-width: auto;
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

        <!-- Sidebar -->
        <aside class="sidebar" id="sidebar">

            <div class="sidebar-logo">
                <div class="logo-icon">
                    <img src="../images/logo.png" alt="HMS Logo">
                </div>
            </div>

            <nav class="sidebar-menu">

                <p class="menu-title">MAIN MENU</p>

                <a href="../patient_dashboard.html" class="menu-item">
                    <i class="fa-solid fa-house"></i>
                    <span>Dashboard</span>
                </a>

                <a href="../profile_feature/profile.php" class="menu-item">
                    <i class="fa-solid fa-user"></i>
                    <span>My Profile</span>
                </a>

                <a href="appointments.php" class="menu-item active">
                    <i class="fa-solid fa-calendar-check"></i>
                    <span>Appointments</span>
                </a>

                <a href="../history_feature/history.php" class="menu-item">
                    <i class="fa-solid fa-file-medical"></i>
                    <span>Medical History</span>
                </a>

                <a href="../bills_feature/bills.php" class="menu-item">
                    <i class="fa-solid fa-file-invoice-dollar"></i>
                    <span>My Bills</span>
                </a>

            </nav>

            <div class="sidebar-bottom">
                <a href="../logout.php" class="menu-item logout-link">
                    <i class="fa-solid fa-right-from-bracket"></i>
                    <span>Logout</span>
                </a>
            </div>

        </aside>

        <!-- Main Content -->
        <main class="main-content">

            <!-- Header -->
            <header class="top-header">

                <div class="header-left">
                    <button class="menu-toggle" id="menuToggle">
                        <i class="fa-solid fa-bars"></i>
                    </button>

                    <h2>Appointment Details</h2>
                </div>

                <div class="header-right">

                    <div class="header-date" id="currentDate"></div>

                    <div class="account-menu">

                        <div class="account-trigger" id="accountTrigger">

                            <div class="profile-avatar">
                                <img src="../avatar.php?v=<?php echo time(); ?>"
                                    alt="Patient Avatar"
                                    onerror="this.remove()">
                                <i class="fa-solid fa-user"></i>
                            </div>

                            <div class="profile-text">
                                <strong><?php echo e($patientName); ?></strong>
                                <span>Patient</span>
                            </div>

                            <i class="fa-solid fa-chevron-down"></i>

                        </div>

                        <div class="profile-dropdown" id="profileDropdown" hidden>

                            <div class="dropdown-user">
                                <div class="dropdown-avatar">
                                    <img src="../avatar.php?v=<?php echo time(); ?>"
                                        alt="Patient Avatar"
                                        onerror="this.remove()">
                                    <i class="fa-solid fa-user"></i>
                                </div>

                                <div class="dropdown-user-text">
                                    <strong><?php echo e($patientName); ?></strong>
                                    <span>Patient Account</span>
                                </div>
                            </div>

                            <a href="../profile_feature/profile.php" class="dropdown-item">
                                <i class="fa-solid fa-user-pen"></i>
                                My Profile
                            </a>

                            <a href="../logout.php" class="dropdown-item logout-link">
                                <i class="fa-solid fa-right-from-bracket"></i>
                                Logout
                            </a>

                        </div>

                    </div>

                </div>

            </header>

            <!-- Page Content -->
            <section class="details-content">

                <div class="page-heading">
                    <h1>Appointment Details</h1>
                    <p>View the information about your appointment.</p>
                </div>

                <div class="details-card">

                    <h2 class="card-title">
                        <i class="fa-solid fa-calendar-check"></i>
                        Appointment Information
                    </h2>

                    <div class="detail-row">
                        <span class="detail-label">Appointment ID</span>
                        <span class="detail-value">
                            #<?php echo e($appointment["appointment_id"]); ?>
                        </span>
                    </div>

                    <div class="detail-row">
                        <span class="detail-label">Doctor</span>
                        <span class="detail-value">
                            <?php echo e($appointment["doctor_name"] ?? "Not assigned"); ?>
                        </span>
                    </div>

                    <div class="detail-row">
                        <span class="detail-label">Specialization</span>
                        <span class="detail-value">
                            <?php echo e($appointment["specialization"] ?? "N/A"); ?>
                        </span>
                    </div>

                    <div class="detail-row">
                        <span class="detail-label">Room Number</span>
                        <span class="detail-value">
                            <?php echo e($appointment["room_number"] ?? "N/A"); ?>
                        </span>
                    </div>

                    <div class="detail-row">
                        <span class="detail-label">Date & Time</span>
                        <span class="detail-value">
                            <?php echo formatDate($appointment["appointment_datetime"]); ?>
                        </span>
                    </div>

                    <div class="detail-row">
                        <span class="detail-label">Visit Reason</span>
                        <span class="detail-value">
                            <?php echo e($appointment["visit_reason"] ?? "Not provided"); ?>
                        </span>
                    </div>

                    <div class="detail-row">
                        <span class="detail-label">Appointment Status</span>
                        <span class="detail-value">
                            <span class="status <?php echo getStatusClass($appointment["appointment_status"]); ?>">
                                <?php
                                echo e(
                                    str_replace(
                                        "_",
                                        " ",
                                        $appointment["appointment_status"] ?? "Pending"
                                    )
                                );
                                ?>
                            </span>
                        </span>
                    </div>

                    <div class="detail-row">
                        <span class="detail-label">Doctor Response</span>
                        <span class="detail-value">
                            <?php
                            echo e(
                                !empty($appointment["doctor_response"])
                                    ? ucfirst($appointment["doctor_response"])
                                    : "Not available"
                            );
                            ?>
                        </span>
                    </div>

                    <div class="detail-row">
                        <span class="detail-label">Doctor's Response Time</span>
                        <span class="detail-value">
                            <?php echo formatDate($appointment["doctor_responded_at"]); ?>
                        </span>
                    </div>

                    <?php if (!empty($appointment["doctor_reject_reason"])): ?>
                        <div class="detail-row">
                            <span class="detail-label">Rejection Reason</span>
                            <span class="detail-value">
                                <?php echo e($appointment["doctor_reject_reason"]); ?>
                            </span>
                        </div>
                    <?php endif; ?>

                    <div class="detail-row">
                        <span class="detail-label">Created At</span>
                        <span class="detail-value">
                            <?php echo formatDate($appointment["created_at"]); ?>
                        </span>
                    </div>

                </div>

                <a href="appointments.php" class="back-button">
                    <i class="fa-solid fa-arrow-left"></i>
                    Back to Appointments
                </a>

            </section>

        </main>

    </div>

    <script>
        // Display current date
        const dateElement = document.getElementById("currentDate");

        if (dateElement) {
            dateElement.textContent = new Date().toLocaleDateString("en-US", {
                weekday: "short",
                year: "numeric",
                month: "short",
                day: "numeric"
            });
        }

        // Sidebar toggle
        const menuToggle = document.getElementById("menuToggle");
        const sidebar = document.getElementById("sidebar");

        if (menuToggle && sidebar) {
            menuToggle.addEventListener("click", function() {
                sidebar.classList.toggle("show");
            });
        }

        // Account dropdown
        const accountTrigger = document.getElementById("accountTrigger");
        const profileDropdown = document.getElementById("profileDropdown");

        if (accountTrigger && profileDropdown) {
            accountTrigger.addEventListener("click", function(event) {
                event.stopPropagation();
                profileDropdown.hidden = !profileDropdown.hidden;
            });

            document.addEventListener("click", function(event) {
                if (
                    !accountTrigger.contains(event.target) &&
                    !profileDropdown.contains(event.target)
                ) {
                    profileDropdown.hidden = true;
                }
            });
        }
    </script>

</body>

</html>