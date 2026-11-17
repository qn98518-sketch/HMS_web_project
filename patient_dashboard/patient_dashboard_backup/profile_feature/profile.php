
<?php
session_start();

// Check login
if (!isset($_SESSION["user_id"]) || $_SESSION["role"] !== "patient") {
    die("Please login first.");
}

// Database connection
$conn = mysqli_connect(
    "localhost",
    "root",
    "",
    "hospital_management_system"
);

if (!$conn) {
    die("Database connection failed: " . mysqli_connect_error());
}

mysqli_set_charset($conn, "utf8mb4");

$userId = $_SESSION["user_id"];
$message = "";
$messageType = "";

// Handle profile update
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $fullName = trim($_POST["full_name"] ?? "");
    $email = trim($_POST["email"] ?? "");
    $phone = trim($_POST["phone_number"] ?? "");
    $address = trim($_POST["address"] ?? "");

    if ($fullName === "" || $email === "") {
        $message = "Full Name and Email are required.";
        $messageType = "error";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = "Please enter a valid email address.";
        $messageType = "error";
    } else {
        // Check duplicate email
        $check = $conn->prepare(
            "SELECT user_id FROM users WHERE email = ? AND user_id != ?"
        );
        $check->bind_param("ss", $email, $userId);
        $check->execute();
        $duplicate = $check->get_result();

        if ($duplicate->num_rows > 0) {
            $message = "This email is already being used.";
            $messageType = "error";
        } else {
            $conn->begin_transaction();

            try {
                // Update user information
                $stmt1 = $conn->prepare(
                    "UPDATE users
                     SET full_name = ?, email = ?, phone_number = ?
                     WHERE user_id = ? AND role = 'patient'"
                );
                $stmt1->bind_param(
                    "ssss",
                    $fullName,
                    $email,
                    $phone,
                    $userId
                );

                if (!$stmt1->execute()) {
                    throw new Exception("Unable to update user information.");
                }

                // Update patient address
                $stmt2 = $conn->prepare(
                    "UPDATE patients
                     SET address = ?
                     WHERE user_id = ?"
                );
                $stmt2->bind_param("ss", $address, $userId);

                if (!$stmt2->execute()) {
                    throw new Exception("Unable to update address.");
                }

                $conn->commit();

                $message = "Profile updated successfully!";
                $messageType = "success";

            } catch (Exception $e) {
                $conn->rollback();
                $message = $e->getMessage();
                $messageType = "error";
            }
        }

        $check->close();
    }
}

// Load current patient information
$stmt = $conn->prepare(
    "SELECT
        u.username,
        u.full_name,
        u.email,
        u.phone_number,
        p.dob,
        p.gender,
        p.address
     FROM users u
     INNER JOIN patients p ON u.user_id = p.user_id
     WHERE u.user_id = ?
       AND u.role = 'patient'
       AND u.user_status = 1
       AND u.is_deleted = 0
     LIMIT 1"
);

$stmt->bind_param("s", $userId);
$stmt->execute();

$result = $stmt->get_result();
$patient = $result->fetch_assoc();

if (!$patient) {
    die("Patient information not found.");
}

function escape($value) {
    return htmlspecialchars($value ?? "", ENT_QUOTES, "UTF-8");
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patient Profile</title>

    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: Arial, sans-serif;
        }

        body {
            background: #f2f6fc;
            padding: 35px 20px;
            color: #263b53;
        }

        .container {
            max-width: 850px;
            margin: auto;
        }

        .back-link {
            display: inline-block;
            margin-bottom: 20px;
            color: #367fb5;
            text-decoration: none;
            font-weight: bold;
        }

        .profile-card {
            background: white;
            padding: 35px;
            border-radius: 14px;
            box-shadow: 0 4px 18px rgba(0, 0, 0, 0.06);
        }

        h1 {
            margin-bottom: 8px;
            color: #24445f;
        }

        .description {
            color: #718096;
            margin-bottom: 28px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 22px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .full-width {
            grid-column: 1 / -1;
        }

        label {
            font-weight: bold;
            margin-bottom: 8px;
            font-size: 14px;
        }

        input {
            width: 100%;
            padding: 12px;
            border: 1px solid #d5e0eb;
            border-radius: 7px;
            font-size: 15px;
            outline: none;
        }

        input:focus {
            border-color: #367fb5;
        }

        input:disabled {
            background: #edf1f5;
            color: #687789;
            cursor: not-allowed;
        }

        .buttons {
            margin-top: 28px;
            display: flex;
            gap: 12px;
        }

        button {
            padding: 12px 24px;
            border: none;
            border-radius: 7px;
            cursor: pointer;
            font-size: 15px;
            font-weight: bold;
        }

        .save-btn {
            background: #367fb5;
            color: white;
        }

        .save-btn:hover {
            background: #286894;
        }

        .back-btn {
            background: #e6edf4;
            color: #24445f;
        }

        .message {
            padding: 13px;
            margin-bottom: 22px;
            border-radius: 7px;
        }

        .success {
            background: #e1f5e8;
            color: #237442;
        }

        .error {
            background: #fde8e8;
            color: #b42318;
        }

        @media (max-width: 600px) {
            .form-grid {
                grid-template-columns: 1fr;
            }

            .full-width {
                grid-column: auto;
            }

            .profile-card {
                padding: 22px;
            }
        }
    </style>
</head>

<body>

<div class="container">

    <a class="back-link"
       href="../patient_dashboard.html">
        ← Back to Dashboard
    </a>

    <div class="profile-card">

        <h1>My Profile</h1>
        <p class="description">
            View and update your personal information.
        </p>

        <?php if ($message !== ""): ?>
            <div class="message <?= escape($messageType) ?>">
                <?= escape($message) ?>
            </div>
        <?php endif; ?>

        <form method="POST">

            <div class="form-grid">

                <div class="form-group">
                    <label>Username</label>
                    <input type="text"
                           value="<?= escape($patient["username"]) ?>"
                           disabled>
                </div>

                <div class="form-group">
                    <label>Full Name *</label>
                    <input type="text"
                           name="full_name"
                           value="<?= escape($patient["full_name"]) ?>"
                           maxlength="100"
                           required>
                </div>

                <div class="form-group">
                    <label>Email *</label>
                    <input type="email"
                           name="email"
                           value="<?= escape($patient["email"]) ?>"
                           maxlength="100"
                           required>
                </div>

                <div class="form-group">
                    <label>Phone Number</label>
                    <input type="text"
                           name="phone_number"
                           value="<?= escape($patient["phone_number"]) ?>"
                           maxlength="20">
                </div>

                <div class="form-group">
                    <label>Date of Birth</label>
                    <input type="text"
                           value="<?= escape($patient["dob"]) ?>"
                           disabled>
                </div>

                <div class="form-group">
                    <label>Gender</label>
                    <input type="text"
                           value="<?= escape($patient["gender"]) ?>"
                           disabled>
                </div>

                <div class="form-group full-width">
                    <label>Address</label>
                    <input type="text"
                           name="address"
                           value="<?= escape($patient["address"]) ?>"
                           maxlength="255">
                </div>

            </div>

            <div class="buttons">
                <button type="submit" class="save-btn">
                    Save Changes
                </button>

                <button type="reset" class="back-btn">
                    Reset
                </button>
            </div>

        </form>

    </div>
</div>

</body>
</html>
