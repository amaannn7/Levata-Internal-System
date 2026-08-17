<?php
/**
 * AI Assistant — a Gemini function-calling agent over the app's existing data.
 * Included by api.php (require_once), same wiring as chat.php / jobs.php / clients.php.
 * Holds ONLY helper functions; the request action (`chat-assistant`) lives as an
 * inline `case` block in api.php's switch.
 *
 * How it works (the agent loop):
 *   1. We send the user's message + a list of tool declarations to Gemini.
 *   2. Gemini answers with plain text (done) OR a functionCall (name + args).
 *   3. We run that tool against the app's REAL data helpers (getJobsStore(),
 *      getClientsStore(), ...), scoped to the logged-in user, and send the
 *      result back as a functionResponse.
 *   4. Repeat until Gemini returns text, capped at AGENT_MAX_STEPS iterations.
 *
 * v1 is READ-ONLY: every tool just reads and summarises data the user can
 * already see. No tool writes, deletes, or sends anything. Write tools
 * (create_job, mark_invoice_paid, ...) are a deliberate later phase and must
 * be added behind a confirmation step.
 */

const AGENT_MAX_STEPS = 6;

/* ============================================================
 * Tool declarations (Gemini functionDeclarations shape)
 * ============================================================ */

function agentToolDeclarations(): array {
    return [
        [
            'name' => 'list_jobs',
            'description' => 'List all client jobs in the Job Registry with their status, value, client, and invoice/payment roll-ups. Use for questions about jobs, pipeline value, what is paid or outstanding, or a client\'s jobs.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'status' => ['type' => 'string', 'description' => 'Optional status filter: open, in_progress, awaiting_payment, completed, or cancelled.'],
                    'client' => ['type' => 'string', 'description' => 'Optional client name to filter to (case-insensitive substring).'],
                ],
            ],
        ],
        [
            'name' => 'list_clients',
            'description' => 'List all clients in the shared client registry with per-client roll-ups (job count, document count, task count, total value). Use for questions about who the clients are or client-level totals.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'search' => ['type' => 'string', 'description' => 'Optional client name to filter to (case-insensitive substring).'],
                ],
            ],
        ],
        [
            'name' => 'get_client_workspace',
            'description' => 'Get everything about ONE client in a single view: their record plus all their jobs, invoices, documents, and tasks. Use when the user asks for a deep dive on a specific named client.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'client' => ['type' => 'string', 'description' => 'The client name (case-insensitive; best match is used).'],
                ],
                'required' => ['client'],
            ],
        ],
        [
            'name' => 'list_documents',
            'description' => 'List saved documents (Cost Proposals, SOWs, Invoices) with their type, client, status (draft/approved) and document number. Use for questions about proposals, SOWs, or the document library.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'client' => ['type' => 'string', 'description' => 'Optional client name filter (case-insensitive substring).'],
                    'type' => ['type' => 'string', 'description' => 'Optional document type filter: sow, cost_proposal, or invoice.'],
                ],
            ],
        ],
        [
            'name' => 'list_tasks',
            'description' => 'List tasks with their status, assignee, client, due date and linked job. Use for questions about what is due, open tasks, or a client\'s tasks.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'status' => ['type' => 'string', 'description' => 'Optional status filter: open or done.'],
                    'client' => ['type' => 'string', 'description' => 'Optional client name filter (case-insensitive substring).'],
                ],
            ],
        ],
        [
            'name' => 'list_my_leads',
            'description' => 'List the logged-in user\'s OWN sales leads (leads are private per user) with company, contact, stage and next action. Use for questions about the current user\'s pipeline or specific leads.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'search' => ['type' => 'string', 'description' => 'Optional filter by company, name, or email (case-insensitive substring).'],
                ],
            ],
        ],

        [
            'name' => 'get_document_content',
            'description' => 'Read the actual TEXT of one document (SOW, Cost Proposal, NDA, invoice) so you can summarise it, quote it, or answer questions about what it says (scope, deliverables, payment terms, timelines). Identify the document by its number, e.g. SOW-0002 or CP-0004. Use list_documents first if you do not know the number. Long documents are truncated, and you can pass a search term to pull just the relevant part.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'doc_no' => ['type' => 'string', 'description' => 'The document number, e.g. SOW-0002, CP-0004, NDA-0001.'],
                    'search' => ['type' => 'string', 'description' => 'Optional keyword (e.g. "payment", "timeline", "scope"). When given, only the sections mentioning it are returned, which keeps long documents readable.'],
                ],
                'required' => ['doc_no'],
            ],
        ],

        /* ---- WRITE tools. These do NOT execute; they PROPOSE an action that the
           user must confirm in the UI. Never claim a write is done from calling
           one of these; only report that you have prepared it for confirmation. ---- */
        [
            'name' => 'create_job',
            'description' => 'Prepare a new job for a client to be created in the Job Registry. Does NOT create it immediately: it returns a proposal the user must confirm. Use when the user asks to create/add/register a job.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'client' => ['type' => 'string', 'description' => 'Client name. Should match an existing client where possible.'],
                    'name' => ['type' => 'string', 'description' => 'The job/project name, e.g. "Website redesign".'],
                    'value' => ['type' => 'number', 'description' => 'Total job value (a number, no currency symbol). For one-off jobs.'],
                    'currency' => ['type' => 'string', 'description' => 'Currency code, e.g. LKR, USD, GBP. Defaults to LKR if omitted.'],
                    'type' => ['type' => 'string', 'description' => 'Either "one_off" or "retainer". Defaults to one_off.'],
                ],
                'required' => ['client', 'name'],
            ],
        ],
        [
            'name' => 'create_task',
            'description' => 'Prepare a new task to be created. Does NOT create it immediately: returns a proposal the user must confirm. Use when the user asks to add/create a task or to-do.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'title' => ['type' => 'string', 'description' => 'The task title / what needs doing.'],
                    'client' => ['type' => 'string', 'description' => 'Optional client this task is for.'],
                    'assignee' => ['type' => 'string', 'description' => 'Optional person the task is assigned to.'],
                    'due_date' => ['type' => 'string', 'description' => 'Optional due date in YYYY-MM-DD format.'],
                    'notes' => ['type' => 'string', 'description' => 'Optional extra notes.'],
                ],
                'required' => ['title'],
            ],
        ],
        [
            'name' => 'mark_invoice_paid',
            'description' => 'Prepare to mark a specific invoice on a job as paid. Does NOT change anything immediately: returns a proposal the user must confirm. First use list_jobs / get_client_workspace to find the job number and the invoice, then reference the invoice by its invoice number or label.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'job_no' => ['type' => 'string', 'description' => 'The job number the invoice belongs to, e.g. JOB-0003.'],
                    'invoice_ref' => ['type' => 'string', 'description' => 'Which invoice to mark paid: its invoice number (e.g. INV-0007) or its label (e.g. "Advance").'],
                ],
                'required' => ['job_no', 'invoice_ref'],
            ],
        ],
        [
            'name' => 'update_job_status',
            'description' => 'Prepare to change a job\'s status. Does NOT change anything immediately: returns a proposal the user must confirm.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'job_no' => ['type' => 'string', 'description' => 'The job number, e.g. JOB-0003.'],
                    'status' => ['type' => 'string', 'description' => 'New status: open, in_progress, awaiting_payment, completed, or cancelled.'],
                ],
                'required' => ['job_no', 'status'],
            ],
        ],
    ];
}

