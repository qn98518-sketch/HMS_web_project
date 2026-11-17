
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

$conn->set_charset("utf8mb4");

if ($conn->connect_error) {
    die("Database connection failed.");
}

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

function formatDate($date) {
    if (empty($date)) {
        return "Not available";
    }

    return date("d/m/Y H:i", strtotime($date));
}

function getStatusClass($status) {
    switch (strtolower($status)) {
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
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Appointment Details | HMS</title>

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            background: #F4F7FB;
            color: #26384A;
        }

        .container {
            max-width: 850px;
            margin: 40px auto;
            padding: 0 20px;
        }

        .page-header {
            margin-bottom: 25px;
        }

        .page-header h1 {
            font-size: 26px;
            margin-bottom: 8px;
        }

        .page-header p {
            color: #8795A5;
            font-size: 14px;
        }

        .details-card {
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 3px 12px rgba(20, 50, 80, 0.04);
        }

        .card-title {
            font-size: 18px;
            margin-bottom: 25px;
        }

        .detail-row {
            display: flex;
            justify-content: space-between;
            gap: 20px;
            padding: 17px 0;
            border-bottom: 1px solid #EDF0F4;
        }

        .detail-row:last-child {
            border-bottom: none;
        }

        .detail-label {
            color: #8190A0;
            font-size: 13px;
        }

        .detail-value {
            text-align: right;
            font-size: 14px;
            font-weight: 600;
            max-width: 60%;
            overflow-wrap: anywhere;
        }

        .status {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
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
            display: inline-block;
            margin-top: 25px;
            background: #367FB5;
            color: white;
            padding: 12px 20px;
            border-radius: 8px;
            text-decoration: none;
            font-size: 13px;
        }

        .back-button:hover {
            background: #286895;
        }

        @media (max-width: 550px) {
            .details-card {
                padding: 20px 15px;
            }

            .detail-row {
                flex-direction: column;
                gap: 8px;
            }

            .detail-value {
                text-align: left;
                max-width: 100%;
            }
        }
    </style>
</head>

<body>

<div class="container">

    <div class="page-header">
        <h1>Appointment Details</h1>
        <p>View the information about your appointment.</p>
    </div>

    <div class="details-card">

        <h2 class="card-title">Appointment Information</h2>

        <div class="detail-row">
            <span class="detail-label">Appointment ID</span>
            <span class="detail-value">
                <?php echo htmlspecialchars($appointment["appointment_id"]); ?>
            </span>
        </div>

        <div class="detail-row">
            <span class="detail-label">Doctor</span>
            <span class="detail-value">
                <?php echo htmlspecialchars($appointment["doctor_name"] ?? "Not assigned"); ?>
            </span>
        </div>

        <div class="detail-row">
            <span class="detail-label">Specialization</span>
            <span class="detail-value">
                <?php echo htmlspecialchars($appointment["specialization"] ?? "N/A"); ?>
            </span>
        </div>

        <div class="detail-row">
            <span class="detail-label">Room Number</span>
            <span class="detail-value">
                <?php echo htmlspecialchars((string)($appointment["room_number"] ?? "N/A")); ?>
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
                <?php echo htmlspecialchars($appointment["visit_reason"] ?? "Not provided"); ?>
            </span>
        </div>

        <div class="detail-row">
            <span class="detail-label">Appointment Status</span>
            <span class="detail-value">
                <span class="status <?php echo getStatusClass($appointment["appointment_status"]); ?>">
                    <?php
                    echo htmlspecialchars(
                        str_replace("_", " ", $appointment["appointment_status"])
                    );
                    ?>
                </span>
            </span>
        </div>

        <div class="detail-row">
            <span class="detail-label">Doctor Response</span>
            <span class="detail-value">
                <?php
                echo htmlspecialchars(
                    ucfirst($appointment["doctor_response"])
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
                    <?php echo htmlspecialchars($appointment["doctor_reject_reason"]); ?>
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
        Back to Appointments
    </a>

</div>

</body>
</html>
