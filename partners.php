<?php
/**
 * Channel Partners — shared, company-wide directory of referral partners.
 * Included by api.php. A CHANNEL PARTNER is a person/company that sends deals
 * to Levata in exchange for a commission on the deal value: either a flat
 * percentage or a fixed amount per deal.
 *
 * A deal (lead record) optionally carries partner_id + a rate snapshot
 * (partner_rate_type/partner_rate_value) taken from the partner at the moment
 * they're attached, so a later change to the partner's default rate doesn't
 * retroactively change what's owed on deals already in flight. The payout
 * itself is always computed live from the deal's CURRENT value — see
 * partnerPayout() — so editing the deal amount keeps the payout correct
 * without a separate number to keep in sync.
 *
 * Partner number format: CP-0001 (sequential, stored in the blob's seq).
 */

/** Read the shared partner store. Shape: ['partners' => [...], 'seq' => n]. */
function getPartnersStore() {
    $store = dbGetBlob('partners', null);
    if ($store === null) return ['partners' => [], 'seq' => 0];
    if (!isset($store['partners']) || !is_array($store['partners'])) $store['partners'] = [];
    $store['seq'] = (int) ($store['seq'] ?? 0);
    return $store;
}

/** Write the shared partner store. */
function savePartnersStore($store) {
    dbSaveBlob('partners', $store);
}

function nextPartnerNo(&$store) {
    $store['seq']++;
    return sprintf('CP-%04d', $store['seq']);
}

/** Find a partner in a list by id. Returns the partner or null. */
function findPartnerById($partners, $id) {
    $id = trim((string) $id);
    if ($id === '') return null;
    foreach ($partners as $p) {
        if (($p['id'] ?? '') === $id) return $p;
    }
    return null;
}

$VALID_PARTNER_RATE_TYPES = ['percentage', 'fixed'];
$VALID_PARTNER_STATUS = ['active', 'archived'];

/** Build/validate a partner from request input. Used by create and update. */
function applyPartnerFields($partner, $input) {
    global $VALID_PARTNER_RATE_TYPES, $VALID_PARTNER_STATUS;
    $partner['name'] = trim($input['name'] ?? ($partner['name'] ?? ''));
    $partner['contact_name'] = trim($input['contact_name'] ?? ($partner['contact_name'] ?? ''));
    $partner['contact_email'] = trim($input['contact_email'] ?? ($partner['contact_email'] ?? ''));
    $partner['contact_phone'] = trim($input['contact_phone'] ?? ($partner['contact_phone'] ?? ''));
    $partner['notes'] = trim($input['notes'] ?? ($partner['notes'] ?? ''));
    // Default rate — pre-fills onto a deal when this partner is attached, but
    // each deal keeps its own snapshot afterward (see note above).
    $rateType = $input['default_rate_type'] ?? ($partner['default_rate_type'] ?? 'percentage');
    $partner['default_rate_type'] = in_array($rateType, $VALID_PARTNER_RATE_TYPES, true) ? $rateType : 'percentage';
    $partner['default_rate_value'] = dealMoney($input['default_rate_value'] ?? ($partner['default_rate_value'] ?? 0));
    $status = $input['status'] ?? ($partner['status'] ?? 'active');
    $partner['status'] = in_array($status, $VALID_PARTNER_STATUS, true) ? $status : 'active';
    return $partner;
}

/**
 * Portal login — a SEPARATE, parallel auth path from the internal team's
 * users table (same reasoning as the "no client portal" decision: an
 * external party is not an internal user, so it never becomes a `users` row
 * or gets `is_admin`/etc. — it stays confined to its own partner record).
 * A partner's portal_email/portal_password are set by an admin from the
 * Channel Partners page; portal_tokens mirrors the users table's multi-device
 * token array pattern (addUserToken()).
 */
function setPartnerPortalPassword(&$partner, $email, $password) {
    $partner['portal_email'] = trim(strtolower($email));
    $partner['portal_password'] = $password === '' ? ($partner['portal_password'] ?? '') : password_hash($password, PASSWORD_DEFAULT);
}

/** Add a portal token to a partner without dropping existing ones (multi-device, capped). */
function addPartnerPortalToken(&$partner, $token, $max = 5) {
    if (!isset($partner['portal_tokens']) || !is_array($partner['portal_tokens'])) $partner['portal_tokens'] = [];
    $partner['portal_tokens'][] = $token;
    $partner['portal_tokens'] = array_values(array_unique($partner['portal_tokens']));
    if (count($partner['portal_tokens']) > $max) $partner['portal_tokens'] = array_slice($partner['portal_tokens'], -$max);
}

