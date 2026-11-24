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

// Get patient's bills
$sql = "
    SELECT
        bill_id,
        patient_id,
        appointment_id,
        total_amount,
        paid_amount,
        payment_method,
        payment_status,
        bill_date,
        due_date,
        notes
    FROM bills
    WHERE patient_id = ?
    ORDER BY bill_date DESC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $patient_id);
$stmt->execute();

$result = $stmt->get_result();

$bills = [];

$total_amount = 0;
$total_paid = 0;
$total_remaining = 0;

while ($row = $result->fetch_assoc()) {

    $row["remaining_balance"] = max(
        0,
        (float)$row["total_amount"] - (float)$row["paid_amount"]
    );

    $bills[] = $row;

    if (strtolower($row["payment_status"] ?? "") !== "cancelled") {
        $total_amount += (float)$row["total_amount"];
        $total_paid += (float)$row["paid_amount"];
        $total_remaining += $row["remaining_balance"];
    }
}

function showValue($value)
{
    if ($value === null || trim((string)$value) === "") {
        return "Not available";
    }

    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        "UTF-8"
    );
}

function showDate($value)
{
    if (empty($value)) {
        return "Not available";
    }

    return date("d/m/Y H:i", strtotime($value));
}

function showMoney($value)
{
    return number_format((float)$value, 2);
}

function statusClass($status)
{
    $status = strtolower($status ?? "");

    if ($status === "paid") {
        return "paid";
    }

    if ($status === "partially paid") {
        return "partial";
    }

    if ($status === "cancelled") {
        return "cancelled";
    }

    return "unpaid";
}

