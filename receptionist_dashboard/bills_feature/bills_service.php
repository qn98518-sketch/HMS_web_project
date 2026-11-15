<?php
// Business rules and database operations shared by the API and isolated tests.
const BILL_METHODS = ['Cash', 'Card', 'Bank Transfer'];
const BILL_STATUSES = ['Unpaid', 'Partially Paid', 'Paid', 'Cancelled'];

class BillError extends RuntimeException {
    public int $status;
    public function __construct(int $status, string $message) {
        parent::__construct($message);
        $this->status = $status;
    }
}

// DECIMAL(12,2): use integer cents throughout, without floating-point rounding.
function billCents(string $value): int {
    if (!preg_match('/^\d{1,10}(?:\.\d{1,2})?$/D', $value)) {
        throw new BillError(400, 'Enter a non-negative amount with at most 10 whole digits and 2 decimal places.');
    }
    $parts = explode('.', $value);
    return (int)$parts[0] * 100 + (int)str_pad($parts[1] ?? '', 2, '0');
}

function billDecimal(int $cents): string {
    return intdiv($cents, 100) . '.' . str_pad((string)($cents % 100), 2, '0', STR_PAD_LEFT);
}

function billPaymentStatus(int $total, int $paid): string {
    if ($paid < 0 || $paid > $total) throw new BillError(400, 'Paid amount cannot exceed the total.');
    if ($paid === 0) return 'Unpaid';
    return $paid === $total ? 'Paid' : 'Partially Paid';
}

function billMethod(array $input): string {
    $method = (string)($input['payment_method'] ?? '');
    if (!in_array($method, BILL_METHODS, true)) throw new BillError(400, 'Invalid payment method.');
    return $method;
}

function billDetails(array $input, string $billDay): array {
    $due = trim((string)($input['due_date'] ?? ''));
    if ($due !== '') {
        $date = DateTime::createFromFormat('!Y-m-d', $due);
        if (!$date || $date->format('Y-m-d') !== $due || $due < $billDay || $due > '9999-12-31') {
            throw new BillError(400, 'Due date must be a valid date on or after the bill date.');
        }
    }
    $notes = trim((string)($input['notes'] ?? ''));
    $length = function_exists('mb_strlen') ? mb_strlen($notes, 'UTF-8') : strlen($notes);
    if ($length > 5000) throw new BillError(400, 'Notes are too long (max 5000 characters).');
    return [$due === '' ? null : $due, $notes === '' ? null : $notes];
}

function billUuid(): string {
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 15) | 64);
    $bytes[8] = chr((ord($bytes[8]) & 63) | 128);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
}

