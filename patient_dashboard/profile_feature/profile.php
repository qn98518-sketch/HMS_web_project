
<?php
session_start();

// =====================================
// 1. CHECK LOGIN
// =====================================

if (
    !isset($_SESSION["user_id"]) ||
    !isset($_SESSION["role"]) ||
    $_SESSION["role"] !== "patient"
) {
    header("Location: ../../login_feature/index.html");
    exit();
}

// =====================================
// 2. DATABASE CONNECTION
// =====================================

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

$userId = (string) $_SESSION["user_id"];

$message = "";
$messageType = "";


// =====================================
// 3. AVATAR CONFIGURATION
// =====================================

// Avatar folder:
// patient_dashboard/uploads/avatars/

$avatarFolder = __DIR__ . "/../uploads/avatars/";

// Create folder if it does not exist
if (!is_dir($avatarFolder)) {
    mkdir($avatarFolder, 0755, true);
}

// Use a hashed user ID for the avatar filename
$avatarBaseName = hash("sha256", $userId);

$allowedMimeTypes = [
    "image/jpeg" => "jpg",
    "image/png"  => "png",
    "image/webp" => "webp"
];


// =====================================
// 4. GET CURRENT AVATAR
// =====================================

function getPatientAvatar($folder, $baseName, $extensions) {

    foreach ($extensions as $extension) {

        $fileName = $baseName . "." . $extension;
        $filePath = $folder . $fileName;

        if (is_file($filePath)) {
            return "../uploads/avatars/" . $fileName;
        }
    }

    return "";
}

$currentAvatar = getPatientAvatar(
    $avatarFolder,
    $avatarBaseName,
    ["jpg", "png", "webp"]
);


