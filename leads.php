<?php
/**
 * Leads (the pipeline) — shared, company-wide store of deal records.
 * Included by api.php. Every lead carries an owner_id (the rep who owns it),
 * but the store itself is one shared blob, not partitioned per user — this
 * is what makes "Owner" a real, reassignable, team-visible field instead of
 * just "whichever user's private data this happens to live in".
 *
 * getUserData()/saveUserData() in api.php are the only other code that reads
 * or writes leads; they source/split leads through this store so every other
 * existing call site (getUserData($id)['leads'], saveUserData($id, $data))
 * keeps working unchanged, scoped to that user's own leads. Only the
 * team-wide views (the 'leads' and 'stats' actions, command-center) read
 * getLeadsStore() directly for the full, unscoped list.
 */

/** Read the shared leads store. Shape: ['leads' => [...]]. */
function getLeadsStore() {
    $store = memoGetBlob('leads');
    if ($store === null) return ['leads' => []];
    if (!isset($store['leads']) || !is_array($store['leads'])) $store['leads'] = [];
    return $store;
}

/** Write the shared leads store, plus refresh the reporting projection. */
function saveLeadsStore($store) {
    memoSaveBlob('leads', $store);
    dbSyncReportingTable('leads', $store['leads'] ?? [], [
        'owner_id' => 'owner_id',
        'stage' => 'stage',
        'company' => 'company',
        'created_at' => fn($l) => $l['created_at'] ?? null,
        'updated_at' => fn($l) => $l['updated_at'] ?? null,
    ]);
}
