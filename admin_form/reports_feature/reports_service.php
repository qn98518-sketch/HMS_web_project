<?php
// Read-only monthly aggregates. Amounts travel as integer cents for exact totals.
function reportCents(string $amount): int {
    if (!preg_match('/^(\d+)(?:\.(\d{1,2}))?$/D', $amount, $parts)) {
        throw new RuntimeException('Invalid monetary aggregate.');
    }
    $digits = ltrim($parts[1] . str_pad($parts[2] ?? '', 2, '0'), '0');
    $limit = '9007199254740991'; // Number.MAX_SAFE_INTEGER in the browser.
    if (strlen($digits) > strlen($limit) || (strlen($digits) === strlen($limit) && strcmp($digits, $limit) > 0)) {
        throw new RuntimeException('Monetary aggregate exceeds safe numeric precision.');
    }
    return (int)$digits;
}

function reportsList(PDO $pdo, ?int $requestedYear = null): array {
    $pdo->beginTransaction();
    try {
        $asOf = (string)$pdo->query('SELECT NOW()')->fetchColumn();
        $currentYear = (int)substr($asOf, 0, 4);
        $currentMonth = (int)substr($asOf, 5, 2);
        $year = $requestedYear ?? $currentYear;
        if ($year < 1900 || $year > 2100) throw new InvalidArgumentException('Year must be between 1900 and 2100.');
        $start = sprintf('%04d-01-01', $year - 1);
        $end = sprintf('%04d-01-01', $year + 1);
        $rows = [];
        for ($y = $year - 1; $y <= $year; $y++) {
            for ($month = 1; $month <= 12; $month++) {
                $future = $y > $currentYear || ($y === $currentYear && $month > $currentMonth);
                $rows[$y . '-' . $month] = ['year' => $y, 'month' => $month,
                    'appointments' => 0, 'revenue' => $future ? null : 0, 'newPatients' => $future ? null : 0];
            }
        }
        // Separate aggregates avoid multiplying counts/amounts through joins.
        $queries = [
            'appointments' => "SELECT YEAR(appointment_datetime) AS y, MONTH(appointment_datetime) AS m, COUNT(*) AS value
                               FROM appointments WHERE appointment_datetime >= ? AND appointment_datetime < ? GROUP BY y, m",
            'revenue' => "SELECT YEAR(bill_date) AS y, MONTH(bill_date) AS m, COALESCE(SUM(paid_amount), 0) AS value
                          FROM bills WHERE bill_date >= ? AND bill_date < ?
                            AND (payment_status IS NULL OR payment_status <> 'Cancelled') GROUP BY y, m",
            'newPatients' => "SELECT YEAR(created_at) AS y, MONTH(created_at) AS m, COUNT(*) AS value
                              FROM patients WHERE created_at >= ? AND created_at < ? GROUP BY y, m"
        ];
        foreach ($queries as $metric => $sql) {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$start, $end]);
            foreach ($stmt as $item) {
                $key = (int)$item['y'] . '-' . (int)$item['m'];
                if ($rows[$key][$metric] === null) continue;
                $rows[$key][$metric] = $metric === 'revenue' ? reportCents((string)$item['value']) : (int)$item['value'];
            }
        }
        $availableYears = $pdo->query(
            "SELECT YEAR(appointment_datetime) AS y FROM appointments
             UNION SELECT YEAR(bill_date) FROM bills WHERE bill_date IS NOT NULL
             UNION SELECT YEAR(created_at) FROM patients"
        )->fetchAll(PDO::FETCH_COLUMN);
        $years = array_values(array_unique(array_merge([$currentYear, $year], array_map('intval', $availableYears))));
        $years = array_values(array_filter($years, fn($value) => $value >= 1900 && $value <= 2100));
        rsort($years, SORT_NUMERIC);
        $undated = (int)$pdo->query("SELECT COUNT(*) FROM bills WHERE bill_date IS NULL AND paid_amount > 0
                                   AND (payment_status IS NULL OR payment_status <> 'Cancelled')")->fetchColumn();
        $pdo->commit();
        return ['success' => true, 'kind' => 'database', 'year' => $year, 'years' => $years,
                'rows' => array_values($rows), 'as_of' => $asOf, 'current_year' => $currentYear,
                'current_month' => $currentMonth, 'undated_paid_bills' => $undated];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}
