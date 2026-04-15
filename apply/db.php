<?php

require_once __DIR__.'/../config/Database.php';

function gsdCandidateResolveTable(PDO $pdo, array $candidates): ?string
{
    foreach ($candidates as $table) {
        try {
            $check = $pdo->query("SHOW TABLES LIKE ".$pdo->quote($table));
            if ($check && $check->fetchColumn() !== false) {
                return $table;
            }
        } catch (Throwable) {
            continue;
        }
    }

    return null;
}

function gsdOfficialCandidateTable(PDO $pdo): ?string
{
    return gsdCandidateResolveTable($pdo, ['candidates', 'gsd_candidates']);
}

function gsdDraftCandidateTable(PDO $pdo): ?string
{
    return gsdCandidateResolveTable($pdo, ['candidate_drafts', 'gsd_candidate_drafts']);
}

function gsdCandidateTableColumns(PDO $pdo, string $table): array
{
    static $cache = [];

    if (isset($cache[$table])) {
        return $cache[$table];
    }

    $columns = [];
    $statement = $pdo->query('SHOW COLUMNS FROM `'.$table.'`');
    foreach ($statement?->fetchAll(PDO::FETCH_ASSOC) ?? [] as $column) {
        $field = (string) ($column['Field'] ?? '');
        if ($field !== '') {
            $columns[] = $field;
        }
    }

    return $cache[$table] = $columns;
}

function gsdHydrateCandidateRow(array $row, string $table): array
{
    $row['__table'] = $table;

    return $row;
}

function gsdEnsureDraftCandidateTable(PDO $pdo): string
{
    $existing = gsdDraftCandidateTable($pdo);
    if (is_string($existing) && $existing !== '') {
        return $existing;
    }

    $official = gsdOfficialCandidateTable($pdo);
    if (! is_string($official) || $official === '') {
        throw new RuntimeException('Official candidates table was not found.');
    }

    $draftTable = $official === 'candidates' ? 'candidate_drafts' : 'gsd_candidate_drafts';
    $pdo->exec('CREATE TABLE IF NOT EXISTS `'.$draftTable.'` LIKE `'.$official.'`');

    return $draftTable;
}

