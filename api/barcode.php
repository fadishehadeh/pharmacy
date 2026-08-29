<?php
require_once __DIR__ . '/../config/app.php';
header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$db = getDB();
$action = $_GET['action'] ?? '';

if ($action === 'lookup') {
    $barcode = $_GET['barcode'] ?? '';
    if (!$barcode) {
        echo json_encode(['found' => false]);
        exit;
    }

    $stmt = $db->prepare("SELECT m.*, c.name as category_name FROM medicines m LEFT JOIN categories c ON m.category_id = c.id WHERE m.barcode = ? AND m.is_active = 1");
    $stmt->execute([$barcode]);
    $medicine = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($medicine) {
        echo json_encode(['found' => true, 'medicine' => $medicine]);
    } else {
        $stmt = $db->prepare("SELECT * FROM moph_price_list WHERE barcode = ?");
        $stmt->execute([$barcode]);
        $moph = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['found' => false, 'moph_match' => $moph ?: null]);
    }
    exit;
}

if ($action === 'interactions') {
    $medicines = $_GET['medicines'] ?? '';
    $names = array_filter(array_map('trim', explode(',', $medicines)));
    if (count($names) < 2) {
        echo json_encode(['interactions' => []]);
        exit;
    }

    $interactions = [];
    $placeholders = implode(',', array_fill(0, count($names), '?'));
    $params = array_merge($names, $names);
    $stmt = $db->prepare("SELECT * FROM drug_interactions WHERE drug_a IN ($placeholders) OR drug_b IN ($placeholders) ORDER BY FIELD(severity,'contraindicated','major','moderate','minor')");
    $stmt->execute($params);
    $allInteractions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($allInteractions as $inter) {
        $matchA = false;
        $matchB = false;
        foreach ($names as $name) {
            if (stripos($name, $inter['drug_a']) !== false || stripos($inter['drug_a'], $name) !== false) $matchA = true;
            if (stripos($name, $inter['drug_b']) !== false || stripos($inter['drug_b'], $name) !== false) $matchB = true;
        }
        if ($matchA && $matchB) {
            $interactions[] = $inter;
        }
    }

    echo json_encode(['interactions' => $interactions]);
    exit;
}

if ($action === 'upload_image' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $medicineId = intval($_POST['medicine_id'] ?? 0);
    if (!$medicineId) {
        echo json_encode(['error' => 'Missing medicine_id']);
        exit;
    }

    if (isset($_POST['image_data'])) {
        $imageData = $_POST['image_data'];
        if (preg_match('/^data:image\/(\w+);base64,/', $imageData, $type)) {
            $ext = strtolower($type[1]);
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
                echo json_encode(['error' => 'Invalid image type']);
                exit;
            }
            $imageData = substr($imageData, strpos($imageData, ',') + 1);
            $imageData = base64_decode($imageData);
            if ($imageData === false) {
                echo json_encode(['error' => 'Invalid image data']);
                exit;
            }

            $filename = 'med_' . $medicineId . '_' . time() . '.' . $ext;
            $uploadDir = __DIR__ . '/../assets/uploads/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

            file_put_contents($uploadDir . $filename, $imageData);
            $db->prepare("UPDATE medicines SET image = ? WHERE id = ?")->execute([$filename, $medicineId]);
            echo json_encode(['success' => true, 'filename' => $filename]);
            exit;
        }
    }

    if (isset($_FILES['image'])) {
        $file = $_FILES['image'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
            echo json_encode(['error' => 'Invalid file type']);
            exit;
        }

        $filename = 'med_' . $medicineId . '_' . time() . '.' . $ext;
        $uploadDir = __DIR__ . '/../assets/uploads/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

        move_uploaded_file($file['tmp_name'], $uploadDir . $filename);
        $db->prepare("UPDATE medicines SET image = ? WHERE id = ?")->execute([$filename, $medicineId]);
        echo json_encode(['success' => true, 'filename' => $filename]);
        exit;
    }

    echo json_encode(['error' => 'No image provided']);
    exit;
}