// =====================================
// 5. HANDLE FORM SUBMISSION
// =====================================

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $action = $_POST["action"] ?? "";


    // =================================
    // A. UPLOAD AVATAR
    // =================================

    if ($action === "upload_avatar") {

        if (
            !isset($_FILES["avatar"]) ||
            $_FILES["avatar"]["error"] !== UPLOAD_ERR_OK
        ) {

            $message = "Please select an image to upload.";
            $messageType = "error";

        } elseif ($_FILES["avatar"]["size"] > 2 * 1024 * 1024) {

            $message = "Image size must not exceed 2MB.";
            $messageType = "error";

        } else {

            $temporaryFile = $_FILES["avatar"]["tmp_name"];

            // Detect actual file MIME type
            $fileInfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($fileInfo, $temporaryFile);
            finfo_close($fileInfo);

            // Check whether the image is valid
            $imageInfo = getimagesize($temporaryFile);

            if (
                !$imageInfo ||
                !isset($allowedMimeTypes[$mimeType]) ||
                $imageInfo["mime"] !== $mimeType
            ) {

                $message = "Only JPG, PNG and WEBP images are allowed.";
                $messageType = "error";

            } else {

                $extension = $allowedMimeTypes[$mimeType];

                $newFileName = $avatarBaseName . "." . $extension;
                $destination = $avatarFolder . $newFileName;

                // Remove old avatar versions
                foreach (["jpg", "png", "webp"] as $oldExtension) {

                    $oldFile = $avatarFolder .
                               $avatarBaseName . "." . $oldExtension;

                    if (is_file($oldFile)) {
                        unlink($oldFile);
                    }
                }

                // Save uploaded image
                if (move_uploaded_file($temporaryFile, $destination)) {

                    $message = "Avatar updated successfully!";
                    $messageType = "success";

                    $currentAvatar = "../uploads/avatars/" . $newFileName;

                } else {

                    $message = "Unable to save avatar. Please try again.";
                    $messageType = "error";
                }
            }
        }
    }


    // =================================
    // B. UPDATE PROFILE
    // =================================

    elseif ($action === "update_profile") {

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
                "SELECT user_id
                 FROM users
                 WHERE email = ? AND user_id != ?"
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
                         SET full_name = ?,
                             email = ?,
                             phone_number = ?
                         WHERE user_id = ?
                           AND role = 'patient'"
                    );

                    $stmt1->bind_param(
                        "ssss",
                        $fullName,
                        $email,
                        $phone,
                        $userId
                    );

                    if (!$stmt1->execute()) {
                        throw new Exception(
                            "Unable to update user information."
                        );
                    }

                    // Update patient address
                    $stmt2 = $conn->prepare(
                        "UPDATE patients
                         SET address = ?
                         WHERE user_id = ?"
                    );

                    $stmt2->bind_param(
                        "ss",
                        $address,
                        $userId
                    );

                    if (!$stmt2->execute()) {
                        throw new Exception(
                            "Unable to update address."
                        );
                    }

                    $conn->commit();

                    $message = "Profile updated successfully!";
                    $messageType = "success";

                    $stmt1->close();
                    $stmt2->close();

                } catch (Exception $e) {

                    $conn->rollback();

                    $message = $e->getMessage();
                    $messageType = "error";
                }
            }

            $check->close();
        }
    }


    // =================================
    // C. CHANGE PASSWORD
    // =================================

    elseif ($action === "change_password") {

        $currentPassword = $_POST["current_password"] ?? "";
        $newPassword = $_POST["new_password"] ?? "";
        $confirmPassword = $_POST["confirm_password"] ?? "";

        if (
            $currentPassword === "" ||
            $newPassword === "" ||
            $confirmPassword === ""
        ) {

            $message = "Please fill in all password fields.";
            $messageType = "error";

        } elseif (strlen($newPassword) < 8) {

            $message = "New password must contain at least 8 characters.";
            $messageType = "error";

        } elseif ($newPassword !== $confirmPassword) {

            $message = "New password and confirmation do not match.";
            $messageType = "error";

        } elseif ($currentPassword === $newPassword) {

            $message = "New password must be different from current password.";
            $messageType = "error";

        } else {

            // Get current password
            $stmt = $conn->prepare(
                "SELECT user_password
                 FROM users
                 WHERE user_id = ?
                   AND role = 'patient'
                   AND user_status = 1
                   AND is_deleted = 0
                 LIMIT 1"
            );

            $stmt->bind_param("s", $userId);
            $stmt->execute();

            $result = $stmt->get_result();
            $user = $result->fetch_assoc();

            if (!$user) {

                $message = "User account not found.";
                $messageType = "error";

            } elseif (!password_verify(
                $currentPassword,
                $user["user_password"]
            )) {

                $message = "Current password is incorrect.";
                $messageType = "error";

            } else {

                // Hash new password
                $hashedPassword = password_hash(
                    $newPassword,
                    PASSWORD_DEFAULT
                );

                // Update password
                $update = $conn->prepare(
                    "UPDATE users
                     SET user_password = ?
                     WHERE user_id = ?
                       AND role = 'patient'"
                );

                $update->bind_param(
                    "ss",
                    $hashedPassword,
                    $userId
                );

                if ($update->execute()) {

                    $update->close();
                    $stmt->close();
                    $conn->close();

                    // Destroy current session
                    $_SESSION = [];

                    if (ini_get("session.use_cookies")) {

                        $params = session_get_cookie_params();

                        setcookie(
                            session_name(),
                            "",
                            time() - 42000,
                            $params["path"],
                            $params["domain"],
                            $params["secure"],
                            $params["httponly"]
                        );
                    }

                    session_destroy();

                    // Redirect to Login page
                    header(
                        "Location: ../../login_feature/index.html?password_changed=success"
                    );

                    exit();

                } else {

                    $message = "Unable to change password. Please try again.";
                    $messageType = "error";
                }

                $update->close();
            }

            $stmt->close();
        }
    }
}


// =====================================
// 6. LOAD PATIENT INFORMATION
// =====================================

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


// =====================================
// 7. ESCAPE OUTPUT
// =====================================

