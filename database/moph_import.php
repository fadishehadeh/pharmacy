<?php
/**
 * CLI script: Import MOPH price list from the raw CSV exported from moph_price_list.xls
 * Usage: php database/moph_import.php
 *
 * MOPH CSV columns (0-indexed):
 *  0  Code
 *  1  Registration number
 *  2  Brand name        -> medicine_name
 *  3  Strength
 *  4  Presentation
 *  5  Form
 *  6  Agent             -> agent_name
 *  7  Manufacturer
 *  8  Country
 *  9  Public Price LL   -> public_price_lbp
 * 10  Pharmacist Margin
 * 11  Stratum           -> subsidy_category / is_subsidized
 * 12  public price USD  -> public_price_usd
 * 13  Responsible Party Name
 * 14  Responsible Party Country
 * 15  Exch_Dates
 */

require_once __DIR__ . '/../config/app.php';

$csvFile = __DIR__ . '/moph_raw.csv';
if (!file_exists($csvFile)) {
    die("ERROR: $csvFile not found. Download it first.\n");
}

$db = getDB();

$handle = fopen($csvFile, 'r');
$header = fgetcsv($handle); // skip header row

$db->exec("TRUNCATE TABLE moph_price_list");

$stmt = $db->prepare("
    INSERT INTO moph_price_list
        (medicine_name, barcode, public_price_usd, public_price_lbp, hospital_price_usd,
         agent_name, is_subsidized, subsidy_category, effective_date)
    VALUES (?, NULL, ?, ?, NULL, ?, ?, ?, CURDATE())
    ON DUPLICATE KEY UPDATE
        public_price_usd   = VALUES(public_price_usd),
        public_price_lbp   = VALUES(public_price_lbp),
        agent_name         = VALUES(agent_name),
        is_subsidized      = VALUES(is_subsidized),
        subsidy_category   = VALUES(subsidy_category),
        effective_date     = CURDATE()
");

$imported = 0;
$skipped  = 0;

while (($row = fgetcsv($handle)) !== false) {
    if (count($row) < 10) { $skipped++; continue; }

    $name = trim($row[2] ?? '');
    if ($name === '' || $name === 'Brand name') { $skipped++; continue; }

    $priceLbp = floatval(str_replace(',', '', $row[9] ?? '0')) ?: null;
    $priceUsd = floatval(str_replace(',', '', $row[12] ?? '0')) ?: null;
    $agent    = trim($row[6] ?? '') ?: null;
    $stratum  = strtoupper(trim($row[11] ?? ''));

    // Lebanon MOPH stratum: A, A1, A2, B, B1, C, C1 = subsidized; D or blank = not
    $isSubsidized = ($stratum !== '' && $stratum !== 'D') ? 1 : 0;
    $subCat       = $stratum !== '' ? 'Stratum ' . $stratum : null;

    try {
        $stmt->execute([$name, $priceUsd, $priceLbp, $agent, $isSubsidized, $subCat]);
        $imported++;
    } catch (Exception $e) {
        $skipped++;
    }

    if ($imported % 1000 === 0) {
        echo "\rImported: $imported ...";
    }
}
fclose($handle);

echo "\n\nDone! Imported: $imported | Skipped (empty/error): $skipped\n";
