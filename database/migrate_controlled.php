<?php
/**
 * Controlled Substance Migration
 * Usage: /pharmacy/database/migrate_controlled.php?token=migrate2024
 */
ob_start();

if (($_GET['token'] ?? '') !== 'migrate2024') {
    http_response_code(403);
    die('403 Forbidden — provide ?token=migrate2024');
}

require_once __DIR__ . '/../config/database.php';

$pdo = new PDO(
    "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
    DB_USER,
    DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$steps = [];
$errors = [];

function runSQL(PDO $pdo, string $label, string $sql, array &$steps, array &$errors): void {
    try {
        $pdo->exec($sql);
        $steps[] = "✓ $label";
    } catch (PDOException $e) {
        // Duplicate column / object already exists — treat as success
        if (in_array($e->getCode(), ['42S21', '42000']) ||
            strpos($e->getMessage(), 'Duplicate column') !== false ||
            strpos($e->getMessage(), 'already exists') !== false) {
            $steps[] = "- $label (already present, skipped)";
        } else {
            $errors[] = "✗ $label: " . $e->getMessage();
        }
    }
}

// Helper: check if a column exists
function colExists(PDO $pdo, string $table, string $col): bool {
    $db = $pdo->query("SELECT DATABASE()")->fetchColumn();
    $n = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?"
    );
    $n->execute([$db, $table, $col]);
    return (bool)$n->fetchColumn();
}

// ── 1. medicines.controlled_category ───────────────────────────────────────
if (!colExists($pdo, 'medicines', 'controlled_category')) {
    runSQL($pdo, 'medicines.controlled_category', "
        ALTER TABLE medicines
        ADD COLUMN controlled_category VARCHAR(50) DEFAULT NULL
        COMMENT 'narcotic, psychotropic, precursor'
        AFTER controlled_schedule
    ", $steps, $errors);
} else {
    $steps[] = "- medicines.controlled_category (already present, skipped)";
}

// ── 2. customers.id_number ─────────────────────────────────────────────────
if (!colExists($pdo, 'customers', 'id_number')) {
    runSQL($pdo, 'customers.id_number', "
        ALTER TABLE customers
        ADD COLUMN id_number VARCHAR(50) DEFAULT NULL
        COMMENT 'National ID / passport number'
    ", $steps, $errors);
} else {
    $steps[] = "- customers.id_number (already present, skipped)";
}

// ── 3. controlled_substance_log table ──────────────────────────────────────
// Create the table if it doesn't exist yet.
runSQL($pdo, 'CREATE controlled_substance_log', "
    CREATE TABLE IF NOT EXISTS controlled_substance_log (
        id             INT AUTO_INCREMENT PRIMARY KEY,
        medicine_id    INT NOT NULL,
        sale_id        INT DEFAULT NULL,
        dispensed_qty  DECIMAL(10,2) NOT NULL DEFAULT 0,
        patient_name   VARCHAR(200) NOT NULL DEFAULT '',
        patient_id_number  VARCHAR(100) DEFAULT NULL,
        patient_dob    DATE DEFAULT NULL,
        prescriber_name    VARCHAR(200) DEFAULT NULL,
        prescriber_license VARCHAR(100) DEFAULT NULL,
        prescription_number VARCHAR(100) DEFAULT NULL,
        dispensed_by   INT NOT NULL,
        dispensed_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        notes          TEXT,
        -- legacy columns kept for backward compatibility
        transaction_type ENUM('received','dispensed','destroyed','returned') DEFAULT 'dispensed',
        quantity       INT DEFAULT NULL,
        balance_after  INT DEFAULT NULL,
        doctor_name    VARCHAR(200) DEFAULT NULL,
        doctor_license VARCHAR(100) DEFAULT NULL,
        patient_id     VARCHAR(100) DEFAULT NULL,
        supplier_name  VARCHAR(150) DEFAULT NULL,
        witness_name   VARCHAR(150) DEFAULT NULL,
        created_by     INT DEFAULT NULL,
        created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (medicine_id) REFERENCES medicines(id),
        FOREIGN KEY (dispensed_by) REFERENCES users(id)
    )
", $steps, $errors);

// If table already existed (from old schema) add any missing new columns:
$newCols = [
    'sale_id'             => "ALTER TABLE controlled_substance_log ADD COLUMN sale_id INT DEFAULT NULL AFTER medicine_id",
    'dispensed_qty'       => "ALTER TABLE controlled_substance_log ADD COLUMN dispensed_qty DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER sale_id",
    'patient_id_number'   => "ALTER TABLE controlled_substance_log ADD COLUMN patient_id_number VARCHAR(100) DEFAULT NULL",
    'patient_dob'         => "ALTER TABLE controlled_substance_log ADD COLUMN patient_dob DATE DEFAULT NULL",
    'prescriber_name'     => "ALTER TABLE controlled_substance_log ADD COLUMN prescriber_name VARCHAR(200) DEFAULT NULL",
    'prescriber_license'  => "ALTER TABLE controlled_substance_log ADD COLUMN prescriber_license VARCHAR(100) DEFAULT NULL",
    'dispensed_by'        => "ALTER TABLE controlled_substance_log ADD COLUMN dispensed_by INT DEFAULT NULL",
    'dispensed_at'        => "ALTER TABLE controlled_substance_log ADD COLUMN dispensed_at DATETIME DEFAULT CURRENT_TIMESTAMP",
];
foreach ($newCols as $col => $sql) {
    if (!colExists($pdo, 'controlled_substance_log', $col)) {
        runSQL($pdo, "controlled_substance_log.$col", $sql, $steps, $errors);
    } else {
        $steps[] = "- controlled_substance_log.$col (already present, skipped)";
    }
}

// Add index on medicine_id + dispensed_at for fast filtering
try {
    $pdo->exec("ALTER TABLE controlled_substance_log ADD INDEX idx_cs_med_date (medicine_id, dispensed_at)");
    $steps[] = "✓ Index idx_cs_med_date added";
} catch (PDOException $e) {
    $steps[] = "- Index idx_cs_med_date (skipped: already exists or duplicate)";
}
?>
<!doctype html>
<html>
<head><meta charset="utf-8"><title>Controlled Substance Migration</title>
<style>
body{font-family:monospace;padding:2rem;background:#0d1117;color:#c9d1d9}
h1{color:#58a6ff}
.ok{color:#3fb950}.err{color:#f85149}.skip{color:#8b949e}
pre{background:#161b22;padding:1rem;border-radius:6px}
</style></head>
<body>
<h1>Controlled Substance Migration</h1>
<pre>
<?php
foreach ($steps as $s) {
    $cls = str_starts_with($s,'✓') ? 'ok' : (str_starts_with($s,'✗') ? 'err' : 'skip');
    echo "<span class=\"$cls\">" . htmlspecialchars($s) . "</span>\n";
}
if ($errors) {
    echo "\n<span class=\"err\">ERRORS:</span>\n";
    foreach ($errors as $e) echo "<span class=\"err\">" . htmlspecialchars($e) . "</span>\n";
}
?>
</pre>
<?php if (empty($errors)): ?>
<p class="ok">✓ Migration complete — <?= count($steps) ?> steps processed.</p>
<?php else: ?>
<p class="err">Migration finished with <?= count($errors) ?> error(s). Check output above.</p>
<?php endif; ?>
</body></html>
