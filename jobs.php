<?php
/**
 * Job Registry — shared, company-wide database of client jobs.
 * Included by api.php. Stores everything in data/jobs.json (one shared file, not per-user),
 * because the registry is a macro finance view across the whole team.
 *
 * A JOB is one piece of work for a client (website, branding, videography, retainer...).
 * One client can have many jobs. Each job tracks:
 *   - links to the approved cost proposal (CP-xxxx) and the SOW (SOW-xxxx) it came from
 *   - its value and status (open / in_progress / awaiting_payment / completed / cancelled)
 *   - its invoices (advance + final for one-off jobs, or one per month for retainers)
 *
 * Job number format: JOB-0001 (sequential, per registry).
 * Invoice number format: INV-0001 (sequential across all invoices in the registry).
 */

/** Read the shared registry. Shape: ['jobs' => [...], 'seq' => ['job'=>n,'invoice'=>n]]. */
function getJobsStore() {
    $store = dbGetBlob('jobs', null);
    if ($store === null) return ['jobs' => [], 'seq' => ['job' => 0, 'invoice' => 0]];
    if (!isset($store['jobs']) || !is_array($store['jobs'])) $store['jobs'] = [];
    if (!isset($store['seq']) || !is_array($store['seq'])) $store['seq'] = ['job' => 0, 'invoice' => 0];
    $store['seq']['job'] = (int) ($store['seq']['job'] ?? 0);
    $store['seq']['invoice'] = (int) ($store['seq']['invoice'] ?? 0);
    return $store;
}

/** Write the shared registry (mirrors saveUserData), plus refresh the reporting projection. */
function saveJobsStore($store) {
    dbSaveBlob('jobs', $store);
    dbSyncReportingTable('jobs', $store['jobs'] ?? [], [
        'client' => 'client',
        'status' => 'status',
        'created_at' => fn($j) => $j['created_at'] ?? null,
        'updated_at' => fn($j) => $j['updated_at'] ?? null,
    ]);
}

function nextJobNo(&$store) {
    $store['seq']['job']++;
    return sprintf('JOB-%04d', $store['seq']['job']);
}
function nextInvoiceNo(&$store) {
    $store['seq']['invoice']++;
    return sprintf('INV-%04d', $store['seq']['invoice']);
}

/** Coerce a money input to a float (strip currency symbols, commas, spaces). */
function jobMoney($v) {
    if (is_numeric($v)) return (float) $v;
    $clean = preg_replace('/[^0-9.\-]/', '', (string) $v);
    return $clean === '' ? 0.0 : (float) $clean;
}

/** Build a single invoice record. */
function makeInvoice(&$store, $label, $amount, $dueDate = '', $status = 'unpaid') {
    return [
        'id' => 'inv_' . bin2hex(random_bytes(6)),
        'invoice_no' => nextInvoiceNo($store),
        'label' => $label,
        'amount' => jobMoney($amount),
        'due_date' => $dueDate,
        'status' => in_array($status, ['unpaid', 'paid'], true) ? $status : 'unpaid',
        'paid_at' => null,
        'created_at' => date('c'),
    ];
}

/**
 * Generate the default invoice schedule for a new job.
 * - retainer: one invoice per month for `months`, each `monthly_amount`.
 * - one-off:  advance (default 50%) + final.
 */
function buildJobInvoices(&$store, $job, $input) {
    $invoices = [];
    if (($job['type'] ?? 'one_off') === 'retainer') {
        $months = max(1, (int) ($input['retainer_months'] ?? 1));
        $monthly = jobMoney($input['monthly_amount'] ?? 0);
        $start = trim($input['start_date'] ?? '');
        for ($i = 0; $i < $months; $i++) {
            $due = '';
            if ($start !== '') {
                $ts = strtotime($start . ' +' . $i . ' month');
                if ($ts) $due = date('Y-m-d', $ts);
            }
            $invoices[] = makeInvoice($store, 'Month ' . ($i + 1), $monthly, $due);
        }
    } else {
        $total = jobMoney($job['value'] ?? 0);
        if ($total > 0) {
            $advancePct = isset($input['advance_pct']) ? max(0, min(100, (int) $input['advance_pct'])) : 50;
            $advance = round($total * $advancePct / 100, 2);
            $final = round($total - $advance, 2);
            $invoices[] = makeInvoice($store, 'Advance (' . $advancePct . '%)', $advance);
            $invoices[] = makeInvoice($store, 'Final payment', $final);
        }
    }
    return $invoices;
}

