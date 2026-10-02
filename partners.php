<?php
/**
 * Channel Partners — a person/company that sends deals to Levata in exchange
 * for a commission. Included by api.php. Partners are created from the Users
 * page (a Channel Partner login) — that creates the record here too, linked by
 * user_id/partner_id. There is no separate partner directory or default rate.
 *
 * A deal (lead record) optionally carries partner_id + a rate snapshot
 * (partner_rate_type/partner_rate_value) set on the deal itself, per service. The payout
 * itself is always computed live from the deal's CURRENT value — see
 * partnerPayout() — so editing the deal amount keeps the payout correct
 * without a separate number to keep in sync.
 *
 * Partner number format: CP-0001 (sequential, stored in the blob's seq).
 */

/** Read the shared partner store. Shape: ['partners' => [...], 'seq' => n]. */
function getPartnersStore() {
    $store = memoGetBlob('partners');
    if ($store === null) return ['partners' => [], 'seq' => 0];
    if (!isset($store['partners']) || !is_array($store['partners'])) $store['partners'] = [];
    $store['seq'] = (int) ($store['seq'] ?? 0);
    return $store;
}

/** Write the shared partner store. */
function savePartnersStore($store) {
    memoSaveBlob('partners', $store);
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

$VALID_PARTNER_STATUS = ['active', 'archived'];

/**
 * Build/validate a partner record. A partner is just a name now (plus the
 * login that owns it) — there is no default commission: the rate and value are
 * set on each deal in the pipeline, where the deal value actually lives.
 */
function applyPartnerFields($partner, $input) {
    global $VALID_PARTNER_STATUS;
    $partner['name'] = trim($input['name'] ?? ($partner['name'] ?? ''));
    $partner['contact_name'] = trim($input['contact_name'] ?? ($partner['contact_name'] ?? ''));
    $partner['contact_email'] = trim($input['contact_email'] ?? ($partner['contact_email'] ?? ''));
    $partner['contact_phone'] = trim($input['contact_phone'] ?? ($partner['contact_phone'] ?? ''));
    $partner['notes'] = trim($input['notes'] ?? ($partner['notes'] ?? ''));
    $status = $input['status'] ?? ($partner['status'] ?? 'active');
    $partner['status'] = in_array($status, $VALID_PARTNER_STATUS, true) ? $status : 'active';
    return $partner;
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

/**
 * ===== Channel-partner login scope =====
 * A partner login gets the same Dashboard / Pipeline / Jobs / Tasks pages as the
 * team, narrowed to what came through them. Scope is always derived from the
 * caller's own account (partner_id), never from a request parameter.
 */

/**
 * The partner gate: may this partner login make this call?
 *
 * A channel partner works exactly like an admin on the pages they are given —
 * Dashboard, Pipeline (add/edit deals, the full deal modal, win), Cost Proposals,
 * SOWs, Saved Documents, Jobs, Tasks and their bell — with two differences: a
 * limited interface, and they only ever see or touch THEIR OWN records. So this runs
 * once before the router: the action must be on the table below, and every deal,
 * document, job or task the request names must belong to the partner. Anything not
 * listed (clients, users, settings, other sections, outbound email, ClickUp,
 * import/export, changing which partner a deal belongs to) is refused, so a new
 * endpoint is closed to partners by default.
 *
 * Each rule is a list of checks [kind, keys, required]: every id found under `keys`
 * (request body, query or form) must be one of the partner's own `kind` records, and
 * with `required` at least one must be present. A rule of [] needs no record: the
 * handler scopes its own result (lists) or pins the new record to the partner (creates).
 */
function partnerMayCall($user, $action, $method, $input) {
    $L = fn($keys, $req = true) => ['lead', (array) $keys, $req];
    $D = fn($keys, $req = true) => ['doc', (array) $keys, $req];
    $J = fn($keys, $req = true) => ['job', (array) $keys, $req];
    $T = fn($keys, $req = true) => ['task', (array) $keys, $req];
    $rules = [
        'GET' => [
            'me' => [], 'leads' => [], 'stats' => [], 'studio-overview' => [], 'jobs' => [], 'tasks' => [], 'notifications' => [],
            'command-center' => [], 'team-members' => [], // dashboard funnel / owner+assignee pickers (both narrowed to the partner)
            'list-documents' => [], 'all-documents' => [],
            'lead' => [$L('id')], 'next-best-action' => [$L('lead_id')], 'deal-documents' => [$L('lead_id')], 'deal-win-preview' => [$L('lead_id')],
            'get-document' => [$D('id')], 'download-document-file' => [$D('id')],
        ],
        'POST' => [
            'activity-ping' => [], 'notifications' => [],
            'lead' => [], // create: the handler pins it to the partner
            'update-lead' => [$L('id')], 'add-lead-note' => [$L('id')], 'delete-lead' => [$L('id')], 'restore-lead' => [$L('id')],
            'permanent-delete' => [$L('id')], 'set-service-partner-rate' => [$L('id')], 'bulk-delete' => [$L('ids')],
            'set-deal-amount' => [$L('lead_id')], 'save-requisitions' => [$L('lead_id')], 'save-call-outcome' => [$L('lead_id')],
            'log-activity' => [$L('lead_id')], 'drop-lead' => [$L('lead_id')], 'win-deal' => [$L('lead_id')],
            'generate-lead-research' => [$L('lead_id')], 'generate-demo-checklist' => [$L('lead_id')],
            'grade-override' => [$L('lead_id')], 'recalculate-grade' => [$L('lead_id')],
            'link-deal-document' => [$L('lead_id'), $D('document_id')],
            'generate-calendly-email' => [],
            'generate-sow' => [], 'refine-sow' => [], 'generate-cost-proposal' => [], 'refine-cost-proposal' => [],
            'extract-sow' => [], 'extract-cost-proposal' => [],
            'save-document' => [$D('id', false), $L('lead_id', false)],
            'set-document-status' => [$D('id')], 'delete-document' => [$D('id')], 'upload-document-file' => [$D('id')],
            'save-job' => [$J('id', false)], 'delete-job' => [$J('id')],
            'save-invoice' => [$J('job_id')], 'delete-invoice' => [$J('job_id')],
            'save-task' => [$T('id', false), $J('job_id', false)], 'delete-task' => [$T('id')],
        ],
        'PUT' => ['lead' => [$L('id')]],
        'DELETE' => ['lead' => [$L('id')]],
    ];
    if (!isset($rules[$method][$action])) return false;

    $args = array_merge($_GET, $_POST, is_array($input) ? $input : []);
    $own = []; // each kind's own ids, loaded only if a rule needs it
    $ownIds = function ($kind) use (&$own, $user) {
        if (isset($own[$kind])) return $own[$kind];
        if ($kind === 'lead') $ids = array_column(partnerOwnLeads($user, getLeadsStore()['leads']), 'id');
        elseif ($kind === 'job') $ids = array_column(partnerOwnJobs($user), 'id');
        elseif ($kind === 'task') $ids = array_column(partnerOwnTasks($user, getTasksStore()['tasks']), 'id');
        else $ids = array_column(partnerOwnDocs($user, getAllDocuments()), 'id');
        return $own[$kind] = $ids;
    };
    foreach ($rules[$method][$action] as [$kind, $keys, $required]) {
        $named = [];
        foreach ($keys as $k) {
            if (!isset($args[$k]) || $args[$k] === '') continue;
            foreach ((array) $args[$k] as $v) $named[] = (string) $v;
        }
        if (!$named) { if ($required) return false; continue; }
        if (array_diff($named, $ownIds($kind))) return false;
    }
    return true;
}

/** The partner's own live deals: leads carrying their partner_id. */
function partnerOwnLeads($user, $leads) {
    $pid = trim((string) ($user['partner_id'] ?? ''));
    if ($pid === '') return [];
    return array_values(array_filter($leads, fn($l) => ($l['partner_id'] ?? '') === $pid));
}

/** Jobs from the partner's deals (job.lead_id, or the deal's job_ids), plus any job they created themselves. */
function partnerOwnJobs($user) {
    $leads = partnerOwnLeads($user, getLeadsStore()['leads']);
    $leadIds = array_column($leads, 'id');
    $jobIds = [];
    foreach ($leads as $l) foreach (($l['job_ids'] ?? []) as $jid) $jobIds[$jid] = true;
    return array_values(array_filter(getJobsStore()['jobs'], fn($j) =>
        in_array($j['lead_id'] ?? '', $leadIds, true) || isset($jobIds[$j['id'] ?? '']) || (($j['created_by'] ?? '') === ($user['id'] ?? '') && !empty($user['id']))));
}

/** Tasks on one of the partner's jobs, plus any task they created themselves. */
function partnerOwnTasks($user, $tasks) {
    $jobIds = array_column(partnerOwnJobs($user), 'id');
    return array_values(array_filter($tasks, fn($t) =>
        (!empty($t['job_id']) && in_array($t['job_id'], $jobIds, true)) || (($t['created_by'] ?? '') === ($user['id'] ?? '') && !empty($user['id']))));
}

/** Documents (cost proposals, SOWs...) made for the partner's deals, or created by the partner. */
function partnerOwnDocs($user, $docs) {
    $leadIds = array_column(partnerOwnLeads($user, getLeadsStore()['leads']), 'id');
    return array_values(array_filter($docs, fn($d) =>
        (($d['lead_id'] ?? '') !== '' && in_array($d['lead_id'], $leadIds, true)) || (($d['owner_id'] ?? '') === ($user['id'] ?? '') && !empty($user['id']))));
}

/**
 * ===== Partner bell notifications =====
 * A partner's bell only carries things that happen to THEIR deals, jobs and
 * tasks: a deal changing stage or value, a job being created/updated, an invoice
 * paid, a task added or moved. Events are queued while a handler runs and flushed
 * from respond() once the handler has saved its own data, so writing the
 * notification (which touches the partner's user-data blob) can never interleave
 * with a half-finished save of the deal/job/task store.
 */
$GLOBALS['__partnerNotifyQueue'] = [];

function partnerUserIdForPartnerId($partnerId) {
    $partnerId = trim((string) $partnerId);
    if ($partnerId === '') return '';
    foreach (getUsers() as $u) {
        if (!empty($u['is_channel_partner']) && ($u['partner_id'] ?? '') === $partnerId) return $u['id'];
    }
    return '';
}

/** Queue one event for a partner. $page is where clicking it lands (leads / job-registry / tasks). */
function partnerNotifyQueue($partnerId, $page, $title, $body, $leadId = '') {
    if (trim((string) $partnerId) === '') return;
    $GLOBALS['__partnerNotifyQueue'][] = ['partner_id' => $partnerId, 'page' => $page, 'title' => $title, 'body' => $body, 'lead_id' => $leadId];
}

/** Queue an event about a deal, addressed to whichever partner it belongs to. */
function partnerNotifyLead($lead, $page, $title, $body) {
    partnerNotifyQueue($lead['partner_id'] ?? '', $page, $title, $body, $lead['id'] ?? '');
}

/** Queue an event about a job (or a task on it): the partner is whoever referred the job's deal. */
function partnerNotifyJobId($jobId, $page, $title, $body) {
    if (trim((string) $jobId) === '') return;
    $leadId = '';
    foreach (getJobsStore()['jobs'] as $j) { if (($j['id'] ?? '') === $jobId) { $leadId = $j['lead_id'] ?? ''; break; } }
    foreach (getLeadsStore()['leads'] as $l) {
        if (($leadId !== '' && ($l['id'] ?? '') === $leadId) || in_array($jobId, $l['job_ids'] ?? [], true)) {
            partnerNotifyLead($l, $page, $title, $body);
            return;
        }
    }
}

/** Write everything queued into the partners' bells. Called from respond(). */
function flushPartnerNotifications() {
    $queue = $GLOBALS['__partnerNotifyQueue'];
    $GLOBALS['__partnerNotifyQueue'] = [];
    foreach ($queue as $n) {
        $uid = partnerUserIdForPartnerId($n['partner_id']);
        if ($uid === '' || $uid === ($GLOBALS['__actorUserId'] ?? '')) continue; // never notify a partner of their own actions
        // Escaped here because the bell renders title/body as HTML.
        addUserNotification($uid, ['type' => 'partner_update', 'page' => $n['page'], 'title' => htmlspecialchars($n['title']), 'body' => htmlspecialchars($n['body']), 'lead_id' => $n['lead_id']]);
    }
}

/**
 * ===== Jobs / tasks <-> channel partners =====
 * A job belongs to a partner when the deal it was registered from was referred by
 * one (job.lead_id / lead.job_ids -> lead.partner_id); a task belongs to the
 * partner of the job it hangs off. Nothing is stored on the job or task: it is
 * derived here on every read, so it can't drift if a deal's partner changes.
 */
function partnerJobMap() {
    $leadPartner = []; $jobPartner = [];
    foreach (getLeadsStore()['leads'] as $l) {
        if (empty($l['partner_id']) || !empty($l['deleted_at'])) continue;
        $leadPartner[$l['id']] = $l['partner_id'];
        foreach (($l['job_ids'] ?? []) as $jid) $jobPartner[$jid] = $l['partner_id'];
    }
    foreach (getJobsStore()['jobs'] as $j) {
        if (!empty($j['lead_id']) && isset($leadPartner[$j['lead_id']])) $jobPartner[$j['id']] = $leadPartner[$j['lead_id']];
    }
    $userPartner = [];
    foreach (getUsers() as $u) { if (!empty($u['is_channel_partner']) && !empty($u['partner_id'])) $userPartner[$u['id']] = $u['partner_id']; }
    foreach (getJobsStore()['jobs'] as $j) {
        if (!isset($jobPartner[$j['id'] ?? '']) && isset($userPartner[$j['created_by'] ?? ''])) $jobPartner[$j['id']] = $userPartner[$j['created_by']];
    }
    $names = [];
    foreach (getPartnersStore()['partners'] as $p) $names[$p['id']] = $p['name'] ?? '';
    return ['job' => $jobPartner, 'user' => $userPartner, 'names' => $names];
}

/** Stamp partner_id/partner_name on a job or task ($jobId = the job it is/belongs to). */
function partnerStamp($row, $jobId, $map) {
    $pid = $map['job'][$jobId] ?? '';
    // A task with no job still belongs to a partner if a partner login created it.
    if ($pid === '' && isset($map['user'][$row['created_by'] ?? ''])) $pid = $map['user'][$row['created_by']];
    $row['partner_id'] = $pid;
    $row['partner_name'] = $pid === '' ? '' : (($map['names'][$pid] ?? '') ?: '(deleted partner)');
    return $row;
}

/**
 * ===== Telling the admins what a partner did =====
 * Every write a partner makes (anything beyond the session ping and their own bell)
 * puts one notification in each Admin's and Super Admin's bell, e.g. "ZZ Chandan:
 * changed a deal value" with the deal name, linking to the record. The gate stashes
 * the call; respond() sends it once the request has succeeded. Repeats of the same
 * action on the same record within 15 minutes collapse into one (a deal modal
 * auto-saves many times), so the bell stays readable.
 */
function partnerActionSummary($user, $action, $args) {
    $kinds = [
        'update-lead' => ['edited a deal', 'lead'], 'set-deal-amount' => ['changed a deal value', 'lead'],
        'set-service-partner-rate' => ['changed their commission on a deal', 'lead'], 'add-lead-note' => ['added a note to a deal', 'lead'],
        'save-requisitions' => ['updated a deal\'s details', 'lead'], 'save-call-outcome' => ['logged a call on a deal', 'lead'],
        'log-activity' => ['logged activity on a deal', 'lead'], 'drop-lead' => ['dropped a deal', 'lead'],
        'delete-lead' => ['deleted a deal', 'lead'], 'restore-lead' => ['restored a deal', 'lead'], 'permanent-delete' => ['permanently deleted a deal', 'lead'],
        'bulk-delete' => ['deleted deals', 'lead'], 'win-deal' => ['won a deal', 'lead'], 'link-deal-document' => ['linked a document to a deal', 'lead'],
        'generate-lead-research' => ['ran research on a deal', 'lead'], 'generate-demo-checklist' => ['generated a demo checklist', 'lead'],
        'grade-override' => ['overrode a deal grade', 'lead'], 'recalculate-grade' => ['recalculated a deal grade', 'lead'],
        'save-document' => ['saved a document', 'doc'], 'set-document-status' => ['changed a document\'s status', 'doc'],
        'delete-document' => ['deleted a document', 'doc'], 'upload-document-file' => ['uploaded a document file', 'doc'],
        'generate-sow' => ['generated a SOW', 'none'], 'generate-cost-proposal' => ['generated a cost proposal', 'none'],
        'refine-sow' => ['refined a SOW', 'none'], 'refine-cost-proposal' => ['refined a cost proposal', 'none'],
        'save-job' => ['saved a job', 'job'], 'delete-job' => ['deleted a job', 'job'],
        'save-invoice' => ['saved an invoice', 'job'], 'delete-invoice' => ['deleted an invoice', 'job'],
        'save-task' => ['saved a task', 'task'], 'delete-task' => ['deleted a task', 'task'],
    ];
    if (!isset($kinds[$action])) return null;
    [$verb, $kind] = $kinds[$action];
    $id = trim((string) ($args['id'] ?? $args['lead_id'] ?? $args['job_id'] ?? ''));
    $target = ''; $page = 'leads'; $leadId = '';
    if ($kind === 'lead') {
        $leadId = $action === 'bulk-delete' ? '' : $id;
        foreach (getLeadsStore()['leads'] as $l) {
            if (($l['id'] ?? '') === $id) { $target = trim($l['company'] ?? '') ?: trim(($l['first_name'] ?? '') . ' ' . ($l['last_name'] ?? '')); break; }
        }
    } elseif ($kind === 'doc') {
        $page = 'saved-documents'; $target = trim((string) ($args['title'] ?? ''));
        foreach (getAllDocuments() as $d) { if (($d['id'] ?? '') === $id) { $target = $target ?: ($d['doc_no'] ?? '') . ' ' . ($d['title'] ?? ''); break; } }
        if ($target === '' && !empty($args['lead_id'])) $leadId = (string) $args['lead_id'];
    } elseif ($kind === 'job') {
        $page = 'job-registry';
        foreach (getJobsStore()['jobs'] as $j) { if (($j['id'] ?? '') === $id) { $target = ($j['job_no'] ?? '') . ' ' . ($j['name'] ?? ''); break; } }
        if ($target === '') $target = trim((string) ($args['name'] ?? ''));
    } elseif ($kind === 'task') {
        $page = 'tasks'; $target = trim((string) ($args['title'] ?? ''));
        if ($target === '') foreach (getTasksStore()['tasks'] as $t) { if (($t['id'] ?? '') === $id) { $target = $t['title'] ?? ''; break; } }
    }
    return ['verb' => $verb, 'target' => trim($target), 'page' => $page, 'lead_id' => $leadId, 'key' => $action . '|' . ($id ?: $target)];
}

/** Put the stashed partner action into every admin's bell (called from respond() on success). */
function notifyAdminsOfPartnerAction() {
    $pa = $GLOBALS['__partnerAction'] ?? null;
    $GLOBALS['__partnerAction'] = null;
    if (!$pa) return;
    $s = partnerActionSummary($pa['user'], $pa['action'], $pa['args']);
    if (!$s) return;
    $who = $pa['user']['name'] ?? 'A channel partner';
    $key = 'pact_' . ($pa['user']['id'] ?? '') . '_' . md5($s['key']);
    foreach (getUsers() as $u) {
        if (empty($u['is_admin']) && empty($u['is_super_admin'])) continue;
        if (!empty($u['is_channel_partner'])) continue;
        // Collapse repeats of the same action on the same record within 15 minutes.
        foreach ((getUserData($u['id'])['notifications'] ?? []) as $n) {
            if (($n['notif_key'] ?? '') === $key && strtotime($n['created_at'] ?? '0') > time() - 900) continue 2;
        }
        addUserNotification($u['id'], [
            'notif_key' => $key, 'type' => 'partner_activity', 'page' => $s['page'], 'lead_id' => $s['lead_id'],
            'title' => '🤝 ' . htmlspecialchars($who) . ' ' . htmlspecialchars($s['verb']),
            'body' => htmlspecialchars($s['target']),
        ]);
    }
}
