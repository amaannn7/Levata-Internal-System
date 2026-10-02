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
 * Job number format: JOB-0001 (sequential, per registry). The job is the primary record:
 * it gets its number the moment it is created, and nothing is numbered without one.
 * Invoice number format: INV-0001 (sequential across all invoices in the registry). An invoice
 * can only be created against an existing job and carries that job's id + JOB-xxxx.
 */

/** Read the shared registry. Shape: ['jobs' => [...], 'seq' => ['job'=>n,'invoice'=>n]]. */
function getJobsStore() {
    $store = memoGetBlob('jobs');
    if ($store === null) return ['jobs' => [], 'seq' => ['job' => 0, 'invoice' => 0]];
    if (!isset($store['jobs']) || !is_array($store['jobs'])) $store['jobs'] = [];
    if (!isset($store['seq']) || !is_array($store['seq'])) $store['seq'] = ['job' => 0, 'invoice' => 0];
    $store['seq']['job'] = (int) ($store['seq']['job'] ?? 0);
    $store['seq']['invoice'] = (int) ($store['seq']['invoice'] ?? 0);
    // Self-healing: a job with no number, or an invoice not stamped with its job,
    // is repaired once and persisted so the numbers are stable from then on.
    if (ensureJobNumbers($store)) saveJobsStore($store);
    return $store;
}

/** The numeric part of a JOB-0007 / INV-0007 style number, or 0 if it isn't one. */
function registryNumberPart($no) {
    return preg_match('/(\d+)\s*$/', (string) $no, $m) ? (int) $m[1] : 0;
}

/**
 * Make sure every job carries a unique JOB-xxxx and every invoice is stamped
 * with the job it belongs to (`job_id` / `job_no`). Existing numbers are never
 * changed; only gaps are filled, oldest job first so the numbering follows the
 * order work was registered. Also lifts the counters to at least the highest
 * number already in use, so a restored or hand-edited store can't hand out a
 * duplicate. Returns true if anything was changed.
 */
function ensureJobNumbers(&$store) {
    $changed = false;
    $maxJob = $store['seq']['job'];
    $maxInv = $store['seq']['invoice'];
    $taken = [];
    foreach ($store['jobs'] as $j) {
        $no = trim((string) ($j['job_no'] ?? ''));
        if ($no !== '') { $taken[$no] = true; $maxJob = max($maxJob, registryNumberPart($no)); }
        foreach (($j['invoices'] ?? []) as $inv) $maxInv = max($maxInv, registryNumberPart($inv['invoice_no'] ?? ''));
    }
    if ($maxJob > $store['seq']['job']) { $store['seq']['job'] = $maxJob; $changed = true; }
    if ($maxInv > $store['seq']['invoice']) { $store['seq']['invoice'] = $maxInv; $changed = true; }

    // Jobs missing a number, or sharing one with an earlier job, get the next free one.
    $order = array_keys($store['jobs']);
    usort($order, fn($a, $b) => strcmp($store['jobs'][$a]['created_at'] ?? '', $store['jobs'][$b]['created_at'] ?? '') ?: $a <=> $b);
    $seen = [];
    foreach ($order as $i) {
        $no = trim((string) ($store['jobs'][$i]['job_no'] ?? ''));
        if ($no === '' || isset($seen[$no])) {
            $store['jobs'][$i]['job_no'] = nextJobNo($store);
            $changed = true;
        }
        $seen[$store['jobs'][$i]['job_no']] = true;
    }

    foreach ($store['jobs'] as &$j) {
        if (!isset($j['invoices']) || !is_array($j['invoices'])) continue;
        foreach ($j['invoices'] as &$inv) {
            if (($inv['job_id'] ?? '') !== ($j['id'] ?? '') || ($inv['job_no'] ?? '') !== ($j['job_no'] ?? '')) {
                $inv['job_id'] = $j['id'] ?? '';
                $inv['job_no'] = $j['job_no'] ?? '';
                $changed = true;
            }
        }
        unset($inv);
    }
    unset($j);
    return $changed;
}

/** Write the shared registry (mirrors saveUserData), plus refresh the reporting projection. */
function saveJobsStore($store) {
    memoSaveBlob('jobs', $store);
    dbSyncReportingTable('jobs', $store['jobs'] ?? [], [
        'client' => 'client',
        'status' => 'status',
        'created_at' => fn($j) => $j['created_at'] ?? null,
        'updated_at' => fn($j) => $j['updated_at'] ?? null,
    ]);
}

function nextJobNo(&$store) {
    // Never hand out a number a job already holds, even if the counter has drifted behind.
    $used = [];
    foreach ($store['jobs'] ?? [] as $j) $used[$j['job_no'] ?? ''] = true;
    do {
        $store['seq']['job']++;
        $no = sprintf('JOB-%04d', $store['seq']['job']);
    } while (isset($used[$no]));
    return $no;
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

/**
 * Build a single invoice record. An invoice only ever exists against a job, so
 * it is stamped with that job's id and JOB-xxxx at creation (its own INV-xxxx
 * stays a single company-wide sequence, so invoice numbers never repeat).
 */
function makeInvoice(&$store, $job, $label, $amount, $dueDate = '', $status = 'unpaid') {
    return [
        'id' => 'inv_' . bin2hex(random_bytes(6)),
        'invoice_no' => nextInvoiceNo($store),
        'job_id' => $job['id'] ?? '',
        'job_no' => $job['job_no'] ?? '',
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
            $invoices[] = makeInvoice($store, $job, 'Month ' . ($i + 1), $monthly, $due);
        }
    } else {
        $total = jobMoney($job['value'] ?? 0);
        if ($total > 0) {
            $advancePct = isset($input['advance_pct']) ? max(0, min(100, (int) $input['advance_pct'])) : 50;
            $advance = round($total * $advancePct / 100, 2);
            $final = round($total - $advance, 2);
            $invoices[] = makeInvoice($store, $job, 'Advance (' . $advancePct . '%)', $advance);
            $invoices[] = makeInvoice($store, $job, 'Final payment', $final);
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
