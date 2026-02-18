<?php
/**
 * JSON-file based database.
 */

require_once __DIR__ . '/config.php';

$_DATA_DIR = defined('APP_BASE_PATH') ? APP_BASE_PATH . '/database' : dirname(__DIR__) . '/database';
if (!defined('DATA_DIR')) define('DATA_DIR', $_DATA_DIR);

function ensureDataDir(): void
{
    if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0755, true);
}

function tableFilePath(string $table): string
{
    return DATA_DIR . "/$table.json";
}

function tableLockPath(string $table): string
{
    return DATA_DIR . "/$table.lock";
}

function readTable(string $table): array
{
    ensureDataDir();
    $file = tableFilePath($table);
    if (!file_exists($file)) return [];
    $raw = file_get_contents($file);
    if ($raw === false) return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function writeTable(string $table, array $rows): void
{
    ensureDataDir();
    $file = tableFilePath($table);
    $tmp = $file . '.tmp.' . getmypid();
    file_put_contents($tmp, json_encode($rows, JSON_PRETTY_PRINT), LOCK_EX);
    rename($tmp, $file);
}

function withTableWrite(string $table, callable $mutator)
{
    ensureDataDir();

    $lockFile = tableLockPath($table);
    $lockHandle = fopen($lockFile, 'c+');
    if ($lockHandle === false) {
        throw new RuntimeException("Unable to open lock file for table '$table'.");
    }

    try {
        if (!flock($lockHandle, LOCK_EX)) {
            throw new RuntimeException("Unable to lock table '$table'.");
        }

        $rows = readTable($table);
        $result = $mutator($rows);
        writeTable($table, $rows);
        flock($lockHandle, LOCK_UN);

        return $result;
    } finally {
        fclose($lockHandle);
    }
}

function nextId(array $rows): int
{
    if (empty($rows)) return 1;
    return max(array_column($rows, 'id')) + 1;
}

function dbGetUserById(int $id): ?array
{
    foreach (readTable('users') as $u) {
        if ($u['id'] === $id) return $u;
    }
    return null;
}

function dbGetUserByField(string $field, string $value): ?array
{
    $allowed = ['username', 'email', 'id'];
    if (!in_array($field, $allowed, true)) return null;

    foreach (readTable('users') as $u) {
        if (isset($u[$field]) && strtolower((string)$u[$field]) === strtolower($value)) return $u;
    }
    return null;
}

function dbInsertUser(string $username, string $email, string $hash): int
{
    return withTableWrite('users', function (array &$users) use ($username, $email, $hash): int {
        $id = nextId($users);
        $users[] = [
            'id'            => $id,
            'username'      => $username,
            'email'         => $email,
            'password_hash' => $hash,
            'created_at'    => date('Y-m-d H:i:s'),
        ];
        return $id;
    });
}

function dbGetUserTokens(int $userId): array
{
    $tokens = readTable('user_tokens');
    $result = array_filter($tokens, fn($t) => $t['user_id'] === $userId);
    usort($result, fn($a, $b) => strcmp($b['added_at'], $a['added_at']));
    return array_values($result);
}

function dbGetUserToken(int $tokenId, int $userId): ?array
{
    foreach (readTable('user_tokens') as $t) {
        if ($t['id'] === $tokenId && $t['user_id'] === $userId) return $t;
    }
    return null;
}

function dbGetUserTokenByCmc(int $userId, int $cmcId): ?array
{
    foreach (readTable('user_tokens') as $t) {
        if ($t['user_id'] === $userId && $t['cmc_id'] === $cmcId) return $t;
    }
    return null;
}

function dbInsertUserToken(int $userId, int $cmcId, string $symbol, string $name, string $slug): int
{
    return withTableWrite('user_tokens', function (array &$tokens) use ($userId, $cmcId, $symbol, $name, $slug): int {
        $id = nextId($tokens);
        $tokens[] = [
            'id'       => $id,
            'user_id'  => $userId,
            'cmc_id'   => $cmcId,
            'symbol'   => $symbol,
            'name'     => $name,
            'slug'     => $slug,
            'added_at' => date('Y-m-d H:i:s'),
        ];
        return $id;
    });
}

function dbDeleteUserToken(int $tokenId): void
{
    withTableWrite('user_tokens', function (array &$tokens) use ($tokenId): void {
        $tokens = array_values(array_filter($tokens, fn($t) => $t['id'] !== $tokenId));
    });

    withTableWrite('transactions', function (array &$txs) use ($tokenId): void {
        $txs = array_values(array_filter($txs, fn($t) => $t['user_token_id'] !== $tokenId));
    });
}

function dbGetTransactions(int $userTokenId, ?string $type = null): array
{
    $txs    = readTable('transactions');
    $result = array_filter($txs, function ($t) use ($userTokenId, $type) {
        if ($t['user_token_id'] !== $userTokenId) return false;
        if ($type !== null && $t['type'] !== $type) return false;
        return true;
    });
    usort($result, fn($a, $b) => strcmp($a['created_at'], $b['created_at']));
    return array_values($result);
}

function dbGetTransactionsDesc(int $userTokenId): array
{
    return array_reverse(dbGetTransactions($userTokenId));
}

function dbInsertTransaction(int $userTokenId, string $type, float $amount, float $ppu, float $totalValue, float $realizedPL): int
{
    if (!in_array($type, ['buy', 'sell'], true)) {
        throw new InvalidArgumentException("Transaction type must be 'buy' or 'sell'");
    }

    return withTableWrite('transactions', function (array &$txs) use ($userTokenId, $type, $amount, $ppu, $totalValue, $realizedPL): int {
        $id = nextId($txs);
        $txs[] = [
            'id'              => $id,
            'user_token_id'   => $userTokenId,
            'type'            => $type,
            'amount'          => $amount,
            'price_per_unit'  => $ppu,
            'total_value'     => $totalValue,
            'realized_pl'     => $realizedPL,
            'created_at'      => date('Y-m-d H:i:s'),
        ];
        return $id;
    });
}

function dbGetTokenStats(int $userTokenId): array
{
    $txs    = dbGetTransactions($userTokenId);
    $bought = 0.0;
    $sold   = 0.0;
    $spent  = 0.0;
    $realizedPL = 0.0;

    foreach ($txs as $t) {
        if ($t['type'] === 'buy') {
            $bought += $t['amount'];
            $spent  += $t['total_value'];
        } else {
            $sold       += $t['amount'];
            $realizedPL += $t['realized_pl'];
        }
    }
    return [
        'bought'      => $bought,
        'sold'        => $sold,
        'spent'       => $spent,
        'realized_pl' => $realizedPL,
    ];
}

function dbUpdateUser(int $id, array $fields): bool
{
    $allowed = ['username', 'email', 'password_hash'];
    return withTableWrite('users', function (array &$users) use ($id, $fields, $allowed): bool {
        foreach ($users as &$u) {
            if ($u['id'] === $id) {
                foreach ($fields as $k => $v) {
                    if (in_array($k, $allowed, true)) $u[$k] = $v;
                }
                return true;
            }
        }
        return false;
    });
}

function dbPurgeAll(): void
{
    foreach (['users', 'user_tokens', 'transactions'] as $t) {
        writeTable($t, []);
    }
}