/* ============================================================
 * Tool dispatch — runs against the app's REAL data helpers,
 * scoped to the logged-in $user. Returns a plain array (JSON-able).
 * Never calls respond(); never writes.
 * ============================================================ */

function runAgentTool(string $name, array $args, array $user): array {
    switch ($name) {
        case 'list_jobs': {
            $jobs = array_map('decorateJob', getJobsStore()['jobs']);
            $status = strtolower(trim($args['status'] ?? ''));
            $client = strtolower(trim($args['client'] ?? ''));
            $jobs = array_values(array_filter($jobs, function ($j) use ($status, $client) {
                if ($status !== '' && strtolower($j['status'] ?? '') !== $status) return false;
                if ($client !== '' && stripos($j['client'] ?? '', $client) === false) return false;
                return true;
            }));
            $slim = array_map(fn($j) => [
                'job_no' => $j['job_no'] ?? '', 'name' => $j['name'] ?? '', 'client' => $j['client'] ?? '',
                'status' => $j['status'] ?? '', 'type' => $j['type'] ?? '', 'currency' => $j['currency'] ?? '',
                'value' => $j['finance']['value'] ?? null,
                'paid' => $j['finance']['paid'] ?? null,
                'outstanding' => $j['finance']['outstanding'] ?? null,
            ], $jobs);
            return ['count' => count($slim), 'jobs' => $slim, 'summary' => jobsSummary(getJobsStore()['jobs'])];
        }

        case 'list_clients': {
            $store = getClientsStore();
            // Gated by 'backfilled_once' (not just an empty list) so a
            // deliberately emptied registry doesn't get resurrected via chat
            // — see the matching guard on the 'clients' GET action in api.php.
            if (empty($store['clients']) && !$store['backfilled_once']) {
                $store['backfilled_once'] = true;
                backfillClients($store, $user['id'] ?? '');
                saveClientsStore($store);
            }
            $allJobs = getJobsStore()['jobs'];
            $allDocs = getAllDocuments();
            $allTasks = getTasksStore()['tasks'];
            $search = strtolower(trim($args['search'] ?? ''));
            $out = [];
            foreach ($store['clients'] as $c) {
                if ($search !== '' && stripos($c['name'] ?? '', $search) === false) continue;
                $rollup = clientRollup(
                    array_values(array_filter($allJobs, fn($j) => clientOwnsRecord($c, $j))),
                    array_values(array_filter($allDocs, fn($d) => clientOwnsRecord($c, $d))),
                    array_values(array_filter($allTasks, fn($t) => clientOwnsRecord($c, $t)))
                );
                $out[] = ['client_no' => $c['client_no'] ?? '', 'name' => $c['name'] ?? '', 'rollup' => $rollup];
            }
            return ['count' => count($out), 'clients' => $out];
        }

        case 'get_client_workspace': {
            $want = strtolower(trim($args['client'] ?? ''));
            if ($want === '') return ['error' => 'client name is required'];
            $match = null;
            foreach (getClientsStore()['clients'] as $c) {
                $cn = strtolower($c['name'] ?? '');
                if ($cn === $want) { $match = $c; break; }
                if ($match === null && stripos($cn, $want) !== false) $match = $c;
            }
            if (!$match) return ['error' => "No client found matching '{$args['client']}'."];
            $ws = clientWorkspace($match);
            // Trim to essentials to keep the payload small for the model.
            return [
                'client' => ['name' => $match['name'] ?? '', 'client_no' => $match['client_no'] ?? ''],
                'jobs' => array_map(fn($j) => [
                    'job_no' => $j['job_no'] ?? '', 'name' => $j['name'] ?? '',
                    'status' => $j['status'] ?? '',
                    'value' => $j['finance']['value'] ?? ($j['value'] ?? null),
                    'paid' => $j['finance']['paid'] ?? null,
                    'outstanding' => $j['finance']['outstanding'] ?? null,
                ], $ws['jobs'] ?? []),
                'documents' => array_map(fn($d) => [
                    'doc_no' => $d['doc_no'] ?? '', 'type' => $d['type'] ?? '',
                    'title' => $d['title'] ?? '', 'status' => $d['status'] ?? '',
                ], $ws['documents'] ?? []),
                'tasks' => array_map(fn($t) => [
                    'title' => $t['title'] ?? '', 'status' => $t['status'] ?? '', 'due_date' => $t['due_date'] ?? '',
                ], $ws['tasks'] ?? []),
                'invoices' => $ws['invoices'] ?? [],
            ];
        }

        case 'list_documents': {
            $docs = getAllDocuments();
            $client = strtolower(trim($args['client'] ?? ''));
            $type = strtolower(trim($args['type'] ?? ''));
            $out = [];
            foreach ($docs as $d) {
                if ($client !== '' && stripos($d['client'] ?? '', $client) === false) continue;
                if ($type !== '' && strtolower($d['type'] ?? '') !== $type) continue;
                $out[] = [
                    'doc_no' => $d['doc_no'] ?? '', 'type' => $d['type'] ?? '',
                    'title' => $d['title'] ?? '', 'client' => $d['client'] ?? '',
                    'status' => $d['status'] ?? '', 'created_at' => $d['created_at'] ?? '',
                ];
            }
            return ['count' => count($out), 'documents' => $out];
        }

        case 'list_tasks': {
            $tasks = getTasksStore()['tasks'];
            $status = strtolower(trim($args['status'] ?? ''));
            $client = strtolower(trim($args['client'] ?? ''));
            $out = [];
            foreach ($tasks as $t) {
                if ($status !== '' && strtolower($t['status'] ?? '') !== $status) continue;
                if ($client !== '' && stripos($t['client'] ?? '', $client) === false) continue;
                $out[] = [
                    'title' => $t['title'] ?? '', 'status' => $t['status'] ?? '',
                    'assignee' => $t['assignee'] ?? '', 'client' => $t['client'] ?? '',
                    'due_date' => $t['due_date'] ?? '', 'job_no' => $t['job_no'] ?? '',
                ];
            }
            return ['count' => count($out), 'tasks' => $out];
        }

        case 'list_my_leads': {
            $data = getUserData($user['id'] ?? '');
            $leads = array_filter($data['leads'] ?? [], fn($l) => empty($l['deleted_at']));
            $search = strtolower(trim($args['search'] ?? ''));
            $out = [];
            foreach ($leads as $l) {
                $hay = strtolower(($l['first_name'] ?? '') . ' ' . ($l['last_name'] ?? '') . ' ' . ($l['company'] ?? '') . ' ' . ($l['email'] ?? ''));
                if ($search !== '' && strpos($hay, $search) === false) continue;
                $out[] = [
                    'name' => trim(($l['first_name'] ?? '') . ' ' . ($l['last_name'] ?? '')),
                    'company' => $l['company'] ?? '', 'email' => $l['email'] ?? '',
                    'title' => $l['title'] ?? '',
                    'stage' => function_exists('getLeadStage') ? getLeadStage($l) : ($l['status'] ?? ''),
                    'next_action' => $l['next_action'] ?? '', 'followup_date' => $l['followup_date'] ?? '',
                ];
            }
            return ['count' => count($out), 'leads' => $out];
        }

        case 'get_document_content': {
            $want = strtoupper(trim($args['doc_no'] ?? ''));
            if ($want === '') return ['error' => 'A document number is required, e.g. SOW-0002.'];
            $doc = null;
            foreach (getAllDocuments() as $d) {
                if (strtoupper($d['doc_no'] ?? '') === $want) { $doc = $d; break; }
            }
            if (!$doc) return ['error' => "No document found with number {$want}. Use list_documents to see what exists."];

            $md = (string) ($doc['markdown'] ?? '');
            if (trim($md) === '') {
                return [
                    'doc_no' => $doc['doc_no'] ?? '', 'title' => $doc['title'] ?? '',
                    'error' => 'This document has no stored text. It may be an uploaded file (PDF/DOCX), whose contents cannot be read here.',
                    'has_uploaded_file' => !empty($doc['file_path']),
                ];
            }

            $meta = [
                'doc_no' => $doc['doc_no'] ?? '', 'type' => $doc['type'] ?? '',
                'title' => $doc['title'] ?? '', 'client' => $doc['client'] ?? '',
                'status' => $doc['status'] ?? 'draft', 'updated_at' => $doc['updated_at'] ?? ($doc['created_at'] ?? ''),
            ];

            $search = trim($args['search'] ?? '');
            if ($search !== '') {
                $hits = assistantExtractSections($md, $search);
                if ($hits === '') {
                    return $meta + ['search' => $search, 'content' => '', 'note' => "Nothing matching \"{$search}\" was found in this document."];
                }
                return $meta + ['search' => $search, 'content' => assistantTrim($hits)];
            }
            return $meta + ['content' => assistantTrim($md)];
        }

        /* ---- WRITE tools: validate + return a pending_action proposal. NEVER write here. ---- */
        case 'create_job': {
            $client = trim($args['client'] ?? '');
            $jobName = trim($args['name'] ?? '');
            if ($client === '' || $jobName === '') return ['error' => 'Both client and name are required.'];
            $ref = resolveClientRef(['client' => $client]);
            $type = ($args['type'] ?? 'one_off') === 'retainer' ? 'retainer' : 'one_off';
            $value = isset($args['value']) ? (float) $args['value'] : 0.0;
            $currency = trim($args['currency'] ?? '') ?: 'LKR';
            $spec = [
                'client' => $ref['name'] !== '' ? $ref['name'] : $client,
                'client_id' => $ref['id'],
                'name' => $jobName,
                'value' => $value,
                'currency' => $currency,
                'type' => $type,
            ];
            $summary = "Create job \"{$jobName}\" for {$spec['client']}"
                . ($value > 0 ? " ({$currency} " . number_format($value) . ", {$type})" : " ({$type})") . '.'
                . ($ref['id'] === '' ? " Note: no existing client matches \"{$client}\"; it will be saved with that typed name." : '');
            return [
                'pending_action' => ['type' => 'create_job', 'spec' => $spec, 'summary' => $summary],
                'note' => 'Prepared. Awaiting user confirmation. Do not say it is done.',
            ];
        }

        case 'create_task': {
            $title = trim($args['title'] ?? '');
            if ($title === '') return ['error' => 'A task title is required.'];
            $client = trim($args['client'] ?? '');
            $ref = $client !== '' ? resolveClientRef(['client' => $client]) : ['id' => '', 'name' => ''];
            $spec = [
                'title' => $title,
                'client' => $ref['name'] !== '' ? $ref['name'] : $client,
                'client_id' => $ref['id'],
                'assignee' => trim($args['assignee'] ?? ''),
                'due_date' => trim($args['due_date'] ?? ''),
                'notes' => trim($args['notes'] ?? ''),
            ];
            $summary = "Create task \"{$title}\""
                . ($spec['client'] !== '' ? " for {$spec['client']}" : '')
                . ($spec['assignee'] !== '' ? ", assigned to {$spec['assignee']}" : '')
                . ($spec['due_date'] !== '' ? ", due {$spec['due_date']}" : '') . '.';
            return [
                'pending_action' => ['type' => 'create_task', 'spec' => $spec, 'summary' => $summary],
                'note' => 'Prepared. Awaiting user confirmation. Do not say it is done.',
            ];
        }

        case 'mark_invoice_paid': {
            $jobNo = trim($args['job_no'] ?? '');
            $ref = trim($args['invoice_ref'] ?? '');
            if ($jobNo === '' || $ref === '') return ['error' => 'Both job_no and invoice_ref are required.'];
            [$job, $inv] = assistantFindInvoice($jobNo, $ref);
            if (!$job) return ['error' => "No job found with number {$jobNo}."];
            if (!$inv) return ['error' => "No invoice matching \"{$ref}\" on {$jobNo}. Use list_jobs to see its invoices."];
            if (($inv['status'] ?? '') === 'paid') return ['already_paid' => true, 'note' => "Invoice {$inv['invoice_no']} on {$jobNo} is already marked paid."];
            $spec = ['job_id' => $job['id'], 'invoice_id' => $inv['id']];
            $amt = number_format((float) ($inv['amount'] ?? 0));
            $label = $inv['label'] ?? ($inv['invoice_no'] ?? 'invoice');
            $summary = "Mark invoice {$inv['invoice_no']} ({$label}, {$job['currency']} {$amt}) on {$jobNo} \"{$job['name']}\" as PAID.";
            return [
                'pending_action' => ['type' => 'mark_invoice_paid', 'spec' => $spec, 'summary' => $summary],
                'note' => 'Prepared. Awaiting user confirmation. Do not say it is done.',
            ];
        }

        case 'update_job_status': {
            $jobNo = trim($args['job_no'] ?? '');
            $status = trim($args['status'] ?? '');
            $valid = ['open', 'in_progress', 'awaiting_payment', 'completed', 'cancelled'];
            if (!in_array($status, $valid, true)) return ['error' => 'Status must be one of: ' . implode(', ', $valid) . '.'];
            $job = assistantFindJob($jobNo);
            if (!$job) return ['error' => "No job found with number {$jobNo}."];
            if (($job['status'] ?? '') === $status) return ['no_change' => true, 'note' => "{$jobNo} is already {$status}."];
            $spec = ['job_id' => $job['id'], 'status' => $status];
            $summary = "Change {$jobNo} \"{$job['name']}\" status from " . ($job['status'] ?? 'open') . " to {$status}.";
            return [
                'pending_action' => ['type' => 'update_job_status', 'spec' => $spec, 'summary' => $summary],
                'note' => 'Prepared. Awaiting user confirmation. Do not say it is done.',
            ];
        }
    }
    return ['error' => "Unknown tool: {$name}"];
}

