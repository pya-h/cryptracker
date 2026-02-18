<?php
$_DATA_DIR = defined('APP_BASE_PATH') ? APP_BASE_PATH . '/database' : dirname(__DIR__) . '/database';
if (!defined('DATA_DIR')) define('DATA_DIR', $_DATA_DIR);

function ensureDataDir(): void
{
    if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0755, true);
}

function readTable(string $table): array
{
    ensureDataDir();
    $file = DATA_DIR . "/$table.json";
    if (!file_exists($file)) return [];
    $raw = file_get_contents($file);
    if ($raw === false) return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function writeTable(string $table, array $rows): void
{
    ensureDataDir();
    $file = DATA_DIR . "/$table.json";
    $tmp = $file . '.tmp.' . getmypid();
    file_put_contents($tmp, json_encode($rows, JSON_PRETTY_PRINT), LOCK_EX);
    rename($tmp, $file);
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
    $users = readTable('users');
    $id    = nextId($users);
    $users[] = [
        'id'            => $id,
        'username'      => $username,
        'email'         => $email,
        'password_hash' => $hash,
        'created_at'    => date('Y-m-d H:i:s'),
    ];
    writeTable('users', $users);
    return $id;
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
    $tokens = readTable('user_tokens');
    $id     = nextId($tokens);
    $tokens[] = [
        'id'       => $id,
        'user_id'  => $userId,
        'cmc_id'   => $cmcId,
        'symbol'   => $symbol,
        'name'     => $name,
        'slug'     => $slug,
        'added_at' => date('Y-m-d H:i:s'),
    ];
    writeTable('user_tokens', $tokens);
    return $id;
}

function dbDeleteUserToken(int $tokenId): void
{
    $tokens = readTable('user_tokens');
    $tokens = array_values(array_filter($tokens, fn($t) => $t['id'] !== $tokenId));
    writeTable('user_tokens', $tokens);

    $txs = readTable('transactions');
    $txs = array_values(array_filter($txs, fn($t) => $t['user_token_id'] !== $tokenId));
    writeTable('transactions', $txs);
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

    $txs = readTable('transactions');
    $id  = nextId($txs);
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
    writeTable('transactions', $txs);
    return $id;
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
    $users = readTable('users');
    foreach ($users as &$u) {
        if ($u['id'] === $id) {
            foreach ($fields as $k => $v) {
                if (in_array($k, $allowed, true)) $u[$k] = $v;
            }
            writeTable('users', $users);
            return true;
        }
    }
    return false;
}

function dbPurgeAll(): void
{
    foreach (['users', 'user_tokens', 'transactions'] as $t) {
        writeTable($t, []);
    }
}
