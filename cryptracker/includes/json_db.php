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
    foreach ($result as &$t) {
        if (!array_key_exists('coinlore_id', $t)) $t['coinlore_id'] = null;
        if (!array_key_exists('coingecko_id', $t)) $t['coingecko_id'] = null;
    }
    usort($result, fn($a, $b) => strcmp($b['added_at'], $a['added_at']));
    return array_values($result);
}

function dbGetUserToken(int $tokenId, int $userId): ?array
{
    foreach (readTable('user_tokens') as $t) {
        if ($t['id'] === $tokenId && $t['user_id'] === $userId) {
            if (!array_key_exists('coinlore_id', $t)) $t['coinlore_id'] = null;
            if (!array_key_exists('coingecko_id', $t)) $t['coingecko_id'] = null;
            return $t;
        }
    }
    return null;
}

function dbGetUserTokenByCmc(int $userId, int $cmcId): ?array
{
    foreach (readTable('user_tokens') as $t) {
        if ($t['user_id'] === $userId && $t['cmc_id'] === $cmcId) {
            if (!array_key_exists('coinlore_id', $t)) $t['coinlore_id'] = null;
            if (!array_key_exists('coingecko_id', $t)) $t['coingecko_id'] = null;
            return $t;
        }
    }
    return null;
}

function dbInsertUserToken(int $userId, int $cmcId, string $symbol, string $name, string $slug, ?int $coinloreId = null, ?string $coingeckoId = null): int
{
    $tokens = readTable('user_tokens');
    $id     = nextId($tokens);

    $coinloreId = ($coinloreId !== null && $coinloreId > 0) ? $coinloreId : null;
    $coingeckoId = $coingeckoId !== null ? strtolower(trim($coingeckoId)) : null;
    if ($coingeckoId === '') $coingeckoId = null;

    $tokens[] = [
        'id'       => $id,
        'user_id'  => $userId,
        'cmc_id'   => $cmcId,
        'symbol'   => $symbol,
        'name'     => $name,
        'slug'     => $slug,
        'coinlore_id' => $coinloreId,
        'coingecko_id' => $coingeckoId,
        'added_at' => date('Y-m-d H:i:s'),
    ];
    writeTable('user_tokens', $tokens);
    return $id;
}

function dbSetTokenSourceMappings(int $tokenId, ?int $coinloreId, ?string $coingeckoId): bool
{
    $tokens = readTable('user_tokens');
    $updated = false;

    $coinloreId = ($coinloreId !== null && $coinloreId > 0) ? $coinloreId : null;
    $coingeckoId = $coingeckoId !== null ? strtolower(trim($coingeckoId)) : null;
    if ($coingeckoId === '') $coingeckoId = null;

    foreach ($tokens as &$t) {
        if ($t['id'] === $tokenId) {
            $t['coinlore_id'] = $coinloreId;
            $t['coingecko_id'] = $coingeckoId;
            $updated = true;
            break;
        }
    }

    if ($updated) {
        writeTable('user_tokens', $tokens);
    }

    return $updated;
}

