<?php
$pageTitle = 'Database Backup';
require_once __DIR__ . '/../../includes/header.php';
requireLogin();
if (!hasRole('admin')) {
    flashMessage('Access denied', 'error');
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}
$db = getDB();

$backupDir = __DIR__ . '/../../backups';
if (!is_dir($backupDir)) mkdir($backupDir, 0755, true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create_backup'])) {
        $filename = 'pharmacy_backup_' . date('Y-m-d_His') . '.sql';
        $filepath = $backupDir . '/' . $filename;

        $tables = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        $sql = "-- PharmaSys Database Backup\n";
        $sql .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
        $sql .= "-- Tables: " . count($tables) . "\n\n";
        $sql .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";

        foreach ($tables as $table) {
            $create = $db->query("SHOW CREATE TABLE `$table`")->fetch();
            $sql .= "DROP TABLE IF EXISTS `$table`;\n";
            $sql .= $create['Create Table'] . ";\n\n";

            $rows = $db->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($rows)) {
                $cols = array_keys($rows[0]);
                $colList = '`' . implode('`,`', $cols) . '`';
                foreach (array_chunk($rows, 100) as $chunk) {
                    $sql .= "INSERT INTO `$table` ($colList) VALUES\n";
                    $vals = [];
                    foreach ($chunk as $row) {
                        $rowVals = [];
                        foreach ($row as $v) {
                            if ($v === null) $rowVals[] = 'NULL';
                            else $rowVals[] = $db->quote($v);
                        }
                        $vals[] = '(' . implode(',', $rowVals) . ')';
                    }
                    $sql .= implode(",\n", $vals) . ";\n";
                }
                $sql .= "\n";
            }
        }
        $sql .= "SET FOREIGN_KEY_CHECKS = 1;\n";

        file_put_contents($filepath, $sql);
        addAuditLog('backup', 'database', 0);
        flashMessage("Backup created: $filename (" . round(filesize($filepath) / 1024, 1) . " KB)");
        header('Location: backup.php');
        exit;
    }

    if (isset($_POST['download_backup'])) {
        $file = basename($_POST['filename']);
        $path = $backupDir . '/' . $file;
        if (file_exists($path)) {
            header('Content-Type: application/sql');
            header('Content-Disposition: attachment; filename="' . $file . '"');
            header('Content-Length: ' . filesize($path));
            readfile($path);
            exit;
        }
        flashMessage('Backup file not found', 'error');
    }

    if (isset($_POST['delete_backup'])) {
        $file = basename($_POST['filename']);
        $path = $backupDir . '/' . $file;
        if (file_exists($path)) {
            unlink($path);
            flashMessage('Backup deleted');
        }
        header('Location: backup.php');
        exit;
    }
}

$backups = [];
if (is_dir($backupDir)) {
    foreach (scandir($backupDir, SCANDIR_SORT_DESCENDING) as $f) {
        if (pathinfo($f, PATHINFO_EXTENSION) === 'sql') {
            $backups[] = [
                'name' => $f,
                'size' => filesize($backupDir . '/' . $f),
                'date' => filemtime($backupDir . '/' . $f)
            ];
        }
    }
}

$tables = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
$dbSize = 0;
foreach ($tables as $t) {
    $info = $db->query("SELECT data_length + index_length as size FROM information_schema.TABLES WHERE table_schema = DATABASE() AND table_name = " . $db->quote($t))->fetch();
    $dbSize += $info['size'] ?? 0;
}
?>

<div class="row g-3 mb-3">
    <div class="col-md-4">
        <div class="card stat-card">
            <div class="stat-label">Database Size</div>
            <div class="stat-value"><?= round($dbSize / 1024 / 1024, 2) ?> MB</div>
            <small class="text-muted"><?= count($tables) ?> tables</small>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card stat-card info">
            <div class="stat-label">Backups</div>
            <div class="stat-value"><?= count($backups) ?></div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card p-3 d-flex justify-content-center">
            <form method="POST">
                <button type="submit" name="create_backup" value="1" class="btn btn-primary w-100"><i class="bi bi-download me-2"></i>Create Backup Now</button>
            </form>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header"><h6 class="mb-0"><i class="bi bi-clock-history me-2"></i>Backup History</h6></div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead><tr><th>Filename</th><th>Size</th><th>Created</th><th>Actions</th></tr></thead>
            <tbody>
                <?php foreach ($backups as $b): ?>
                <tr>
                    <td><i class="bi bi-file-earmark-code me-1"></i><?= sanitize($b['name']) ?></td>
                    <td><?= round($b['size'] / 1024, 1) ?> KB</td>
                    <td><?= date('M d, Y H:i:s', $b['date']) ?></td>
                    <td>
                        <form method="POST" class="d-inline">
                            <input type="hidden" name="filename" value="<?= sanitize($b['name']) ?>">
                            <button type="submit" name="download_backup" value="1" class="btn btn-sm btn-outline-primary"><i class="bi bi-download"></i></button>
                            <button type="submit" name="delete_backup" value="1" class="btn btn-sm btn-outline-danger" onclick="return confirm('Delete this backup?')"><i class="bi bi-trash"></i></button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card mt-3">
    <div class="card-header"><h6 class="mb-0"><i class="bi bi-database me-2"></i>Database Tables</h6></div>
    <div class="table-responsive">
        <table class="table table-sm mb-0">
            <thead><tr><th>Table</th><th>Rows</th><th>Size</th></tr></thead>
            <tbody>
                <?php foreach ($tables as $t): ?>
                <?php
                $info = $db->query("SELECT table_rows, data_length + index_length as size FROM information_schema.TABLES WHERE table_schema = DATABASE() AND table_name = " . $db->quote($t))->fetch();
                ?>
                <tr>
                    <td><code><?= $t ?></code></td>
                    <td><?= number_format($info['table_rows'] ?? 0) ?></td>
                    <td><?= round(($info['size'] ?? 0) / 1024, 1) ?> KB</td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
