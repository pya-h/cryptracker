<?php
/**
 * P/L calculation engine: weighted-average and FIFO modes.
 */

/**
 * P/L calculator: 'avg' (weighted-average) or 'fifo' (oldest lots first).
 */
function calcTokenPL(int $tokenId, float $currentPrice, string $mode = 'avg'): array
{
    $txs = dbGetTransactions($tokenId);

    if ($mode === 'fifo') {
        return _calcFifo($txs, $currentPrice);
    }
    return _calcAvg($txs, $currentPrice);
}

/**
 * Realized P/L for a prospective sell of $amount at $ppu, given the token's
 * existing transactions (ascending by date). Honors the active cost-basis mode
 * ('fifo' = oldest lots first, otherwise weighted-average). This is the single
 * source of truth shared by the buy/sell handler and the swap handler.
 */
function realizedPLForSell(array $txsAsc, float $amount, float $ppu, string $mode): float
{
    if ($mode === 'fifo') {
        $lots = [];
        foreach ($txsAsc as $t) {
            if ($t['type'] === 'buy') {
                $lots[] = ['amount' => (float) $t['amount'], 'price' => (float) $t['price_per_unit']];
            } else {
                $rem = (float) $t['amount'];
                while ($rem > 1e-10 && !empty($lots)) {
                    $take = min($rem, $lots[0]['amount']);
                    $lots[0]['amount'] -= $take;
                    $rem -= $take;
                    if ($lots[0]['amount'] < 1e-10) array_shift($lots);
                }
            }
        }
        $sellCost = 0.0;
        $rem = $amount;
        while ($rem > 1e-10 && !empty($lots)) {
            $take = min($rem, $lots[0]['amount']);
            $sellCost += $take * $lots[0]['price'];
            $lots[0]['amount'] -= $take;
            $rem -= $take;
            if ($lots[0]['amount'] < 1e-10) array_shift($lots);
        }
        return ($amount * $ppu) - $sellCost;
    }

    // Weighted-average: proceeds vs all-time average buy cost.
    $bought = 0.0;
    $spent  = 0.0;
    foreach ($txsAsc as $t) {
        if ($t['type'] === 'buy') {
            $bought += (float) $t['amount'];
            $spent  += (float) $t['total_value'];
        }
    }
    $avgBuy = ($bought > 0) ? ($spent / $bought) : 0;
    return $amount * ($ppu - $avgBuy);
}

function _calcAvg(array $txs, float $currentPrice): array
{
    $totalBought = 0.0;
    $totalSpent  = 0.0;
    $totalSold   = 0.0;
    $realizedPL  = 0.0;
    $timeline    = [];

    $runBuyAmt   = 0.0;
    $runBuyCost  = 0.0;
    $runHoldings = 0.0;
    $runRealized = 0.0;

    foreach ($txs as $tx) {
        if ($tx['type'] === 'buy') {
            $totalBought += $tx['amount'];
            $totalSpent  += $tx['total_value'];
            $runBuyAmt   += $tx['amount'];
            $runBuyCost  += $tx['total_value'];
            $runHoldings += $tx['amount'];
            $txRealized   = 0.0;
        } else {
            $totalSold   += $tx['amount'];
            $runAvg       = ($runBuyAmt > 0) ? ($runBuyCost / $runBuyAmt) : 0;
            $txRealized   = $tx['amount'] * ($tx['price_per_unit'] - $runAvg);
            $realizedPL  += $txRealized;
            $runRealized += $txRealized;
            $runHoldings -= $tx['amount'];
        }

        $runAvg        = ($runBuyAmt > 0) ? ($runBuyCost / $runBuyAmt) : 0;
        $runCostBasis  = $runHoldings * $runAvg;
        $runCurrValue  = $runHoldings * $tx['price_per_unit'];
        $runUnrealized = $runCurrValue - $runCostBasis;
        $runTotalPL    = $runRealized + $runUnrealized;

        $timeline[] = [
            'date'         => $tx['created_at'],
            'type'         => $tx['type'],
            'amount'       => $tx['amount'],
            'ppu'          => $tx['price_per_unit'],
            'total'        => $tx['total_value'],
            'realized'     => $txRealized,
            'holdings'     => $runHoldings,
            'avg_cost'     => $runAvg,
            'cum_realized' => $runRealized,
            'unrealized'   => $runUnrealized,
            'total_pl'     => $runTotalPL,
        ];
    }

    $holdings  = max(0, $totalBought - $totalSold);
    $avgBuy    = ($totalBought > 0) ? ($totalSpent / $totalBought) : 0;
    $costBasis = $holdings * $avgBuy;
    $currValue = $holdings * $currentPrice;
    $unrealPL  = $currValue - $costBasis;

    return [
        'holdings'      => $holdings,
        'avg_buy'       => $avgBuy,
        'cost_basis'    => $costBasis,
        'current_value' => $currValue,
        'realized_pl'   => $realizedPL,
        'unrealized_pl' => $unrealPL,
        'total_pl'      => $realizedPL + $unrealPL,
        'total_spent'   => $totalSpent,
        'timeline'      => $timeline,
    ];
}