function gsdFindCandidateByToken(PDO $pdo, string $token, ?array $tableOrder = null): ?array
{
    $tables = $tableOrder ?? array_values(array_filter([
        gsdOfficialCandidateTable($pdo),
        gsdDraftCandidateTable($pdo),
    ]));

    foreach ($tables as $table) {
        $stmt = $pdo->prepare('SELECT * FROM `'.$table.'` WHERE token = ? LIMIT 1');
        $stmt->execute([$token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            return gsdHydrateCandidateRow($row, $table);
        }
    }

    return null;
}

function gsdFindCandidateByEmail(PDO $pdo, string $email, ?array $tableOrder = null): ?array
{
    $tables = $tableOrder ?? array_values(array_filter([
        gsdDraftCandidateTable($pdo),
        gsdOfficialCandidateTable($pdo),
    ]));

    foreach ($tables as $table) {
        $stmt = $pdo->prepare('SELECT * FROM `'.$table.'` WHERE email = ? ORDER BY updated_at DESC LIMIT 1');
        $stmt->execute([$email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            return gsdHydrateCandidateRow($row, $table);
        }
    }

    return null;
}

function gsdEnsureDraftCandidateRow(PDO $pdo, string $token): void
{
    $draftTable = gsdEnsureDraftCandidateTable($pdo);
    $existing = gsdFindCandidateByToken($pdo, $token, [$draftTable]);

    if (is_array($existing)) {
        return;
    }

    $stmt = $pdo->prepare('INSERT INTO `'.$draftTable.'` (token, type, name, email, processing_status, is_main, created_at, updated_at) VALUES (?, \'candidate\', \'Draft Candidate\', ?, \'pending\', 1, NOW(), NOW())');
    $stmt->execute([$token, 'draft+'.strtolower($token).'@local.gsd']);
}

function gsdUpdateCandidateRowByToken(PDO $pdo, string $table, string $token, array $payload): void
{
    $columns = gsdCandidateTableColumns($pdo, $table);
    $sets = [];
    $values = [];

    foreach ($payload as $column => $value) {
        if ($column === 'token' || $column === '__table' || ! in_array($column, $columns, true)) {
            continue;
        }

        $sets[] = '`'.$column.'` = ?';
        $values[] = $value;
    }

    if (in_array('updated_at', $columns, true)) {
        $sets[] = '`updated_at` = NOW()';
    }

    if ($sets === []) {
        return;
    }

    $values[] = $token;

    $pdo->prepare('UPDATE `'.$table.'` SET '.implode(', ', $sets).' WHERE token = ?')
        ->execute($values);
}

function gsdUpsertDraftCandidate(PDO $pdo, string $token, array $payload): array
{
    $draftTable = gsdEnsureDraftCandidateTable($pdo);
    gsdEnsureDraftCandidateRow($pdo, $token);
    gsdUpdateCandidateRowByToken($pdo, $draftTable, $token, $payload);

    $row = gsdFindCandidateByToken($pdo, $token, [$draftTable]);
    if (! is_array($row)) {
        throw new RuntimeException('Draft candidate could not be persisted.');
    }

    return $row;
}

function gsdPromoteDraftCandidate(PDO $pdo, string $token): ?array
{
    $officialTable = gsdOfficialCandidateTable($pdo);
    if (! is_string($officialTable) || $officialTable === '') {
        return null;
    }

    $draftTable = gsdDraftCandidateTable($pdo);
    $draft = is_string($draftTable) && $draftTable !== ''
        ? gsdFindCandidateByToken($pdo, $token, [$draftTable])
        : null;

    if (! is_array($draft)) {
        return gsdFindCandidateByToken($pdo, $token, [$officialTable]);
    }

    $officialExisting = gsdFindCandidateByToken($pdo, $token, [$officialTable]);
    $officialColumns = gsdCandidateTableColumns($pdo, $officialTable);
    $draftColumns = gsdCandidateTableColumns($pdo, $draftTable);
    $sharedColumns = array_values(array_intersect($officialColumns, $draftColumns));

    $insertColumns = [];
    $insertValues = [];
    $updateSets = [];
    $updateValues = [];

    foreach ($sharedColumns as $column) {
        if (in_array($column, ['id', '__table'], true)) {
            continue;
        }

        $value = $draft[$column] ?? null;

        if ($officialExisting === null) {
            $insertColumns[] = '`'.$column.'`';
            $insertValues[] = $value;
            continue;
        }

        if (in_array($column, ['token', 'created_at'], true)) {
            continue;
        }

        $updateSets[] = '`'.$column.'` = ?';
        $updateValues[] = $value;
    }

    if ($officialExisting === null) {
        $placeholders = implode(', ', array_fill(0, count($insertColumns), '?'));
        $sql = 'INSERT INTO `'.$officialTable.'` ('.implode(', ', $insertColumns).') VALUES ('.$placeholders.')';
        $pdo->prepare($sql)->execute($insertValues);
    } else {
        if (in_array('updated_at', $officialColumns, true)) {
            $updateSets[] = '`updated_at` = NOW()';
        }
        if ($updateSets === []) {
            return gsdFindCandidateByToken($pdo, $token, [$officialTable]);
        }
        $updateValues[] = $token;
        $sql = 'UPDATE `'.$officialTable.'` SET '.implode(', ', $updateSets).' WHERE token = ?';
        $pdo->prepare($sql)->execute($updateValues);
    }

    if (is_string($draftTable) && $draftTable !== '') {
        $delete = $pdo->prepare('DELETE FROM `'.$draftTable.'` WHERE token = ?');
        $delete->execute([$token]);
    }

    return gsdFindCandidateByToken($pdo, $token, [$officialTable]);
}

function getDB(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $database = new Database();
    $pdo = $database->getConnection();

    if (! $pdo instanceof PDO) {
        throw new RuntimeException('Database connection is not available for recruitment/apply.');
    }

    return $pdo;
}

function generateToken(): string
{
    $db = getDB();
    $draftTable = gsdEnsureDraftCandidateTable($db);

    $tempToken = 'TMP-'.bin2hex(random_bytes(8));
    $stmt = $db->prepare('INSERT INTO `'.$draftTable.'` (token, type, name, email, processing_status, is_main, created_at, updated_at) VALUES (?, \'candidate\', \'Draft Candidate\', ?, \'pending\', 1, NOW(), NOW())');
    $stmt->execute([$tempToken, 'draft+'.strtolower($tempToken).'@local.gsd']);

    $id = (int) $db->lastInsertId();
    $token = 'GSD-CANDIDATE-'.str_pad((string) $id, 4, '0', STR_PAD_LEFT);

    $updateStmt = $db->prepare('UPDATE `'.$draftTable.'` SET token = ?, email = ? WHERE id = ?');
    $updateStmt->execute([$token, 'draft+'.strtolower($token).'@local.gsd', $id]);

    return $token;
}
