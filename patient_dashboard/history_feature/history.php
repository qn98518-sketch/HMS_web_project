<?php
session_start();

// Check patient login
if (!isset($_SESSION["user_id"]) || $_SESSION["role"] !== "patient") {
    header("Location: ../../login_feature/index.html");
    exit();
}

// Database connection
$conn = new mysqli(
    "localhost",
    "root",
    "",
    "hospital_management_system"
);

if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");

$user_id = $_SESSION["user_id"];

// Get patient ID
$patient_sql = "
    SELECT patient_id
    FROM patients
    WHERE user_id = ?
    LIMIT 1
";

$patient_stmt = $conn->prepare($patient_sql);
$patient_stmt->bind_param("s", $user_id);
$patient_stmt->execute();

$patient_result = $patient_stmt->get_result();
$patient = $patient_result->fetch_assoc();

if (!$patient) {
    die("Patient information not found.");
}

$patient_id = $patient["patient_id"];

// Get medical history
$sql = "
    SELECT
        mr.record_id,
        mr.patient_id,
        mr.doctor_id,
        mr.appointment_id,
        mr.diagnosis,
        mr.symptoms,
        mr.treatment,
        mr.notes,
        mr.record_date,
        u.full_name AS doctor_name,
        d.specialization
    FROM medical_records mr
    LEFT JOIN doctors d
        ON mr.doctor_id = d.doctor_id
    LEFT JOIN users u
        ON d.user_id = u.user_id
    WHERE mr.patient_id = ?
    ORDER BY mr.record_date DESC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $patient_id);
$stmt->execute();

$result = $stmt->get_result();
$records = [];

while ($row = $result->fetch_assoc()) {
    $records[] = $row;
}

