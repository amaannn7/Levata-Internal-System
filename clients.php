<?php
/**
 * Clients — shared, company-wide client registry (the Flozy-style hub).
 * Included by api.php after jobs.php/tasks.php so it can aggregate their stores.
 *
 * A CLIENT is the master record everything else hangs off: jobs (and their
 * invoices), documents (CPs/SOWs) and tasks. Records made before this module
 * existed only carry a free-typed client *name*, so aggregation matches by
 * client_id first and falls back to a case-insensitive name match — nothing
 * old breaks, and a backfill can promote those names into real client records.
 *
 * Client number format: CLI-0001 (sequential, stored in the blob's seq).
 */

/** Read the shared client store. Shape: ['clients' => [...], 'seq' => n, 'backfilled_once' => bool]. */
function getClientsStore() {
    $store = dbGetBlob('clients', null);
    if ($store === null) return ['clients' => [], 'seq' => 0, 'backfilled_once' => false];
    if (!isset($store['clients']) || !is_array($store['clients'])) $store['clients'] = [];
    $store['seq'] = (int) ($store['seq'] ?? 0);
    $store['backfilled_once'] = (bool) ($store['backfilled_once'] ?? false);
    return $store;
}

/** Write the shared client store, plus refresh the reporting projection. */
function saveClientsStore($store) {
    dbSaveBlob('clients', $store);
    dbSyncReportingTable('clients', $store['clients'] ?? [], [
        'client_no' => fn($c) => $c['client_no'] ?? '',
        'name' => fn($c) => $c['name'] ?? '',
        'status' => fn($c) => $c['status'] ?? 'active',
        'contact_email' => fn($c) => $c['contact_email'] ?? '',
        'created_at' => fn($c) => $c['created_at'] ?? null,
        'updated_at' => fn($c) => $c['updated_at'] ?? null,
    ]);
}

function nextClientNo(&$store) {
    $store['seq']++;
    return sprintf('CLI-%04d', $store['seq']);
}

/** Normalised key for case/whitespace-insensitive client-name matching. */
function clientNameKey($name) {
    return mb_strtolower(trim((string) $name));
}

/** Find a client in a list by exact-normalised name. Returns the client or null. */
function findClientByName($clients, $name) {
    $key = clientNameKey($name);
    if ($key === '') return null;
    foreach ($clients as $c) {
        if (clientNameKey($c['name'] ?? '') === $key) return $c;
    }
    return null;
}

/** Find a client in a list by id. Returns the client or null. */
function findClientById($clients, $id) {
    $id = trim((string) $id);
    if ($id === '') return null;
    foreach ($clients as $c) {
        if (($c['id'] ?? '') === $id) return $c;
    }
    return null;
}

/**
 * Resolve a typed client name to a client_id (or '' if no client record matches).
 * Used by save-job / save-task / save-document so records link themselves to the
 * registry automatically whenever the name matches.
 */
function resolveClientId($name) {
    $c = findClientByName(getClientsStore()['clients'], $name);
    return $c ? ($c['id'] ?? '') : '';
}

/**
 * Resolve the client for a job/task/document save from request input. Prefers an
 * explicit client_id (set by the client picker — authoritative, typo-proof) and
 * derives the display name from that record. Falls back to matching a typed
 * client name for any caller that doesn't send an id yet (older code paths,
 * ClickUp extraction, CSV import, etc.), so nothing existing breaks.
 *
 * Returns ['id' => string, 'name' => string]. Both '' if neither resolves.
 */
function resolveClientRef($input, $currentName = '') {
    $clients = getClientsStore()['clients'];
    $id = trim($input['client_id'] ?? '');
    if ($id !== '') {
        $c = findClientById($clients, $id);
        if ($c) return ['id' => $c['id'], 'name' => $c['name'] ?? ''];
    }
    // No valid id given: fall back to whatever name was provided (or the record's
    // existing name, on an update where the client field wasn't touched).
    $name = trim($input['client'] ?? $currentName);
    $c = findClientByName($clients, $name);
    return ['id' => $c ? $c['id'] : '', 'name' => $name];
}

/** Does this job/task/document belong to this client? (id first, name fallback) */
function clientOwnsRecord($client, $rec) {
    if (!empty($rec['client_id']) && $rec['client_id'] === ($client['id'] ?? '')) return true;
    $key = clientNameKey($client['name'] ?? '');
    return $key !== '' && clientNameKey($rec['client'] ?? '') === $key;
}

$VALID_CLIENT_STATUS = ['active', 'archived'];

