<?php
/**
 * db.example.php — TEMPLATE for db.php.
 *
 * db.php itself is gitignored (it holds real credentials for local dev and/or
 * production). Copy this file to db.php and fill in real values:
 *
 *   - Local dev:  point at your local Postgres instance.
 *   - cPanel:     upload db.php via cPanel File Manager (never via git), with
 *                 the DB name/user cPanel actually created (often prefixed,
 *                 e.g. "levatahq_levatadb" / "levatahq_levata").
 *
 * See DEPLOY_POSTGRES.md for the full cPanel setup walkthrough.
 */

define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_PORT', getenv('DB_PORT') ?: '5432');
define('DB_NAME', getenv('DB_NAME') ?: 'levata_local');
define('DB_USER', getenv('DB_USER') ?: 'levata');
define('DB_PASS', getenv('DB_PASS') ?: 'CHANGE_ME');

function db(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;
    $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', DB_HOST, DB_PORT, DB_NAME);
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    dbBootstrap($pdo);
    return $pdo;
}

function dbBootstrap(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id              TEXT PRIMARY KEY,
            name            TEXT        NOT NULL DEFAULT '',
            email           TEXT        NOT NULL UNIQUE,
            password        TEXT        NOT NULL,
            is_admin        BOOLEAN     NOT NULL DEFAULT FALSE,
            is_super_admin  BOOLEAN     NOT NULL DEFAULT FALSE,
            created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
            last_login_at   TIMESTAMPTZ,
            session_start   TIMESTAMPTZ,
            last_active_at  TIMESTAMPTZ,
            data            JSONB       NOT NULL DEFAULT '{}'::jsonb
        );

        CREATE TABLE IF NOT EXISTS store_blobs (
            name        TEXT PRIMARY KEY,
            data        JSONB       NOT NULL DEFAULT '{}'::jsonb,
            updated_at  TIMESTAMPTZ NOT NULL DEFAULT now()
        );

        CREATE TABLE IF NOT EXISTS documents (
            id                    TEXT PRIMARY KEY,
            doc_no                TEXT        NOT NULL DEFAULT '',
            type                  TEXT        NOT NULL DEFAULT '',
            client                TEXT        NOT NULL DEFAULT '',
            owner_id              TEXT        NOT NULL DEFAULT '',
            linked_cost_proposal  TEXT        NOT NULL DEFAULT '',
            created_at            TIMESTAMPTZ,
            updated_at            TIMESTAMPTZ,
            data                  JSONB       NOT NULL DEFAULT '{}'::jsonb
        );
        CREATE INDEX IF NOT EXISTS documents_type_idx ON documents (type);
        CREATE INDEX IF NOT EXISTS documents_client_idx ON documents (client);
        CREATE INDEX IF NOT EXISTS documents_owner_idx ON documents (owner_id);

        CREATE TABLE IF NOT EXISTS jobs (
            id          TEXT PRIMARY KEY,
            client      TEXT        NOT NULL DEFAULT '',
            status      TEXT        NOT NULL DEFAULT 'open',
            created_at  TIMESTAMPTZ,
            updated_at  TIMESTAMPTZ,
            data        JSONB       NOT NULL DEFAULT '{}'::jsonb
        );
        CREATE INDEX IF NOT EXISTS jobs_client_idx ON jobs (client);
        CREATE INDEX IF NOT EXISTS jobs_status_idx ON jobs (status);

        CREATE TABLE IF NOT EXISTS clients (
            id             TEXT PRIMARY KEY,
            client_no      TEXT        NOT NULL DEFAULT '',
            name           TEXT        NOT NULL DEFAULT '',
            status         TEXT        NOT NULL DEFAULT 'active',
            contact_email  TEXT        NOT NULL DEFAULT '',
            created_at     TIMESTAMPTZ,
            updated_at     TIMESTAMPTZ,
            data           JSONB       NOT NULL DEFAULT '{}'::jsonb
        );
        CREATE INDEX IF NOT EXISTS clients_name_idx ON clients (name);
        CREATE INDEX IF NOT EXISTS clients_status_idx ON clients (status);

        CREATE TABLE IF NOT EXISTS tasks (
            id          TEXT PRIMARY KEY,
            status      TEXT        NOT NULL DEFAULT 'open',
            assignee    TEXT        NOT NULL DEFAULT '',
            due_date    TEXT        NOT NULL DEFAULT '',
            created_at  TIMESTAMPTZ,
            updated_at  TIMESTAMPTZ,
            data        JSONB       NOT NULL DEFAULT '{}'::jsonb
        );
        CREATE INDEX IF NOT EXISTS tasks_status_idx ON tasks (status);
        CREATE INDEX IF NOT EXISTS tasks_assignee_idx ON tasks (assignee);

        CREATE TABLE IF NOT EXISTS tickets (
            id          TEXT PRIMARY KEY,
            type        TEXT        NOT NULL DEFAULT 'support',
            status      TEXT        NOT NULL DEFAULT 'open',
            priority    TEXT        NOT NULL DEFAULT 'normal',
            created_at  TIMESTAMPTZ,
            updated_at  TIMESTAMPTZ,
            data        JSONB       NOT NULL DEFAULT '{}'::jsonb
        );
        CREATE INDEX IF NOT EXISTS tickets_status_idx ON tickets (status);

        CREATE TABLE IF NOT EXISTS chat_channels (
            id         TEXT PRIMARY KEY,
            data       JSONB       NOT NULL,
            updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
        );

        CREATE TABLE IF NOT EXISTS chat_messages (
            id         TEXT PRIMARY KEY,
            thread_id  TEXT        NOT NULL,
            data       JSONB       NOT NULL,
            updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
        );
        CREATE INDEX IF NOT EXISTS chat_messages_thread_idx ON chat_messages (thread_id);

        CREATE TABLE IF NOT EXISTS chat_last_read (
            user_id    TEXT        NOT NULL,
            thread_id  TEXT        NOT NULL,
            last_read  TEXT        NOT NULL DEFAULT '',
            PRIMARY KEY (user_id, thread_id)
        );

        CREATE TABLE IF NOT EXISTS activity_pings (
            id         BIGSERIAL   PRIMARY KEY,
            user_id    TEXT        NOT NULL,
            user_name  TEXT        NOT NULL DEFAULT '',
            user_role  TEXT        NOT NULL DEFAULT '',
            page       TEXT        NOT NULL DEFAULT '',
            pinged_at  TIMESTAMPTZ NOT NULL DEFAULT now()
        );
        CREATE INDEX IF NOT EXISTS activity_pings_user_idx ON activity_pings (user_id, pinged_at DESC);
        CREATE INDEX IF NOT EXISTS activity_pings_at_idx ON activity_pings (pinged_at DESC);
    ");
}