function billRevision(array $bill): string {
    $values = [];
    foreach (['bill_id', 'patient_id', 'appointment_id', 'total_amount', 'paid_amount',
              'payment_method', 'payment_status', 'bill_date', 'due_date', 'notes'] as $key) {
        $values[] = $bill[$key];
    }
    return hash('sha256', json_encode($values, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
}

function billsList(PDO $pdo): array {
    $rows = $pdo->query(
        "SELECT b.*, u.full_name AS patient_name, u.username AS patient_username,
                u.is_deleted AS patient_deleted, a.appointment_datetime,
                a.appointment_status, du.full_name AS doctor_name
         FROM bills b
         LEFT JOIN patients p ON p.patient_id = b.patient_id
         LEFT JOIN users u ON u.user_id = p.user_id
         LEFT JOIN appointments a ON a.appointment_id = b.appointment_id
         LEFT JOIN doctors d ON d.doctor_id = a.doctor_id
         LEFT JOIN users du ON du.user_id = d.user_id
         ORDER BY b.bill_date DESC, b.bill_id DESC"
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$row) {
        $row['revision'] = billRevision($row);
        $row['balance'] = billDecimal(billCents($row['total_amount']) - billCents($row['paid_amount']));
        $row['patient_deleted'] = (int)($row['patient_deleted'] ?? 0) === 1;
    }
    unset($row);
    // Keep historical bills visible; only current patients can receive new bills.
    $patients = $pdo->query(
        "SELECT p.patient_id, u.full_name, u.username
         FROM patients p LEFT JOIN users u ON u.user_id = p.user_id
         WHERE p.user_id IS NULL OR
               (u.role = 'patient' AND u.user_status = 1 AND u.is_deleted = 0)
         ORDER BY u.full_name, p.patient_id"
    )->fetchAll(PDO::FETCH_ASSOC);
    $appointments = $pdo->query(
        "SELECT a.appointment_id, a.patient_id, a.appointment_datetime,
                a.appointment_status, u.full_name AS doctor_name
         FROM appointments a
         LEFT JOIN doctors d ON d.doctor_id = a.doctor_id
         LEFT JOIN users u ON u.user_id = d.user_id
         ORDER BY a.appointment_datetime DESC"
    )->fetchAll(PDO::FETCH_ASSOC);
    return ['success' => true, 'data' => $rows, 'patients' => $patients,
            'appointments' => $appointments, 'today' => $pdo->query('SELECT CURDATE()')->fetchColumn()];
}

function billsAction(PDO $pdo, string $action, array $input): array {
    if ($action === 'list') return billsList($pdo);
    if (!in_array($action, ['create', 'update', 'pay', 'cancel'], true)) {
        throw new BillError(400, 'Unknown action.');
    }
    $pdo->beginTransaction();
    try {
        if ($action === 'create') {
            $patient = trim((string)($input['patient_id'] ?? ''));
            $appointment = trim((string)($input['appointment_id'] ?? ''));
            // Lock the patient/account while checking its current role and status.
            $stmt = $pdo->prepare(
                "SELECT p.patient_id FROM patients p LEFT JOIN users u ON u.user_id = p.user_id
                 WHERE p.patient_id = ? AND (p.user_id IS NULL OR
                       (u.role = 'patient' AND u.user_status = 1 AND u.is_deleted = 0)) FOR UPDATE");
            $stmt->execute([$patient]);
            if (!$stmt->fetchColumn()) throw new BillError(400, 'Choose an active patient with the current Patient role.');
            if ($appointment !== '') {
                $stmt = $pdo->prepare('SELECT patient_id FROM appointments WHERE appointment_id = ? FOR UPDATE');
                $stmt->execute([$appointment]);
                if ($stmt->fetchColumn() !== $patient) {
                    throw new BillError(400, 'The appointment must belong to the selected patient.');
                }
            }
            $total = billCents(trim((string)($input['total_amount'] ?? '')));
            $paid = billCents(trim((string)($input['paid_amount'] ?? '0')));
            $status = billPaymentStatus($total, $paid);
            $method = billMethod($input);
            $day = (string)$pdo->query('SELECT CURDATE()')->fetchColumn();
            [$due, $notes] = billDetails($input, $day);
            $id = billUuid();
            $pdo->prepare(
                'INSERT INTO bills (bill_id, patient_id, appointment_id, total_amount, paid_amount,
                                    payment_method, payment_status, due_date, notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([$id, $patient, $appointment === '' ? null : $appointment,
                        billDecimal($total), billDecimal($paid), $method, $status, $due, $notes]);
            $message = 'Bill created.';
        } else {
            $id = trim((string)($input['id'] ?? ''));
            $stmt = $pdo->prepare('SELECT * FROM bills WHERE bill_id = ? FOR UPDATE');
            $stmt->execute([$id]);
            $bill = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$bill) throw new BillError(404, 'Bill not found.');
            if (!hash_equals(billRevision($bill), (string)($input['revision'] ?? ''))) {
                throw new BillError(409, 'This bill has changed. Refresh the list and try again.');
            }
            if ($bill['payment_status'] === 'Cancelled') throw new BillError(409, 'Cancelled bills cannot be changed.');
            $total = billCents($bill['total_amount']);
            $paid = billCents($bill['paid_amount']);
            if ($action === 'update') {
                $total = billCents(trim((string)($input['total_amount'] ?? '')));
                $status = billPaymentStatus($total, $paid);
                [$due, $notes] = billDetails($input, substr((string)$bill['bill_date'], 0, 10));
                $pdo->prepare('UPDATE bills SET total_amount = ?, payment_status = ?, due_date = ?, notes = ? WHERE bill_id = ?')
                    ->execute([billDecimal($total), $status, $due, $notes, $id]);
                $message = 'Bill updated.';
            } elseif ($action === 'pay') {
                $amount = billCents(trim((string)($input['amount'] ?? '')));
                if ($amount <= 0) throw new BillError(400, 'Payment amount must be greater than zero.');
                if ($amount > $total - $paid) throw new BillError(400, 'Payment amount exceeds the outstanding balance.');
                $method = billMethod($input);
                $paid += $amount;
                $pdo->prepare('UPDATE bills SET paid_amount = ?, payment_method = ?, payment_status = ? WHERE bill_id = ?')
                    ->execute([billDecimal($paid), $method, billPaymentStatus($total, $paid), $id]);
                $message = 'Payment recorded.';
            } else {
                if ($paid !== 0) throw new BillError(409, 'Bills with payments cannot be cancelled.');
                $pdo->prepare("UPDATE bills SET payment_status = 'Cancelled' WHERE bill_id = ?")->execute([$id]);
                $message = 'Bill cancelled.';
            }
        }
        $pdo->commit();
        return ['success' => true, 'id' => $id, 'message' => $message];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}
