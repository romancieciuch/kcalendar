<?php
declare(strict_types=1);

function prepareDataStore(PDO $db, string $base): void {
    $columns = $db->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_ASSOC);
    if (!in_array('data_uuid', array_column($columns, 'name'), true)) {
        $db->exec('ALTER TABLE users ADD COLUMN data_uuid TEXT');
    }
    $directory = $base . DIRECTORY_SEPARATOR . 'users';
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Nie można utworzyć katalogu danych');
    }
}

function newDataUuid(): string {
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    $hex = bin2hex($bytes);
    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
}

function dataUpdatedAt(): string {
    return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
}

function dataFilePath(string $base, string $uuid): string {
    if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/', $uuid)) {
        throw new RuntimeException('Nieprawidłowy identyfikator danych');
    }
    return $base . DIRECTORY_SEPARATOR . 'users' . DIRECTORY_SEPARATOR . $uuid . '.json';
}

function writeDataFile(string $path, array $document): void {
    if (isset($document['data']['days']) && is_array($document['data']['days']) && $document['data']['days'] === []) {
        $document['data']['days'] = (object)[];
    }
    $json = json_encode($document, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    $temporary = tempnam(dirname($path), '.kcalendar_');
    if ($temporary === false) throw new RuntimeException('Nie można przygotować pliku danych');
    try {
        if (file_put_contents($temporary, $json) !== strlen($json)) throw new RuntimeException('Niepełny zapis danych');
        @chmod($temporary, 0600);
        if (!rename($temporary, $path)) throw new RuntimeException('Nie można zastąpić pliku danych');
    } finally {
        if (is_file($temporary)) @unlink($temporary);
    }
}

function readDataFile(string $path): array {
    $raw = file_get_contents($path);
    if ($raw === false) throw new RuntimeException('Nie można odczytać danych');
    $document = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($document) || !is_array($document['data'] ?? null) || !is_int($document['revision'] ?? null)) {
        throw new RuntimeException('Nieprawidłowy plik danych');
    }
    return $document;
}

function migrateUserData(PDO $db, string $base, int $userId): string {
    $db->exec('BEGIN IMMEDIATE');
    try {
        $query = $db->prepare('SELECT data_uuid,data FROM users WHERE id=?');
        $query->execute([$userId]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new RuntimeException('Nie znaleziono konta');
        $uuid = (string)($row['data_uuid'] ?? '');
        if ($uuid === '') {
            $uuid = newDataUuid();
            $db->prepare('UPDATE users SET data_uuid=? WHERE id=?')->execute([$uuid, $userId]);
            $path = dataFilePath($base, $uuid);
            $legacy = json_decode((string)$row['data'], true);
            if (!is_array($legacy)) $legacy = ['foods'=>[], 'meals'=>[], 'days'=>[]];
            writeDataFile($path, ['uuid'=>$uuid, 'revision'=>1, 'updatedAt'=>dataUpdatedAt(), 'data'=>$legacy]);
        } elseif (!is_file(dataFilePath($base, $uuid))) {
            throw new RuntimeException('Brak pliku danych konta');
        }
        $db->exec('COMMIT');
        return $uuid;
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->exec('ROLLBACK');
        throw $error;
    }
}

function updateUserData(string $base, string $uuid, int $expectedRevision, callable $change): array {
    $path = dataFilePath($base, $uuid);
    $lock = fopen($path . '.lock', 'c');
    if (!$lock) throw new RuntimeException('Nie można zablokować danych');
    try {
        if (!flock($lock, LOCK_EX)) throw new RuntimeException('Nie można zablokować danych');
        $document = readDataFile($path);
        if ($document['revision'] !== $expectedRevision) return ['conflict'=>true, 'revision'=>$document['revision'], 'updatedAt'=>$document['updatedAt']];
        $data = $change($document['data']);
        if (!is_array($data)) throw new RuntimeException('Nieprawidłowe dane');
        $document['data'] = $data;
        $document['revision']++;
        $document['updatedAt'] = dataUpdatedAt();
        writeDataFile($path, $document);
        return ['ok'=>true, 'revision'=>$document['revision'], 'updatedAt'=>$document['updatedAt']];
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