function dbGetBlob(string $name, $default = []) {
    $stmt = db()->prepare("SELECT data FROM store_blobs WHERE name = :n");
    $stmt->execute([':n' => $name]);
    $row = $stmt->fetch();
    if (!$row) return $default;
    $v = json_decode($row['data'], true);
    return is_array($v) ? $v : $default;
}

function dbSaveBlob(string $name, $data): void {
    db()->prepare("
        INSERT INTO store_blobs (name, data, updated_at)
        VALUES (:n, :d::jsonb, now())
        ON CONFLICT (name) DO UPDATE SET data = EXCLUDED.data, updated_at = now()
    ")->execute([':n' => $name, ':d' => json_encode($data, JSON_UNESCAPED_UNICODE)]);
}

function dbSyncReportingTable(string $table, array $records, array $columns): void {
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $ids = [];
        $colNames = array_keys($columns);
        $insertCols = array_merge(['id'], $colNames, ['data']);
        $placeholders = array_merge([':id'], array_map(fn($c) => ":$c", $colNames), [':data']);
        $updateSet = implode(', ', array_map(fn($c) => "$c = EXCLUDED.$c", array_merge($colNames, ['data'])));
        $sql = "INSERT INTO {$table} (" . implode(', ', $insertCols) . ")
                VALUES (" . implode(', ', $placeholders) . ")
                ON CONFLICT (id) DO UPDATE SET {$updateSet}";
        $stmt = $pdo->prepare($sql);
        foreach ($records as $rec) {
            $id = $rec['id'] ?? null;
            if ($id === null) continue;
            $ids[] = $id;
            $params = [':id' => $id, ':data' => json_encode($rec, JSON_UNESCAPED_UNICODE)];
            foreach ($columns as $col => $field) {
                $params[":$col"] = is_callable($field) ? $field($rec) : ($rec[$field] ?? '');
            }
            $stmt->execute($params);
        }
        if (!empty($ids)) {
            $ph = implode(',', array_map(fn($i) => ":del{$i}", array_keys($ids)));
            $del = $pdo->prepare("DELETE FROM {$table} WHERE id NOT IN ({$ph})");
            foreach ($ids as $i => $id) $del->bindValue(":del{$i}", $id);
            $del->execute();
        } else {
            $pdo->exec("DELETE FROM {$table}");
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
    }
}

function dbRecordPing(string $userId, string $userName, string $userRole, string $page): void {
    db()->prepare("
        INSERT INTO activity_pings (user_id, user_name, user_role, page)
        VALUES (:u, :n, :r, :p)
    ")->execute([':u' => $userId, ':n' => $userName, ':r' => $userRole, ':p' => $page]);
}

function dbLoadPings(int $days): array {
    $days = max(1, min(90, $days));
    $stmt = db()->prepare("
        SELECT user_id, user_name, user_role, page, pinged_at
        FROM activity_pings
        WHERE pinged_at >= now() - (:days || ' days')::interval
        ORDER BY user_id, pinged_at ASC
    ");
    $stmt->execute([':days' => $days]);
    return $stmt->fetchAll();
}

// ── Team chat: generic id/data table helpers (channels) ──
function dbLoadAll(string $table): array {
    $rows = db()->query("SELECT data FROM {$table} ORDER BY updated_at ASC")->fetchAll();
    return array_map(fn($r) => json_decode($r['data'], true), $rows);
}

function dbSaveAll(string $table, array $records, string $idKey = 'id'): void {
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $newIds = [];
        foreach ($records as $rec) {
            $id = $rec[$idKey] ?? null;
            if ($id === null) continue;
            $newIds[] = $id;
            $json = json_encode($rec, JSON_UNESCAPED_UNICODE);
            $pdo->prepare("
                INSERT INTO {$table} (id, data, updated_at)
                VALUES (:id, :data::jsonb, now())
                ON CONFLICT (id) DO UPDATE
                    SET data = EXCLUDED.data, updated_at = now()
            ")->execute([':id' => $id, ':data' => $json]);
        }
        if (!empty($newIds)) {
            $placeholders = implode(',', array_map(fn($i) => ":del{$i}", array_keys($newIds)));
            $stmt = $pdo->prepare("DELETE FROM {$table} WHERE id NOT IN ({$placeholders})");
            foreach ($newIds as $i => $id) $stmt->bindValue(":del{$i}", $id);
            $stmt->execute();
        } else {
            $pdo->exec("DELETE FROM {$table}");
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// ── Team chat: messages (per-thread rows, not a whole-blob rewrite) ──
function dbLoadMessages(string $threadId): array {
    $stmt = db()->prepare("SELECT data FROM chat_messages WHERE thread_id=:t ORDER BY updated_at ASC");
    $stmt->execute([':t' => $threadId]);
    return array_map(fn($r) => json_decode($r['data'], true), $stmt->fetchAll());
}

function dbSaveMessages(string $threadId, array $messages): void {
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $newIds = [];
        foreach ($messages as $msg) {
            $id = $msg['id'] ?? null;
            if ($id === null) continue;
            $newIds[] = $id;
            $pdo->prepare("
                INSERT INTO chat_messages (id, thread_id, data, updated_at)
                VALUES (:id, :t, :data::jsonb, now())
                ON CONFLICT (id) DO UPDATE
                    SET data = EXCLUDED.data, thread_id = EXCLUDED.thread_id, updated_at = now()
            ")->execute([':id' => $id, ':t' => $threadId, ':data' => json_encode($msg, JSON_UNESCAPED_UNICODE)]);
        }
        if (!empty($newIds)) {
            $placeholders = implode(',', array_map(fn($i) => ":del{$i}", array_keys($newIds)));
            $stmt = $pdo->prepare("DELETE FROM chat_messages WHERE thread_id=:t AND id NOT IN ({$placeholders})");
            $stmt->bindValue(':t', $threadId);
            foreach ($newIds as $i => $id) $stmt->bindValue(":del{$i}", $id);
            $stmt->execute();
        } else {
            $pdo->prepare("DELETE FROM chat_messages WHERE thread_id=:t")->execute([':t' => $threadId]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// ── Team chat: per-user last-read markers ──
function dbGetLastRead(string $userId, string $threadId): string {
    $stmt = db()->prepare("SELECT last_read FROM chat_last_read WHERE user_id=:u AND thread_id=:t");
    $stmt->execute([':u' => $userId, ':t' => $threadId]);
    $row = $stmt->fetch();
    return $row ? $row['last_read'] : '';
}

function dbSetLastRead(string $userId, string $threadId, string $lastRead): void {
    db()->prepare("
        INSERT INTO chat_last_read (user_id, thread_id, last_read)
        VALUES (:u, :t, :lr)
        ON CONFLICT (user_id, thread_id) DO UPDATE SET last_read = EXCLUDED.last_read
    ")->execute([':u' => $userId, ':t' => $threadId, ':lr' => $lastRead]);
}