/** Build/validate a client from request input. Used by create and update. */
function applyClientFields($client, $input) {
    global $VALID_CLIENT_STATUS;
    $client['name'] = trim($input['name'] ?? ($client['name'] ?? ''));
    $client['contact_name'] = trim($input['contact_name'] ?? ($client['contact_name'] ?? ''));
    $client['contact_email'] = trim($input['contact_email'] ?? ($client['contact_email'] ?? ''));
    $client['contact_phone'] = trim($input['contact_phone'] ?? ($client['contact_phone'] ?? ''));
    if (!isset($client['contacts']) || !is_array($client['contacts'])) $client['contacts'] = [];
    $client['website'] = trim($input['website'] ?? ($client['website'] ?? ''));
    $client['notes'] = trim($input['notes'] ?? ($client['notes'] ?? ''));
    $client['lead_id'] = trim($input['lead_id'] ?? ($client['lead_id'] ?? ''));
    $status = $input['status'] ?? ($client['status'] ?? 'active');
    $client['status'] = in_array($status, $VALID_CLIENT_STATUS, true) ? $status : 'active';
    return $client;
}

/**
 * A client (company) can have several contact people — the CLIENT record
 * itself stays "the company"; each person who talks to them is one entry in
 * $client['contacts']. Dedupes on email (case-insensitive) so re-registering
 * a deal for a repeat contact doesn't pile up duplicates. The FIRST contact
 * a client ever gets also mirrors into the legacy singular contact_name/
 * contact_email/contact_phone fields, so anything still reading those (older
 * client-workspace UI) keeps showing someone sensible.
 * Returns the (possibly newly created) contact.
 */
function addClientContact(&$client, $contact) {
    $name = trim($contact['name'] ?? '');
    $email = trim($contact['email'] ?? '');
    $phone = trim($contact['phone'] ?? '');
    if ($name === '' && $email === '' && $phone === '') return null;
    if (!isset($client['contacts']) || !is_array($client['contacts'])) $client['contacts'] = [];
    if ($email !== '') {
        foreach ($client['contacts'] as $existing) {
            if (mb_strtolower($existing['email'] ?? '') === mb_strtolower($email)) return $existing;
        }
    }
    $newContact = [
        'id' => 'contact_' . bin2hex(random_bytes(6)),
        'name' => $name,
        'email' => $email,
        'phone' => $phone,
        'created_at' => date('c'),
    ];
    $isFirst = empty($client['contacts']);
    $client['contacts'][] = $newContact;
    if ($isFirst) {
        $client['contact_name'] = $name;
        $client['contact_email'] = $email;
        $client['contact_phone'] = $phone;
    }
    return $newContact;
}

/**
 * When a client is renamed, rewrite the display-name string on every job/task/
 * document linked to it by client_id, so the whole app keeps showing one name.
 */
function propagateClientRename($clientId, $newName) {
    if ($clientId === '' || trim($newName) === '') return;

    $jobsStore = getJobsStore();
    $changed = false;
    foreach ($jobsStore['jobs'] as &$j) {
        if (($j['client_id'] ?? '') === $clientId && ($j['client'] ?? '') !== $newName) { $j['client'] = $newName; $changed = true; }
    }
    unset($j);
    if ($changed) saveJobsStore($jobsStore);

    $tasksStore = getTasksStore();
    $changed = false;
    foreach ($tasksStore['tasks'] as &$t) {
        if (($t['client_id'] ?? '') === $clientId && ($t['client'] ?? '') !== $newName) { $t['client'] = $newName; $changed = true; }
    }
    unset($t);
    if ($changed) saveTasksStore($tasksStore);

    $docsStore = getDocsStore();
    $changed = false;
    foreach ($docsStore['documents'] as &$d) {
        if (($d['client_id'] ?? '') === $clientId && ($d['client'] ?? '') !== $newName) { $d['client'] = $newName; $changed = true; }
    }
    unset($d);
    if ($changed) saveDocsStore($docsStore);
}

/**
 * Create client records for every distinct client name found on jobs, documents
 * and tasks that doesn't already have one, and stamp client_id back onto those
 * records. Returns how many clients were created.
 */
function backfillClients(&$store, $createdBy = '') {
    $created = 0;
    $now = date('c');

    $sources = [
        ['store' => getJobsStore(),  'key' => 'jobs',      'save' => 'saveJobsStore'],
        ['store' => getDocsStore(),  'key' => 'documents', 'save' => 'saveDocsStore'],
        ['store' => getTasksStore(), 'key' => 'tasks',     'save' => 'saveTasksStore'],
    ];

    foreach ($sources as &$src) {
        $dirty = false;
        foreach ($src['store'][$src['key']] as &$rec) {
            $name = trim($rec['client'] ?? '');
            if ($name === '') continue;
            $client = findClientByName($store['clients'], $name);
            if (!$client) {
                $client = applyClientFields([
                    'id' => 'client_' . bin2hex(random_bytes(8)),
                    'client_no' => nextClientNo($store),
                    'created_by' => $createdBy,
                    'created_at' => $now,
                    'updated_at' => $now,
                ], ['name' => $name]);
                $store['clients'][] = $client;
                $created++;
            }
            if (($rec['client_id'] ?? '') !== $client['id']) { $rec['client_id'] = $client['id']; $dirty = true; }
        }
        unset($rec);
        if ($dirty) $src['save']($src['store']);
    }
    unset($src);

    return $created;
}

