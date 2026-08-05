<?php

function sqliteDbPath(): string
{
    $base = defined('APP_BASE_PATH') ? APP_BASE_PATH : dirname(__DIR__);
    $dir = $base . '/database';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    return $dir . '/cryptracker.sqlite';
}

function dbPdo(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $pdo = new PDO('sqlite:' . sqliteDbPath());
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA synchronous = NORMAL');

    dbEnsureSchema($pdo);
    return $pdo;
}

function dbEnsureSchema(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL,
        email TEXT NOT NULL,
        password_hash TEXT NOT NULL,
        created_at TEXT NOT NULL
    )');

    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_users_username ON users(username)');
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_users_email ON users(email)');

    $pdo->exec('CREATE TABLE IF NOT EXISTS user_tokens (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        cmc_id INTEGER NOT NULL,
        symbol TEXT NOT NULL,
        name TEXT NOT NULL,
        slug TEXT NOT NULL,
        added_at TEXT NOT NULL,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )');

    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_user_tokens_user_cmc ON user_tokens(user_id, cmc_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_user_tokens_user ON user_tokens(user_id)');

    $colStmt = $pdo->query("PRAGMA table_info(user_tokens)");
    $columns = $colStmt ? $colStmt->fetchAll() : [];
    $existing = [];
    foreach ($columns as $col) {
        $existing[(string) ($col['name'] ?? '')] = true;
    }

    if (!isset($existing['coinlore_id'])) {
        $pdo->exec('ALTER TABLE user_tokens ADD COLUMN coinlore_id INTEGER NULL');
    }
    if (!isset($existing['coingecko_id'])) {
        $pdo->exec('ALTER TABLE user_tokens ADD COLUMN coingecko_id TEXT NULL');
    }

    $pdo->exec('CREATE TABLE IF NOT EXISTS transactions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_token_id INTEGER NOT NULL,
        type TEXT NOT NULL CHECK(type IN ("buy", "sell")),
        amount REAL NOT NULL,
        price_per_unit REAL NOT NULL,
        total_value REAL NOT NULL,
        realized_pl REAL NOT NULL,
        created_at TEXT NOT NULL,
        FOREIGN KEY (user_token_id) REFERENCES user_tokens(id) ON DELETE CASCADE
    )');

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_transactions_token ON transactions(user_token_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_transactions_token_date ON transactions(user_token_id, created_at)');

    $txColStmt = $pdo->query("PRAGMA table_info(transactions)");
    $txColumns = $txColStmt ? $txColStmt->fetchAll() : [];
    $txExisting = [];
    foreach ($txColumns as $col) {
        $txExisting[(string) ($col['name'] ?? '')] = true;
    }
    if (!isset($txExisting['note'])) {
        $pdo->exec('ALTER TABLE transactions ADD COLUMN note TEXT NULL');
    }
}

function dbGetUserById(int $id): ?array
{
    $stmt = dbPdo()->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function dbGetUserByField(string $field, string $value): ?array
{
    if ($field === 'id') {
        $stmt = dbPdo()->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => (int) $value]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    $allowed = ['username', 'email'];
    if (!in_array($field, $allowed, true)) return null;

    $stmt = dbPdo()->prepare("SELECT * FROM users WHERE LOWER($field) = LOWER(:v) LIMIT 1");
    $stmt->execute([':v' => $value]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function dbInsertUser(string $username, string $email, string $hash): int
{
    $stmt = dbPdo()->prepare('INSERT INTO users (username, email, password_hash, created_at) VALUES (:u, :e, :h, :c)');
    $stmt->execute([
        ':u' => $username,
        ':e' => $email,
        ':h' => $hash,
        ':c' => date('Y-m-d H:i:s'),
    ]);
    return (int) dbPdo()->lastInsertId();
}

function dbGetUserTokens(int $userId): array
{
    $stmt = dbPdo()->prepare('SELECT *, coinlore_id, coingecko_id FROM user_tokens WHERE user_id = :u ORDER BY added_at DESC');
    $stmt->execute([':u' => $userId]);
    return $stmt->fetchAll();
}

function dbGetUserToken(int $tokenId, int $userId): ?array
{
    $stmt = dbPdo()->prepare('SELECT *, coinlore_id, coingecko_id FROM user_tokens WHERE id = :id AND user_id = :uid LIMIT 1');
    $stmt->execute([':id' => $tokenId, ':uid' => $userId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function dbGetUserTokenByCmc(int $userId, int $cmcId): ?array
{
    $stmt = dbPdo()->prepare('SELECT *, coinlore_id, coingecko_id FROM user_tokens WHERE user_id = :uid AND cmc_id = :cmc LIMIT 1');
    $stmt->execute([':uid' => $userId, ':cmc' => $cmcId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function dbInsertUserToken(int $userId, int $cmcId, string $symbol, string $name, string $slug, ?int $coinloreId = null, ?string $coingeckoId = null): int
{
    $coinloreId = ($coinloreId !== null && $coinloreId > 0) ? $coinloreId : null;
    $coingeckoId = $coingeckoId !== null ? strtolower(trim($coingeckoId)) : null;
    if ($coingeckoId === '') $coingeckoId = null;

    $stmt = dbPdo()->prepare('INSERT INTO user_tokens (user_id, cmc_id, symbol, name, slug, coinlore_id, coingecko_id, added_at) VALUES (:uid, :cmc, :s, :n, :sl, :clid, :gid, :a)');
    $stmt->execute([
        ':uid' => $userId,
        ':cmc' => $cmcId,
        ':s' => $symbol,
        ':n' => $name,
        ':sl' => $slug,
        ':clid' => $coinloreId,
        ':gid' => $coingeckoId,
        ':a' => date('Y-m-d H:i:s'),
    ]);
    return (int) dbPdo()->lastInsertId();
}

function dbSetTokenSourceMappings(int $tokenId, ?int $coinloreId, ?string $coingeckoId): bool
{
    $coinloreId = ($coinloreId !== null && $coinloreId > 0) ? $coinloreId : null;
    $coingeckoId = $coingeckoId !== null ? strtolower(trim($coingeckoId)) : null;
    if ($coingeckoId === '') $coingeckoId = null;

    $stmt = dbPdo()->prepare('UPDATE user_tokens SET coinlore_id = :clid, coingecko_id = :gid WHERE id = :id');
    $stmt->execute([
        ':clid' => $coinloreId,
        ':gid' => $coingeckoId,
        ':id' => $tokenId,
    ]);

    return $stmt->rowCount() > 0;
}

function dbGetTokenSourceMappingsByCmcIds(array $cmcIds): array
{
    $cmcIds = array_values(array_unique(array_map('intval', $cmcIds)));
    $cmcIds = array_values(array_filter($cmcIds, fn($id) => $id > 0));
    if (empty($cmcIds)) return [];

    $ph = implode(',', array_fill(0, count($cmcIds), '?'));
    $stmt = dbPdo()->prepare('SELECT cmc_id, coinlore_id, coingecko_id FROM user_tokens WHERE cmc_id IN (' . $ph . ') GROUP BY cmc_id');
    $stmt->execute($cmcIds);

    $rows = $stmt->fetchAll();
    $out = [];
    foreach ($rows as $row) {
        $cmcId = (int) ($row['cmc_id'] ?? 0);
        if ($cmcId <= 0) continue;

        $coinloreId = isset($row['coinlore_id']) && $row['coinlore_id'] !== null ? (int) $row['coinlore_id'] : null;
        if ($coinloreId !== null && $coinloreId <= 0) $coinloreId = null;

        $coingeckoId = isset($row['coingecko_id']) ? strtolower(trim((string) $row['coingecko_id'])) : null;
        if ($coingeckoId === '') $coingeckoId = null;

        $out[$cmcId] = [
            'coinlore_id' => $coinloreId,
            'coingecko_id' => $coingeckoId,
        ];
    }

    return $out;
}

function dbDeleteUserToken(int $tokenId): void
{
    $stmt = dbPdo()->prepare('DELETE FROM user_tokens WHERE id = :id');
    $stmt->execute([':id' => $tokenId]);
}

function dbGetTransactions(int $userTokenId, ?string $type = null): array
{
    if ($type !== null) {
        $stmt = dbPdo()->prepare('SELECT * FROM transactions WHERE user_token_id = :t AND type = :type ORDER BY created_at ASC, id ASC');
        $stmt->execute([':t' => $userTokenId, ':type' => $type]);
        return $stmt->fetchAll();
    }

    $stmt = dbPdo()->prepare('SELECT * FROM transactions WHERE user_token_id = :t ORDER BY created_at ASC, id ASC');
    $stmt->execute([':t' => $userTokenId]);
    return $stmt->fetchAll();
}

function dbGetTransactionsDesc(int $userTokenId): array
{
    $stmt = dbPdo()->prepare('SELECT * FROM transactions WHERE user_token_id = :t ORDER BY created_at DESC, id DESC');
    $stmt->execute([':t' => $userTokenId]);
    return $stmt->fetchAll();
}

function dbInsertTransaction(int $userTokenId, string $type, float $amount, float $ppu, float $totalValue, float $realizedPL, ?string $note = null): int
{
    if (!in_array($type, ['buy', 'sell'], true)) {
        throw new InvalidArgumentException("Transaction type must be 'buy' or 'sell'");
    }

    $note = ($note !== null && trim($note) !== '') ? trim($note) : null;

    $stmt = dbPdo()->prepare('INSERT INTO transactions (user_token_id, type, amount, price_per_unit, total_value, realized_pl, note, created_at) VALUES (:ut, :ty, :am, :ppu, :tv, :rp, :note, :ca)');
    $stmt->execute([
        ':ut' => $userTokenId,
        ':ty' => $type,
        ':am' => $amount,
        ':ppu' => $ppu,
        ':tv' => $totalValue,
        ':rp' => $realizedPL,
        ':note' => $note,
        ':ca' => date('Y-m-d H:i:s'),
    ]);

    return (int) dbPdo()->lastInsertId();
}

function dbGetTokenStats(int $userTokenId): array
{
    $stmt = dbPdo()->prepare('SELECT
        COALESCE(SUM(CASE WHEN type = "buy" THEN amount ELSE 0 END), 0) AS bought,
        COALESCE(SUM(CASE WHEN type = "sell" THEN amount ELSE 0 END), 0) AS sold,
        COALESCE(SUM(CASE WHEN type = "buy" THEN total_value ELSE 0 END), 0) AS spent,
        COALESCE(SUM(CASE WHEN type = "sell" THEN realized_pl ELSE 0 END), 0) AS realized_pl
        FROM transactions
        WHERE user_token_id = :t');
    $stmt->execute([':t' => $userTokenId]);
    $row = $stmt->fetch() ?: ['bought' => 0, 'sold' => 0, 'spent' => 0, 'realized_pl' => 0];

    return [
        'bought' => (float) $row['bought'],
        'sold' => (float) $row['sold'],
        'spent' => (float) $row['spent'],
        'realized_pl' => (float) $row['realized_pl'],
    ];
}

function dbUpdateUser(int $id, array $fields): bool
{
    $allowed = ['username', 'email', 'password_hash'];
    $set = [];
    $params = [':id' => $id];

    foreach ($fields as $key => $value) {
        if (in_array($key, $allowed, true)) {
            $param = ':' . $key;
            $set[] = "$key = $param";
            $params[$param] = $value;
        }
    }

    if (empty($set)) return false;

    $sql = 'UPDATE users SET ' . implode(', ', $set) . ' WHERE id = :id';
    $stmt = dbPdo()->prepare($sql);
    $stmt->execute($params);
    return $stmt->rowCount() > 0;
}

function dbPurgeAll(): void
{
    $pdo = dbPdo();
    $pdo->exec('DELETE FROM transactions');
    $pdo->exec('DELETE FROM user_tokens');
    $pdo->exec('DELETE FROM users');
    $pdo->exec('DELETE FROM sqlite_sequence WHERE name IN ("transactions", "user_tokens", "users")');
}
