<?php
/**
 * Live FX rates for converting Pipeline/Dashboard totals to LKR for display.
 *
 * Source amounts (deal_amount, invoices, etc.) are NEVER touched or
 * converted in storage — the whole rest of the app's per-currency-grouped
 * design (sumByCurrency, addToCurrencyBucket) stays exactly as documented in
 * CLAUDE.md. This module only produces an ADDITIONAL, clearly-labelled
 * approximate LKR total for display, derived live from cached exchange
 * rates, alongside (never instead of) the real per-currency breakdown.
 *
 * Rates are cached in the shared `fx_rates` blob (store_blobs) and only
 * re-fetched when stale (see FX_CACHE_TTL_SECONDS), so a normal page load
 * never blocks on an external HTTP call.
 */

define('FX_CACHE_TTL_SECONDS', 6 * 3600); // refetch at most every 6 hours
define('FX_API_URL', 'https://open.er-api.com/v6/latest/USD');

/**
 * Fetch fresh USD-based rates from the external API. Returns
 * ['success'=>bool, 'rates'=>[CODE=>rate_per_1_usd], 'error'=>?string].
 */
function fetchFxRatesFromApi() {
    $ch = curl_init(FX_API_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);
    if ($error) return ['success' => false, 'error' => 'Could not reach FX rate provider: ' . $error];
    $data = json_decode($response, true);
    if (!is_array($data) || ($data['result'] ?? '') !== 'success' || empty($data['rates'])) {
        return ['success' => false, 'error' => 'FX rate provider returned an unusable response'];
    }
    return ['success' => true, 'rates' => $data['rates']];
}

/**
 * Cached USD-based rate table, refreshed lazily when older than the TTL.
 * On a failed refresh, keeps serving the last good cached rates (staleness
 * over a broken conversion) and flags `stale` so callers/UI can show that.
 */
function getFxRates() {
    $cache = dbGetBlob('fx_rates', null);
    $now = time();
    $isFresh = $cache && !empty($cache['fetched_at']) && ($now - $cache['fetched_at']) < FX_CACHE_TTL_SECONDS;
    if ($isFresh) return ['rates' => $cache['rates'], 'fetched_at' => $cache['fetched_at'], 'stale' => false];

    $res = fetchFxRatesFromApi();
    if ($res['success']) {
        $cache = ['rates' => $res['rates'], 'fetched_at' => $now];
        dbSaveBlob('fx_rates', $cache);
        return ['rates' => $cache['rates'], 'fetched_at' => $cache['fetched_at'], 'stale' => false];
    }
    // Refresh failed — serve whatever we last had (even if old) rather than
    // breaking the LKR total entirely; mark it stale so the UI can say so.
    if ($cache && !empty($cache['rates'])) {
        return ['rates' => $cache['rates'], 'fetched_at' => $cache['fetched_at'], 'stale' => true];
    }
    return ['rates' => null, 'fetched_at' => null, 'stale' => true];
}

/**
 * Convert one amount in $currency to LKR using the cached rate table.
 * Returns null if no rate is available (never silently returns 0).
 */
function fxToLkr($amount, $currency, $rateTable) {
    if (!$rateTable || empty($rateTable['LKR']) || empty($rateTable[$currency])) return null;
    // rateTable is USD-based (1 USD = rateTable[X] of currency X), so:
    // 1 unit of $currency in USD = 1 / rateTable[$currency]
    // in LKR = that * rateTable['LKR']
    $usd = $amount / $rateTable[$currency];
    return $usd * $rateTable['LKR'];
}

/**
 * Convert a {CODE: amount} map (the shape used everywhere for grouped
 * totals — stage_value, open_value, etc.) to one LKR total. Currencies with
 * no available rate are skipped and reported separately so the caller can
 * flag "X not included" rather than silently underreporting.
 */
function fxConvertMapToLkr($amountByCurrency, $rateTable) {
    $total = 0;
    $skipped = [];
    foreach (($amountByCurrency ?? []) as $cur => $amt) {
        $lkr = fxToLkr($amt, $cur, $rateTable);
        if ($lkr === null) { $skipped[] = $cur; continue; }
        $total += $lkr;
    }
    return ['lkr' => $total, 'skipped_currencies' => array_unique($skipped)];
}