/** Roll-up (jobs/finance/tasks/docs counts) over a client's already-filtered records. */
function clientRollup($myJobs, $myDocs, $myTasks) {
    $pipeline = 0.0; $paid = 0.0; $outstanding = 0.0; $activeJobs = 0;
    $pipelineBy = []; $paidBy = []; $outstandingBy = [];
    foreach ($myJobs as $j) {
        $f = jobFinance($j);
        $cur = normalizeCurrency($j['currency'] ?? '');
        if (($j['status'] ?? 'open') !== 'cancelled') { $pipeline += $f['value']; addToCurrencyBucket($pipelineBy, $cur, $f['value']); }
        if (in_array($j['status'] ?? 'open', ['open', 'in_progress', 'awaiting_payment'], true)) $activeJobs++;
        $paid += $f['paid'];
        $outstanding += $f['outstanding'];
        addToCurrencyBucket($paidBy, $cur, $f['paid']);
        addToCurrencyBucket($outstandingBy, $cur, $f['outstanding']);
    }
    $openTasks = count(array_filter($myTasks, fn($t) => ($t['status'] ?? 'open') !== 'done'));

    return [
        'jobs' => count($myJobs),
        'active_jobs' => $activeJobs,
        'documents' => count($myDocs),
        'tasks' => count($myTasks),
        'open_tasks' => $openTasks,
        'pipeline' => $pipeline,
        'paid' => $paid,
        'outstanding' => $outstanding,
        // Per-currency breakdowns — money is never converted between codes.
        'pipeline_by_currency' => $pipelineBy,
        'paid_by_currency' => $paidBy,
        'outstanding_by_currency' => $outstandingBy,
    ];
}

/** Trimmed document metadata for client views (same shape as list-documents). */
function clientDocMeta($d) {
    return [
        'id' => $d['id'] ?? '',
        'doc_no' => $d['doc_no'] ?? '',
        'type' => $d['type'] ?? 'sow',
        'title' => $d['title'] ?? 'Untitled',
        'client' => $d['client'] ?? '',
        'status' => $d['status'] ?? 'draft',
        'linked_cost_proposal' => $d['linked_cost_proposal'] ?? '',
        'owner' => $d['owner'] ?? '',
        'created_at' => $d['created_at'] ?? '',
        'updated_at' => $d['updated_at'] ?? ($d['created_at'] ?? ''),
        'has_file' => !empty($d['file_path']),
        'file_name' => $d['file_name'] ?? '',
    ];
}

/**
 * Everything about one client in a single payload: the record itself plus its
 * jobs (with finance), flattened invoices, documents (metadata) and tasks.
 */
function clientWorkspace($client) {
    $jobs = array_values(array_filter(getJobsStore()['jobs'], fn($j) => clientOwnsRecord($client, $j)));
    usort($jobs, fn($a, $b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));
    $jobs = array_map('decorateJob', $jobs);

    // Flatten invoices across the client's jobs for the Invoices tab.
    $invoices = [];
    foreach ($jobs as $j) {
        foreach (($j['invoices'] ?? []) as $inv) {
            $inv['job_id'] = $j['id'] ?? '';
            $inv['job_no'] = $j['job_no'] ?? '';
            $inv['job_name'] = $j['name'] ?? '';
            $inv['currency'] = $j['currency'] ?? '';
            $invoices[] = $inv;
        }
    }
    usort($invoices, fn($a, $b) => strcmp($a['due_date'] ?? '', $b['due_date'] ?? ''));

    $documents = array_map('clientDocMeta', array_values(array_filter(getAllDocuments(), fn($d) => clientOwnsRecord($client, $d))));
    usort($documents, fn($a, $b) => strcmp($b['updated_at'], $a['updated_at']));

    $tasks = array_values(array_filter(getTasksStore()['tasks'], fn($t) => clientOwnsRecord($client, $t)));
    usort($tasks, fn($a, $b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));

    return [
        'client' => $client,
        'jobs' => $jobs,
        'invoices' => $invoices,
        'documents' => $documents,
        'tasks' => $tasks,
        'rollup' => clientRollup($jobs, $documents, $tasks),
    ];
}

/** Company-wide summary across all clients, for the stat cards. */
function clientsSummary($clients, $jobs) {
    $active = count(array_filter($clients, fn($c) => ($c['status'] ?? 'active') === 'active'));
    $pipeline = 0.0; $outstanding = 0.0;
    $pipelineBy = []; $outstandingBy = [];
    foreach ($jobs as $j) {
        $f = jobFinance($j);
        $cur = normalizeCurrency($j['currency'] ?? '');
        if (($j['status'] ?? 'open') !== 'cancelled') { $pipeline += $f['value']; addToCurrencyBucket($pipelineBy, $cur, $f['value']); }
        $outstanding += $f['outstanding'];
        addToCurrencyBucket($outstandingBy, $cur, $f['outstanding']);
    }
    return [
        'clients' => count($clients),
        'active_clients' => $active,
        'pipeline_value' => $pipeline,
        'total_outstanding' => $outstanding,
        'pipeline_value_by_currency' => $pipelineBy,
        'total_outstanding_by_currency' => $outstandingBy,
    ];
}
