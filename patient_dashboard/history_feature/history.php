
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

function showValue($value) {
    if ($value === null || trim((string)$value) === "") {
        return "Not available";
    }

    return htmlspecialchars((string)$value);
}

function showDate($value) {
    if (empty($value)) {
        return "Not available";
    }

    return date("d/m/Y H:i", strtotime($value));
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Medical History | Patient</title>

    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">

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
            padding: 35px 20px;
        }

        .container {
            max-width: 1100px;
            margin: auto;
        }

        .page-header {
            margin-bottom: 25px;
        }

        .page-header h1 {
            color: #173B63;
            font-size: 28px;
            margin-bottom: 8px;
        }

        .page-header p {
            color: #718096;
        }

        .summary {
            display: flex;
            align-items: center;
            gap: 15px;
            background: white;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 25px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.04);
        }

        .summary-icon {
            width: 50px;
            height: 50px;
            background: #E4F0FA;
            color: #367FB5;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
        }

        .summary h3 {
            font-size: 14px;
            color: #718096;
            margin-bottom: 4px;
        }

        .summary strong {
            font-size: 23px;
            color: #173B63;
        }

        .record-card {
            background: white;
            padding: 25px;
            border-radius: 12px;
            margin-bottom: 20px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.04);
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
            font-size: 18px;
            color: #173B63;
        }

        .record-date {
            color: #367FB5;
            font-size: 13px;
            font-weight: 600;
        }

        .section {
            margin-top: 22px;
        }

        .section h3 {
            font-size: 15px;
            color: #173B63;
            margin-bottom: 13px;
            padding-left: 10px;
            border-left: 4px solid #367FB5;
        }

        .details-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
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
            color: #718096;
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
            background: white;
            padding: 55px 20px;
            border-radius: 12px;
        }

        .empty-state i {
            font-size: 45px;
            color: #A7BDD1;
            margin-bottom: 18px;
        }

        .empty-state h2 {
            color: #173B63;
            margin-bottom: 10px;
        }

        .empty-state p {
            color: #718096;
        }

        .back-button {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-top: 15px;
            padding: 11px 18px;
            background: #367FB5;
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
        }

        .back-button:hover {
            background: #286A9D;
        }

        @media (max-width: 650px) {
            body {
                padding: 20px 12px;
            }

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
    </style>
</head>

<body>

<div class="container">

    <div class="page-header">
        <h1>
            <i class="fa-solid fa-file-medical"></i>
            Medical History
        </h1>

        <p>View your medical records and previous treatment information.</p>
    </div>

    <div class="summary">
        <div class="summary-icon">
            <i class="fa-solid fa-notes-medical"></i>
        </div>

        <div>
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

                <div class="section">
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

                <div class="section">
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

</div>

</body>
</html>

<?php
$stmt->close();
$patient_stmt->close();
$conn->close();
?>