function escape($value) {

    return htmlspecialchars(
        $value ?? "",
        ENT_QUOTES,
        "UTF-8"
    );
}

$patientName = $patient["full_name"] ?: $patient["username"];


// Get latest avatar
$currentAvatar = getPatientAvatar(
    $avatarFolder,
    $avatarBaseName,
    ["jpg", "png", "webp"]
);

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>My Profile | HMS</title>

    <!-- Shared Patient Layout -->
    <link rel="stylesheet" href="../css/layout.css">

    <!-- Profile CSS -->
    <link rel="stylesheet" href="../css/profile.css">

    <!-- Font Awesome -->
    <link rel="stylesheet"
          href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">

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

            <p class="menu-title">MAIN MENU</p>

            <a href="../patient_dashboard.html" class="menu-item">
                <i class="fa-solid fa-house"></i>
                <span>Dashboard</span>
            </a>

            <a href="profile.php" class="menu-item active">
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


    <!-- =====================================
         MAIN CONTENT
    ===================================== -->

    <main class="main-content">


        <!-- HEADER -->

        <header class="top-header">

            <div class="header-left">

                <button class="menu-toggle" id="menuToggle">
                    <i class="fa-solid fa-bars"></i>
                </button>

                <h3>My Profile</h3>

            </div>


            <div class="header-right">

                <div class="header-date" id="currentDate"></div>


                <div class="account-menu">

                    <div class="account-trigger" id="accountTrigger">

                        <div class="profile-avatar">

                            <?php if ($currentAvatar !== ""): ?>

                                <img
                                    src="<?php echo escape($currentAvatar); ?>?v=<?php echo time(); ?>"
                                    alt="Patient Avatar"
                                >

                            <?php else: ?>

                                <i class="fa-solid fa-user"></i>

                            <?php endif; ?>

                        </div>


                        <div class="profile-text">

                            <strong><?php echo escape($patientName); ?></strong>

                            <span>Patient</span>

                        </div>

                        <i class="fa-solid fa-chevron-down"></i>

                    </div>


                    <!-- ACCOUNT DROPDOWN -->

                    <div class="profile-dropdown" id="profileDropdown" hidden>

                        <div class="dropdown-user">

                            <div class="dropdown-avatar">

                                <?php if ($currentAvatar !== ""): ?>

                                    <img
                                        src="<?php echo escape($currentAvatar); ?>?v=<?php echo time(); ?>"
                                        alt="Patient Avatar"
                                    >

                                <?php else: ?>

                                    <i class="fa-solid fa-user"></i>

                                <?php endif; ?>

                            </div>


                            <div class="dropdown-user-text">

                                <strong><?php echo escape($patientName); ?></strong>

                                <span>Patient Account</span>

                            </div>

                        </div>


                        <a href="profile.php" class="dropdown-item">
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


        <!-- =====================================
             PROFILE CONTENT
        ===================================== -->

        <section class="profile-content">


            <!-- BACK TO DASHBOARD -->

            <a href="../patient_dashboard.html" class="back-button">

                <i class="fa-solid fa-arrow-left"></i>

                Back to Dashboard

            </a>


            <div class="page-heading">

                <h1>My Profile</h1>

                <p>
                    View and update your personal information.
                </p>

            </div>


            <!-- MESSAGE -->

            <?php if ($message !== ""): ?>

                <div class="message <?php echo escape($messageType); ?>">

                    <?php echo escape($message); ?>

                </div>

            <?php endif; ?>


            <!-- =================================
                 AVATAR SECTION
            ================================== -->

            <div class="profile-card">

                <div class="card-heading">

                    <i class="fa-solid fa-camera"></i>

                    <h2>Profile Avatar</h2>

                </div>


                <p class="description">
                    Upload a profile picture for your Patient account.
                </p>


                <div class="avatar-upload-container">

                    <!-- AVATAR PREVIEW -->

                    <div class="avatar-preview">

                        <?php if ($currentAvatar !== ""): ?>

                            <img
                                src="<?php echo escape($currentAvatar); ?>?v=<?php echo time(); ?>"
                                id="avatarPreview"
                                alt="Avatar Preview"
                            >

                        <?php else: ?>

                            <div class="avatar-placeholder" id="avatarPlaceholder">

                                <i class="fa-solid fa-user"></i>

                            </div>

                            <img
                                src=""
                                id="avatarPreview"
                                alt="Avatar Preview"
                                style="display: none;"
                            >

                        <?php endif; ?>

                    </div>


                    <!-- UPLOAD FORM -->

                    <div class="avatar-upload-details">

                        <form
                            method="POST"
                            enctype="multipart/form-data"
                        >

                            <input
                                type="hidden"
                                name="action"
                                value="upload_avatar"
                            >


                            <div class="form-group">

                                <label for="avatarInput">
                                    Choose Profile Picture
                                </label>

                                <input
                                    type="file"
                                    name="avatar"
                                    id="avatarInput"
                                    accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                                    required
                                >

                                <span class="password-note">
                                    JPG, PNG or WEBP. Maximum file size: 2MB.
                                </span>

                            </div>


                            <div class="form-buttons">

                                <button
                                    type="submit"
                                    class="form-button save-button"
                                >

                                    <i class="fa-solid fa-upload"></i>

                                    Save Avatar

                                </button>

                            </div>

                        </form>

                    </div>

                </div>

            </div>


            <!-- =================================
                 PERSONAL INFORMATION
            ================================== -->

            <div class="profile-card">

                <div class="card-heading">

                    <i class="fa-solid fa-user"></i>

                    <h2>Personal Information</h2>

                </div>


                <p class="description">
                    Manage your personal information below.
                </p>


                <form method="POST">

                    <input
                        type="hidden"
                        name="action"
                        value="update_profile"
                    >


                    <div class="form-grid">


                        <div class="form-group">

                            <label>Username</label>

                            <input
                                type="text"
                                value="<?php echo escape($patient["username"]); ?>"
                                disabled
                            >

                        </div>


                        <div class="form-group">

                            <label>Full Name *</label>

                            <input
                                type="text"
                                name="full_name"
                                value="<?php echo escape($patient["full_name"]); ?>"
                                maxlength="100"
                                required
                            >

                        </div>


                        <div class="form-group">

                            <label>Email *</label>

                            <input
                                type="email"
                                name="email"
                                value="<?php echo escape($patient["email"]); ?>"
                                maxlength="100"
                                required
                            >

                        </div>


                        <div class="form-group">

                            <label>Phone Number</label>

                            <input
                                type="text"
                                name="phone_number"
                                value="<?php echo escape($patient["phone_number"]); ?>"
                                maxlength="20"
                            >

                        </div>


                        <div class="form-group">

                            <label>Date of Birth</label>

                            <input
                                type="text"
                                value="<?php echo escape($patient["dob"]); ?>"
                                disabled
                            >

                        </div>


                        <div class="form-group">

                            <label>Gender</label>

                            <input
                                type="text"
                                value="<?php echo escape($patient["gender"]); ?>"
                                disabled
                            >

                        </div>


                        <div class="form-group full-width">

                            <label>Address</label>

                            <input
                                type="text"
                                name="address"
                                value="<?php echo escape($patient["address"]); ?>"
                                maxlength="255"
                            >

                        </div>

                    </div>


                    <div class="form-buttons">

                        <button
                            type="submit"
                            class="form-button save-button"
                        >

                            <i class="fa-solid fa-floppy-disk"></i>

                            Save Changes

                        </button>


                        <button
                            type="reset"
                            class="form-button reset-button"
                        >

                            <i class="fa-solid fa-rotate-left"></i>

                            Reset

                        </button>

                    </div>

                </form>

            </div>


            <!-- =================================
                 CHANGE PASSWORD
            ================================== -->

            <div class="profile-card">

                <div class="card-heading">

                    <i class="fa-solid fa-lock"></i>

                    <h2>Change Password</h2>

                </div>


                <p class="description">
                    Update your password to keep your account secure.
                </p>


                <form method="POST">

                    <input
                        type="hidden"
                        name="action"
                        value="change_password"
                    >


                    <div class="form-grid">


                        <div class="form-group full-width">

                            <label>Current Password *</label>

                            <input
                                type="password"
                                name="current_password"
                                placeholder="Enter your current password"
                                autocomplete="current-password"
                                required
                            >

                        </div>


                        <div class="form-group">

                            <label>New Password *</label>

                            <input
                                type="password"
                                name="new_password"
                                placeholder="Enter new password"
                                minlength="8"
                                autocomplete="new-password"
                                required
                            >

                            <span class="password-note">
                                Minimum 8 characters.
                            </span>

                        </div>


                        <div class="form-group">

                            <label>Confirm New Password *</label>

                            <input
                                type="password"
                                name="confirm_password"
                                placeholder="Confirm new password"
                                minlength="8"
                                autocomplete="new-password"
                                required
                            >

                        </div>

                    </div>


                    <div class="form-buttons">

                        <button
                            type="submit"
                            class="form-button save-button"
                        >

                            <i class="fa-solid fa-key"></i>

                            Update Password

                        </button>

                    </div>

                </form>

            </div>

        </section>

    </main>