/* ============================================================
 * Lookup helpers shared by propose (runAgentTool) and execute.
 * ============================================================ */

/**
 * Cap document text fed to the model. Documents run ~11k characters (~3k tokens)
 * each, so a whole one is fine but several would crowd out the response budget.
 * Cuts on a paragraph boundary where possible and says so, rather than stopping
 * mid-sentence and letting the model quote a fragment as if it were complete.
 */
function assistantTrim(string $text, int $maxChars = 12000): string {
    $text = trim($text);
    if (strlen($text) <= $maxChars) return $text;
    $cut = substr($text, 0, $maxChars);
    $lastBreak = strrpos($cut, "\n\n");
    if ($lastBreak !== false && $lastBreak > $maxChars * 0.6) $cut = substr($cut, 0, $lastBreak);
    return rtrim($cut) . "\n\n[... document truncated. Ask about a specific section, or pass a search term, to see more.]";
}

/**
 * Pull just the markdown sections mentioning $needle, so a targeted question
 * ("what are the payment terms?") doesn't need the whole document. Splits on
 * markdown headings and keeps any section whose heading or body matches.
 */
function assistantExtractSections(string $markdown, string $needle): string {
    $lines = preg_split('/\R/', $markdown);
    $sections = [];
    $current = [];
    foreach ($lines as $line) {
        if (preg_match('/^#{1,6}\s/', $line)) {
            if (!empty($current)) $sections[] = implode("\n", $current);
            $current = [$line];
        } else {
            $current[] = $line;
        }
    }
    if (!empty($current)) $sections[] = implode("\n", $current);

    $matched = [];
    foreach ($sections as $s) {
        if (stripos($s, $needle) !== false) $matched[] = trim($s);
    }
    return implode("\n\n", $matched);
}

