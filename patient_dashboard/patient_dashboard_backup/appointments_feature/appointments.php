
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

$conn->set_charset("utf8mb4");

if ($conn->connect_error) {
    die("Database connection failed.");
}

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

    <title>My Appointments | HMS</title>

    <!-- Font Awesome -->
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
        }

        a {
            text-decoration: none;
        }

        /* MAIN CONTAINER */

        .container {
            max-width: 1200px;
            margin: auto;
            padding: 35px;
        }

        /* PAGE HEADER */

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            margin-bottom: 30px;
        }

        .page-header h1 {
            font-size: 26px;
            margin-bottom: 8px;
        }

        .page-header p {
            color: #8795A5;
            font-size: 14px;
        }

        .back-button {
            background: #367FB5;
            color: white;
            padding: 11px 18px;
            border-radius: 8px;
            font-size: 13px;
            transition: 0.2s;
        }

        .back-button:hover {
            background: #286895;
        }

        /* APPOINTMENT CARD */

        .appointment-card {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 3px 12px rgba(20, 50, 80, 0.04);
        }

        .card-header {
            margin-bottom: 22px;
        }

        .card-header h2 {
            font-size: 18px;
            margin-bottom: 7px;
        }

        .card-header p {
            color: #929EAA;
            font-size: 13px;
        }

        /* TABLE */

        .table-wrapper {
            width: 100%;
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 900px;
        }

        thead {
            background: #F5F8FC;
        }

        th {
            color: #75869A;
            font-size: 12px;
            font-weight: 600;
            text-align: left;
            padding: 15px 12px;
        }

        td {
            padding: 16px 12px;
            font-size: 13px;
            border-bottom: 1px solid #EDF0F4;
            vertical-align: middle;
        }

        .doctor-name {
            font-weight: 600;
            color: #26384A;
        }

        .specialization {
            display: block;
            margin-top: 5px;
            color: #929EAA;
            font-size: 11px;
        }

        /* STATUS */

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

        /* VIEW DETAILS BUTTON */

        .details-button {
            display: inline-block;
            background: #367FB5;
            color: white;
            padding: 8px 12px;
            border-radius: 6px;
            font-size: 11px;
            white-space: nowrap;
            transition: 0.2s;
        }

        .details-button:hover {
            background: #286895;
        }

        /* EMPTY STATE */

        .empty-state {
            text-align: center;
            padding: 60px 15px;
            color: #929EAA;
        }

        .empty-state i {
            font-size: 40px;
            margin-bottom: 15px;
            color: #AAB8C6;
        }

        .empty-state h3 {
            color: #40566C;
            margin-bottom: 8px;
        }

        .empty-state p {
            font-size: 13px;
        }

        /* RESPONSE */

        .response-text {
            color: #65788C;
            font-size: 12px;
        }

        /* RESPONSIVE */

        @media (max-width: 600px) {

            .container {
                padding: 20px 15px;
            }

            .page-header {
                align-items: flex-start;
                flex-direction: column;
            }

            .appointment-card {
                padding: 18px 12px;
            }

        }

    </style>

</head>

<body>

<div class="container">

    <!-- PAGE HEADER -->

    <div class="page-header">

        <div>

            <h1>My Appointments</h1>

            <p>
                View and keep track of your appointments.
            </p>

        </div>

        <a href="../patient_dashboard.html" class="back-button">
            <i class="fas fa-arrow-left"></i>
            Back to Dashboard
        </a>

    </div>


    <!-- APPOINTMENTS TABLE -->

    <div class="appointment-card">

        <div class="card-header">

            <h2>Appointment History</h2>

            <p>
                You have
                <?php echo count($appointments); ?>
                appointment(s).
            </p>

        </div>


        <?php if (count($appointments) > 0): ?>

            <div class="table-wrapper">

                <table>

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

                                        <?php
                                        echo htmlspecialchars(
                                            $appointment["doctor_name"] ?? "Not assigned"
                                        );
                                        ?>

                                    </span>

                                    <span class="specialization">

                                        <?php
                                        echo htmlspecialchars(
                                            $appointment["specialization"] ?? "N/A"
                                        );
                                        ?>

                                    </span>

                                </td>


                                <!-- DATE AND TIME -->

                                <td>

                                    <?php

                                    if (!empty($appointment["appointment_datetime"])) {

                                        echo date(
                                            "d/m/Y H:i",
                                            strtotime($appointment["appointment_datetime"])
                                        );

                                    } else {

                                        echo "Not available";

                                    }

                                    ?>

                                </td>


                                <!-- VISIT REASON -->

                                <td>

                                    <?php
                                    echo htmlspecialchars(
                                        $appointment["visit_reason"] ?? "Not provided"
                                    );
                                    ?>

                                </td>


                                <!-- APPOINTMENT STATUS -->

                                <td>

                                    <span class="status <?php
                                        echo getStatusClass(
                                            $appointment["appointment_status"]
                                        );
                                    ?>">

                                        <?php

                                        echo htmlspecialchars(
                                            str_replace(
                                                "_",
                                                " ",
                                                $appointment["appointment_status"]
                                            )
                                        );

                                        ?>

                                    </span>

                                </td>


                                <!-- DOCTOR RESPONSE -->

                                <td class="response-text">

                                    <?php

                                    if (
                                        $appointment["doctor_response"] === "accepted"
                                    ) {

                                        echo "Accepted";

                                    } elseif (
                                        $appointment["doctor_response"] === "rejected"
                                    ) {

                                        echo "Rejected";

                                        if (!empty($appointment["doctor_reject_reason"])) {

                                            echo ": " . htmlspecialchars(
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
                                        href="appointment_details.php?id=<?php
                                            echo urlencode(
                                                $appointment["appointment_id"]
                                            );
                                        ?>"
                                        class="details-button"
                                    >

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

            <!-- NO APPOINTMENTS -->

            <div class="empty-state">

                <i class="fas fa-calendar-xmark"></i>

                <h3>No Appointments Yet</h3>

                <p>
                    You do not have any appointments at the moment.
                </p>

            </div>

        <?php endif; ?>

    </div>

</div>

</body>
</html>
