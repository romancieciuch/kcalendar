<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/www/data_store.php';

$databasePath = $argv[1] ?? dirname(__DIR__) . '/data/kcalendar.sqlite';
$base = $argv[2] ?? dirname($databasePath);
if (!is_file($databasePath)) throw new RuntimeException('Nie znaleziono bazy: ' . $databasePath);
if (!is_dir($base) && !mkdir($base, 0700, true) && !is_dir($base)) {
    throw new RuntimeException('Nie można utworzyć katalogu docelowego');
}
$db = new PDO('sqlite:' . $databasePath);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
prepareDataStore($db, $base);
$ids = $db->query('SELECT id FROM users ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
$migrated = 0;
foreach ($ids as $id) {
    $query = $db->prepare('SELECT data FROM users WHERE id=?');
    $query->execute([(int)$id]);
    $legacy = json_decode((string)$query->fetchColumn(), true);
    $uuid = migrateUserData($db, $base, (int)$id);
    $file = readDataFile(dataFilePath($base, $uuid));
    if (is_array($legacy) && $file['data'] !== $legacy && $file['revision'] === 1) {
        throw new RuntimeException('Kontrola migracji nie powiodła się dla konta #' . $id);
    }
    $migrated++;
}
echo 'Sprawdzono kont: ' . $migrated . PHP_EOL;