function assistantFindJob(string $jobNo): ?array {
    $jobNo = strtoupper(trim($jobNo));
    foreach (getJobsStore()['jobs'] as $j) {
        if (strtoupper($j['job_no'] ?? '') === $jobNo) return $j;
    }
    return null;
}

// Returns [job|null, invoice|null]. Matches invoice by invoice_no or label (case-insensitive).
function assistantFindInvoice(string $jobNo, string $ref): array {
    $job = assistantFindJob($jobNo);
    if (!$job) return [null, null];
    $ref = strtolower(trim($ref));
    foreach (($job['invoices'] ?? []) as $inv) {
        if (strtolower($inv['invoice_no'] ?? '') === $ref) return [$job, $inv];
    }
    foreach (($job['invoices'] ?? []) as $inv) {
        if (strtolower($inv['label'] ?? '') === $ref || stripos($inv['label'] ?? '', $ref) !== false) return [$job, $inv];
    }
    return [$job, null];
}

/* ============================================================
 * System prompt
 * ============================================================ */

function agentSystemPrompt(array $user): string {
    $name = $user['name'] ?? 'the user';
    $today = date('Y-m-d');
    return "You are the Levata internal AI assistant, embedded in Levata's Sales Intelligence and operations system. "
        . "You are talking to {$name}, a member of the Levata team. Today is {$today}.\n\n"
        . "You can answer questions about the team's jobs, clients, documents (Cost Proposals, SOWs, invoices), tasks, and the current user's own sales leads by calling the provided tools. "
        . "To answer questions about what a document SAYS (scope, deliverables, payment terms, timelines) use get_document_content to read its text, rather than guessing from the title. "
        . "When a document comes back truncated, say so instead of implying you read all of it. "
        . "Always call a tool to get real data before answering a data question; never invent numbers, clients, jobs, or document numbers. "
        . "If a tool returns no matching records, say so plainly rather than guessing.\n\n"
        . "You can also help with general questions and writing, using your own knowledge, when no data is needed.\n\n"
        . "You can also PROPOSE changes using the write tools (create_job, create_task, mark_invoice_paid, update_job_status). "
        . "These tools do NOT perform the change: they prepare a proposal that the user must confirm with a button in the interface. "
        . "When you call a write tool, briefly restate what will happen and tell the user to confirm below. "
        . "NEVER claim a change has been made, saved, created, or completed from calling a write tool. It is only done after the user confirms. "
        . "Before proposing mark_invoice_paid or update_job_status, look up the job first (list_jobs or get_client_workspace) so you reference the correct job number and invoice.\n\n"
        . "Style: concise, professional, British/international English, no emoji. Format money and lists clearly.";
}