function showValue($value)
{
    if ($value === null || trim((string)$value) === "") {
        return "Not available";
    }

    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

function showDate($value)
{
    if (empty($value)) {
        return "Not available";
    }

    return date("d/m/Y H:i", strtotime($value));
}

$patientName = $_SESSION["username"] ?? "Patient";
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Medical History | Patient</title>

    <link rel="stylesheet" href="../css/layout.css">

    <link rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">

    <style>
        .history-content {
            padding: 30px;
            max-width: 1200px;
            margin: 0 auto;
        }

        .page-heading {
            margin-bottom: 25px;
        }

        .page-heading h1 {
            color: #26384A;
            font-size: 25px;
            margin-bottom: 8px;
        }

        .page-heading p {
            color: #8795A5;
            font-size: 14px;
        }

        .summary-card {
            display: flex;
            align-items: center;
            gap: 16px;
            background: #FFFFFF;
            padding: 22px;
            border-radius: 12px;
            margin-bottom: 25px;
            box-shadow: 0 3px 12px rgba(20, 50, 80, 0.05);
        }

        .summary-icon {
            width: 52px;
            height: 52px;
            flex-shrink: 0;
            background: #E4F0FA;
            color: #367FB5;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
        }

        .summary-info h3 {
            font-size: 13px;
            color: #8795A5;
            margin-bottom: 5px;
        }

        .summary-info strong {
            font-size: 24px;
            color: #173B63;
        }

        .record-card {
            background: #FFFFFF;
            padding: 25px;
            border-radius: 12px;
            margin-bottom: 20px;
            box-shadow: 0 3px 12px rgba(20, 50, 80, 0.05);
        }

        .record-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            padding-bottom: 18px;
            border-bottom: 1px solid #E8EEF5;
        }

        .record-header h2 {
            font-size: 17px;
            color: #173B63;
        }

        .record-date {
            color: #367FB5;
            font-size: 13px;
            font-weight: 600;
        }

        .record-section {
            margin-top: 22px;
        }

        .record-section h3 {
            font-size: 15px;
            color: #173B63;
            margin-bottom: 13px;
            padding-left: 10px;
            border-left: 4px solid #367FB5;
        }

        .details-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 15px;
        }

        .detail-item {
            background: #F7FAFD;
            padding: 15px;
            border-radius: 8px;
            min-width: 0;
        }

        .detail-item.full {
            grid-column: 1 / -1;
        }

        .detail-label {
            color: #8795A5;
            font-size: 12px;
            margin-bottom: 7px;
        }

        .detail-value {
            color: #26384A;
            font-size: 14px;
            line-height: 1.6;
            overflow-wrap: anywhere;
            white-space: pre-line;
        }

        .empty-state {
            text-align: center;
            background: #FFFFFF;
            padding: 55px 20px;
            border-radius: 12px;
            box-shadow: 0 3px 12px rgba(20, 50, 80, 0.05);
        }

        .empty-state i {
            font-size: 45px;
            color: #A7BDD1;
            margin-bottom: 18px;
        }

        .empty-state h2 {
            color: #173B63;
            font-size: 19px;
            margin-bottom: 10px;
        }

        .empty-state p {
            color: #8795A5;
            font-size: 14px;
        }

        .back-button {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-top: 15px;
            padding: 11px 18px;
            background: #367FB5;
            color: #FFFFFF;
            text-decoration: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            transition: 0.2s;
        }

        .back-button:hover {
            background: #286A9D;
        }

        @media (max-width: 768px) {
            .history-content {
                padding: 20px 15px;
            }
        }

        @media (max-width: 650px) {
            .details-grid {
                grid-template-columns: 1fr;
            }

            .detail-item.full {
                grid-column: auto;
            }

            .record-card {
                padding: 18px;
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

                <a href="../appointments_feature/appointments.php" class="menu-item">
                    <i class="fa-solid fa-calendar-check"></i>
                    <span>Appointments</span>
                </a>

                <a href="history.php" class="menu-item active">
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

                    <h2>Medical History</h2>
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
                                <strong><?php echo showValue($patientName); ?></strong>
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
                                    <strong><?php echo showValue($patientName); ?></strong>
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

            <!-- Medical History Content -->
            <section class="history-content">

                <div class="page-heading">
                    <h1>
                        <i class="fa-solid fa-file-medical"></i>
                        Medical History
                    </h1>

                    <p>View your medical records and previous treatment information.</p>
                </div>

                <div class="summary-card">

                    <div class="summary-icon">
                        <i class="fa-solid fa-notes-medical"></i>
                    </div>

                    <div class="summary-info">
                        <h3>Total Medical Records</h3>
                        <strong><?php echo count($records); ?></strong>
                    </div>

                </div>

                <?php if (count($records) > 0): ?>

                    <?php foreach ($records as $index => $record): ?>

                        <div class="record-card">

                            <div class="record-header">

                                <h2>
                                    Medical Record #<?php echo $index + 1; ?>
                                </h2>

                                <div class="record-date">
                                    <i class="fa-regular fa-calendar"></i>
                                    <?php echo showDate($record["record_date"]); ?>
                                </div>

                            </div>

                            <div class="record-section">

                                <h3>Record Information</h3>

                                <div class="details-grid">

                                    <div class="detail-item full">
                                        <div class="detail-label">Record ID</div>
                                        <div class="detail-value">
                                            <?php echo showValue($record["record_id"]); ?>
                                        </div>
                                    </div>

                                    <div class="detail-item">
                                        <div class="detail-label">Doctor Name</div>
                                        <div class="detail-value">
                                            <?php echo showValue($record["doctor_name"]); ?>
                                        </div>
                                    </div>

                                    <div class="detail-item">
                                        <div class="detail-label">Specialization</div>
                                        <div class="detail-value">
                                            <?php echo showValue($record["specialization"]); ?>
                                        </div>
                                    </div>

                                    <div class="detail-item full">
                                        <div class="detail-label">Appointment ID</div>
                                        <div class="detail-value">
                                            <?php echo showValue($record["appointment_id"]); ?>
                                        </div>
                                    </div>

                                </div>

                            </div>

                            <div class="record-section">

                                <h3>Medical Information</h3>

                                <div class="details-grid">

                                    <div class="detail-item full">
                                        <div class="detail-label">Diagnosis</div>
                                        <div class="detail-value">
                                            <?php echo showValue($record["diagnosis"]); ?>
                                        </div>
                                    </div>

                                    <div class="detail-item full">
                                        <div class="detail-label">Symptoms</div>
                                        <div class="detail-value">
                                            <?php echo showValue($record["symptoms"]); ?>
                                        </div>
                                    </div>

                                    <div class="detail-item full">
                                        <div class="detail-label">Treatment</div>
                                        <div class="detail-value">
                                            <?php echo showValue($record["treatment"]); ?>
                                        </div>
                                    </div>

                                    <div class="detail-item full">
                                        <div class="detail-label">Additional Notes</div>
                                        <div class="detail-value">
                                            <?php echo showValue($record["notes"]); ?>
                                        </div>
                                    </div>

                                </div>

                            </div>

                        </div>

                    <?php endforeach; ?>

                <?php else: ?>

                    <div class="empty-state">
                        <i class="fa-solid fa-file-circle-xmark"></i>

                        <h2>No Medical Records Yet</h2>

                        <p>Your medical history will appear here when records are available.</p>
                    </div>

                <?php endif; ?>

                <a href="../patient_dashboard.html" class="back-button">
                    <i class="fa-solid fa-arrow-left"></i>
                    Back to Dashboard
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

<?php
$stmt->close();
$patient_stmt->close();
$conn->close();
?>