/** Resolve the partner behind a portal token, or null. Mirrors getCurrentUser(). */
function getCurrentPartner() {
    $token = trim($_SERVER['HTTP_X_PARTNER_TOKEN'] ?? ($_GET['token'] ?? ''));
    if ($token === '') return null;
    foreach (getPartnersStore()['partners'] as $p) {
        foreach (($p['portal_tokens'] ?? []) as $valid) {
            if (hash_equals($valid, $token)) return $p;
        }
    }
    return null;
}

/**
 * Every deal referred by this partner, across EVERY rep's own lead list —
 * deals are stored per-user (user_data:<id> blobs), not in a shared store,
 * so there is no single place to query "deals for partner X" — this walks
 * every user the same way an admin's cross-team report would. Read-only,
 * and trimmed to exactly what a partner should see: never the lead's contact
 * details, notes, or internal activity — just enough to know what's owed.
 */
function dealsForPartner($partnerId) {
    $out = [];
    foreach (getUsers() as $u) {
        $data = getUserData($u['id'] ?? '');
        foreach (($data['leads'] ?? []) as $lead) {
            if (($lead['partner_id'] ?? '') !== $partnerId) continue;
            if (!empty($lead['deleted_at'])) continue;
            $stage = getLeadStage($lead);
            $out[] = [
                'company' => $lead['company'] ?? '',
                'stage' => $stage,
                'amount' => dealMoney($lead['deal_amount'] ?? 0),
                'currency' => normalizeCurrency($lead['deal_currency'] ?? ''),
                'rate_type' => $lead['partner_rate_type'] ?? 'percentage',
                'rate_value' => $lead['partner_rate_value'] ?? 0,
                'payout' => partnerPayout($lead),
                'created_at' => $lead['created_at'] ?? '',
            ];
        }
    }
    usort($out, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));
    return $out;
}

/**
 * The commission owed on a deal, computed live from its CURRENT deal
 * value(s) — never stored as a separate number, so it can't drift out of
 * sync when a deal value changes.
 * Returns 0 if the deal has no partner attached.
 *
 * Always the SUM of each service's own payout (that service's own
 * estimated_deal_value requisition against its own rate — which falls back
 * to the deal-level partner_rate_type/value when that service has no entry
 * in partner_rates_by_service). Falls back to the old flat dealAmount calc
 * ONLY when the deal has no services at all to sum, so the figure is never
 * silently 0 just because deal_amount hasn't been separately rolled up —
 * that used to happen whenever partner_rates_by_service was still empty
 * (i.e. before any service's rate had ever been overridden), which is
 * exactly the common case right after a partner is first attached.
 */
function partnerPayout($lead) {
    if (empty($lead['partner_id'])) return 0.0;
    $services = array_unique(array_merge($lead['services'] ?? [], $lead['demo_services'] ?? []));
    if (empty($services)) {
        $amount = dealMoney($lead['deal_amount'] ?? 0);
        $rateType = $lead['partner_rate_type'] ?? 'percentage';
        $rateValue = dealMoney($lead['partner_rate_value'] ?? 0);
        if ($rateType === 'fixed') return $rateValue;
        return round($amount * $rateValue / 100, 2);
    }
    $rates = $lead['partner_rates_by_service'] ?? [];
    $requisitions = $lead['requisitions'] ?? [];
    // Once the deal has reached Demo (feasibility_by_service has at least
    // one entry), a service only earns commission once it's actually marked
    // Feasible — mirrors partnerPayoutTotal() in index.html. Before Demo, no
    // feasibility decision exists yet for any service, so every service
    // still counts (nothing has been ruled out).
    $feasibility = $lead['demo_feasibility_by_service'] ?? [];
    $feasibilityDecided = !empty($feasibility);
    $total = 0.0;
    foreach ($services as $service) {
        if ($feasibilityDecided && empty($feasibility[$service])) continue;
        $est = $requisitions["{$service}::estimated_deal_value"] ?? null;
        $svcAmount = (is_array($est) && isset($est['amount'])) ? dealMoney($est['amount']) : 0.0;
        $rate = $rates[$service] ?? ['type' => $lead['partner_rate_type'] ?? 'percentage', 'value' => $lead['partner_rate_value'] ?? 0];
        $rateType = $rate['type'] ?? 'percentage';
        $rateValue = dealMoney($rate['value'] ?? 0);
        $total += $rateType === 'fixed' ? $rateValue : round($svcAmount * $rateValue / 100, 2);
    }
    return round($total, 2);
}

/** Trimmed partner reference for embedding in deal/lead payloads. */
function partnerRefFor($lead, $partnersById) {
    if (empty($lead['partner_id'])) return null;
    $p = $partnersById[$lead['partner_id']] ?? null;
    return [
        'id' => $lead['partner_id'],
        'name' => $p['name'] ?? '(deleted partner)',
        'rate_type' => $lead['partner_rate_type'] ?? 'percentage',
        'rate_value' => $lead['partner_rate_value'] ?? 0,
        'payout' => partnerPayout($lead),
        'currency' => $lead['deal_currency'] ?? '',
    ];
}