function dbGetTokenSourceMappingsByCmcIds(array $cmcIds): array
{
    $cmcSet = array_flip(array_map('intval', $cmcIds));
    if (empty($cmcSet)) return [];

    $out = [];
    foreach (readTable('user_tokens') as $t) {
        $cmcId = (int) ($t['cmc_id'] ?? 0);
        if ($cmcId <= 0 || !isset($cmcSet[$cmcId]) || isset($out[$cmcId])) continue;

        $coinloreId = isset($t['coinlore_id']) ? (int) $t['coinlore_id'] : null;
        if ($coinloreId !== null && $coinloreId <= 0) $coinloreId = null;
        $coingeckoId = isset($t['coingecko_id']) ? strtolower(trim((string) $t['coingecko_id'])) : null;
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
    $tokens = readTable('user_tokens');
    $tokens = array_values(array_filter($tokens, fn($t) => $t['id'] !== $tokenId));
    writeTable('user_tokens', $tokens);

    $txs = readTable('transactions');
    $txs = array_values(array_filter($txs, fn($t) => $t['user_token_id'] !== $tokenId));
    writeTable('transactions', $txs);

    // Drop any per-bank balances that referenced this token.
    $bal = readTable('bank_balances');
    $filtered = array_values(array_filter($bal, fn($r) => (int) $r['user_token_id'] !== $tokenId));
    if (count($filtered) !== count($bal)) writeTable('bank_balances', $filtered);
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

function dbInsertTransaction(int $userTokenId, string $type, float $amount, float $ppu, float $totalValue, float $realizedPL, ?string $note = null): int
{
    if (!in_array($type, ['buy', 'sell'], true)) {
        throw new InvalidArgumentException("Transaction type must be 'buy' or 'sell'");
    }

    $note = ($note !== null && trim($note) !== '') ? trim($note) : null;

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
        'note'            => $note,
        'created_at'      => date('Y-m-d H:i:s'),
    ];
    writeTable('transactions', $txs);
    return $id;
}

function dbGetTransactionById(int $txId): ?array
{
    foreach (readTable('transactions') as $t) {
        if ((int) $t['id'] === $txId) return $t;
    }
    return null;
}

/**
 * Delete transactions by id. Returns the number of rows actually removed.
 * A single atomic write (temp file + rename) removes all given ids at once.
 */
function dbDeleteTransactions(array $txIds): int
{
    $ids = array_flip(array_map('intval', $txIds));
    if (empty($ids)) return 0;

    $txs    = readTable('transactions');
    $before = count($txs);
    $txs    = array_values(array_filter($txs, fn($t) => !isset($ids[(int) $t['id']])));
    $removed = $before - count($txs);

    if ($removed > 0) writeTable('transactions', $txs);
    return $removed;
}

/** Highest transaction id belonging to any of the user's tokens, or null. */
function dbGetMaxTransactionIdForUser(int $userId): ?int
{
    $tokenIds = array_flip(array_map('intval', array_column(dbGetUserTokens($userId), 'id')));
    if (empty($tokenIds)) return null;

    $max = null;
    foreach (readTable('transactions') as $t) {
        if (!isset($tokenIds[(int) $t['user_token_id']])) continue;
        $id = (int) $t['id'];
        if ($max === null || $id > $max) $max = $id;
    }
    return $max;
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

/**
 * Undo state is stored on the user row as a small structured record
 * (or null when there is nothing to undo). It is intentionally NOT part of
 * dbUpdateUser's field whitelist — only these dedicated accessors touch it.
 */
function dbGetUserUndo(int $userId): ?array
{
    foreach (readTable('users') as $u) {
        if ((int) $u['id'] === $userId) {
            $ua = $u['undo_action'] ?? null;
            return is_array($ua) ? $ua : null;
        }
    }
    return null;
}

function dbSetUserUndo(int $userId, ?array $undo): bool
{
    $users   = readTable('users');
    $updated = false;
    foreach ($users as &$u) {
        if ((int) $u['id'] === $userId) {
            $u['undo_action'] = $undo; // null clears it
            $updated = true;
            break;
        }
    }
    if ($updated) writeTable('users', $users);
    return $updated;
}

/* ── Banks (global per-user wallets that segment holdings) ──────────
 * A "bank" is a wallet owned by the user; only NON-default banks store an
 * explicit per-token balance in `bank_balances`. The default bank's balance
 * for a token is derived elsewhere as the remainder of total holdings (see
 * helpers/bank.php), so it is never persisted here. */

function dbEnsureDefaultBank(int $userId): int
{
    $banks = readTable('banks');
    foreach ($banks as $b) {
        if ((int) $b['user_id'] === $userId && !empty($b['is_default'])) {
            return (int) $b['id'];
        }
    }
    $id = nextId($banks);
    $banks[] = [
        'id'         => $id,
        'user_id'    => $userId,
        'name'       => 'Main',
        'is_default' => 1,
        'created_at' => date('Y-m-d H:i:s'),
    ];
    writeTable('banks', $banks);
    return $id;
}

function dbGetBanks(int $userId): array
{
    $rows = array_values(array_filter(readTable('banks'), fn($b) => (int) $b['user_id'] === $userId));
    foreach ($rows as &$b) { $b['is_default'] = !empty($b['is_default']) ? 1 : 0; }
    unset($b);
    // Default first, then oldest-created (id ascending) for stable ordering.
    usort($rows, function ($a, $b) {
        if ($a['is_default'] !== $b['is_default']) return $b['is_default'] <=> $a['is_default'];
        return (int) $a['id'] <=> (int) $b['id'];
    });
    return $rows;
}

function dbGetBank(int $bankId, int $userId): ?array
{
    foreach (readTable('banks') as $b) {
        if ((int) $b['id'] === $bankId && (int) $b['user_id'] === $userId) {
            $b['is_default'] = !empty($b['is_default']) ? 1 : 0;
            return $b;
        }
    }
    return null;
}

function dbCreateBank(int $userId, string $name): int
{
    $banks = readTable('banks');
    $id    = nextId($banks);
    $banks[] = [
        'id'         => $id,
        'user_id'    => $userId,
        'name'       => $name,
        'is_default' => 0,
        'created_at' => date('Y-m-d H:i:s'),
    ];
    writeTable('banks', $banks);
    return $id;
}

function dbDeleteBank(int $bankId): void
{
    $banks = array_values(array_filter(readTable('banks'), fn($b) => (int) $b['id'] !== $bankId));
    writeTable('banks', $banks);
    $bal = array_values(array_filter(readTable('bank_balances'), fn($r) => (int) $r['bank_id'] !== $bankId));
    writeTable('bank_balances', $bal);
}

function dbGetBankBalance(int $bankId, int $userTokenId): float
{
    foreach (readTable('bank_balances') as $r) {
        if ((int) $r['bank_id'] === $bankId && (int) $r['user_token_id'] === $userTokenId) {
            return (float) $r['amount'];
        }
    }
    return 0.0;
}

/** Map of bank_id => amount for every explicit (non-default) balance of a token. */
function dbGetBankBalancesForToken(int $userTokenId): array
{
    $out = [];
    foreach (readTable('bank_balances') as $r) {
        if ((int) $r['user_token_id'] === $userTokenId) {
            $out[(int) $r['bank_id']] = (float) $r['amount'];
        }
    }
    return $out;
}

/** Upsert a bank's per-token balance; near-zero balances are removed. */
function dbSetBankBalance(int $bankId, int $userTokenId, float $amount): void
{
    $rows  = readTable('bank_balances');
    $isZero = abs($amount) < 1e-12;

    if ($isZero) {
        $filtered = array_values(array_filter($rows, fn($r) =>
            !((int) $r['bank_id'] === $bankId && (int) $r['user_token_id'] === $userTokenId)));
        if (count($filtered) !== count($rows)) writeTable('bank_balances', $filtered);
        return;
    }

    foreach ($rows as &$r) {
        if ((int) $r['bank_id'] === $bankId && (int) $r['user_token_id'] === $userTokenId) {
            $r['amount'] = $amount;
            writeTable('bank_balances', $rows);
            return;
        }
    }
    unset($r);

    $rows[] = [
        'id'            => nextId($rows),
        'bank_id'       => $bankId,
        'user_token_id' => $userTokenId,
        'amount'        => $amount,
    ];
    writeTable('bank_balances', $rows);
}

/** Does this bank hold a non-negligible balance of any token? */
function dbBankHasAnyBalance(int $bankId): bool
{
    foreach (readTable('bank_balances') as $r) {
        if ((int) $r['bank_id'] === $bankId && abs((float) $r['amount']) > 1e-9) {
            return true;
        }
    }
    return false;
}

function dbPurgeAll(): void
{
    foreach (['users', 'user_tokens', 'transactions', 'banks', 'bank_balances'] as $t) {
        writeTable($t, []);
    }
}