/* ============================================================
 * Gemini tool-aware call (one turn of the conversation)
 * $contents is Gemini's contents[] array (roles: user / model / function).
 * Returns the raw first candidate's content part-set, or ['error'=>...].
 * ============================================================ */

function callGeminiWithTools(string $apiKey, array $contents, array $tools, string $systemPrompt): array {
    $body = [
        'system_instruction' => ['parts' => [['text' => $systemPrompt]]],
        'contents' => $contents,
        'tools' => [['function_declarations' => $tools]],
        'generationConfig' => ['temperature' => 0.3, 'maxOutputTokens' => 2048],
    ];
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
        . rawurlencode(chosenModelFor('gemini')) . ':generateContent?key=' . urlencode($apiKey);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 120,
    ]);
    $response = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($error) return ['error' => $error, 'retryable' => true];
    $r = json_decode($response, true);
    if (isset($r['error'])) {
        return [
            'error' => $r['error']['message'] ?? 'Gemini API error',
            'retryable' => llmIsRetryableStatus($status),
        ];
    }
    $parts = $r['candidates'][0]['content']['parts'] ?? null;
    if ($parts === null) return ['error' => 'No response from Gemini', 'retryable' => true];
    return ['parts' => $parts];
}

/* ============================================================
 * The agent loop. $history is prior turns as Gemini contents[]
 * (from the client, so multi-turn works). Returns
 * ['reply'=>string, 'tools_used'=>[...], 'history'=>updated contents[]].
 * ============================================================ */

/**
 * Provider order for the assistant: the configured default first, then the other
 * configured providers as fallbacks. Only providers with a key are included.
 *
 * Cerebras sits ahead of Groq by default because its free tier is far more
 * generous on tokens-per-minute, and document questions (~3k tokens each) hit
 * Groq's 12k TPM ceiling quickly.
 */
function assistantProviderChain(array $admin): array {
    $keys = [
        'cerebras' => trim($admin['cerebras_key'] ?? ''),
        'groq'     => trim($admin['groq_key'] ?? ''),
        'gemini'   => trim($admin['gemini_key'] ?? ''),
    ];
    $preferred = trim($admin['assistant_provider'] ?? '') ?: trim($admin['default_provider'] ?? '');
    $order = ['cerebras', 'groq', 'gemini'];
    if ($preferred !== '' && isset($keys[$preferred])) {
        $order = array_merge([$preferred], array_values(array_diff($order, [$preferred])));
    }
    $chain = [];
    foreach ($order as $p) {
        if ($keys[$p] !== '') $chain[] = ['provider' => $p, 'key' => $keys[$p]];
    }
    return $chain;
}