$patientName = $_SESSION["username"] ?? "Patient";
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>My Bills | Patient</title>

    <link rel="stylesheet" href="../css/layout.css">

    <link rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">

    <style>
        .bills-content {
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

        /* Summary */
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 18px;
            margin-bottom: 25px;
        }

        .summary-card {
            background: #FFFFFF;
            padding: 22px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            gap: 15px;
            box-shadow: 0 3px 12px rgba(20, 50, 80, 0.05);
            min-width: 0;
        }

        .summary-icon {
            width: 50px;
            height: 50px;
            flex-shrink: 0;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }

        .summary-icon.blue {
            background: #E4F0FA;
            color: #367FB5;
        }

        .summary-icon.green {
            background: #E1F5E9;
            color: #27945A;
        }

        .summary-icon.orange {
            background: #FFF0DC;
            color: #D88922;
        }

        .summary-info p {
            font-size: 12px;
            color: #8795A5;
            margin-bottom: 6px;
        }

        .summary-info strong {
            font-size: 20px;
            color: #173B63;
            overflow-wrap: anywhere;
        }

        /* Bill card */
        .bill-card {
            background: #FFFFFF;
            padding: 25px;
            border-radius: 12px;
            margin-bottom: 20px;
            box-shadow: 0 3px 12px rgba(20, 50, 80, 0.05);
        }

        .bill-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            padding-bottom: 18px;
            border-bottom: 1px solid #E8EEF5;
        }

        .bill-header h2 {
            font-size: 18px;
            color: #173B63;
        }

        /* Payment status */
        .status {
            display: inline-block;
            padding: 7px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: capitalize;
        }

        .paid {
            background: #DDF5E8;
            color: #18834B;
        }

        .partial {
            background: #FFF4D6;
            color: #A66A00;
        }

        .unpaid {
            background: #FDE5E5;
            color: #C0392B;
        }

        .cancelled {
            background: #E8E8E8;
            color: #666666;
        }

        /* Bill sections */
        .bill-section {
            margin-top: 22px;
        }

        .bill-section h3 {
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

        /* Empty state */
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
            margin-bottom: 10px;
            font-size: 19px;
        }

        .empty-state p {
            color: #8795A5;
            font-size: 14px;
        }

        /* Back button */
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

        @media (max-width: 900px) {
            .summary-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .bills-content {
                padding: 20px 15px;
            }
        }

        @media (max-width: 600px) {
            .details-grid {
                grid-template-columns: 1fr;
            }

            .detail-item.full {
                grid-column: auto;
            }

            .bill-card {
                padding: 18px;
            }
        }

        /* Patient Avatar */
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

                <a href="../history_feature/history.php" class="menu-item">
                    <i class="fa-solid fa-file-medical"></i>
                    <span>Medical History</span>
                </a>

                <a href="bills.php" class="menu-item active">
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

                    <h2>My Bills</h2>
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

            <!-- Bills Content -->
            <section class="bills-content">

                <div class="page-heading">
                    <h1>
                        <i class="fa-solid fa-file-invoice-dollar"></i>
                        My Bills
                    </h1>

                    <p>View your billing information and payment history.</p>
                </div>

                <!-- Summary -->
                <div class="summary-grid">

                    <div class="summary-card">

                        <div class="summary-icon blue">
                            <i class="fa-solid fa-file-invoice"></i>
                        </div>

                        <div class="summary-info">
                            <p>Total Bills</p>
                            <strong><?php echo count($bills); ?></strong>
                        </div>

                    </div>

                    <div class="summary-card">

                        <div class="summary-icon green">
                            <i class="fa-solid fa-money-bill-wave"></i>
                        </div>

                        <div class="summary-info">
                            <p>Total Paid</p>
                            <strong><?php echo showMoney($total_paid); ?></strong>
                        </div>

                    </div>

                    <div class="summary-card">

                        <div class="summary-icon orange">
                            <i class="fa-solid fa-wallet"></i>
                        </div>

                        <div class="summary-info">
                            <p>Remaining Balance</p>
                            <strong><?php echo showMoney($total_remaining); ?></strong>
                        </div>

                    </div>

                </div>

                <!-- Bills List -->
                <?php if (count($bills) > 0): ?>

                    <?php foreach ($bills as $index => $bill): ?>

                        <div class="bill-card">

                            <div class="bill-header">

                                <h2>
                                    <i class="fa-solid fa-receipt"></i>
                                    Bill #<?php echo $index + 1; ?>
                                </h2>

                                <span class="status <?php echo statusClass($bill["payment_status"]); ?>">
                                    <?php echo showValue($bill["payment_status"]); ?>
                                </span>

                            </div>

                            <!-- Bill Information -->
                            <div class="bill-section">

                                <h3>Bill Information</h3>

                                <div class="details-grid">

                                    <div class="detail-item full">
                                        <div class="detail-label">Bill ID</div>
                                        <div class="detail-value">
                                            <?php echo showValue($bill["bill_id"]); ?>
                                        </div>
                                    </div>

                                    <div class="detail-item full">
                                        <div class="detail-label">Appointment ID</div>
                                        <div class="detail-value">
                                            <?php echo showValue($bill["appointment_id"]); ?>
                                        </div>
                                    </div>

                                    <div class="detail-item">
                                        <div class="detail-label">Bill Date</div>
                                        <div class="detail-value">
                                            <?php echo showDate($bill["bill_date"]); ?>
                                        </div>
                                    </div>

                                    <div class="detail-item">
                                        <div class="detail-label">Due Date</div>
                                        <div class="detail-value">
                                            <?php
                                            echo !empty($bill["due_date"])
                                                ? date("d/m/Y", strtotime($bill["due_date"]))
                                                : "Not available";
                                            ?>
                                        </div>
                                    </div>

                                </div>

                            </div>

                            <!-- Payment Information -->
                            <div class="bill-section">

                                <h3>Payment Information</h3>

                                <div class="details-grid">

                                    <div class="detail-item">
                                        <div class="detail-label">Total Amount</div>
                                        <div class="detail-value">
                                            <?php echo showMoney($bill["total_amount"]); ?>
                                        </div>
                                    </div>

                                    <div class="detail-item">
                                        <div class="detail-label">Paid Amount</div>
                                        <div class="detail-value">
                                            <?php echo showMoney($bill["paid_amount"]); ?>
                                        </div>
                                    </div>

                                    <div class="detail-item">
                                        <div class="detail-label">Remaining Balance</div>
                                        <div class="detail-value">
                                            <?php echo showMoney($bill["remaining_balance"]); ?>
                                        </div>
                                    </div>

                                    <div class="detail-item">
                                        <div class="detail-label">Payment Method</div>
                                        <div class="detail-value">
                                            <?php echo showValue($bill["payment_method"]); ?>
                                        </div>
                                    </div>

                                    <div class="detail-item">
                                        <div class="detail-label">Payment Status</div>
                                        <div class="detail-value">
                                            <?php echo showValue($bill["payment_status"]); ?>
                                        </div>
                                    </div>

                                    <div class="detail-item full">
                                        <div class="detail-label">Notes</div>
                                        <div class="detail-value">
                                            <?php echo showValue($bill["notes"]); ?>
                                        </div>
                                    </div>

                                </div>

                            </div>

                        </div>

                    <?php endforeach; ?>

                <?php else: ?>

                    <div class="empty-state">

                        <i class="fa-solid fa-file-circle-xmark"></i>

                        <h2>No Bills Yet</h2>

                        <p>Your billing information will appear here when bills are available.</p>

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