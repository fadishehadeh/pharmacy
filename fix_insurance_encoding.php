<?php
require_once __DIR__ . '/config/app.php';
requireLogin();
if (currentUser()['role'] !== 'admin') die('Admin only');

$db = getDB();

$fixes = [
    ['type' => 'NSSF',         'name' => 'NSSF - الصندوق الوطني للضمان الاجتماعي'],
    ['type' => 'army',         'name' => 'Lebanese Army - الجيش اللبناني'],
    ['type' => 'ISF',          'name' => 'ISF - قوى الأمن الداخلي'],
    ['type' => 'public_sector','name' => 'Public Sector - القطاع العام'],
];

$updated = 0;
foreach ($fixes as $f) {
    $rows = $db->prepare("UPDATE insurance_providers SET name=? WHERE type=?")->execute([$f['name'], $f['type']]);
    $updated++;
}

echo "Done. Fixed $updated providers. <a href='/modules/insurance/providers.php'>View</a><br>";
echo "<b>Delete this file now.</b>";