function runAssistant(string $userMessage, array $history, array $user): array {
    $admin = getAdmin();
    $chain = assistantProviderChain($admin);
    if (empty($chain)) {
        return ['error' => 'No AI provider is configured. Add a Cerebras, Groq, or Gemini API key in Admin > Settings.'];
    }

    $lastError = '';
    foreach ($chain as $i => $hop) {
        $res = ($hop['provider'] === 'gemini')
            ? runAssistantGemini($userMessage, $history, $user, $hop['key'])
            : runAssistantOpenAIStyle($hop['provider'], $userMessage, $history, $user, $hop['key']);

        if (!isset($res['error'])) {
            // Tell the client which provider answered (shown subtly in the widget).
            $res['provider'] = $hop['provider'];
            if ($i > 0) $res['fell_back_from'] = $chain[0]['provider'];
            return $res;
        }
        $lastError = $res['error'];
        // Only move to the next provider for transient failures (rate limit,
        // outage). A bad key or malformed request would fail identically
        // everywhere, so surface it immediately rather than masking it.
        if (empty($res['retryable'])) return ['error' => $lastError];
    }
    return ['error' => $lastError ?: 'All configured AI providers failed.'];
}

function runAssistantGemini(string $userMessage, array $history, array $user, string $apiKey): array {
    $tools = agentToolDeclarations();
    $system = agentSystemPrompt($user);

    // Sanitise incoming history to only the roles/shapes Gemini accepts.
    $contents = [];
    foreach ($history as $h) {
        $role = $h['role'] ?? '';
        if (($role === 'user' || $role === 'model') && !empty($h['parts'])) {
            $contents[] = ['role' => $role, 'parts' => $h['parts']];
        }
    }
    $contents[] = ['role' => 'user', 'parts' => [['text' => $userMessage]]];

    $toolsUsed = [];
    $pendingAction = null;
    for ($step = 0; $step < AGENT_MAX_STEPS; $step++) {
        $res = callGeminiWithTools($apiKey, $contents, $tools, $system);
        if (isset($res['error'])) return ['error' => $res['error'], 'retryable' => !empty($res['retryable'])];
        $parts = $res['parts'];

        // Collect any function calls in this turn, and rebuild the model turn
        // into clean parts. We must NOT echo the raw response parts back: a
        // functionCall part must be sent on its own (no sibling text/empty keys),
        // and each part must contain exactly one field, or Gemini's send-side
        // rejects it ("Proto field is not repeating").
        $calls = [];
        $textOut = '';
        $modelParts = [];
        foreach ($parts as $p) {
            if (isset($p['functionCall'])) {
                $fc = ['name' => $p['functionCall']['name'] ?? ''];
                // args is optional; only include it when present and non-empty.
                if (!empty($p['functionCall']['args']) && is_array($p['functionCall']['args'])) {
                    $fc['args'] = $p['functionCall']['args'];
                }
                $calls[] = $fc;
                $modelParts[] = ['functionCall' => $fc];
            } elseif (isset($p['text']) && $p['text'] !== '') {
                $textOut .= $p['text'];
                $modelParts[] = ['text' => $p['text']];
            }
        }
        if (empty($modelParts)) $modelParts[] = ['text' => ''];

        // Record the model's (normalised) turn so the next request has full context.
        $contents[] = ['role' => 'model', 'parts' => $modelParts];

        if (empty($calls)) {
            // No tool calls -> this is the final answer.
            $out = ['reply' => trim($textOut) !== '' ? trim($textOut) : '(no answer)', 'tools_used' => $toolsUsed, 'history' => $contents];
            if ($pendingAction !== null) $out['pending_action'] = $pendingAction;
            return $out;
        }

        // Run each requested tool and feed the results back.
        $responseParts = [];
        foreach ($calls as $call) {
            $fname = $call['name'] ?? '';
            $fargs = $call['args'] ?? [];
            $toolsUsed[] = $fname;
            $result = runAgentTool($fname, is_array($fargs) ? $fargs : [], $user);
            // Capture the FIRST proposed write; the UI renders Confirm/Cancel for it.
            // We still feed the result back so the model writes a natural summary,
            // but the write itself only happens later via assistant-execute.
            if ($pendingAction === null && isset($result['pending_action'])) {
                $pendingAction = $result['pending_action'];
            }
            $responseParts[] = [
                'functionResponse' => [
                    'name' => $fname,
                    'response' => ['result' => $result],
                ],
            ];
        }
        $contents[] = ['role' => 'user', 'parts' => $responseParts];
    }

    $out = ['error' => 'The assistant took too many steps without finishing. Please try rephrasing.'];
    if ($pendingAction !== null) { unset($out['error']); $out = ['reply' => 'I prepared the action below. Please confirm.', 'tools_used' => $toolsUsed, 'history' => $contents, 'pending_action' => $pendingAction]; }
    return $out;
}

/* ============================================================
 * GROQ agent path (OpenAI-compatible tool calling). Groq's chat/completions
 * API uses a different shape from Gemini: a flat messages[] array with roles
 * user/assistant/tool, and tools declared as {type:function, function:{...}}.
 * The tool DISPATCH (runAgentTool) and the pending_action/confirm mechanism are
 * shared with the Gemini path; only the transport differs.
 *
 * History note: to keep the client simple, the browser stores history in the
 * Gemini contents[] shape for BOTH providers. On the Groq path we convert that
 * to/from OpenAI messages[] here, and return history back in contents[] shape so
 * the widget code never has to know which provider is active.
 * ============================================================ */