if ($action === 'search') {
    $q = $_GET['q'] ?? '';
    if (strlen($q) < 2) {
        echo json_encode(['results' => []]);
        exit;
    }
    $stmt = $db->prepare("SELECT m.id, m.name, m.name_ar, m.generic_name, m.barcode, m.strength, m.form, m.sell_price, m.cost_price, m.quantity_in_stock, m.image, m.requires_prescription, m.is_controlled, m.is_subsidized, m.subsidy_percentage, m.unit, c.name as category_name
        FROM medicines m LEFT JOIN categories c ON m.category_id = c.id
        WHERE m.is_active = 1 AND (m.name LIKE ? OR m.name_ar LIKE ? OR m.generic_name LIKE ? OR m.barcode LIKE ?)
        ORDER BY m.name LIMIT 20");
    $search = "%$q%";
    $stmt->execute([$search, $search, $search, $search]);
    echo json_encode(['results' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

if ($action === 'patient_interactions') {
    $patientId = intval($_GET['patient_id'] ?? 0);
    $newMedicineId = intval($_GET['medicine_id'] ?? 0);
    if (!$patientId || !$newMedicineId) {
        echo json_encode(['warnings' => []]);
        exit;
    }

    $newMed = $db->prepare("SELECT name, generic_name FROM medicines WHERE id = ?");
    $newMed->execute([$newMedicineId]);
    $newMed = $newMed->fetch();
    if (!$newMed) {
        echo json_encode(['warnings' => []]);
        exit;
    }

    $activeMeds = $db->prepare("SELECT pm.medicine_name, m.name, m.generic_name FROM patient_medications pm LEFT JOIN medicines m ON pm.medicine_id = m.id WHERE pm.patient_id = ? AND pm.is_active = 1");
    $activeMeds->execute([$patientId]);
    $activeMeds = $activeMeds->fetchAll();

    $warnings = [];
    foreach ($activeMeds as $am) {
        $drugNames = array_filter([$am['medicine_name'], $am['name'], $am['generic_name']]);
        $newDrugNames = array_filter([$newMed['name'], $newMed['generic_name']]);

        foreach ($drugNames as $dn) {
            foreach ($newDrugNames as $nn) {
                $stmt = $db->prepare("SELECT * FROM drug_interactions WHERE (drug_a LIKE ? AND drug_b LIKE ?) OR (drug_a LIKE ? AND drug_b LIKE ?) ORDER BY FIELD(severity,'contraindicated','major','moderate','minor') LIMIT 1");
                $stmt->execute(["%$dn%", "%$nn%", "%$nn%", "%$dn%"]);
                $inter = $stmt->fetch();
                if ($inter) {
                    $warnings[] = [
                        'existing_drug' => $am['medicine_name'] ?: $am['name'],
                        'new_drug' => $newMed['name'],
                        'severity' => $inter['severity'],
                        'description' => $inter['description'],
                        'recommendation' => $inter['recommendation']
                    ];
                }
            }
        }
    }

    echo json_encode(['warnings' => $warnings]);
    exit;
}

if ($action === 'alternatives') {
    $medId = intval($_GET['medicine_id'] ?? 0);
    if (!$medId) { echo json_encode(['alternatives' => []]); exit; }
    $alts = $db->prepare("SELECT m.id, m.name, m.generic_name, m.sell_price, m.quantity_in_stock, m.manufacturer, alt.type
        FROM medicine_alternatives alt
        JOIN medicines m ON (m.id = CASE WHEN alt.medicine_a_id = ? THEN alt.medicine_b_id ELSE alt.medicine_a_id END)
        WHERE (alt.medicine_a_id = ? OR alt.medicine_b_id = ?) AND m.is_active = 1 AND m.quantity_in_stock > 0
        ORDER BY m.sell_price ASC");
    $alts->execute([$medId, $medId, $medId]);
    echo json_encode(['alternatives' => $alts->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

if ($action === 'dashboard_stats') {
    $stats = [];
    $stats['total_medicines'] = $db->query("SELECT COUNT(*) FROM medicines WHERE is_active = 1")->fetchColumn();
    $stats['low_stock'] = $db->query("SELECT COUNT(*) FROM medicines WHERE is_active = 1 AND quantity_in_stock <= min_stock_level")->fetchColumn();
    $stats['expiring'] = $db->query("SELECT COUNT(*) FROM medicines WHERE expiry_date IS NOT NULL AND expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY) AND is_active = 1")->fetchColumn();
    $stats['today_sales'] = $db->query("SELECT COALESCE(SUM(total_amount),0) FROM sales WHERE DATE(sale_date) = CURDATE() AND status = 'completed'")->fetchColumn();
    $stats['today_transactions'] = $db->query("SELECT COUNT(*) FROM sales WHERE DATE(sale_date) = CURDATE() AND status = 'completed'")->fetchColumn();
    echo json_encode($stats);
    exit;
}

echo json_encode(['error' => 'Invalid action']);