</div>


<!-- =====================================
     JAVASCRIPT
===================================== -->

<script>

// =====================================
// DISPLAY CURRENT DATE
// =====================================

const dateElement = document.getElementById("currentDate");

if (dateElement) {

    dateElement.textContent = new Date().toLocaleDateString("en-US", {
        weekday: "short",
        year: "numeric",
        month: "short",
        day: "numeric"
    });

}


// =====================================
// SIDEBAR TOGGLE
// =====================================

const menuToggle = document.getElementById("menuToggle");
const sidebar = document.getElementById("sidebar");

if (menuToggle && sidebar) {

    menuToggle.addEventListener("click", function () {

        sidebar.classList.toggle("show");

    });

}


// =====================================
// ACCOUNT DROPDOWN
// =====================================

const accountTrigger = document.getElementById("accountTrigger");
const profileDropdown = document.getElementById("profileDropdown");

if (accountTrigger && profileDropdown) {

    accountTrigger.addEventListener("click", function (event) {

        event.stopPropagation();

        profileDropdown.hidden = !profileDropdown.hidden;

    });


    document.addEventListener("click", function (event) {

        if (
            !accountTrigger.contains(event.target) &&
            !profileDropdown.contains(event.target)
        ) {

            profileDropdown.hidden = true;

        }

    });

}


// =====================================
// AVATAR IMAGE PREVIEW
// =====================================

const avatarInput = document.getElementById("avatarInput");
const avatarPreview = document.getElementById("avatarPreview");
const avatarPlaceholder = document.getElementById("avatarPlaceholder");

if (avatarInput && avatarPreview) {

    avatarInput.addEventListener("change", function () {

        const file = this.files[0];

        if (!file) {
            return;
        }

        const allowedTypes = [
            "image/jpeg",
            "image/png",
            "image/webp"
        ];

        if (!allowedTypes.includes(file.type)) {

            alert("Please select a JPG, PNG or WEBP image.");

            this.value = "";

            return;
        }


        if (file.size > 2 * 1024 * 1024) {

            alert("Image size must not exceed 2MB.");

            this.value = "";

            return;
        }


        const reader = new FileReader();

        reader.onload = function (event) {

            avatarPreview.src = event.target.result;

            avatarPreview.style.display = "block";

            if (avatarPlaceholder) {
                avatarPlaceholder.style.display = "none";
            }

        };

        reader.readAsDataURL(file);

    });

}

</script>

</body>
</html>

<?php

$stmt->close();
$conn->close();

?>