// Wrap the shared tool declarations in OpenAI's {type:function, function:{...}} envelope.
function agentToolsOpenAI(): array {
    return array_map(fn($t) => ['type' => 'function', 'function' => $t], agentToolDeclarations());
}

// contents[] (Gemini shape) -> messages[] (OpenAI shape). Text-only turns; prior
// tool-call turns from earlier in the SAME server call are added natively below,
// so cross-request history only needs to carry plain user/assistant text.
function geminiHistoryToOpenAI(array $history): array {
    $msgs = [];
    foreach ($history as $h) {
        $role = $h['role'] ?? '';
        $text = '';
        foreach (($h['parts'] ?? []) as $p) { if (isset($p['text'])) $text .= $p['text']; }
        if ($text === '') continue;
        if ($role === 'user') $msgs[] = ['role' => 'user', 'content' => $text];
        elseif ($role === 'model') $msgs[] = ['role' => 'assistant', 'content' => $text];
    }
    return $msgs;
}

// One Groq chat completion. Returns the raw message object or ['error'=>...].
/**
 * Endpoint for each OpenAI-compatible provider. Cerebras and Groq speak the same
 * chat/completions + tools dialect, so one implementation serves both; only the
 * base URL and the configured model differ.
 */
function openAIStyleEndpoint(string $provider): string {
    switch ($provider) {
        case 'cerebras': return 'https://api.cerebras.ai/v1/chat/completions';
        case 'groq':
        default:         return 'https://api.groq.com/openai/v1/chat/completions';
    }
}

function callOpenAIStyleWithTools(string $provider, string $apiKey, array $messages, array $tools, string $systemPrompt): array {
    $payload = [
        'model' => chosenModelFor($provider),
        'messages' => array_merge([['role' => 'system', 'content' => $systemPrompt]], $messages),
        'tools' => $tools,
        'tool_choice' => 'auto',
        'temperature' => 0.3,
        'max_tokens' => 2048,
    ];
    $ch = curl_init(openAIStyleEndpoint($provider));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
        CURLOPT_TIMEOUT => 120,
    ]);
    $response = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($error) return ['error' => $error, 'retryable' => true];
    $r = json_decode($response, true);
    // Error shapes differ: Groq/OpenAI nest under "error", Cerebras returns
    // {"message":...,"type":"invalid_request_error"} at the top level. Treat any
    // non-2xx as an error so a bad key is never mistaken for a transient blip.
    $isError = isset($r['error']) || $status < 200 || $status >= 300;
    if ($isError) {
        $detail = $r['error']['message'] ?? ($r['message'] ?? null);
        // 429 (rate limit) and 5xx are worth retrying on another provider;
        // a 401/400 is a real configuration problem and should surface as-is.
        return [
            'error' => ucfirst($provider) . ': ' . ($detail ?: ('API error (HTTP ' . $status . ')')),
            'retryable' => llmIsRetryableStatus($status),
        ];
    }
    $msg = $r['choices'][0]['message'] ?? null;
    if ($msg === null) return ['error' => 'No response from ' . ucfirst($provider), 'retryable' => true];
    return ['message' => $msg];
}

function runAssistantOpenAIStyle(string $provider, string $userMessage, array $history, array $user, string $apiKey): array {
    $tools = agentToolsOpenAI();
    $system = agentSystemPrompt($user);

    $messages = geminiHistoryToOpenAI($history);
    $messages[] = ['role' => 'user', 'content' => $userMessage];

    $toolsUsed = [];
    $pendingAction = null;

    for ($step = 0; $step < AGENT_MAX_STEPS; $step++) {
        $res = callOpenAIStyleWithTools($provider, $apiKey, $messages, $tools, $system);
        if (isset($res['error'])) {
            // Carry 'retryable' up so runAssistant can fail over to the next provider.
            return ['error' => $res['error'], 'retryable' => !empty($res['retryable'])];
        }
        $msg = $res['message'];
        $toolCalls = $msg['tool_calls'] ?? [];

        // Record the assistant turn (with any tool_calls) so follow-up requests have context.
        $assistantTurn = ['role' => 'assistant', 'content' => $msg['content'] ?? ''];
        if (!empty($toolCalls)) $assistantTurn['tool_calls'] = $toolCalls;
        $messages[] = $assistantTurn;

        if (empty($toolCalls)) {
            $reply = trim((string) ($msg['content'] ?? ''));
            $out = [
                'reply' => $reply !== '' ? $reply : '(no answer)',
                'tools_used' => $toolsUsed,
                'history' => openAIHistoryToGemini($messages),
            ];
            if ($pendingAction !== null) $out['pending_action'] = $pendingAction;
            return $out;
        }

        // Execute each tool call and append a tool result message per call.
        foreach ($toolCalls as $tc) {
            $fname = $tc['function']['name'] ?? '';
            $rawArgs = $tc['function']['arguments'] ?? '{}';
            $fargs = json_decode(is_string($rawArgs) ? $rawArgs : '{}', true);
            if (!is_array($fargs)) $fargs = [];
            $toolsUsed[] = $fname;
            $result = runAgentTool($fname, $fargs, $user);
            if ($pendingAction === null && isset($result['pending_action'])) {
                $pendingAction = $result['pending_action'];
            }
            $messages[] = [
                'role' => 'tool',
                'tool_call_id' => $tc['id'] ?? '',
                'name' => $fname,
                'content' => json_encode(['result' => $result], JSON_UNESCAPED_UNICODE),
            ];
        }
    }

    $out = ['error' => 'The assistant took too many steps without finishing. Please try rephrasing.'];
    if ($pendingAction !== null) {
        $out = ['reply' => 'I prepared the action below. Please confirm.', 'tools_used' => $toolsUsed, 'history' => openAIHistoryToGemini($messages), 'pending_action' => $pendingAction];
    }
    return $out;
}