/** Roll up totals for one job (value, invoiced, paid, outstanding). */
function jobFinance($job) {
    $invoices = $job['invoices'] ?? [];
    $invoiced = 0.0; $paid = 0.0;
    foreach ($invoices as $inv) {
        $amt = (float) ($inv['amount'] ?? 0);
        $invoiced += $amt;
        if (($inv['status'] ?? '') === 'paid') $paid += $amt;
    }
    // For retainers the job "value" is the sum of monthly invoices; for one-off it's the stated value.
    $value = ($job['type'] ?? 'one_off') === 'retainer' ? $invoiced : (float) ($job['value'] ?? 0);
    return [
        'value' => $value,
        'invoiced' => $invoiced,
        'paid' => $paid,
        'outstanding' => max(0, $invoiced - $paid),
    ];
}

/**
 * Company-wide summary across all jobs, for the stat cards.
 * Money is reported BOTH as a flat total (legacy callers) and grouped by
 * currency code in `*_by_currency` — jobs can be quoted in different
 * currencies and those are never converted into one another.
 */
function jobsSummary($jobs) {
    $openCount = 0; $progressCount = 0; $awaitingCount = 0; $completedCount = 0;
    $pipelineValue = 0.0; $totalPaid = 0.0; $totalOutstanding = 0.0;
    $pipelineBy = []; $paidBy = []; $outstandingBy = [];
    foreach ($jobs as $job) {
        $status = $job['status'] ?? 'open';
        if ($status === 'open') $openCount++;
        elseif ($status === 'in_progress') $progressCount++;
        elseif ($status === 'awaiting_payment') $awaitingCount++;
        elseif ($status === 'completed') $completedCount++;
        $f = jobFinance($job);
        $cur = normalizeCurrency($job['currency'] ?? '');
        // Pipeline value = value of everything not cancelled.
        if ($status !== 'cancelled') { $pipelineValue += $f['value']; addToCurrencyBucket($pipelineBy, $cur, $f['value']); }
        $totalPaid += $f['paid'];
        $totalOutstanding += $f['outstanding'];
        addToCurrencyBucket($paidBy, $cur, $f['paid']);
        addToCurrencyBucket($outstandingBy, $cur, $f['outstanding']);
    }
    return [
        'open' => $openCount,
        'in_progress' => $progressCount,
        'awaiting_payment' => $awaitingCount,
        'completed' => $completedCount,
        'pipeline_value' => $pipelineValue,
        'total_paid' => $totalPaid,
        'total_outstanding' => $totalOutstanding,
        'pipeline_value_by_currency' => $pipelineBy,
        'total_paid_by_currency' => $paidBy,
        'total_outstanding_by_currency' => $outstandingBy,
    ];
}

/** Look up a job by id. Returns the job array or null. */
function jobRefById($id) {
    if (trim((string) $id) === '') return null;
    foreach (getJobsStore()['jobs'] as $j) {
        if (($j['id'] ?? '') === $id) return $j;
    }
    return null;
}

/** Attach computed finance to each job for the API response. */
function decorateJob($job) {
    $job['finance'] = jobFinance($job);
    return $job;
}

$VALID_JOB_STATUS = ['open', 'in_progress', 'awaiting_payment', 'completed', 'cancelled'];

/**
 * Build/validate a job from request input. Used by create and update.
 * Does NOT touch invoices (those are managed separately) except on initial create.
 */
function applyJobFields($job, $input) {
    global $VALID_JOB_STATUS;
    // Prefer an explicit client_id from the client picker (authoritative); fall
    // back to matching a typed name for any caller that doesn't send one yet.
    $ref = resolveClientRef($input, $job['client'] ?? '');
    $job['client'] = $ref['name'];
    $job['client_id'] = $ref['id'];
    $job['name'] = trim($input['name'] ?? ($job['name'] ?? ''));
    $job['category'] = trim($input['category'] ?? ($job['category'] ?? '')); // website / branding / video...
    $type = $input['type'] ?? ($job['type'] ?? 'one_off');
    $job['type'] = in_array($type, ['one_off', 'retainer'], true) ? $type : 'one_off';
    $job['value'] = jobMoney($input['value'] ?? ($job['value'] ?? 0));
    // Validated against supportedCurrencies(); falls back to the studio default.
    $job['currency'] = normalizeCurrency($input['currency'] ?? ($job['currency'] ?? ''));
    $status = $input['status'] ?? ($job['status'] ?? 'open');
    $job['status'] = in_array($status, $VALID_JOB_STATUS, true) ? $status : 'open';
    $job['linked_cost_proposal'] = trim($input['linked_cost_proposal'] ?? ($job['linked_cost_proposal'] ?? ''));
    $job['linked_sow'] = trim($input['linked_sow'] ?? ($job['linked_sow'] ?? ''));
    $job['notes'] = trim($input['notes'] ?? ($job['notes'] ?? ''));
    return $job;
}