function _calcFifo(array $txs, float $currentPrice): array
{
    $lots        = [];
    $realizedPL  = 0.0;
    $totalSpent  = 0.0;
    $timeline    = [];
    $runRealized = 0.0;

    foreach ($txs as $tx) {
        $txRealized = 0.0;

        if ($tx['type'] === 'buy') {
            $lots[] = ['amount' => $tx['amount'], 'price' => $tx['price_per_unit']];
            $totalSpent += $tx['total_value'];
        } else {
            $remaining  = $tx['amount'];
            $sellPrice  = $tx['price_per_unit'];
            $sellCost   = 0.0;

            while ($remaining > 1e-10 && !empty($lots)) {
                $take = min($remaining, $lots[0]['amount']);
                $sellCost += $take * $lots[0]['price'];
                $lots[0]['amount'] -= $take;
                $remaining -= $take;
                if ($lots[0]['amount'] < 1e-10) {
                    array_shift($lots);
                }
            }

            $txRealized  = ($tx['amount'] * $sellPrice) - $sellCost;
            $realizedPL += $txRealized;
            $runRealized += $txRealized;
        }

        $holdings  = array_sum(array_column($lots, 'amount'));
        $costBasis = 0.0;
        foreach ($lots as $l) $costBasis += $l['amount'] * $l['price'];
        $avgCost   = ($holdings > 1e-10) ? ($costBasis / $holdings) : 0;

        $runCurrValue  = $holdings * $tx['price_per_unit'];
        $runUnrealized = $runCurrValue - $costBasis;
        $runTotalPL    = $runRealized + $runUnrealized;

        $timeline[] = [
            'date'         => $tx['created_at'],
            'type'         => $tx['type'],
            'amount'       => $tx['amount'],
            'ppu'          => $tx['price_per_unit'],
            'total'        => $tx['total_value'],
            'realized'     => $txRealized,
            'holdings'     => $holdings,
            'avg_cost'     => $avgCost,
            'cum_realized' => $runRealized,
            'unrealized'   => $runUnrealized,
            'total_pl'     => $runTotalPL,
        ];
    }

    $holdings  = array_sum(array_column($lots, 'amount'));
    $costBasis = 0.0;
    foreach ($lots as $l) $costBasis += $l['amount'] * $l['price'];
    $avgBuy    = ($holdings > 1e-10) ? ($costBasis / $holdings) : 0;
    $currValue = $holdings * $currentPrice;
    $unrealPL  = $currValue - $costBasis;

    return [
        'holdings'      => $holdings,
        'avg_buy'       => $avgBuy,
        'cost_basis'    => $costBasis,
        'current_value' => $currValue,
        'realized_pl'   => $realizedPL,
        'unrealized_pl' => $unrealPL,
        'total_pl'      => $realizedPL + $unrealPL,
        'total_spent'   => $totalSpent,
        'timeline'      => $timeline,
    ];
}