// messages[] (OpenAI) -> contents[] (Gemini shape) for the client. Only plain
// user/assistant text is carried across requests (tool-call scaffolding is
// per-request), so the two providers share one client-side history format.
function openAIHistoryToGemini(array $messages): array {
    $contents = [];
    foreach ($messages as $m) {
        $role = $m['role'] ?? '';
        $content = is_string($m['content'] ?? '') ? trim($m['content']) : '';
        if ($content === '') continue;
        if ($role === 'user') $contents[] = ['role' => 'user', 'parts' => [['text' => $content]]];
        elseif ($role === 'assistant') $contents[] = ['role' => 'model', 'parts' => [['text' => $content]]];
    }
    return $contents;
}

/* ============================================================
 * Execute a confirmed write. Called by the `assistant-execute` action AFTER
 * the user clicks Confirm. Re-validates and reuses the app's own write helpers
 * (same code paths as the save-job / save-task / save-invoice endpoints) so the
 * agent can never do anything a normal user action couldn't. The client sends
 * back the action TYPE and SPEC we proposed; we re-resolve everything by id/no
 * server-side rather than trusting client-supplied display values.
 * Returns ['success'=>true, 'message'=>...] or ['error'=>...].
 * ============================================================ */

function executeAssistantAction(string $type, array $spec, array $user): array {
    switch ($type) {
        case 'create_job': {
            $store = getJobsStore();
            $now = date('c');
            $job = [
                'id' => 'job_' . bin2hex(random_bytes(8)),
                'job_no' => nextJobNo($store),
                'created_by' => $user['id'] ?? '',
                'created_at' => $now,
                'updated_at' => $now,
                'invoices' => [],
            ];
            $inputLike = [
                'client'    => $spec['client'] ?? '',
                'client_id' => $spec['client_id'] ?? '',
                'name'      => $spec['name'] ?? '',
                'value'     => $spec['value'] ?? 0,
                'currency'  => $spec['currency'] ?? 'LKR',
                'type'      => $spec['type'] ?? 'one_off',
            ];
            $job = applyJobFields($job, $inputLike);
            $job['invoices'] = buildJobInvoices($store, $job, $inputLike);
            $store['jobs'][] = $job;
            saveJobsStore($store);
            return ['success' => true, 'message' => "Created {$job['job_no']} \"{$job['name']}\" for {$job['client']}."];
        }

        case 'create_task': {
            $title = trim($spec['title'] ?? '');
            if ($title === '') return ['error' => 'Task title is required.'];
            $store = getTasksStore();
            [$store, $id] = nextTaskId($store);
            $store['tasks'][] = [
                'id'         => $id,
                'title'      => $title,
                'assignee'   => trim($spec['assignee'] ?? ''),
                'client'     => trim($spec['client'] ?? ''),
                'client_id'  => trim($spec['client_id'] ?? ''),
                'job_id'     => '',
                'job_no'     => '',
                'due_date'   => trim($spec['due_date'] ?? ''),
                'notes'      => trim($spec['notes'] ?? ''),
                'status'     => 'open',
                'source'     => 'assistant',
                'created_at' => date('c'),
                'created_by' => $user['id'] ?? '',
            ];
            saveTasksStore($store);
            return ['success' => true, 'message' => "Created task \"{$title}\"."];
        }

        case 'mark_invoice_paid': {
            $jobId = trim($spec['job_id'] ?? '');
            $invId = trim($spec['invoice_id'] ?? '');
            $store = getJobsStore();
            $done = null;
            foreach ($store['jobs'] as &$job) {
                if (($job['id'] ?? '') !== $jobId) continue;
                // Iterate the REAL invoices array by reference. Using `$job['invoices'] ?? []`
                // as the foreach expression would reference a throwaway copy, so the write
                // would be lost. Ensure the key exists, then reference it directly.
                if (!isset($job['invoices']) || !is_array($job['invoices'])) $job['invoices'] = [];
                foreach ($job['invoices'] as &$inv) {
                    if (($inv['id'] ?? '') === $invId) {
                        $inv['status'] = 'paid';
                        $inv['paid_at'] = $inv['paid_at'] ?? date('c');
                        $done = ($inv['invoice_no'] ?? 'invoice') . ' on ' . ($job['job_no'] ?? '');
                        break;
                    }
                }
                unset($inv);
                $job['updated_at'] = date('c');
                break;
            }
            unset($job);
            if ($done === null) return ['error' => 'That invoice no longer exists.'];
            saveJobsStore($store);
            return ['success' => true, 'message' => "Marked {$done} as paid."];
        }

        case 'update_job_status': {
            $jobId = trim($spec['job_id'] ?? '');
            $status = trim($spec['status'] ?? '');
            $valid = ['open', 'in_progress', 'awaiting_payment', 'completed', 'cancelled'];
            if (!in_array($status, $valid, true)) return ['error' => 'Invalid status.'];
            $store = getJobsStore();
            $found = null;
            foreach ($store['jobs'] as &$job) {
                if (($job['id'] ?? '') === $jobId) {
                    $job['status'] = $status;
                    $job['updated_at'] = date('c');
                    $found = $job['job_no'] ?? '';
                    break;
                }
            }
            unset($job);
            if ($found === null) return ['error' => 'That job no longer exists.'];
            saveJobsStore($store);
            return ['success' => true, 'message' => "Set {$found} to {$status}."];
        }
    }
    return ['error' => "Unknown action type: {$type}"];
}
