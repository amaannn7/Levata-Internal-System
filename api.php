<?php
/**
 * Levata Sales Intelligence API
 * B2B sales outreach platform (Levata)
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
// LLM calls can take 60-120 seconds for large documents — extend execution time.
set_time_limit(180);
ini_set('max_execution_time', 180);

header('Content-Type: application/json');

// CORS: restrict to known origins
$allowedOrigins = ['https://levataos.com', 'https://www.levataos.com', 'http://localhost:8000', 'http://localhost:8080'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins)) {
    header('Access-Control-Allow-Origin: ' . $origin);
} else {
    header('Access-Control-Allow-Origin: https://levataos.com');
}
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-User-Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit(0);

define('DATA_DIR', __DIR__ . '/data');

if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0755, true);

// PostgreSQL connection + schema bootstrap. All app data lives in Postgres;
// data/ is now only used for uploaded document files (PDF/DOCX) and rate-limit state.
require_once __DIR__ . '/db.php';

// A "user" is a plain assoc array everywhere in this app. Known fields are real
// columns on the `users` table; anything else (tokens[], reset_token, reset_expires,
// onboarding_completed, …) rides along in the `data` JSONB column and is merged
// back on read so existing array-mutation call sites keep working unchanged.
define('USER_COLUMNS', ['id', 'name', 'email', 'password', 'is_admin', 'is_super_admin', 'created_at', 'last_login_at', 'session_start', 'last_active_at']);

// Document Studio: SOW generation + shared infra.
require_once __DIR__ . '/sow.php';
// Document Studio: Cost Proposal generation (depends on sow.php).
require_once __DIR__ . '/cp.php';
// Meeting Minutes generation (depends on sow.php for LLM + ClickUp plumbing).
require_once __DIR__ . '/minutes.php';
// Job Registry (shared company-wide jobs/invoices) — added module.
require_once __DIR__ . '/jobs.php';
// Leads/pipeline (shared company-wide store; every lead carries owner_id).
require_once __DIR__ . '/leads.php';
require_once __DIR__ . '/fx.php';
// Task system (shared company-wide tasks; extraction depends on minutes.php LLM plumbing).
require_once __DIR__ . '/tasks.php';
// Clients (shared company-wide client registry; aggregates jobs/documents/tasks).
require_once __DIR__ . '/clients.php';
// Channel partners (shared company-wide referral-partner directory).
require_once __DIR__ . '/partners.php';
// Help & Support (feedback + support tickets) — added module.
require_once __DIR__ . '/support.php';
// Team Chat (channels + DMs) — added module.
require_once __DIR__ . '/chat.php';
// AI Assistant (Gemini function-calling agent over app data) — added module.
require_once __DIR__ . '/chatbot.php';

// Rate limiting
function checkRateLimit($identifier, $maxRequests = 120, $windowSeconds = 60) {
    $rateFile = DATA_DIR . '/.rate_limits.json';
    $limits = file_exists($rateFile) ? json_decode(file_get_contents($rateFile), true) : [];
    $now = time();
    $windowStart = $now - $windowSeconds;

    // Clean old entries for this identifier
    $limits[$identifier] = array_values(array_filter($limits[$identifier] ?? [], function($ts) use ($windowStart) {
        return $ts > $windowStart;
    }));

    if (count($limits[$identifier]) >= $maxRequests) {
        header('HTTP/1.1 429 Too Many Requests');
        echo json_encode(['success' => false, 'error' => 'Rate limit exceeded. Please try again later.']);
        exit;
    }

    $limits[$identifier][] = $now;

    // Clean stale identifiers older than 2 minutes
    foreach ($limits as $key => $timestamps) {
        $limits[$key] = array_values(array_filter($timestamps, function($ts) use ($windowStart) {
            return $ts > $windowStart;
        }));
        if (empty($limits[$key])) unset($limits[$key]);
    }

    file_put_contents($rateFile, json_encode($limits));
}

$rateLimitKey = ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . ':' . ($_SERVER['HTTP_X_USER_TOKEN'] ?? 'anon');
checkRateLimit($rateLimitKey);

// Input sanitization utility
function sanitizeInput($value) {
    if (is_array($value)) return array_map('sanitizeInput', $value);
    if (!is_string($value)) return $value;
    return htmlspecialchars(strip_tags(trim($value)), ENT_QUOTES, 'UTF-8');
}

$defaultRequisitions = [
    ['id' => 'business_type', 'title' => 'Business Type', 'subtitle' => 'What kind of customer or channel are they?', 'type' => 'single', 'options' => ['Startup / Founder', 'SME', 'Agency', 'Enterprise', 'Non-profit', 'Other'], 'enabled' => true],
    ['id' => 'project_context', 'title' => 'Project Context', 'subtitle' => 'What type of work are they discussing?', 'type' => 'single', 'options' => ['Website', 'Software / system', 'Mobile app', 'Branding / design', 'Content', 'Other'], 'enabled' => true],
    ['id' => 'current_solution', 'title' => 'Current Solution', 'subtitle' => 'How do they handle this today?', 'type' => 'single', 'options' => ['In-house', 'Another agency / vendor', 'Freelancers', 'No solution yet', 'Unclear'], 'enabled' => true],
    ['id' => 'primary_need', 'title' => 'Primary Need', 'subtitle' => 'What is our strongest angle?', 'type' => 'single', 'options' => ['Quality', 'Price / Value', 'Speed', 'Design', 'Technical capability', 'Support'], 'enabled' => true],
    ['id' => 'timeline', 'title' => 'Timeline', 'subtitle' => 'When could this move?', 'type' => 'single', 'options' => ['Immediate', '1-3 Months', '3-6 Months', 'Future / Nurture', 'Unknown'], 'enabled' => true],
    ['id' => 'decision_role', 'title' => 'Decision Role', 'subtitle' => 'What role do they play in supplier choice?', 'type' => 'single', 'options' => ['Owner / Founder', 'Director', 'Designer / Specifier', 'Procurement', 'Site / Project Manager', 'Influencer', 'Not Decision-maker'], 'enabled' => true],
    ['id' => 'project_size_volume', 'title' => 'Project Size / Volume', 'subtitle' => 'How meaningful is the opportunity?', 'type' => 'single', 'options' => ['One-off', 'Small Recurring', 'Medium Recurring', 'High Volume', 'Unknown'], 'enabled' => true],
    ['id' => 'next_step_agreed', 'title' => 'Next Step Agreed', 'subtitle' => 'What did they agree to after the conversation?', 'type' => 'single', 'options' => ['Send Proposal', 'Book Consultation', 'Call Back', 'Nurture', 'Not Interested'], 'enabled' => true],
    ['id' => 'notes_objections', 'title' => 'Notes / Objections', 'subtitle' => 'Capture objections, buying signals, or specific project details.', 'type' => 'text', 'options' => [], 'enabled' => true]
];

// The services a lead can be tagged with on the New Lead stage. Feeds both
// the Services checkboxes and the prompt for generate-qualification-questions
// (the selected service name goes straight into the AI prompt as the thing
// to ask about). "Other" is handled separately in the UI as a free-text
// field (services_other on the lead), not a list entry here.
$defaultServiceOptions = ['Website Development', 'Sales Intelligence System', 'Custom Services'];

// Default ICP configuration
$defaultIcpConfig = [
    'enabled' => true,
    'scoring_weights' => [
        'segment_match' => 25,
        'decision_authority' => 15,
        'project_type' => 15,
        'project_size' => 10,
        'timeline_urgency' => 15,
        'location' => 10,
        'active_project_evidence' => 5,
        'company_stability' => 5
    ],
    'ideal_segments' => ['Startup / Founder', 'SME', 'Agency', 'Enterprise'],
    'later_segments' => ['Other'],
    'target_geographies' => [],
    'brand_positioning' => [
        'company_description' => 'Design and engineering studio building websites, software systems, and brand identities',
        'proof_points' => []
    ]
];

// Default stage validation rules
$defaultStageRules = [
    'stage_1_min_score' => 60,
    'stage_2_min_score' => 60,
    'stage_3_min_score' => 60,
    'grade_a_threshold' => 80,
    'grade_b_threshold' => 60,
    'grade_c_threshold' => 40
];

// Default outreach rules
$defaultOutreachRules = [
    'grade_a_channels' => ['email', 'linkedin', 'call'],
    'grade_b_channels' => ['email'],
    'grade_c_action' => 'nurture',
    'max_sequence_steps' => 4,
    'email_cadence_days' => ['initial' => 1, 'followup1' => 4, 'followup2' => 9, 'breakup' => 15],
    'call_outcomes' => ['no_answer_retry', 'left_voicemail', 'gatekeeper', 'wrong_number', 'not_interested', 'interested_followup', 'consultation_booked', 'callback_requested', 'not_right_time_park_90']
];

if (dbGetBlob('admin_config', null) === null) {
    dbSaveBlob('admin_config', [
        'groq_key' => '',
        'gemini_key' => '',
        'anthropic_key' => '',
        'default_provider' => 'groq',
        'requisitions' => $defaultRequisitions,
        'icp_config' => $defaultIcpConfig,
        'stage_validation_rules' => $defaultStageRules,
        'outreach_rules' => $defaultOutreachRules
    ]);
}

// Gated on an explicit 'install_seeded' flag (not just "users table is empty")
// so that a users table emptied after the real install — a bad migration, a
// failed restore, any future bulk-delete path — can never silently recreate
// admin@levatahq.com / password (a public default credential, granted
// super-admin) on the next request with no log or warning.
$installSeeded = dbGetBlob('admin_config', []);
if (($installSeeded['install_seeded'] ?? false) !== true && db()->query('SELECT COUNT(*) FROM users')->fetchColumn() == 0) {
    $adminId = 'user_' . bin2hex(random_bytes(8));
    $defaultAdmin = [
        'id' => $adminId,
        'name' => 'Admin',
        'email' => 'admin@levatahq.com',
        'password' => password_hash('password', PASSWORD_DEFAULT),
        'tokens' => [bin2hex(random_bytes(32))],
        'created_at' => date('c'),
        'is_admin' => true,
        'is_super_admin' => true,
    ];
    saveUsers([$defaultAdmin]);
    saveUserData($adminId, [
        'leads' => [],
        'settings' => ['sender_name' => 'Admin', 'sender_company' => 'Levata', 'sender_title' => '', 'company_description' => '', 'value_proposition' => '', 'social_proof' => '', 'calendar_link' => '', 'email_tone' => 'professional', 'signature' => '']
    ]);
    $installSeeded['install_seeded'] = true;
    dbSaveBlob('admin_config', $installSeeded);
}

// One-time migration: leads used to live in each user's own 'user_data:<id>'
// blob; they now live in the shared 'leads' store (leads.php) so Owner can
// be a real, team-visible field. Runs once (gated on the 'leads' blob not
// existing yet, the same "row missing, not list empty" idiom used elsewhere
// in this file), reads every user's OLD per-user blob directly (bypassing
// getUserData(), which now sources leads from the NEW store — reading
// through it here would just see nothing yet), stamps owner_id from each
// blob's own user id, and writes the merged result once. The old per-user
// blobs are left untouched afterward as a safety net; nothing reads their
// 'leads' key anymore once this has run.
if (dbGetBlob('leads', null) === null) {
    $migratedLeads = [];
    foreach (db()->query('SELECT id FROM users')->fetchAll(PDO::FETCH_COLUMN) as $existingUserId) {
        $oldBlob = dbGetBlob("user_data:{$existingUserId}", null);
        foreach (($oldBlob['leads'] ?? []) as $oldLead) {
            if (empty($oldLead['owner_id'])) $oldLead['owner_id'] = $existingUserId;
            $migratedLeads[] = $oldLead;
        }
    }
    dbSaveBlob('leads', ['leads' => $migratedLeads]);
}

function dbRowToUser($row) {
    $extra = json_decode($row['data'] ?? '{}', true) ?: [];
    $u = array_merge($extra, [
        'id' => $row['id'],
        'name' => $row['name'],
        'email' => $row['email'],
        'password' => $row['password'],
        'is_admin' => (bool) $row['is_admin'],
        'is_super_admin' => (bool) $row['is_super_admin'],
        'created_at' => $row['created_at'],
        'last_login_at' => $row['last_login_at'],
        'session_start' => $row['session_start'],
        'last_active_at' => $row['last_active_at'],
    ]);
    return $u;
}

function getUsers() {
    $rows = db()->query('SELECT * FROM users ORDER BY created_at ASC')->fetchAll();
    return array_map('dbRowToUser', $rows);
}

function saveUsers($users) {
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $ids = [];
        foreach ($users as $u) {
            $id = $u['id'] ?? null;
            if ($id === null) continue;
            $ids[] = $id;
            $extra = array_diff_key($u, array_flip(USER_COLUMNS));
            $pdo->prepare("
                INSERT INTO users (id, name, email, password, is_admin, is_super_admin, created_at, last_login_at, session_start, last_active_at, data)
                VALUES (:id, :name, :email, :password, :is_admin::boolean, :is_super_admin::boolean, :created_at, :last_login_at, :session_start, :last_active_at, :data::jsonb)
                ON CONFLICT (id) DO UPDATE SET
                    name = EXCLUDED.name, email = EXCLUDED.email, password = EXCLUDED.password,
                    is_admin = EXCLUDED.is_admin, is_super_admin = EXCLUDED.is_super_admin,
                    last_login_at = EXCLUDED.last_login_at, session_start = EXCLUDED.session_start,
                    last_active_at = EXCLUDED.last_active_at, data = EXCLUDED.data
            ")->execute([
                ':id' => $id,
                ':name' => $u['name'] ?? '',
                ':email' => $u['email'] ?? '',
                ':password' => $u['password'] ?? '',
                ':is_admin' => !empty($u['is_admin']) ? 'true' : 'false',
                ':is_super_admin' => !empty($u['is_super_admin']) ? 'true' : 'false',
                ':created_at' => $u['created_at'] ?? date('c'),
                ':last_login_at' => $u['last_login_at'] ?? null,
                ':session_start' => $u['session_start'] ?? null,
                ':last_active_at' => $u['last_active_at'] ?? null,
                ':data' => json_encode($extra, JSON_UNESCAPED_UNICODE),
            ]);
        }
        if (!empty($ids)) {
            $ph = implode(',', array_map(fn($i) => ":del{$i}", array_keys($ids)));
            $stmt = $pdo->prepare("DELETE FROM users WHERE id NOT IN ({$ph})");
            foreach ($ids as $i => $id) $stmt->bindValue(":del{$i}", $id);
            $stmt->execute();
        } else {
            $pdo->exec('DELETE FROM users');
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// ── Activity pings (session analytics: heartbeat log used to reconstruct login sessions) ──
// Backed by the real `activity_pings` table — see dbRecordPing()/dbLoadPings() in db.php.
function recordActivityPing($userId, $userName, $userRole, $page) {
    dbRecordPing($userId, $userName, $userRole, $page);
}

function loadActivityPings($days) {
    return dbLoadPings((int) $days);
}

/**
 * THE deal pipeline. One linear path from first contact to a registered job:
 *   lead -> qualified -> demo -> cost_proposal -> sow -> won  (or lost, from anywhere)
 *
 * Every stage carries a deal amount, which is expected to change as the deal
 * firms up (a guess at `lead`, a real number by `cost_proposal`). `order` drives
 * the funnel and the "furthest stage reached" logic; `lost` sits outside the
 * ladder (order 99) because it is an exit, not a step forward.
 *
 * `legacy` maps back onto the old status strings that older records, the Zoho
 * export and a few UI filters still speak.
 */
function getMacktilesStages() {
    return [
        'lead'          => ['label' => 'Lead',            'legacy' => 'new',        'order' => 1],
        'qualified'     => ['label' => 'Qualified',       'legacy' => 'qualified',  'order' => 2],
        'demo'          => ['label' => 'Demo/Discussion', 'legacy' => 'qualified',  'order' => 3],
        'cost_proposal' => ['label' => 'Cost Proposal',   'legacy' => 'qualified',  'order' => 4],
        'sow'           => ['label' => 'SOW',             'legacy' => 'qualified',  'order' => 5],
        'won'           => ['label' => 'Won',             'legacy' => 'qualified',  'order' => 6],
        'lost'          => ['label' => 'Lost',            'legacy' => 'disqualified','order' => 99]
    ];
}

/** Numeric position of a stage on the ladder (lost = 99). */
function stageOrder($stage) {
    return getMacktilesStages()[$stage]['order'] ?? 1;
}

/**
 * Coerce anything that was ever written into `stage`/`status` onto the pipeline.
 * The old nine-stage model and the original legacy statuses both collapse in
 * here: everything pre-qualification is simply "Lead", since the outreach steps
 * (research/email/call) are activity on a lead, not pipeline positions.
 */
function legacyStatusToStage($status) {
    $map = [
        // Current statuses
        'new' => 'lead',
        'qualified' => 'qualified',
        'disqualified' => 'lost',
        'won' => 'won',
        'lost' => 'lost',
        // Retired nine-stage model
        'new_lead' => 'lead',
        'research' => 'lead',
        'researched' => 'lead',
        'email_sent' => 'lead',
        'call_due' => 'lead',
        'call_attempted' => 'lead',
        'outcome_logged' => 'lead',
        'contacted' => 'lead',
        'replied' => 'qualified',
        'engaged' => 'qualified',
        'consultation_booked' => 'demo',
        'meeting_booked' => 'demo',
        'nurture_parked' => 'lost',
        'not_interested' => 'lost'
    ];
    return $map[$status ?: 'new'] ?? (isset(getMacktilesStages()[$status]) ? $status : 'lead');
}

function stageToLegacyStatus($stage) {
    $stages = getMacktilesStages();
    return $stages[$stage]['legacy'] ?? 'new';
}

function normalizeLeadSource($source) {
    $source = strtolower(trim($source ?: 'firmable'));
    $map = [
        'csv' => 'firmable',
        'firmable database' => 'firmable',
        'booking' => 'inbound',
        'google_ads' => 'inbound',
        'meta_ads' => 'inbound',
        'offline' => 'manual',
        'trade_show' => 'manual',
        'showroom' => 'manual',
        'referral' => 'manual'
    ];
    $source = $map[$source] ?? $source;
    return in_array($source, ['firmable', 'inbound', 'manual', 'zoho', 'other']) ? $source : 'manual';
}

function initialStageForSource($source, $warm = false) {
    $source = normalizeLeadSource($source);
    // A warm manual add is someone we've already spoken to, so it starts qualified.
    if ($source === 'manual' && $warm) return 'qualified';
    return 'lead';
}

/** Coerce a deal amount to a float (strip currency symbols, commas, spaces). */
function dealMoney($v) {
    if (is_numeric($v)) return (float) $v;
    $clean = preg_replace('/[^0-9.\-]/', '', (string) $v);
    return $clean === '' ? 0.0 : (float) $clean;
}

/** A commission rate can never be negative; a percentage additionally can
 * never exceed 100 (a fixed amount has no such ceiling — there's no upper
 * bound on a flat currency payout). Applied at every write site so a bad
 * value can never reach storage regardless of which UI control sent it. */
function clampPartnerRateValue($rateType, $value) {
    $v = dealMoney($value);
    if ($v < 0) $v = 0.0;
    if ($rateType === 'percentage' && $v > 100) $v = 100.0;
    return $v;
}

/**
 * Pull a deal amount out of a CP/SOW document's free-text investment field
 * (e.g. "LKR 450,000") — used to keep a linked deal's value in sync with
 * whatever figure is actually on the document, since a rep negotiating in
 * the CP/SOW is the real source of truth for "what this deal is worth" once
 * a document exists. Only the number is taken; the deal's own currency is
 * left as-is (the investment field is freeform text, not a reliable place
 * to parse a currency code from). Retainer-engagement documents have no
 * single investment figure (setupFee + recurring retainerAmount instead),
 * so those return null rather than a misleading partial number.
 */
function extractDocumentInvestmentAmount($docInput) {
    if (!is_array($docInput)) return null;
    if (($docInput['engagementType'] ?? '') === 'retainer') return null;
    $raw = trim($docInput['investment'] ?? '');
    if ($raw === '') return null;
    return dealMoney($raw);
}

/**
 * Currencies the studio can quote in. Deals, jobs and invoices each store their
 * own code, so an overseas client can be quoted in USD while local work stays
 * in LKR. Money is NEVER converted between them — totals are grouped by code
 * (see `sumByCurrency()`), because a stale exchange rate would silently make
 * finance figures wrong.
 */
function supportedCurrencies() {
    return ['LKR', 'USD', 'EUR', 'GBP', 'AUD', 'AED', 'INR', 'SGD', 'CAD'];
}

/** The studio's default currency (Admin → Settings), used for new records. */
function defaultCurrency() {
    $c = strtoupper(trim(getAdmin()['default_currency'] ?? ''));
    return in_array($c, supportedCurrencies(), true) ? $c : 'LKR';
}

/** Validate a currency code, falling back to the studio default. */
function normalizeCurrency($c) {
    $c = strtoupper(trim((string) $c));
    return in_array($c, supportedCurrencies(), true) ? $c : defaultCurrency();
}

/**
 * Sum amounts grouped by currency code. Returns e.g. ['LKR' => 2400000.0,
 * 'USD' => 12000.0]. Callers render each code separately rather than adding
 * unlike currencies together.
 */
function sumByCurrency($rows, $amountKey = 'amount', $currencyKey = 'currency') {
    $out = [];
    foreach ($rows as $r) {
        $cur = normalizeCurrency($r[$currencyKey] ?? '');
        $out[$cur] = ($out[$cur] ?? 0.0) + (float) ($r[$amountKey] ?? 0);
    }
    return $out;
}

/** Add one amount into a by-currency bucket map. */
function addToCurrencyBucket(&$bucket, $currency, $amount) {
    $cur = normalizeCurrency($currency);
    $bucket[$cur] = ($bucket[$cur] ?? 0.0) + (float) $amount;
}

/**
 * Set a lead's deal amount, recording every change so the value history is
 * auditable (a deal legitimately changes value as it moves down the pipeline).
 * Returns true if the amount actually changed.
 */
function setDealAmount(&$lead, $amount, $stage = '', $actor = null) {
    $new = dealMoney($amount);
    $old = dealMoney($lead['deal_amount'] ?? 0);
    if (abs($new - $old) < 0.005) return false;
    if (!isset($lead['deal_amount_history']) || !is_array($lead['deal_amount_history'])) {
        $lead['deal_amount_history'] = [];
    }
    $lead['deal_amount_history'][] = [
        'from' => $old,
        'to' => $new,
        'stage' => $stage ?: getLeadStage($lead),
        'actor' => $actor,
        'timestamp' => date('c')
    ];
    $lead['deal_amount'] = $new;
    $lead['updated_at'] = date('c');
    return true;
}

function getLeadStage($lead) {
    return legacyStatusToStage($lead['stage'] ?? ($lead['status'] ?? 'new'));
}

function setLeadStage(&$lead, $stage, $reason = '', $actor = null) {
    $stage = legacyStatusToStage($stage);
    $oldStage = getLeadStage($lead);
    if (!isset($lead['stage_history']) || !is_array($lead['stage_history'])) $lead['stage_history'] = [];
    if ($oldStage !== $stage || empty($lead['stage_entered_at'])) {
        $lead['stage_history'][] = [
            'from' => $oldStage,
            'to' => $stage,
            'reason' => $reason,
            'actor' => $actor,
            'timestamp' => date('c')
        ];
        $lead['stage_entered_at'] = date('c');
    }
    $lead['stage'] = $stage;
    $lead['status'] = stageToLegacyStatus($stage);
    // Track the furthest point reached so a deal that goes back a step (or is
    // lost) still reports how far it actually got.
    $peak = $lead['peak_stage'] ?? $stage;
    if (stageOrder($stage) !== 99 && stageOrder($stage) >= stageOrder($peak)) $peak = $stage;
    $lead['peak_stage'] = $peak;
    if ($stage === 'won' && empty($lead['won_at'])) $lead['won_at'] = date('c');
    if ($stage === 'lost' && empty($lead['lost_at'])) $lead['lost_at'] = date('c');
    $lead['updated_at'] = date('c');
}

/**
 * Validates/normalizes email, LinkedIn URL and website for a lead — shared
 * by both lead creation and the Profile-tab edit path (update-lead) so an
 * edited email can't bypass the same checks a newly created one gets.
 * Responds with a 400 directly on a hard validation failure (mirrors the
 * original inline behavior in the create path); returns the normalized
 * website value (https:// prepended if missing) for the caller to store.
 */
function validateLeadContactFields($email, $linkedin, $website, $phone = null) {
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        respond(['success' => false, 'error' => 'Invalid email format. Please enter a valid email address.'], 400);
    }
    if ($linkedin !== '' && stripos($linkedin, 'linkedin.com') === false) {
        respond(['success' => false, 'error' => 'Invalid LinkedIn URL. Please enter a valid LinkedIn profile URL.'], 400);
    }
    // Phone: requires a leading "+" country code (no separate country-code
    // picker UI exists, so this is the simplest way to make sure one is
    // actually present) followed by 8-15 digits, with the punctuation real
    // numbers use (spaces, dashes, parens, dots) allowed in between.
    if ($phone !== null && $phone !== '') {
        $digitCount = strlen(preg_replace('/\D/', '', $phone));
        if (!preg_match('/^\+[0-9 ()\-.]+$/', $phone) || $digitCount < 8 || $digitCount > 15) {
            respond(['success' => false, 'error' => 'Invalid phone number. Include the country code, e.g. +1 555 123 4567.'], 400);
        }
    }
    if ($website !== '' && !preg_match('/^https?:\/\/|^www\./i', $website)) {
        $website = 'https://' . $website;
    }
    // A website must resolve to a real host with a dot (a TLD) once the
    // scheme is normalized above — catches plain typed garbage ("asdf")
    // that would otherwise sail through as a "valid" URL once prefixed.
    if ($website !== '') {
        $host = parse_url($website, PHP_URL_HOST);
        if (!$host || strpos($host, '.') === false) {
            respond(['success' => false, 'error' => 'Invalid website. Please enter a valid website address.'], 400);
        }
    }
    return $website;
}

/**
 * Fetches a company's homepage and reduces it to plain text worth handing
 * an LLM — title, meta description, and visible body copy, truncated to a
 * few thousand characters (a full page HTML dump would blow the prompt
 * budget and mostly consists of markup/scripts the model doesn't need).
 * There's no browsing tool wired into callLLM(), so this is the only way
 * the research briefing sees anything from the actual site rather than
 * guessing from the company name and industry alone.
 *
 * Best-effort: many sites block non-browser requests, time out, or return
 * something unparseable — any of that just returns '' so the caller falls
 * back to company+industry only, never surfaces a fetch error to the rep.
 */
function fetchWebsiteTextForResearch($url) {
    if (!$url) return '';
    if (!preg_match('#^https?://#i', $url)) $url = 'https://' . $url;
    if (!filter_var($url, FILTER_VALIDATE_URL)) return '';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; LevataResearchBot/1.0)',
        CURLOPT_HTTPHEADER     => ['Accept: text/html'],
    ]);
    $html = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (!$html || $code < 200 || $code >= 300) return '';

    $title = '';
    if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) $title = trim(html_entity_decode(strip_tags($m[1])));
    $description = '';
    if (preg_match('/<meta\s+[^>]*name=["\']description["\'][^>]*content=["\'](.*?)["\']/is', $html, $m)) {
        $description = trim(html_entity_decode($m[1]));
    }

    // Strip script/style blocks first so their contents don't leak into the
    // "visible body copy" — strip_tags() alone would keep JS/CSS text.
    $body = preg_replace('#<(script|style|noscript)\b[^>]*>.*?</\1>#is', ' ', $html);
    $body = strip_tags($body);
    $body = html_entity_decode($body, ENT_QUOTES);
    $body = preg_replace('/\s+/', ' ', $body);
    $body = trim($body);
    $body = mb_substr($body, 0, 3000);

    $parts = [];
    if ($title) $parts[] = "Page title: {$title}";
    if ($description) $parts[] = "Meta description: {$description}";
    if ($body) $parts[] = "Page text: {$body}";
    return implode("\n", $parts);
}

/**
 * True once a Lead-stage deal has services, at least one qualification
 * question answered (not necessarily all — answering every single
 * question was too heavy a bar), and an engagement method — the gate for
 * leaving the Lead stage forward. Questions are the fixed per-service sets
 * (SERVICE_QUALIFICATION_FORMS in index.html), not AI-generated, so this
 * only checks that `requisitions` has at least one non-empty answer rather
 * than cross-referencing a question list. Mirrors leadQualifyChecklistDone()
 * in index.html; keep both in sync if the checklist rules change.
 */
function leadQualifyChecklistDone($lead) {
    $services = $lead['services'] ?? [];
    if (empty($services) && empty($lead['services_other'])) return false;
    $answers = $lead['requisitions'] ?? [];
    if (!is_array($answers) || empty($answers)) return false;
    $hasAnswer = false;
    foreach ($answers as $v) {
        if (is_array($v) ? !empty($v) : (!empty($v) || $v === '0')) { $hasAnswer = true; break; }
    }
    if (!$hasAnswer) return false;
    if (empty($lead['engagement_method'])) return false;
    return true;
}

/**
 * True once a Demo-stage deal has at least one requirement-gathering
 * checklist item answered (not necessarily all), a transcript on file, and
 * all 3 feasibility checks (legal, functional, financial) ticked —
 * feasibility stays a hard yes/no per item since each one is a real
 * go/no-go signal, not busywork. Does NOT check for a linked cost proposal —
 * that's enforced separately (linking a CP is itself what advances the
 * stage, via link-deal-document), so this only gates whether the "Create
 * Cost Proposal" handoff is allowed to start. Mirrors
 * leadDemoChecklistDone() in index.html; keep both in sync.
 */
function leadDemoChecklistDone($lead) {
    $checklist = $lead['demo_checklist'] ?? [];
    if (empty($checklist)) return false;
    $answers = $lead['demo_checklist_answers'] ?? [];
    if (!is_array($answers) || empty($answers)) return false;
    if (empty(trim($lead['demo_transcript'] ?? ''))) return false;
    $feasibility = $lead['demo_feasibility'] ?? [];
    foreach (['legal', 'functional', 'financial'] as $f) {
        if (empty($feasibility[$f])) return false;
    }
    return true;
}

function normalizeLeadForMapping($lead) {
    $source = normalizeLeadSource($lead['source'] ?? $lead['import_source'] ?? $lead['lead_source'] ?? 'firmable');
    $stage = getLeadStage($lead);
    $lead['source'] = $source;
    $lead['source_detail'] = $lead['source_detail'] ?? ($lead['lead_source'] ?? '');
    $lead['assigned_to'] = $lead['assigned_to'] ?? null;
    $lead['stage'] = $stage;
    $lead['status'] = stageToLegacyStatus($stage);
    $lead['stage_entered_at'] = $lead['stage_entered_at'] ?? ($lead['last_action_at'] ?? $lead['created_at'] ?? date('c'));
    $lead['stage_history'] = $lead['stage_history'] ?? [];
    $lead['urgency_flag'] = $lead['urgency_flag'] ?? ($source === 'inbound' ? 'high' : 'normal');
    $lead['call_history'] = $lead['call_history'] ?? [];
    $lead['rejection_reason'] = $lead['rejection_reason'] ?? ($lead['disqualified_reason'] ?? '');
    $lead['consultation_type'] = $lead['consultation_type'] ?? '';
    // Deal fields — every stage carries an amount, and the pipeline links out to
    // the documents/job it produced.
    $lead['deal_amount'] = dealMoney($lead['deal_amount'] ?? 0);
    $lead['deal_currency'] = normalizeCurrency($lead['deal_currency'] ?? '');
    $lead['deal_amount_history'] = $lead['deal_amount_history'] ?? [];
    $lead['peak_stage'] = $lead['peak_stage'] ?? $stage;
    $lead['client_id'] = $lead['client_id'] ?? '';
    $lead['cost_proposal_id'] = $lead['cost_proposal_id'] ?? '';
    $lead['sow_id'] = $lead['sow_id'] ?? '';
    $lead['job_id'] = $lead['job_id'] ?? '';
    $lead['job_no'] = $lead['job_no'] ?? '';
    // Channel partner — a deal either came to us directly (partner_id empty)
    // or through a referral partner. Rate is a snapshot taken when the partner
    // was attached, so changing the partner's default rate later doesn't
    // retroactively change what's owed on deals already in flight.
    $lead['partner_id'] = $lead['partner_id'] ?? '';
    $lead['partner_rate_type'] = ($lead['partner_rate_type'] ?? 'percentage') === 'fixed' ? 'fixed' : 'percentage';
    $lead['partner_rate_value'] = clampPartnerRateValue($lead['partner_rate_type'], $lead['partner_rate_value'] ?? 0);
    return $lead;
}

function macktilesCallOutcomeConfig($outcome) {
    $config = [
        // Outcomes that leave the deal where it is stay on 'lead'; the call is
        // logged as activity either way.
        'no_answer_retry' => ['label' => 'No answer, will retry', 'stage' => 'lead', 'next_action' => 'followup_date'],
        'left_voicemail' => ['label' => 'No answer, left voicemail', 'stage' => 'lead', 'next_action' => 'followup_date'],
        'gatekeeper' => ['label' => 'Gatekeeper, could not reach decision-maker', 'stage' => 'lead', 'next_action' => 'followup_date'],
        'wrong_number' => ['label' => 'Wrong number / person no longer at company', 'stage' => 'lost', 'next_action' => 'disqualify'],
        'not_interested' => ['label' => 'Not interested, asked not to be contacted', 'stage' => 'lost', 'next_action' => 'disqualify'],
        'interested_followup' => ['label' => 'Interested, follow-up scheduled', 'stage' => 'qualified', 'next_action' => 'followup_date'],
        'consultation_booked' => ['label' => 'Demo / discussion booked', 'stage' => 'demo', 'next_action' => 'qualify_zoho'],
        'callback_requested' => ['label' => 'Call back requested, date/time noted', 'stage' => 'lead', 'next_action' => 'followup_date'],
        'not_right_time_park_90' => ['label' => 'Spoke, not the right time; park for 90 days', 'stage' => 'lost', 'next_action' => 'park']
    ];
    return $config[$outcome] ?? null;
}
function getAdmin() {
    global $defaultRequisitions, $defaultServiceOptions, $defaultIcpConfig, $defaultStageRules, $defaultOutreachRules;
    $admin = dbGetBlob('admin_config', []);
    if (!isset($admin['service_options'])) $admin['service_options'] = $defaultServiceOptions;
    if (!isset($admin['requisitions'])) $admin['requisitions'] = $defaultRequisitions;
    // One-time migration to the v1 discovery question set, gated on the
    // version stamp rather than list contents — an admin who deliberately
    // clears or edits requisitions down to an empty/different list (which
    // save-admin also stamps with this version) must NOT get overwritten
    // back to defaults on the next request.
    if (($admin['requisitions_version'] ?? '') !== 'sales_discovery_v1') {
        if (!isset($admin['legacy_requisitions_backup'])) {
            $admin['legacy_requisitions_backup'] = $admin['requisitions'] ?? [];
        }
        $admin['requisitions'] = $defaultRequisitions;
        $admin['requisitions_version'] = 'sales_discovery_v1';
        saveAdmin($admin);
    }
    if (!isset($admin['icp_config'])) $admin['icp_config'] = $defaultIcpConfig;
    if (!isset($admin['stage_validation_rules'])) $admin['stage_validation_rules'] = $defaultStageRules;
    if (!isset($admin['outreach_rules'])) $admin['outreach_rules'] = $defaultOutreachRules;
    return $admin;
}
function saveAdmin($admin) {
    dbSaveBlob('admin_config', $admin);
}

/**
 * Leads live in the shared 'leads' store (leads.php), not in this per-user
 * blob — this function still returns them under $data['leads'] so every
 * existing caller (getUserData($id)['leads']) keeps working unchanged, just
 * scoped to this user's own leads via owner_id. Settings remain genuinely
 * per-user, stored in this blob as before.
 */
function getUserData($userId) {
    $defaultSettings = ['sender_name' => '', 'sender_company' => 'Levata', 'sender_title' => '', 'company_description' => '', 'value_proposition' => '', 'social_proof' => '', 'calendar_link' => '', 'email_tone' => 'professional', 'signature' => ''];
    $blobName = "user_data:{$userId}";
    $existing = dbGetBlob($blobName, null);
    if ($existing === null) {
        dbSaveBlob($blobName, ['settings' => $defaultSettings]);
        $data = ['settings' => $defaultSettings];
    } else {
        $data = $existing;
        if (!isset($data['settings'])) $data['settings'] = $defaultSettings;
    }

    $ownLeads = array_values(array_filter(getLeadsStore()['leads'], fn($l) => ($l['owner_id'] ?? '') === $userId));

    // Migrate existing leads with default values for new fields — unchanged
    // logic, just now running over the shared store's leads for this owner
    // instead of a per-user blob array.
    if (!empty($ownLeads)) {
        $changed = false;
        foreach ($ownLeads as &$lead) {
            // Original fields
            $lead['fit_grade'] = $lead['fit_grade'] ?? '';
            $lead['fit_score'] = $lead['fit_score'] ?? 0;
            $lead['grade_override'] = $lead['grade_override'] ?? null;
            $lead['calls_made'] = $lead['calls_made'] ?? 0;
            $lead['email_history'] = $lead['email_history'] ?? [];
            $lead['emails_sent'] = $lead['emails_sent'] ?? 0;

            // Proactive Intelligence fields (Phase 1)
            $lead['engagement_score'] = $lead['engagement_score'] ?? 0;
            $lead['temperature'] = $lead['temperature'] ?? 'cold';
            $lead['velocity'] = $lead['velocity'] ?? 'stalled';
            $lead['last_score_update'] = $lead['last_score_update'] ?? null;
            $lead['activities'] = $lead['activities'] ?? [];
            $lead['skipped_until'] = $lead['skipped_until'] ?? null;

            // Notification tracking
            $lead['last_notification_at'] = $lead['last_notification_at'] ?? null;

            $before = json_encode($lead);
            $lead = normalizeLeadForMapping($lead);
            $changed = $changed || ($before !== json_encode($lead));
        }
        unset($lead);
        if ($changed) {
            $data['leads'] = $ownLeads;
            saveUserData($userId, $data);
            return $data;
        }
    }
    $data['leads'] = $ownLeads;
    return $data;
}

/**
 * Splits the write: 'settings' (genuinely per-user) goes to this user's own
 * blob as before; 'leads' goes into the shared leads store (leads.php),
 * replacing this user's own leads within that store's full array and
 * leaving every other owner's leads untouched. Every existing call site
 * (saveUserData($id, $data) after mutating $data['leads']) keeps working
 * unchanged.
 */
function saveUserData($userId, $data) {
    if (array_key_exists('leads', $data)) {
        $leadsStore = getLeadsStore();
        $others = array_values(array_filter($leadsStore['leads'], fn($l) => ($l['owner_id'] ?? '') !== $userId));
        $mine = array_map(function ($l) use ($userId) {
            if (empty($l['owner_id'])) $l['owner_id'] = $userId;
            return $l;
        }, $data['leads']);
        $leadsStore['leads'] = array_merge($others, $mine);
        saveLeadsStore($leadsStore);
    }
    $settingsOnly = $data;
    unset($settingsOnly['leads']);
    dbSaveBlob("user_data:{$userId}", $settingsOnly);
}
function generateId($prefix = '') { return $prefix . bin2hex(random_bytes(8)); }
function generateToken() { return bin2hex(random_bytes(32)); }
function logActivity(&$lead, $type, $detail, $extra = []) {
    if (!isset($lead['activity_log'])) $lead['activity_log'] = [];
    $lead['activity_log'][] = array_merge([
        'type' => $type,
        'detail' => $detail,
        'timestamp' => date('c')
    ], $extra);
}

// Default stage fields for new leads (used by CSV import)
function getDefaultStageFields() {
    return [
        'fit_grade' => '',
        'fit_score' => 0,
        'grade_override' => null,
        'calls_made' => 0,
        'email_history' => [],
        'emails_sent' => 0,
        // Proactive Intelligence fields
        'engagement_score' => 0,
        'temperature' => 'cold',
        'velocity' => 'stalled',
        'last_score_update' => null,
        'activities' => [],
        'skipped_until' => null,
        'last_notification_at' => null
    ];
}

// Calculate ICP grade based on lead data and admin config
function calculateLeadGrade($lead, $admin, $enrichmentData = null) {
    $score = 0;
    $factors = [];
    $disqualifiers = [];
    $icpConfig = $admin['icp_config'] ?? [];
    $weights = $icpConfig['scoring_weights'] ?? [];
    $req = $lead['requisitions'] ?? [];
    if (is_string($req)) $req = json_decode($req, true) ?: [];

    // Parse enrichment if provided as string
    if ($enrichmentData === null && !empty($lead['enrichment'])) {
        $enrichmentData = json_decode($lead['enrichment'], true);
    }

    $segment = $req['business_type'] ?? $lead['industry'] ?? '';
    $idealSegments = $icpConfig['ideal_segments'] ?? ['Startup / Founder', 'SME', 'Agency', 'Enterprise'];
    $laterSegments = $icpConfig['later_segments'] ?? ['Other'];
    foreach ($idealSegments as $ideal) {
        if (stripos($segment, $ideal) !== false || stripos($lead['industry'] ?? '', $ideal) !== false) {
            $score += $weights['segment_match'] ?? 25;
            $factors[] = 'Primary ICP segment';
            break;
        }
    }
    if (!$factors) {
        foreach ($laterSegments as $later) {
            if (stripos($segment, $later) !== false || stripos($lead['industry'] ?? '', $later) !== false) {
                $score += round(($weights['segment_match'] ?? 25) * 0.4);
                $factors[] = 'Later-priority segment';
                break;
            }
        }
    }

    $authority = strtolower($req['decision_authority'] ?? $lead['title'] ?? '');
    if (preg_match('/owner|director|sole|founder|managing|decision/', $authority)) {
        $score += $weights['decision_authority'] ?? 15;
        $factors[] = 'Decision-maker signal';
    } elseif (preg_match('/purchasing|procurement|manager|specifier|architect|designer|influencer/', $authority)) {
        $score += round(($weights['decision_authority'] ?? 15) * 0.7);
        $factors[] = 'Influencer or purchasing signal';
    } elseif (strpos($authority, 'unknown') !== false || empty($authority)) {
        $score += round(($weights['decision_authority'] ?? 15) * 0.35);
        $factors[] = 'Authority unknown';
    }

    $projectType = strtolower($req['project_type'] ?? '');
    if (preg_match('/renovation|new build|commercial|design/', $projectType)) {
        $score += $weights['project_type'] ?? 15;
        $factors[] = 'Qualified project type';
    }

    $projectSize = strtolower($req['project_size'] ?? '');
    if (preg_match('/1-2|3\\+|multiple|larger/', $projectSize)) {
        $score += $weights['project_size'] ?? 10;
        $factors[] = 'Serviceable project size';
    }

    $timeline = strtolower($req['timeline_urgency'] ?? '');
    if (strpos($timeline, 'within 1 week') !== false) {
        $score += round(($weights['timeline_urgency'] ?? 15) * 0.35);
        $factors[] = 'Urgent timeline needs stock check';
    } elseif (strpos($timeline, '2-8') !== false || strpos($timeline, '2+ months') !== false) {
        $score += $weights['timeline_urgency'] ?? 15;
        $factors[] = 'Workable project timeline';
    }

    $location = strtolower($req['location'] ?? $lead['country'] ?? '');
    if (strpos($location, 'melbourne') !== false) {
        $score += $weights['location'] ?? 10;
        $factors[] = 'Melbourne priority geography';
    } elseif (strpos($location, 'victoria') !== false || strpos($location, 'vic') !== false) {
        $score += round(($weights['location'] ?? 10) * 0.7);
        $factors[] = 'Victoria regional geography';
    } elseif (strpos($location, 'interstate') !== false) {
        $score += round(($weights['location'] ?? 10) * 0.25);
        $factors[] = 'Interstate deprioritised at launch';
    }

    $evidence = strtolower($req['active_project_evidence'] ?? '');
    if (preg_match('/confirmed|public|active/', $evidence)) {
        $score += $weights['active_project_evidence'] ?? 5;
        $factors[] = 'Active project evidence';
    }

    $stability = strtolower($req['company_stability'] ?? '');
    if (strpos($stability, 'risk') !== false) {
        $disqualifiers[] = 'Company stability risk';
    } else {
        $score += $weights['company_stability'] ?? 5;
    }

    if (strpos($timeline, 'within 1 week') !== false && stripos($req['project_size'] ?? '', '3+') !== false) {
        $disqualifiers[] = 'Immediate larger supply need may exceed current stock capacity';
    }
    if (stripos($segment, 'Other') !== false) {
        $disqualifiers[] = 'Wrong or unclear segment';
    }

    // Determine grade based on thresholds
    $stageRules = $admin['stage_validation_rules'] ?? [];
    $gradeAThreshold = $stageRules['grade_a_threshold'] ?? 80;
    $gradeBThreshold = $stageRules['grade_b_threshold'] ?? 60;
    $gradeCThreshold = $stageRules['grade_c_threshold'] ?? 40;

    if ($disqualifiers && $score < $gradeBThreshold) {
        $grade = 'Disqualified';
    } elseif ($score >= $gradeAThreshold) {
        $grade = 'A';
    } elseif ($score >= $gradeBThreshold) {
        $grade = 'B';
    } elseif ($score >= $gradeCThreshold) {
        $grade = 'C';
    } else {
        $grade = 'Disqualified';
    }

    return ['grade' => $grade, 'score' => max(0, min(100, $score)), 'factors' => $factors, 'disqualifiers' => $disqualifiers];
}

// ============ PROACTIVE INTELLIGENCE SCORING ============

// Calculate engagement score based on lead activities and status
function calculateEngagementScore($lead) {
    $score = 0;
    $breakdown = [];

    // Points for research completed
    if (!empty($lead['enrichment'])) {
        $score += 10;
        $breakdown['research'] = 10;
    }

    // Points for emails sent
    $emailsSent = intval($lead['emails_sent'] ?? 0);
    if ($emailsSent > 0) {
        $emailPoints = min(20, $emailsSent * 10); // Max 20 points for emails
        $score += $emailPoints;
        $breakdown['emails'] = $emailPoints;
    }

    // Points for calls made
    $callsMade = intval($lead['calls_made'] ?? 0);
    if ($callsMade > 0) {
        $callPoints = min(25, $callsMade * 15); // Max 25 points for calls
        $score += $callPoints;
        $breakdown['calls'] = $callPoints;
    }

    // Points for positive call outcomes
    $positiveOutcomes = ['interested_followup', 'callback_requested', 'consultation_booked', 'meeting_booked', 'qualified'];
    if (in_array($lead['call_outcome'] ?? '', $positiveOutcomes)) {
        $score += 25;
        $breakdown['positive_outcome'] = 25;
    }

    // Points for qualified status
        if (in_array(getLeadStage($lead), ['demo', 'cost_proposal', 'sow', 'won'])) {
            $score += 30;
            $breakdown['qualified'] = 30;
        }

    // Decay for inactivity
    $daysSinceActivity = calculateDaysSinceLastActivity($lead);
    if ($daysSinceActivity > 7) {
        $weeksInactive = floor($daysSinceActivity / 7);
        $decay = min(30, $weeksInactive * 5); // Max -30 decay
        $score -= $decay;
        $breakdown['decay'] = -$decay;
    }

    return [
        'score' => max(0, min(100, $score)),
        'breakdown' => $breakdown
    ];
}

// Calculate days since last activity on a lead
function calculateDaysSinceLastActivity($lead) {
    $lastActivityDate = null;

    // Check last_action_at
    if (!empty($lead['last_action_at'])) {
        $lastActivityDate = strtotime($lead['last_action_at']);
    }

    // Check activities array for most recent
    if (!empty($lead['activities']) && is_array($lead['activities'])) {
        foreach ($lead['activities'] as $activity) {
            $actTime = strtotime($activity['timestamp'] ?? '');
            if ($actTime && (!$lastActivityDate || $actTime > $lastActivityDate)) {
                $lastActivityDate = $actTime;
            }
        }
    }

    // Check email history
    if (!empty($lead['email_history']) && is_array($lead['email_history'])) {
        foreach ($lead['email_history'] as $email) {
            $emailTime = strtotime($email['sent_at'] ?? '');
            if ($emailTime && (!$lastActivityDate || $emailTime > $lastActivityDate)) {
                $lastActivityDate = $emailTime;
            }
        }
    }

    // Check followup_date if it's in the past (means we should have done something)
    if (!empty($lead['followup_date'])) {
        $followupTime = strtotime($lead['followup_date']);
        if ($followupTime && $followupTime < time() && (!$lastActivityDate || $followupTime > $lastActivityDate)) {
            $lastActivityDate = $followupTime;
        }
    }

    if (!$lastActivityDate) {
        // If no activity found, use created_at or default to 30 days
        $lastActivityDate = strtotime($lead['created_at'] ?? '-30 days');
    }

    return max(0, floor((time() - $lastActivityDate) / 86400));
}

// Calculate SLA status for a lead based on current stage
function calculateSLAStatus($lead) {
    $slaRules = [
        'lead' => ['max_days' => 3, 'next_action' => 'Research, contact and qualify this lead', 'action_type' => 'research'],
        'qualified' => ['max_days' => 7, 'next_action' => 'Book the demo or discussion call', 'action_type' => 'consultation'],
        'demo' => ['max_days' => 7, 'next_action' => 'Run the demo, then build the cost proposal', 'action_type' => 'cost_proposal'],
        'cost_proposal' => ['max_days' => 10, 'next_action' => 'Chase the cost proposal decision, then raise the SOW', 'action_type' => 'sow'],
        'sow' => ['max_days' => 10, 'next_action' => 'Get the SOW signed and mark the deal won', 'action_type' => 'close']
    ];

    $status = getLeadStage($lead);
    $daysSinceAction = calculateDaysSinceLastActivity($lead);

    if (($lead['source'] ?? '') === 'inbound' && !empty($lead['created_at']) && $status === 'lead') {
        $hoursSinceCreated = floor((time() - strtotime($lead['created_at'])) / 3600);
        return [
            'stage' => $status,
            'days_in_stage' => $daysSinceAction,
            'sla_days' => 0,
            'is_overdue' => $hoursSinceCreated > 2,
            'urgency' => $hoursSinceCreated > 2 ? 'critical' : 'high',
            'next_action' => 'Call inbound booking lead within 2 hours',
            'action_type' => 'call',
            'hours_remaining' => max(0, 2 - $hoursSinceCreated)
        ];
    }

    if (in_array($status, ['won', 'lost'])) {
        return [
            'stage' => $status,
            'days_in_stage' => $daysSinceAction,
            'sla_days' => null,
            'is_overdue' => false,
            'urgency' => 'complete',
            'next_action' => null,
            'action_type' => null
        ];
    }

    $rule = $slaRules[$status] ?? $slaRules['lead'];
    $maxDays = $rule['max_days'];
    $isOverdue = $daysSinceAction > $maxDays;

    // Determine urgency
    $urgency = 'on_track';
    if ($isOverdue) {
        $urgency = $daysSinceAction > ($maxDays * 2) ? 'critical' : 'overdue';
    } elseif ($daysSinceAction >= $maxDays - 1) {
        $urgency = 'due_soon';
    }

    return [
        'stage' => $status,
        'days_in_stage' => $daysSinceAction,
        'sla_days' => $maxDays,
        'is_overdue' => $isOverdue,
        'urgency' => $urgency,
        'next_action' => $rule['next_action'],
        'action_type' => $rule['action_type'],
        'days_remaining' => max(0, $maxDays - $daysSinceAction)
    ];
}

// Calculate temperature based on fit + engagement + recency
function calculateTemperature($fitScore, $engagementScore, $daysSinceActivity, $lead = null) {
    $combined = ($fitScore * 0.4) + ($engagementScore * 0.6);

    // Apply time decay: reduce combined score based on inactivity
    if ($daysSinceActivity > 7) {
        $decayFactor = max(0.3, 1 - (($daysSinceActivity - 7) * 0.05));
        $combined *= $decayFactor;
    }

    // Boost/penalize based on email outcomes if available
    if ($lead && !empty($lead['email_history'])) {
        $lastEmail = end($lead['email_history']);
        $outcome = $lastEmail['outcome'] ?? '';
        if ($outcome === 'replied' || $outcome === 'meeting_booked') {
            $combined = min(100, $combined + 20);
        } elseif ($outcome === 'bounced') {
            $combined = max(0, $combined - 30);
        }
        // Count consecutive no-responses
        $noResponseStreak = 0;
        foreach (array_reverse($lead['email_history']) as $eh) {
            if (($eh['outcome'] ?? '') === 'no_response') $noResponseStreak++;
            else break;
        }
        if ($noResponseStreak >= 3) {
            $combined = max(0, $combined - 20);
        }
    }

    if ($combined >= 80 && $daysSinceActivity <= 3) {
        return 'on_fire';
    } elseif ($combined >= 60 && $daysSinceActivity <= 7) {
        return 'hot';
    } elseif ($combined >= 40 || $daysSinceActivity <= 14) {
        return 'warm';
    } else {
        return 'cold';
    }
}

// Calculate velocity (activity trend)
function calculateVelocity($lead) {
    $activities = $lead['activities'] ?? [];
    $daysSinceActivity = calculateDaysSinceLastActivity($lead);

    // If no activity in 14+ days, it's stalled
    if ($daysSinceActivity >= 14) {
        return 'stalled';
    }

    // Count activities in last 7 days vs previous 7 days
    $now = time();
    $sevenDaysAgo = $now - (7 * 86400);
    $fourteenDaysAgo = $now - (14 * 86400);

    $recentCount = 0;
    $previousCount = 0;

    // Count from activities array
    foreach ($activities as $activity) {
        $actTime = strtotime($activity['timestamp'] ?? '');
        if ($actTime >= $sevenDaysAgo) {
            $recentCount++;
        } elseif ($actTime >= $fourteenDaysAgo) {
            $previousCount++;
        }
    }

    // Also count emails as activities
    $emailHistory = $lead['email_history'] ?? [];
    foreach ($emailHistory as $email) {
        $emailTime = strtotime($email['sent_at'] ?? '');
        if ($emailTime >= $sevenDaysAgo) {
            $recentCount++;
        } elseif ($emailTime >= $fourteenDaysAgo) {
            $previousCount++;
        }
    }

    // If no previous activity to compare, check if there's recent activity
    if ($previousCount == 0) {
        return $recentCount > 0 ? 'accelerating' : 'stalled';
    }

    // Calculate change percentage
    $changeRatio = $recentCount / max(1, $previousCount);

    if ($changeRatio >= 1.5) {
        return 'accelerating';
    } elseif ($changeRatio >= 0.75) {
        return 'stable';
    } elseif ($changeRatio >= 0.5) {
        return 'slowing';
    } else {
        return 'stalled';
    }
}

// Master function to recalculate all scores for a lead
function recalculateAllLeadScores($lead, $admin) {
    // Calculate fit score using existing function
    $fitResult = calculateLeadGrade($lead, $admin);

    // Calculate engagement score
    $engagementResult = calculateEngagementScore($lead);

    // Calculate days since last activity
    $daysSinceActivity = calculateDaysSinceLastActivity($lead);

    // Calculate temperature
    $temperature = calculateTemperature($fitResult['score'], $engagementResult['score'], $daysSinceActivity, $lead);

    // Calculate velocity
    $velocity = calculateVelocity($lead);

    return [
        'fit_grade' => $fitResult['grade'],
        'fit_score' => $fitResult['score'],
        'engagement_score' => $engagementResult['score'],
        'temperature' => $temperature,
        'velocity' => $velocity,
        'last_score_update' => date('c'),
        'scoring_factors' => [
            'fit_breakdown' => ['score' => $fitResult['score'], 'grade' => $fitResult['grade']],
            'engagement_breakdown' => $engagementResult['breakdown'],
            'last_activity_days' => $daysSinceActivity,
            'activity_trend' => $velocity
        ]
    ];
}

// Log an activity for a lead
function logLeadActivity($lead, $type, $details = null) {
    $activities = $lead['activities'] ?? [];
    $activities[] = [
        'id' => 'act_' . bin2hex(random_bytes(8)),
        'type' => $type,
        'timestamp' => date('c'),
        'details' => $details
    ];
    return $activities;
}

// ============================================================================
// PROACTIVE INTELLIGENCE HELPER FUNCTIONS
// ============================================================================

/**
 * Generate a prioritized focus queue of leads requiring action
 * Priority scoring considers temperature, overdue status, velocity, and recency
 */
function generateFocusQueue($leads, $admin, $limit = 10) {
    $now = time();
    $queue = [];

    foreach ($leads as $lead) {
        $stage = getLeadStage($lead);
        if (in_array($stage, ['won', 'lost'])) continue;
        $fitGrade = strtolower($lead['fit_grade'] ?? '');
        if ($fitGrade === 'disqualified' && $stage !== 'lost') continue;

        // Skip leads that are snoozed (skipped_until)
        if (!empty($lead['skipped_until']) && strtotime($lead['skipped_until']) > $now) continue;

        // Calculate priority score
        $priorityScore = 0;
        $reason = '';
        $suggestedAction = '';
        $urgency = 'normal';

        // Source/SLA priority from the Levata mapping.
        if (($lead['source'] ?? '') === 'inbound' && $stage === 'lead') {
            $priorityScore += 250;
            $reason = 'Inbound booking lead - call within 2 hours';
            $suggestedAction = 'call';
            $urgency = 'critical';
        }

        // Deals closer to signature are worth more attention than cold leads.
        $stageScores = [
            'sow' => 70,
            'cost_proposal' => 60,
            'demo' => 45,
            'qualified' => 35,
            'lead' => 20
        ];
        $priorityScore += $stageScores[$stage] ?? 0;
        $tempScores = ['on_fire' => 20, 'hot' => 12, 'warm' => 6, 'cold' => 0];
        $temp = $lead['temperature'] ?? 'cold';
        $priorityScore += $tempScores[$temp] ?? 0;
        // A bigger deal outranks a smaller one at the same stage (capped so value
        // never fully drowns out urgency).
        $priorityScore += min(40, dealMoney($lead['deal_amount'] ?? 0) / 25000);

        // Overdue bonus (+50) - followup date has passed
        $isOverdue = false;
        if (!empty($lead['followup_date'])) {
            $followupTime = strtotime($lead['followup_date']);
            if ($followupTime && $followupTime < $now) {
                $priorityScore += 50;
                $isOverdue = true;
                $urgency = 'high';
                $daysOverdue = floor(($now - $followupTime) / 86400);
                $reason = "Callback overdue by {$daysOverdue} day(s)";
                $suggestedAction = 'call';
            } elseif ($followupTime && $followupTime <= strtotime('today 23:59:59')) {
                $priorityScore += 30;
                $reason = "Callback due today";
                $suggestedAction = 'call';
                $urgency = 'high';
            }
        }

        // Velocity penalty (stalled=-30, slowing=-15)
        $velocity = $lead['velocity'] ?? 'stalled';
        if ($velocity === 'stalled') {
            $priorityScore -= 15; // Less severe penalty - stalled leads may still be worth pursuing
        } elseif ($velocity === 'slowing') {
            $priorityScore -= 10;
        } elseif ($velocity === 'accelerating') {
            $priorityScore += 20;
        }

        // Recency bonus (+20 if activity in 3 days)
        $daysSinceActivity = calculateDaysSinceLastActivity($lead);
        if ($daysSinceActivity <= 3) {
            $priorityScore += 20;
        }

        // Determine suggested action based on canonical stage
        $status = $stage;
        if (empty($reason)) {
            switch ($status) {
                case 'lead':
                    // Within Lead, the next step depends on how far outreach got.
                    if (empty($lead['enrichment'])) {
                        $reason = "New lead - needs research";
                        $suggestedAction = 'research';
                    } elseif ((int) ($lead['emails_sent'] ?? 0) === 0) {
                        $reason = "Research complete - ready for outreach";
                        $suggestedAction = 'email';
                        $priorityScore += 15;
                    } elseif ($daysSinceActivity >= 3) {
                        $reason = "Email sent {$daysSinceActivity} days ago - follow up";
                        $suggestedAction = 'followup';
                    } else {
                        $reason = "Waiting for response";
                        $suggestedAction = 'wait';
                        $priorityScore -= 20;
                    }
                    break;
                case 'qualified':
                    $outcome = $lead['call_outcome'] ?? '';
                    if (in_array($outcome, ['no_answer_retry', 'callback_requested', 'left_voicemail'])) {
                        $reason = "Last call: {$outcome} - retry";
                        $suggestedAction = 'call';
                    } else {
                        $reason = "Qualified - book the demo or discussion";
                        $suggestedAction = 'consultation';
                    }
                    break;
                case 'demo':
                    $reason = "Demo booked - run it, then build the cost proposal";
                    $suggestedAction = 'consultation';
                    $priorityScore += 40;
                    $urgency = 'high';
                    break;
                case 'cost_proposal':
                    $reason = empty($lead['cost_proposal_id'])
                        ? "Cost proposal stage - generate the CP"
                        : "Cost proposal sent - chase the decision";
                    $suggestedAction = 'cost_proposal';
                    $priorityScore += 45;
                    $urgency = 'high';
                    break;
                case 'sow':
                    $reason = empty($lead['sow_id'])
                        ? "CP accepted - raise the SOW"
                        : "SOW issued - chase signature to close";
                    $suggestedAction = 'sow';
                    $priorityScore += 50;
                    $urgency = 'critical';
                    break;
                default:
                    $reason = "Review needed";
                    $suggestedAction = 'review';
            }
        }

        // Calculate SLA status
        $slaStatus = calculateSLAStatus($lead);

        // Override urgency if SLA is critical
        if ($slaStatus['urgency'] === 'critical') {
            $urgency = 'critical';
        } elseif ($slaStatus['urgency'] === 'overdue' && $urgency === 'normal') {
            $urgency = 'high';
        }

        $queue[] = [
            'lead_id' => $lead['id'],
            'name' => trim(($lead['first_name'] ?? '') . ' ' . ($lead['last_name'] ?? '')),
            'company' => $lead['company'] ?? '',
            'title' => $lead['title'] ?? '',
            'source' => $lead['source'] ?? '',
            'temperature' => $temp,
            'velocity' => $velocity,
            'fit_grade' => $lead['fit_grade'] ?? '',
            'status' => stageToLegacyStatus($stage),
            'stage' => $stage,
            'priority_score' => $priorityScore,
            'suggested_action' => $suggestedAction,
            'reason' => $reason,
            'urgency' => $urgency,
            'days_since_activity' => $daysSinceActivity,
            'followup_date' => $lead['followup_date'] ?? null,
            'is_overdue' => $isOverdue,
            'sla' => $slaStatus
        ];
    }

    // Sort by priority score descending
    usort($queue, function($a, $b) {
        return $b['priority_score'] - $a['priority_score'];
    });

    // Return top N
    return array_slice($queue, 0, $limit);
}

/**
 * Find leads that need immediate attention (going cold, overdue, stalled)
 */
function findAttentionNeeded($leads) {
    $now = time();
    $attention = [];

    foreach ($leads as $lead) {
        if (in_array(getLeadStage($lead), ['won', 'lost'])) continue;

        $issues = [];
        $temp = $lead['temperature'] ?? 'cold';
        $velocity = $lead['velocity'] ?? 'stalled';
        $daysSinceActivity = calculateDaysSinceLastActivity($lead);

        // Going cold: was hot/warm, no activity 7+ days
        if (in_array($temp, ['hot', 'warm']) && $daysSinceActivity >= 7) {
            $issues[] = [
                'type' => 'going_cold',
                'message' => "Going cold - no activity in {$daysSinceActivity} days",
                'severity' => 'warning'
            ];
        }

        // On fire but stalled: high temp but no momentum
        if ($temp === 'on_fire' && $velocity === 'stalled') {
            $issues[] = [
                'type' => 'stalled_hot',
                'message' => "Hot lead losing momentum",
                'severity' => 'critical'
            ];
        }

        // Callback overdue
        if (!empty($lead['followup_date'])) {
            $followupTime = strtotime($lead['followup_date']);
            if ($followupTime && $followupTime < $now) {
                $daysOverdue = floor(($now - $followupTime) / 86400);
                $issues[] = [
                    'type' => 'callback_overdue',
                    'message' => "Callback overdue by {$daysOverdue} day(s)",
                    'severity' => $daysOverdue > 3 ? 'critical' : 'warning'
                ];
            }
        }

        // Multiple attempts, no success
        $callsMade = intval($lead['calls_made'] ?? 0);
        $outcome = $lead['call_outcome'] ?? '';
        if ($callsMade >= 3 && in_array($outcome, ['no_answer_retry', 'left_voicemail', ''])) {
            $issues[] = [
                'type' => 'multiple_attempts',
                'message' => "{$callsMade} call attempts with no answer",
                'severity' => 'warning'
            ];
        }

        // Research complete but no outreach (stale research)
        $status = getLeadStage($lead);
        if ($status === 'lead' && !empty($lead['enrichment']) && intval($lead['emails_sent'] ?? 0) === 0 && $daysSinceActivity >= 5) {
            $issues[] = [
                'type' => 'stale_research',
                'message' => "Research done {$daysSinceActivity} days ago - no outreach",
                'severity' => 'warning'
            ];
        }

        if (!empty($issues)) {
            $attention[] = [
                'lead_id' => $lead['id'],
                'name' => trim(($lead['first_name'] ?? '') . ' ' . ($lead['last_name'] ?? '')),
                'company' => $lead['company'] ?? '',
                'temperature' => $temp,
                'velocity' => $velocity,
                'fit_grade' => $lead['fit_grade'] ?? '',
                'status' => $status,
                'issues' => $issues,
                'days_since_activity' => $daysSinceActivity
            ];
        }
    }

    // Sort by most critical first
    usort($attention, function($a, $b) {
        $severityOrder = ['critical' => 0, 'warning' => 1, 'info' => 2];
        $aMax = 2;
        $bMax = 2;
        foreach ($a['issues'] as $issue) {
            $aMax = min($aMax, $severityOrder[$issue['severity']] ?? 2);
        }
        foreach ($b['issues'] as $issue) {
            $bMax = min($bMax, $severityOrder[$issue['severity']] ?? 2);
        }
        return $aMax - $bMax;
    });

    return $attention;
}

/**
 * Generate AI-powered next-best-action recommendation for a lead
 */
function generateNextBestAction($lead, $admin) {
    // Get API key for LLM call
    $provider = $admin['default_provider'] ?? 'groq';
    $apiKey = $admin[$provider . '_key'] ?? '';

    // Build context about the lead
    $name = trim(($lead['first_name'] ?? '') . ' ' . ($lead['last_name'] ?? ''));
    $company = $lead['company'] ?? 'Unknown Company';
    $title = $lead['title'] ?? '';
    $status = $lead['status'] ?? 'new';
    $temperature = $lead['temperature'] ?? 'cold';
    $velocity = $lead['velocity'] ?? 'stalled';
    $fitGrade = $lead['fit_grade'] ?? '';
    $engagementScore = $lead['engagement_score'] ?? 0;
    $daysSinceActivity = calculateDaysSinceLastActivity($lead);
    $callsMade = intval($lead['calls_made'] ?? 0);
    $emailsSent = intval($lead['emails_sent'] ?? 0);
    $lastOutcome = $lead['call_outcome'] ?? '';
    $followupDate = $lead['followup_date'] ?? '';

    // Get research data if available
    $researchSummary = '';
    if (!empty($lead['enrichment'])) {
        $enrichment = json_decode($lead['enrichment'], true);
        if ($enrichment) {
            $painPoints = $enrichment['prospect_analysis']['pain_points'] ?? [];
            $openingHooks = $enrichment['sales_strategy']['opening_hooks'] ?? [];
            if (!empty($painPoints)) {
                $painTexts = array_map(function($p) {
                    return is_array($p) ? ($p['pain'] ?? $p['point'] ?? reset($p) ?? '') : (string)$p;
                }, array_slice($painPoints, 0, 3));
                $researchSummary .= "Pain points: " . implode(', ', $painTexts) . ". ";
            }
            if (!empty($openingHooks)) {
                $hookTexts = array_map(function($h) {
                    return is_array($h) ? ($h['hook'] ?? $h['text'] ?? reset($h) ?? '') : (string)$h;
                }, array_slice($openingHooks, 0, 2));
                $researchSummary .= "Hooks: " . implode(', ', $hookTexts) . ". ";
            }
        }
    }

    // Build email history summary
    $emailHistory = '';
    if (!empty($lead['email_history'])) {
        $emails = $lead['email_history'];
        $emailHistory = count($emails) . " email(s) sent. Last type: " . ($emails[count($emails)-1]['type'] ?? 'unknown');
    }

    // If no API key, return rule-based recommendation
    if (empty($apiKey)) {
        return generateRuleBasedNBA($lead, $daysSinceActivity);
    }

    $prompt = <<<PROMPT
You are a sales intelligence AI. Based on the lead data below, recommend the SINGLE best next action.

LEAD DATA:
- Name: {$name}
- Company: {$company}
- Title: {$title}
- Status: {$status}
- Temperature: {$temperature} (cold/warm/hot/on_fire)
- Velocity: {$velocity} (stalled/slowing/stable/accelerating)
- ICP Grade: {$fitGrade}
- Engagement Score: {$engagementScore}/100
- Days since last activity: {$daysSinceActivity}
- Calls made: {$callsMade}
- Emails sent: {$emailsSent}
- Last call outcome: {$lastOutcome}
- Scheduled followup: {$followupDate}
- Research: {$researchSummary}
- Email history: {$emailHistory}

Return a JSON object with exactly these fields:
{
  "action": "call|email|followup|research|wait|drop",
  "urgency": "critical|high|medium|low",
  "reason": "One sentence explaining why this action now",
  "talking_point": "A specific thing to mention based on their situation",
  "risk_if_delayed": "What happens if you don't act today"
}

Only return valid JSON, no other text.
PROMPT;

    try {
        $result = callLLM($provider, $apiKey, $prompt);

        if (!$result['success'] || empty($result['content'])) {
            return generateRuleBasedNBA($lead, $daysSinceActivity);
        }

        // Clean the response - remove markdown code blocks if present
        $response = trim($result['content']);
        $response = preg_replace('/^```json?\s*/', '', $response);
        $response = preg_replace('/\s*```$/', '', $response);

        $nba = json_decode($response, true);

        if ($nba && isset($nba['action'])) {
            return [
                'action' => $nba['action'],
                'urgency' => $nba['urgency'] ?? 'medium',
                'reason' => $nba['reason'] ?? 'AI recommendation',
                'talking_point' => $nba['talking_point'] ?? '',
                'risk_if_delayed' => $nba['risk_if_delayed'] ?? '',
                'source' => 'ai'
            ];
        }
    } catch (Exception $e) {
        // Fall through to rule-based
    }

    // Fallback to rule-based
    return generateRuleBasedNBA($lead, $daysSinceActivity);
}

/**
 * Rule-based next-best-action (fallback when AI unavailable)
 */
function generateRuleBasedNBA($lead, $daysSinceActivity) {
    $status = $lead['status'] ?? 'new';
    $temp = $lead['temperature'] ?? 'cold';
    $velocity = $lead['velocity'] ?? 'stalled';
    $callsMade = intval($lead['calls_made'] ?? 0);
    $outcome = $lead['call_outcome'] ?? '';

    // Decision tree
    $status = legacyStatusToStage($status);
    $emailsSent = intval($lead['emails_sent'] ?? 0);
    if ($status === 'lead' && empty($lead['enrichment'])) {
        return [
            'action' => 'research',
            'urgency' => 'medium',
            'reason' => 'New lead needs research before outreach',
            'talking_point' => 'Run AI research to understand their business',
            'risk_if_delayed' => 'Lead may go to competitors',
            'source' => 'rules'
        ];
    }

    if ($status === 'lead' && $emailsSent === 0) {
        return [
            'action' => 'email',
            'urgency' => $temp === 'hot' ? 'high' : 'medium',
            'reason' => 'Research complete - time to reach out',
            'talking_point' => 'Use research insights for personalized email',
            'risk_if_delayed' => 'Research becomes stale after 5 days',
            'source' => 'rules'
        ];
    }

    if ($status === 'lead' && $daysSinceActivity >= 3) {
        return [
            'action' => 'followup',
            'urgency' => $daysSinceActivity >= 7 ? 'high' : 'medium',
            'reason' => "No response after {$daysSinceActivity} days",
            'talking_point' => 'Reference previous email, add new value',
            'risk_if_delayed' => 'Lead forgets about you',
            'source' => 'rules'
        ];
    }

    if ($status === 'cost_proposal') {
        return [
            'action' => 'cost_proposal',
            'urgency' => 'high',
            'reason' => empty($lead['cost_proposal_id']) ? 'Deal is ready for a cost proposal' : 'Cost proposal is out - chase the decision',
            'talking_point' => 'Confirm scope and budget, then walk them through the numbers',
            'risk_if_delayed' => 'Momentum from the demo is lost',
            'source' => 'rules'
        ];
    }

    if ($status === 'sow') {
        return [
            'action' => 'sow',
            'urgency' => 'critical',
            'reason' => empty($lead['sow_id']) ? 'Cost proposal accepted - raise the SOW' : 'SOW issued - chase signature',
            'talking_point' => 'Confirm deliverables and timeline, then get it signed',
            'risk_if_delayed' => 'A signed-ready deal stalls at the last step',
            'source' => 'rules'
        ];
    }

    if ($outcome === 'callback_requested') {
        return [
            'action' => 'call',
            'urgency' => 'high',
            'reason' => 'Callback is scheduled or requested',
            'talking_point' => $lead['call_anchor'] ?? 'Follow up on previous conversation',
            'risk_if_delayed' => 'Breaking promise damages trust',
            'source' => 'rules'
        ];
    }

    if (in_array($outcome, ['no_answer_retry', 'left_voicemail']) && $callsMade < 5) {
        return [
            'action' => 'call',
            'urgency' => 'medium',
            'reason' => "Try again - only {$callsMade} attempts so far",
            'talking_point' => 'Try different time of day',
            'risk_if_delayed' => 'May lose timing window',
            'source' => 'rules'
        ];
    }

    if ($temp === 'on_fire' || $temp === 'hot') {
        return [
            'action' => 'call',
            'urgency' => 'high',
            'reason' => 'Hot lead - strike while iron is hot',
            'talking_point' => 'They are showing buying signals',
            'risk_if_delayed' => 'Hot leads cool down fast',
            'source' => 'rules'
        ];
    }

    // Default
    return [
        'action' => 'review',
        'urgency' => 'low',
        'reason' => 'Review lead and plan next steps',
        'talking_point' => 'Check for any missed opportunities',
        'risk_if_delayed' => 'Lead may become stale',
        'source' => 'rules'
    ];
}

/**
 * Generate notifications for leads that need attention
 */
function generateNotifications($leads, $existingNotifications) {
    $now = time();
    $today = date('Y-m-d');
    $newNotifications = [];

    // Build set of existing unread notification keys to avoid duplicates
    // Key = notif_key field if set, otherwise type + lead_id
    $existingKeys = [];
    foreach ($existingNotifications as $notif) {
        if (!($notif['read'] ?? false)) {
            $key = $notif['notif_key'] ?? (($notif['type'] ?? '') . '_' . ($notif['lead_id'] ?? ''));
            $existingKeys[$key] = true;
        }
    }

    foreach ($leads as $lead) {
        if (in_array(getLeadStage($lead), ['won', 'lost'])) continue;

        $leadId = $lead['id'];
        $name = trim(($lead['first_name'] ?? '') . ' ' . ($lead['last_name'] ?? ''));
        $company = $lead['company'] ?? '';
        $temp = $lead['temperature'] ?? 'cold';
        $daysSinceActivity = calculateDaysSinceLastActivity($lead);

        $status = getLeadStage($lead);

        $checks = [
            ['cond' => in_array($temp, ['on_fire','hot']) && $daysSinceActivity >= 1,
             'key'  => "hot_lead_{$leadId}", 'type' => 'hot_lead',
             'title'=> "🔥 Hot lead needs attention",
             'body' => "{$name} from {$company} hasn't been contacted in {$daysSinceActivity} day(s)"],

            ['cond' => !empty($lead['followup_date']) && date('Y-m-d', strtotime($lead['followup_date'])) === $today,
             'key'  => "callback_due_{$leadId}", 'type' => 'callback_due',
             'title'=> "📞 Callback due today",
             'body' => "{$name} from {$company}"],

            ['cond' => !empty($lead['followup_date']) && strtotime($lead['followup_date']) < strtotime('today'),
             'key'  => "callback_overdue_{$leadId}", 'type' => 'callback_overdue',
             'title'=> "⚠️ Callback overdue",
             'body' => "{$name} from {$company}, " . floor(($now - strtotime($lead['followup_date'])) / 86400) . " day(s) overdue"],

            ['cond' => in_array($temp, ['hot','warm']) && $daysSinceActivity >= 7 && $daysSinceActivity < 14,
             'key'  => "going_cold_{$leadId}", 'type' => 'going_cold',
             'title'=> "❄️ Lead going cold",
             'body' => "{$name} from {$company}, {$daysSinceActivity} days inactive"],

            ['cond' => $status === 'lead' && !empty($lead['enrichment']) && intval($lead['emails_sent'] ?? 0) === 0 && $daysSinceActivity >= 3,
             'key'  => "stale_research_{$leadId}", 'type' => 'stale_research',
             'title'=> "🔍 Send outreach to {$name}",
             'body' => "Research done {$daysSinceActivity} days ago for {$company}"],

            ['cond' => $status === 'lead' && intval($lead['emails_sent'] ?? 0) > 0 && $daysSinceActivity >= 3,
             'key'  => "followup_call_{$leadId}", 'type' => 'callback_due',
             'title'=> "📞 Follow-up call needed",
             'body' => "{$name} from {$company}, email sent {$daysSinceActivity} days ago"],

            ['cond' => $status === 'lead' && empty($lead['enrichment']) && $daysSinceActivity >= 2,
             'key'  => "new_lead_idle_{$leadId}", 'type' => 'stale_research',
             'title'=> "⏰ New lead needs attention",
             'body' => "{$name} from {$company}, added {$daysSinceActivity} days ago"],

            ['cond' => $status === 'cost_proposal' && $daysSinceActivity >= 5,
             'key'  => "cp_stalled_{$leadId}", 'type' => 'callback_due',
             'title'=> "💰 Cost proposal going quiet",
             'body' => "{$name} from {$company}, no movement for {$daysSinceActivity} days"],

            ['cond' => $status === 'sow' && $daysSinceActivity >= 5,
             'key'  => "sow_stalled_{$leadId}", 'type' => 'callback_due',
             'title'=> "📝 SOW awaiting signature",
             'body' => "{$name} from {$company}, no movement for {$daysSinceActivity} days"],
        ];

        foreach ($checks as $check) {
            if ($check['cond'] && !isset($existingKeys[$check['key']])) {
                $newNotifications[] = [
                    'id'        => 'notif_' . bin2hex(random_bytes(8)),
                    'notif_key' => $check['key'],
                    'type'      => $check['type'],
                    'lead_id'   => $leadId,
                    'title'     => $check['title'],
                    'body'      => $check['body'],
                    'message'   => $check['title'],
                    'created_at'=> date('c'),
                    'read'      => false
                ];
                $existingKeys[$check['key']] = true;
            }
        }
    }

    return $newNotifications;
}

// ============================================================================
// END PROACTIVE INTELLIGENCE HELPER FUNCTIONS
// ============================================================================

function generatePassword() { $c = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789'; $p = ''; for ($i = 0; $i < 10; $i++) $p .= $c[random_int(0, strlen($c) - 1)]; return $p; }
function respond($data, $code = 200) { http_response_code($code); echo json_encode($data); exit; }

// Resolve the current user from the X-User-Token header (or ?token= query param as a fallback).
// Returns null when no valid token is presented.
// Multi-device: a user may have many active tokens (one per device/login). We keep the
// legacy single 'token' field for back-compat and also check the 'tokens' array.
function userTokens($u) {
    $list = [];
    if (!empty($u['token'])) $list[] = $u['token'];
    if (!empty($u['tokens']) && is_array($u['tokens'])) {
        foreach ($u['tokens'] as $t) { if (is_string($t) && $t !== '') $list[] = $t; }
    }
    return array_values(array_unique($list));
}

function getCurrentUser() {
    $token = $_SERVER['HTTP_X_USER_TOKEN'] ?? ($_GET['token'] ?? '');
    $token = trim($token);
    if ($token === '') return null;
    foreach (getUsers() as $u) {
        foreach (userTokens($u) as $valid) {
            if (hash_equals($valid, $token)) return $u;
        }
    }
    return null;
}

// Add a new device token to a user without removing existing ones (caps the list so it
// can't grow forever). Mutates the passed user array by reference.
function addUserToken(&$u, $token, $max = 10) {
    if (!isset($u['tokens']) || !is_array($u['tokens'])) $u['tokens'] = [];
    // Migrate any legacy single token into the array, then drop the legacy field.
    if (!empty($u['token'])) { $u['tokens'][] = $u['token']; unset($u['token']); }
    $u['tokens'][] = $token;
    $u['tokens'] = array_values(array_unique($u['tokens']));
    if (count($u['tokens']) > $max) $u['tokens'] = array_slice($u['tokens'], -$max);
}

// Require a logged-in user; 401 otherwise.
function requireAuth() {
    $u = getCurrentUser();
    if (!$u) respond(['success' => false, 'error' => 'Not authenticated'], 401);
    return $u;
}

// Require an admin (super-admins count as admins); 403 otherwise.
function requireAdmin() {
    $u = requireAuth();
    if (empty($u['is_admin']) && empty($u['is_super_admin'])) {
        respond(['success' => false, 'error' => 'Admin access required'], 403);
    }
    return $u;
}

// Require a super-admin; 403 otherwise.
function requireSuperAdmin() {
    $u = requireAuth();
    if (empty($u['is_super_admin'])) {
        respond(['success' => false, 'error' => 'Super-admin access required'], 403);
    }
    return $u;
}

$method = $_SERVER['REQUEST_METHOD'];
$path = $_GET['action'] ?? '';
$input = in_array($method, ['POST', 'PUT']) ? (json_decode(file_get_contents('php://input'), true) ?: []) : [];


switch ($path) {

case 'generate-sow':
    if ($method !== 'POST') break;
    requireAuth();
    $admin = getAdmin();
    [$provider, $apiKey] = documentProviderAndKey($admin);
    if (!$apiKey) respond(['success' => false, 'error' => 'AI not configured'], 400);

    $sowInput = $input['input'] ?? [];
    if (empty($sowInput['projectName']) && empty($sowInput['clientName'])) {
        respond(['success' => false, 'error' => 'Add at least a project or client name'], 400);
    }
    // Stamp the next sequential SOW number (e.g. SOW-0001) into the title, matching the saved doc number.
    if (empty($sowInput['sowNumber'])) {
        $sowInput['sowNumber'] = nextDocumentNumber(getAllDocuments(), 'sow');
    }
    $res = generateSow($provider, $apiKey, $sowInput);
    if (!$res['success']) respond(['success' => false, 'error' => $res['error']], 502);
    respond(['success' => true, 'markdown' => $res['content']]);
    break;

case 'generate-cost-proposal':
    if ($method !== 'POST') break;
    requireAuth();
    $admin = getAdmin();
    [$provider, $apiKey] = documentProviderAndKey($admin);
    if (!$apiKey) respond(['success' => false, 'error' => 'AI not configured'], 400);

    $cpInput = $input['input'] ?? [];
    if (empty($cpInput['serviceType']) && empty($cpInput['clientName'])) {
        respond(['success' => false, 'error' => 'Add at least a service type or client name'], 400);
    }
    // Proposal ID is auto-generated server-side, never typed by the rep —
    // see nextProposalId() in cp.php. Overwrites anything the client sent
    // for this field so a stale/reused value from a copied form can never
    // slip through.
    $cpInput['projectId'] = nextProposalId();
    $res = generateCostProposal($provider, $apiKey, $cpInput);
    if (!$res['success']) respond(['success' => false, 'error' => $res['error']], 502);
    // Sent back explicitly rather than relying on the rep to find it in the
    // generated markdown — the document body deliberately never repeats
    // cover-page info (see costProposalSystemPrompt()), so the frontend
    // needs the real value here to show it on the cover and save it with
    // the document's input.
    respond(['success' => true, 'markdown' => $res['content'], 'project_id' => $cpInput['projectId']]);
    break;

case 'refine-sow':
    if ($method !== 'POST') break;
    requireAuth();
    $admin = getAdmin();
    [$provider, $apiKey] = documentProviderAndKey($admin);
    if (!$apiKey) respond(['success' => false, 'error' => 'AI not configured'], 400);

    $markdown = $input['markdown'] ?? '';
    $instruction = $input['instruction'] ?? '';
    if (!trim($markdown)) respond(['success' => false, 'error' => 'Nothing to refine yet'], 400);
    if (!trim($instruction)) respond(['success' => false, 'error' => 'Describe what to change'], 400);
    $res = refineSow($provider, $apiKey, $markdown, $instruction);
    if (!$res['success']) respond(['success' => false, 'error' => $res['error']], 502);
    respond(['success' => true, 'markdown' => $res['content']]);
    break;

case 'refine-cost-proposal':
    if ($method !== 'POST') break;
    requireAuth();
    $admin = getAdmin();
    [$provider, $apiKey] = documentProviderAndKey($admin);
    if (!$apiKey) respond(['success' => false, 'error' => 'AI not configured'], 400);

    $markdown = $input['markdown'] ?? '';
    $instruction = $input['instruction'] ?? '';
    if (!trim($markdown)) respond(['success' => false, 'error' => 'Nothing to refine yet'], 400);
    if (!trim($instruction)) respond(['success' => false, 'error' => 'Describe what to change'], 400);
    $res = refineCostProposal($provider, $apiKey, $markdown, $instruction);
    if (!$res['success']) respond(['success' => false, 'error' => $res['error']], 502);
    respond(['success' => true, 'markdown' => $res['content']]);
    break;

case 'clickup-meetings':
    if ($method !== 'POST') break;
    requireAuth();
    $admin = getAdmin();
    $cuToken = $admin['clickup_token'] ?? '';
    $cuWorkspace = $admin['clickup_workspace_id'] ?? '';
    if (!$cuToken || !$cuWorkspace) respond(['success' => false, 'error' => 'Add a ClickUp API token and Workspace ID in Settings first'], 400);
    $res = clickupListMeetingDocs($cuToken, $cuWorkspace, 15);
    if (!$res['success']) respond(['success' => false, 'error' => $res['error']], 502);
    respond(['success' => true, 'meetings' => $res['meetings']]);
    break;

case 'clickup-transcript':
    if ($method !== 'POST') break;
    requireAuth();
    $admin = getAdmin();
    $cuToken = $admin['clickup_token'] ?? '';
    $cuWorkspace = $admin['clickup_workspace_id'] ?? '';
    if (!$cuToken || !$cuWorkspace) respond(['success' => false, 'error' => 'Add a ClickUp API token and Workspace ID in Settings first'], 400);
    $id = $input['id'] ?? '';
    if (!trim($id)) respond(['success' => false, 'error' => 'No doc selected'], 400);
    $res = clickupFetchDocText($cuToken, $cuWorkspace, $id);
    if (!$res['success']) respond(['success' => false, 'error' => $res['error']], 502);
    respond(['success' => true, 'title' => $res['title'], 'text' => $res['text']]);
    break;

case 'extract-sow':
    if ($method !== 'POST') break;
    requireAuth();
    $admin = getAdmin();
    $provider = $admin['default_provider'] ?? 'groq';
    $apiKey = $admin[$provider . '_key'] ?? '';
    if (!$apiKey) respond(['success' => false, 'error' => 'AI not configured'], 400);
    $transcript = $input['transcript'] ?? '';
    if (!trim($transcript)) respond(['success' => false, 'error' => 'Paste the meeting notes first'], 400);
    $res = extractSowInput($provider, $apiKey, $transcript);
    if (!$res['success']) respond(['success' => false, 'error' => $res['error']], 502);
    respond(['success' => true, 'input' => $res['input']]);
    break;

case 'extract-cost-proposal':
    if ($method !== 'POST') break;
    requireAuth();
    $admin = getAdmin();
    $provider = $admin['default_provider'] ?? 'groq';
    $apiKey = $admin[$provider . '_key'] ?? '';
    if (!$apiKey) respond(['success' => false, 'error' => 'AI not configured'], 400);
    $transcript = $input['transcript'] ?? '';
    if (!trim($transcript)) respond(['success' => false, 'error' => 'Paste the meeting notes first'], 400);
    $res = extractCostProposalInput($provider, $apiKey, $transcript);
    if (!$res['success']) respond(['success' => false, 'error' => $res['error']], 502);
    respond(['success' => true, 'input' => $res['input']]);
    break;

case 'generate-minutes':
    if ($method !== 'POST') break;
    requireAuth();
    $admin = getAdmin();
    $provider = $admin['default_provider'] ?? 'groq';
    $apiKey = $admin[$provider . '_key'] ?? '';
    if (!$apiKey) respond(['success' => false, 'error' => 'AI not configured'], 400);
    $transcript = $input['transcript'] ?? '';
    if (!trim($transcript)) respond(['success' => false, 'error' => 'Add the meeting transcript or notes first'], 400);
    $context = [
        'clientName'   => $input['client_name'] ?? '',
        'meetingTitle' => $input['meeting_title'] ?? '',
        'meetingDate'  => $input['meeting_date'] ?? '',
    ];
    $res = generateMeetingMinutes($provider, $apiKey, $transcript, $context);
    if (!$res['success']) respond(['success' => false, 'error' => $res['error']], 502);
    respond(['success' => true, 'markdown' => $res['content']]);
    break;

case 'refine-minutes':
    if ($method !== 'POST') break;
    requireAuth();
    $admin = getAdmin();
    $provider = $admin['default_provider'] ?? 'groq';
    $apiKey = $admin[$provider . '_key'] ?? '';
    if (!$apiKey) respond(['success' => false, 'error' => 'AI not configured'], 400);
    $markdown = $input['markdown'] ?? '';
    $instruction = $input['instruction'] ?? '';
    if (!trim($markdown)) respond(['success' => false, 'error' => 'Nothing to refine yet'], 400);
    if (!trim($instruction)) respond(['success' => false, 'error' => 'Describe what to change'], 400);
    $res = refineMeetingMinutes($provider, $apiKey, $markdown, $instruction);
    if (!$res['success']) respond(['success' => false, 'error' => $res['error']], 502);
    respond(['success' => true, 'markdown' => $res['content']]);
    break;

case 'send-minutes-email':
    // Email the generated Meeting Minutes PDF to a client via Resend.
    // The browser generates the branded PDF, converts it to base64, and sends it here.
    // Self-contained sender block — does not share code with lead outreach or support email.
    if ($method !== 'POST') break;
    $user = requireAuth();
    $admin = getAdmin();

    $toEmail    = trim($input['to']         ?? '');
    $fromAddr   = trim($input['from']       ?? '');
    $subject    = trim($input['subject']    ?? '');
    $note       = trim($input['note']       ?? '');
    $pdfBase64  = trim($input['pdf_base64'] ?? '');
    $filename   = trim($input['filename']   ?? 'meeting-minutes.pdf');

    if (!$toEmail || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        respond(['success' => false, 'error' => 'A valid recipient email is required'], 400);
    }
    if ($subject === '') {
        respond(['success' => false, 'error' => 'Subject is required'], 400);
    }
    if ($pdfBase64 === '') {
        respond(['success' => false, 'error' => 'No PDF data received'], 400);
    }

    $resendKey = trim($admin['resend_key'] ?? '');
    if ($resendKey === '') {
        respond(['success' => false, 'error' => 'Email sending is not configured (no Resend API key in Settings)'], 400);
    }

    // Fall back to the configured outreach address if the rep did not override.
    if ($fromAddr === '' || !filter_var($fromAddr, FILTER_VALIDATE_EMAIL)) {
        $fromAddr = trim($admin['outreach_from'] ?? '') ?: (trim($admin['support_from'] ?? '') ?: 'onboarding@resend.dev');
    }
    $repName  = trim($user['name'] ?? '') ?: 'Levata';
    $replyTo  = trim($user['email'] ?? '');

    $e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
    $bodyHtml = '<div style="font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;max-width:560px;margin:0 auto;color:#111827;font-size:14px;line-height:1.6;">';
    if ($note !== '') {
        foreach (preg_split('/\n{2,}/', $note) as $p) {
            $p = trim($p);
            if ($p !== '') $bodyHtml .= '<p style="margin:0 0 12px;">' . nl2br($e($p)) . '</p>';
        }
        $bodyHtml .= '<p style="margin:0 0 12px;">&nbsp;</p>';
    }
    $bodyHtml .= '<p style="margin:0 0 12px;">Please find the Meeting Minutes attached as a PDF.</p>';
    $bodyHtml .= '<p style="margin:18px 0 0;font-size:12px;color:#9ca3af;">Sent by ' . $e($repName) . ' at Levata.</p>';
    $bodyHtml .= '</div>';

    $bodyText = ($note !== '' ? $note . "\n\n" : '') . "Please find the Meeting Minutes attached as a PDF.\n\n-- " . $repName . ", Levata";

    $payload = [
        'from'        => $repName . ' <' . $fromAddr . '>',
        'to'          => [$toEmail],
        'subject'     => $subject,
        'text'        => $bodyText,
        'html'        => $bodyHtml,
        'attachments' => [[
            'filename'    => $filename,
            'content'     => $pdfBase64,
            'content_type' => 'application/pdf',
        ]],
    ];
    if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
        $payload['reply_to'] = $replyTo;
    }

    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $resendKey,
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT => 20,
    ]);
    $sendResponse = curl_exec($ch);
    $sendCode     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $sendDecoded = json_decode($sendResponse, true);
    if ($sendCode < 200 || $sendCode >= 300) {
        $err = is_array($sendDecoded) && !empty($sendDecoded['message'])
            ? $sendDecoded['message'] : ('Resend returned HTTP ' . $sendCode);
        respond(['success' => false, 'error' => 'Email not sent: ' . $err], 502);
    }
    respond(['success' => true, 'message_id' => $sendDecoded['id'] ?? null]);
    break;

// ===== Task system =====

case 'tasks':
    if ($method !== 'GET') break;
    requireAuth();
    $store = getTasksStore();
    $tasks = $store['tasks'];
    // Optional filters via query string.
    if (!empty($_GET['status']))   $tasks = array_values(array_filter($tasks, fn($t) => ($t['status'] ?? '') === $_GET['status']));
    if (!empty($_GET['assignee'])) $tasks = array_values(array_filter($tasks, fn($t) => stripos($t['assignee'] ?? '', $_GET['assignee']) !== false));
    if (!empty($_GET['client']))   $tasks = array_values(array_filter($tasks, fn($t) => stripos($t['client'] ?? '', $_GET['client']) !== false));
    // Newest first.
    usort($tasks, fn($a, $b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''));
    respond(['success' => true, 'tasks' => $tasks]);
    break;

// Unified calendar feed: task due dates + job invoice due dates, for the Calendar view.
case 'calendar-events':
    if ($method !== 'GET') break;
    requireAuth();
    $events = [];
    foreach (getTasksStore()['tasks'] as $t) {
        $due = trim($t['due_date'] ?? '');
        if ($due === '') continue;
        $events[] = [
            'date' => $due,
            'type' => 'task',
            'title' => $t['title'] ?? '',
            'status' => $t['status'] ?? 'open',
            'assignee' => $t['assignee'] ?? '',
            'client' => $t['client'] ?? '',
            'notes' => $t['notes'] ?? '',
            'ref_id' => $t['id'] ?? '',
        ];
    }
    foreach (getJobsStore()['jobs'] as $j) {
        foreach (($j['invoices'] ?? []) as $inv) {
            $due = trim($inv['due_date'] ?? '');
            if ($due === '') continue;
            $events[] = [
                'date' => $due,
                'type' => 'invoice',
                'title' => ($inv['label'] ?? 'Invoice') . ' for ' . ($j['client'] ?? ''),
                'status' => $inv['status'] ?? 'unpaid',
                'assignee' => '',
                'client' => $j['client'] ?? '',
                'ref_id' => $j['id'] ?? '',
                'invoice_id' => $inv['id'] ?? '',
                'amount' => $inv['amount'] ?? 0,
                'invoice_no' => $inv['invoice_no'] ?? '',
                'job_no' => $j['job_no'] ?? '',
            ];
        }
    }
    usort($events, fn($a, $b) => strcmp($a['date'], $b['date']));
    respond(['success' => true, 'events' => $events]);
    break;

case 'save-task':
    if ($method !== 'POST') break;
    $user = requireAuth();
    $store = getTasksStore();
    $id = trim($input['id'] ?? '');
    $title = trim($input['title'] ?? '');
    if ($id === '' && $title === '') respond(['success' => false, 'error' => 'Task title is required'], 400);

    // Optional link to a Job Registry job (dropdown in the task modal).
    $taskJob = jobRefById(trim($input['job_id'] ?? ''));
    // Prefer an explicit client_id from the client picker (authoritative); fall
    // back to matching a typed name for any caller that doesn't send one yet.
    $clientRef = resolveClientRef($input);

    if ($id === '') {
        // Create.
        [$store, $id] = nextTaskId($store);
        $task = [
            'id'            => $id,
            'title'         => $title,
            'assignee'      => trim($input['assignee'] ?? ''),
            'client'        => $clientRef['name'],
            'client_id'     => $clientRef['id'],
            'job_id'        => $taskJob ? ($taskJob['id'] ?? '') : '',
            'job_no'        => $taskJob ? ($taskJob['job_no'] ?? '') : '',
            'due_date'      => trim($input['due_date'] ?? ''),
            'notes'         => trim($input['notes'] ?? ''),
            'status'                => 'open',
            'source'                => trim($input['source'] ?? 'manual'),
            'clickup_doc_id'        => trim($input['clickup_doc_id'] ?? ''),
            'clickup_doc_title'     => trim($input['clickup_doc_title'] ?? ''),
            'clickup_task_id'       => '',
            'clickup_task_url'      => '',
            'created_at'            => date('c'),
            'created_by'            => $user['id'],
        ];

        // Best-effort push to ClickUp: don't fail the local save if this fails.
        $cuWarning = null;
        $admin = getAdmin();
        $cuToken = $admin['clickup_token'] ?? '';
        $cuList = $admin['clickup_list_id'] ?? '';
        if ($cuToken && $cuList) {
            $cuRes = clickupCreateTask($cuToken, $cuList, $task['title'], [
                'notes' => $task['notes'],
                'due_date' => $task['due_date'],
            ]);
            if ($cuRes['success']) {
                $task['clickup_task_id'] = $cuRes['id'];
                $task['clickup_task_url'] = $cuRes['url'];
            } else {
                $cuWarning = 'Saved locally, but could not create the ClickUp task: ' . $cuRes['error'];
            }
        }

        $store['tasks'][] = $task;
    } else {
        // Update.
        $found = false;
        foreach ($store['tasks'] as &$t) {
            if ($t['id'] === $id) {
                if (isset($input['title']))         $t['title']    = $title;
                if (isset($input['assignee']))      $t['assignee'] = trim($input['assignee']);
                if (isset($input['client']) || isset($input['client_id'])) { $cref = resolveClientRef($input, $t['client'] ?? ''); $t['client'] = $cref['name']; $t['client_id'] = $cref['id']; }
                if (isset($input['job_id']))      { $t['job_id']   = $taskJob ? ($taskJob['id'] ?? '') : ''; $t['job_no'] = $taskJob ? ($taskJob['job_no'] ?? '') : ''; }
                if (isset($input['due_date']))      $t['due_date'] = trim($input['due_date']);
                if (isset($input['notes']))         $t['notes']    = trim($input['notes']);
                if (isset($input['status']))        $t['status']   = trim($input['status']);
                $t['updated_at'] = date('c');
                $task = $t;
                $found = true;
                break;
            }
        }
        unset($t);
        if (!$found) respond(['success' => false, 'error' => 'Task not found'], 404);
    }
    saveTasksStore($store);
    respond(['success' => true, 'task' => $task, 'warning' => $cuWarning ?? null]);
    break;

case 'delete-task':
    if ($method !== 'POST') break;
    requireAuth();
    $store = getTasksStore();
    $id = trim($input['id'] ?? '');
    if ($id === '') respond(['success' => false, 'error' => 'No task id'], 400);
    $before = count($store['tasks']);
    $store['tasks'] = array_values(array_filter($store['tasks'], fn($t) => $t['id'] !== $id));
    if (count($store['tasks']) === $before) respond(['success' => false, 'error' => 'Task not found'], 404);
    saveTasksStore($store);
    respond(['success' => true]);
    break;

case 'extract-tasks':
    // LLM call: ClickUp Doc transcript → candidate task list.
    // Returns JSON for the rep to review; does NOT save anything.
    if ($method !== 'POST') break;
    requireAuth();
    $admin = getAdmin();
    $provider = $admin['default_provider'] ?? 'groq';
    $apiKey   = $admin[$provider . '_key'] ?? '';
    if (!$apiKey) respond(['success' => false, 'error' => 'AI not configured'], 400);
    $transcript = trim($input['transcript'] ?? '');
    if ($transcript === '') respond(['success' => false, 'error' => 'No transcript content provided'], 400);
    $client = trim($input['client'] ?? '');
    $res = extractTasksFromMinutes($provider, $apiKey, $transcript, $client);
    if (!$res['success']) respond(['success' => false, 'error' => $res['error']], 502);
    respond(['success' => true, 'tasks' => $res['tasks']]);
    break;

// ===== Document Studio: saved documents (SOWs, and later cost proposals) =====
case 'list-documents':
case 'all-documents':
    // Company-wide list of all saved documents (metadata only). Both actions return
    // the same shared list now that documents live in one store (documents.json),
    // visible to the whole team. 'all-documents' is kept as an alias used by the
    // Job Registry pickers.
    if ($method !== 'GET') break;
    requireAuth();
    $meta = array_map(function ($d) {
        return [
            'id' => $d['id'] ?? '',
            'doc_no' => $d['doc_no'] ?? '',
            'type' => $d['type'] ?? 'sow',
            'title' => $d['title'] ?? 'Untitled',
            'client' => $d['client'] ?? '',
            'client_id' => $d['client_id'] ?? '',
            'status' => $d['status'] ?? 'draft',
            'approved_at' => $d['approved_at'] ?? null,
            'linked_cost_proposal' => $d['linked_cost_proposal'] ?? '',
            'investment' => ($d['input']['investment'] ?? ''),
            'owner' => $d['owner'] ?? '',
            'created_at' => $d['created_at'] ?? '',
            'updated_at' => $d['updated_at'] ?? ($d['created_at'] ?? ''),
            'has_file' => !empty($d['file_path']),
            'file_name' => $d['file_name'] ?? '',
        ];
    }, getAllDocuments());
    // Newest first.
    usort($meta, function ($a, $b) { return strcmp($b['updated_at'], $a['updated_at']); });
    respond(['success' => true, 'documents' => $meta]);
    break;

case 'deal-documents':
    // Every CP/SOW ever generated for one deal (multiple rounds of
    // negotiation produce multiple CPs) — the Cost Proposal / SOW stage
    // panels list these, separate from cost_proposal_id/sow_id on the lead
    // which only track the currently-active one.
    if ($method !== 'GET') break;
    requireAuth();
    $leadId = $_GET['lead_id'] ?? '';
    if ($leadId === '') respond(['success' => false, 'error' => 'lead_id required'], 400);
    $docs = array_values(array_filter(getAllDocuments(), function ($d) use ($leadId) {
        return ($d['lead_id'] ?? '') === $leadId;
    }));
    usort($docs, function ($a, $b) { return strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''); });
    $meta = array_map(function ($d) {
        return [
            'id' => $d['id'] ?? '',
            'doc_no' => $d['doc_no'] ?? '',
            'type' => $d['type'] ?? 'sow',
            'title' => $d['title'] ?? 'Untitled',
            'investment' => ($d['input']['investment'] ?? ''),
            'created_at' => $d['created_at'] ?? '',
        ];
    }, $docs);
    respond(['success' => true, 'documents' => $meta]);
    break;

case 'get-document':
    if ($method !== 'GET') break;
    requireAuth();
    $id = $_GET['id'] ?? '';
    foreach (getAllDocuments() as $d) {
        if (($d['id'] ?? '') === $id) respond(['success' => true, 'document' => $d]);
    }
    respond(['success' => false, 'error' => 'Document not found'], 404);
    break;

case 'save-document':
    if ($method !== 'POST') break;
    $u = requireAuth();
    $markdown = $input['markdown'] ?? '';
    if (!trim($markdown)) respond(['success' => false, 'error' => 'Nothing to save'], 400);
    $store = getDocsStore();

    $now = date('c');
    $docInput = is_array($input['input'] ?? null) ? $input['input'] : [];
    $type = $input['type'] ?? 'sow';
    $title = trim($input['title'] ?? '') ?: (trim($docInput['projectName'] ?? '') ?: (trim($docInput['clientName'] ?? '') ?: 'Untitled document'));
    // Prefer an explicit client_id from the client picker (authoritative); fall back
    // to a typed client/clientName for any caller that doesn't send one yet.
    $clientInputForRef = $input;
    if (trim($clientInputForRef['client'] ?? '') === '') $clientInputForRef['client'] = trim($docInput['clientName'] ?? '');
    $clientRef = resolveClientRef($clientInputForRef);
    $client = $clientRef['name'];
    $id = trim($input['id'] ?? '');

    if ($id !== '') {
        // Update existing.
        $found = false;
        $leadIdForSync = '';
        foreach ($store['documents'] as &$d) {
            if (($d['id'] ?? '') === $id) {
                $d['markdown'] = $markdown;
                $d['input'] = $docInput;
                $d['title'] = $title;
                $d['client'] = $client;
                $d['client_id'] = $clientRef['id'];
                $d['type'] = $type;
                // Allow updating the linked CP (e.g. set on the SOW form after first save).
                if (array_key_exists('linked_cost_proposal', $input)) {
                    $d['linked_cost_proposal'] = trim($input['linked_cost_proposal']);
                }
                // Only set lead_id from a non-empty value — the CP/SOW
                // builders only send a real lead_id during the Demo/Cost
                // Proposal handoff (pendingDealDocLeadId), and send '' on
                // every later edit once that's cleared. Blindly overwriting
                // with '' here would silently sever the document's link
                // back to its deal the first time anyone edited it again.
                if (trim($input['lead_id'] ?? '') !== '') {
                    $d['lead_id'] = trim($input['lead_id']);
                }
                $d['updated_at'] = $now;
                $found = true;
                $leadIdForSync = trim($d['lead_id'] ?? '');
                break;
            }
        }
        unset($d);
        if (!$found) $id = '';
        // If this document is the deal's CURRENTLY ACTIVE cost proposal or
        // SOW (not just any CP/SOW ever generated for it), re-sync the
        // deal's amount to whatever figure is now on the document —
        // negotiating the price on the CP/SOW is what "changing the deal
        // value" actually looks like at these stages.
        if ($found) {
            if ($leadIdForSync !== '' && in_array($type, ['cp', 'cost-proposal', 'cost_proposal', 'sow'], true)) {
                $leadsStoreForSync = getLeadsStore();
                foreach ($leadsStoreForSync['leads'] as &$leadForSync) {
                    if ($leadForSync['id'] !== $leadIdForSync) continue;
                    $isActiveDoc = ($leadForSync['cost_proposal_id'] ?? '') === $id || ($leadForSync['sow_id'] ?? '') === $id;
                    if (!$isActiveDoc) break;
                    $amt = extractDocumentInvestmentAmount($docInput);
                    if ($amt !== null) setDealAmount($leadForSync, $amt, '', $u['id'] ?? null);
                    break;
                }
                unset($leadForSync);
                saveLeadsStore($leadsStoreForSync);
            }
        }
    }
    if ($id === '') {
        $id = 'doc_' . bin2hex(random_bytes(8));
        // Human-readable, per-type sequential document number (e.g. SOW-0001, CP-0001),
        // global across the company so numbers never collide between users.
        $docNo = nextDocumentNumber($store['documents'], $type);
        // Optional link to an approved cost proposal (set when a SOW is created from a CP).
        $linkedCp = trim($input['linked_cost_proposal'] ?? '');
        // Optional back-reference to the deal this document was created from —
        // lets a deal show every CP/SOW ever generated for it (multiple CPs
        // across negotiation rounds), separate from cost_proposal_id/sow_id
        // on the lead, which track only the currently-active one.
        $leadId = trim($input['lead_id'] ?? '');
        $store['documents'][] = [
            'id' => $id,
            'doc_no' => $docNo,
            'type' => $type,
            'title' => $title,
            'client' => $client,
            'client_id' => $clientRef['id'],
            'linked_cost_proposal' => $linkedCp,
            'lead_id' => $leadId,
            'markdown' => $markdown,
            'input' => $docInput,
            'owner_id' => $u['id'] ?? '',
            'owner' => docOwnerName($u['id'] ?? ''),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
    saveDocsStore($store);
    respond(['success' => true, 'id' => $id, 'doc_no' => $docNo ?? null]);
    break;

case 'set-document-status':
    // Approve a document (or revert it to draft). Approving a Cost Proposal is
    // the trigger for the CP → job workflow on the frontend.
    if ($method !== 'POST') break;
    $u = requireAuth();
    $id = trim($input['id'] ?? '');
    $status = ($input['status'] ?? '') === 'approved' ? 'approved' : 'draft';
    $store = getDocsStore();
    $found = false;
    foreach ($store['documents'] as &$d) {
        if (($d['id'] ?? '') === $id) {
            $d['status'] = $status;
            if ($status === 'approved') {
                $d['approved_at'] = date('c');
                $d['approved_by'] = docOwnerName($u['id'] ?? '');
            } else {
                $d['approved_at'] = null;
                $d['approved_by'] = '';
            }
            $d['updated_at'] = date('c');
            $found = true;
            break;
        }
    }
    unset($d);
    if (!$found) respond(['success' => false, 'error' => 'Document not found'], 404);
    saveDocsStore($store);
    respond(['success' => true, 'status' => $status]);
    break;

case 'delete-document':
    if ($method !== 'POST') break;
    requireAuth();
    $id = $input['id'] ?? '';
    $store = getDocsStore();
    $before = count($store['documents']);
    // Also delete any uploaded file for this doc.
    foreach ($store['documents'] as $d) {
        if (($d['id'] ?? '') === $id && !empty($d['file_path'])) {
            $fp = DATA_DIR . '/uploads/' . basename($d['file_path']);
            if (file_exists($fp)) @unlink($fp);
        }
    }
    $store['documents'] = array_values(array_filter($store['documents'], function ($d) use ($id) {
        return ($d['id'] ?? '') !== $id;
    }));
    if (count($store['documents']) === $before) respond(['success' => false, 'error' => 'Document not found'], 404);
    saveDocsStore($store);
    respond(['success' => true]);
    break;

case 'upload-document-file':
    // Accepts multipart/form-data. Stores the file and records file_path on the document.
    $u = requireAuth();
    $id = $_POST['id'] ?? '';
    if (!$id) respond(['success' => false, 'error' => 'Document ID required'], 400);
    if (empty($_FILES['file'])) respond(['success' => false, 'error' => 'No file uploaded'], 400);
    $file = $_FILES['file'];
    if ($file['error'] !== UPLOAD_ERR_OK) respond(['success' => false, 'error' => 'Upload error'], 400);
    // Max 20 MB.
    if ($file['size'] > 20 * 1024 * 1024) respond(['success' => false, 'error' => 'File too large (max 20 MB)'], 400);
    $allowed = ['application/pdf', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/msword', 'application/vnd.ms-word'];
    $mime = mime_content_type($file['tmp_name']);
    if (!in_array($mime, $allowed)) respond(['success' => false, 'error' => 'Only PDF or DOCX files are accepted'], 400);
    $uploadDir = DATA_DIR . '/uploads';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) ?: 'pdf';
    $filename = $id . '_' . time() . '.' . $ext;
    $dest = $uploadDir . '/' . $filename;
    if (!move_uploaded_file($file['tmp_name'], $dest)) respond(['success' => false, 'error' => 'Could not save file'], 500);
    // Update the document record.
    $store = getDocsStore();
    $found = false;
    foreach ($store['documents'] as &$d) {
        if (($d['id'] ?? '') === $id) {
            // Remove old file if replacing.
            if (!empty($d['file_path'])) {
                $old = DATA_DIR . '/uploads/' . basename($d['file_path']);
                if (file_exists($old)) @unlink($old);
            }
            $d['file_path'] = $filename;
            $d['file_name'] = $file['name'];
            $d['updated_at'] = date('c');
            $found = true;
            break;
        }
    }
    unset($d);
    if (!$found) { @unlink($dest); respond(['success' => false, 'error' => 'Document not found'], 404); }
    saveDocsStore($store);
    respond(['success' => true, 'file_name' => $file['name']]);
    break;

case 'download-document-file':
    // Streams the uploaded file back to the browser.
    requireAuth();
    $id = $_GET['id'] ?? '';
    $doc = null;
    foreach (getAllDocuments() as $d) { if (($d['id'] ?? '') === $id) { $doc = $d; break; } }
    if (!$doc) respond(['success' => false, 'error' => 'Document not found'], 404);
    if (empty($doc['file_path'])) respond(['success' => false, 'error' => 'No uploaded file for this document'], 404);
    $fp = DATA_DIR . '/uploads/' . basename($doc['file_path']);
    if (!file_exists($fp)) respond(['success' => false, 'error' => 'File missing on server'], 404);
    $ext = strtolower(pathinfo($fp, PATHINFO_EXTENSION));
    $mime = $ext === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    $name = $doc['file_name'] ?? (($doc['title'] ?? 'document') . '.' . $ext);
    // Override JSON header set at top of file.
    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . addslashes($name) . '"');
    header('Content-Length: ' . filesize($fp));
    header('Cache-Control: private');
    readfile($fp);
    exit;

/**
 * Studio Overview — the delivery dashboard. Answers the questions this team
 * actually has: what is owed us, what is running, what is overdue, what needs
 * a decision. Aggregates the shared jobs / invoices / tasks / documents stores
 * plus the current user's open deals. Replaced the outbound-sales dashboard.
 */
case 'studio-overview':
    if ($method !== 'GET') break;
    $u = requireAuth();
    $today = date('Y-m-d');
    $jobsStore = getJobsStore();
    $allJobs = $jobsStore['jobs'];

    // --- Money: invoices flattened across every job -----------------------
    // Totals are kept per currency code and never converted — see sumByCurrency().
    $outstanding = []; $overdueAmount = []; $paidThisMonth = [];
    $dueSoon = []; $overdueInvoices = [];
    $monthStart = date('Y-m-01');
    foreach ($allJobs as $j) {
        if (($j['status'] ?? '') === 'cancelled') continue;
        $jobCur = normalizeCurrency($j['currency'] ?? '');
        foreach (($j['invoices'] ?? []) as $inv) {
            $amt = (float) ($inv['amount'] ?? 0);
            $due = $inv['due_date'] ?? '';
            if (($inv['status'] ?? '') === 'paid') {
                if (!empty($inv['paid_at']) && substr($inv['paid_at'], 0, 10) >= $monthStart) addToCurrencyBucket($paidThisMonth, $jobCur, $amt);
                continue;
            }
            addToCurrencyBucket($outstanding, $jobCur, $amt);
            $row = [
                'invoice_no' => $inv['invoice_no'] ?? '', 'label' => $inv['label'] ?? '',
                'amount' => $amt, 'due_date' => $due, 'currency' => normalizeCurrency($j['currency'] ?? ''),
                'job_no' => $j['job_no'] ?? '', 'job_name' => $j['name'] ?? '', 'client' => $j['client'] ?? '',
                'job_id' => $j['id'] ?? '',
            ];
            if ($due !== '' && $due < $today) { addToCurrencyBucket($overdueAmount, $jobCur, $amt); $row['days_overdue'] = (int) floor((strtotime($today) - strtotime($due)) / 86400); $overdueInvoices[] = $row; }
            elseif ($due !== '' && $due <= date('Y-m-d', strtotime('+14 days'))) $dueSoon[] = $row;
        }
    }
    usort($overdueInvoices, fn($a, $b) => ($b['days_overdue'] ?? 0) <=> ($a['days_overdue'] ?? 0));
    usort($dueSoon, fn($a, $b) => strcmp($a['due_date'], $b['due_date']));

    // --- Active jobs, most recently touched first ------------------------
    $activeJobs = array_values(array_filter($allJobs, fn($j) => in_array($j['status'] ?? 'open', ['open', 'in_progress', 'awaiting_payment'], true)));
    usort($activeJobs, fn($a, $b) => strcmp($b['updated_at'] ?? '', $a['updated_at'] ?? ''));
    $activeJobsList = array_map(function ($j) {
        $f = jobFinance($j);
        return [
            'id' => $j['id'] ?? '', 'job_no' => $j['job_no'] ?? '', 'name' => $j['name'] ?? '',
            'client' => $j['client'] ?? '', 'status' => $j['status'] ?? 'open',
            'currency' => normalizeCurrency($j['currency'] ?? ''), 'value' => $f['value'],
            'paid' => $f['paid'], 'outstanding' => $f['outstanding'],
            'progress' => $f['invoiced'] > 0 ? round($f['paid'] / $f['invoiced'] * 100) : 0,
        ];
    }, array_slice($activeJobs, 0, 8));

    // --- Tasks: overdue and due this week --------------------------------
    $tasks = getTasksStore()['tasks'];
    $openTasks = array_values(array_filter($tasks, fn($t) => ($t['status'] ?? 'open') !== 'done'));
    $weekEnd = date('Y-m-d', strtotime('+7 days'));
    $taskRows = [];
    foreach ($openTasks as $t) {
        $due = $t['due_date'] ?? '';
        if ($due === '') continue;
        if ($due > $weekEnd) continue;
        $taskRows[] = [
            'id' => $t['id'] ?? '', 'title' => $t['title'] ?? '', 'client' => $t['client'] ?? '',
            'job_no' => $t['job_no'] ?? '', 'assignee' => $t['assignee'] ?? '', 'due_date' => $due,
            'overdue' => $due < $today,
        ];
    }
    usort($taskRows, fn($a, $b) => strcmp($a['due_date'], $b['due_date']));
    $overdueTaskCount = count(array_filter($taskRows, fn($t) => $t['overdue']));

    // --- Documents awaiting approval -------------------------------------
    // Only CPs and SOWs go through approval — NDAs and other doc types are
    // filed, not decided on, so they would just flood this panel.
    $pendingDocs = [];
    foreach (getAllDocuments() as $d) {
        if (($d['status'] ?? 'draft') !== 'draft') continue;
        if (!in_array($d['type'] ?? '', ['cp', 'cost-proposal', 'cost_proposal', 'sow'], true)) continue;
        $pendingDocs[] = [
            'id' => $d['id'] ?? '', 'doc_no' => $d['doc_no'] ?? '', 'title' => $d['title'] ?? 'Untitled',
            'type' => $d['type'] ?? 'sow', 'client' => $d['client'] ?? '',
            'updated_at' => $d['updated_at'] ?? ($d['created_at'] ?? ''),
        ];
    }
    usort($pendingDocs, fn($a, $b) => strcmp($b['updated_at'], $a['updated_at']));

    // --- Deals still in play (the user's own pipeline) --------------------
    $userData = getUserData($u['id']);
    $openDeals = []; $openDealValue = [];
    // Focus Queue: deals that need attention right now, for two different
    // reasons — merged into one list so a rep has a single "what do I do
    // today" queue instead of hunting across the pipeline.
    //   1. FOLLOW-UP DUE — the deal's CURRENT stage has its own follow-up
    //      date (see Profile's Follow-ups list, index.html) that's today,
    //      overdue, or coming up within 14 days. A rep set this deliberately,
    //      so it's the most concrete kind of "due".
    //   2. STALE — no follow-up date was ever set for the current stage, but
    //      the deal has sat there past that stage's normal SLA window (see
    //      calculateSLAStatus() — max_days per stage) with no communication
    //      logged. Catches deals nobody scheduled a follow-up for at all, so
    //      nothing silently falls through the cracks. A deal with an
    //      explicit follow-up date is never ALSO flagged stale — the rep
    //      already has a plan for it, even if that plan is now overdue
    //      (reason 1 already covers the "it's late" signal for those).
    $focusQueue = [];
    $followupWindow = date('Y-m-d', strtotime('+14 days'));
    foreach ($userData['leads'] ?? [] as $l) {
        if (!empty($l['deleted_at'])) continue;
        $stage = getLeadStage($l);
        if (in_array($stage, ['won', 'lost'], true)) continue;
        $amt = dealMoney($l['deal_amount'] ?? 0);
        addToCurrencyBucket($openDealValue, $l['deal_currency'] ?? '', $amt);
        $name = trim(($l['first_name'] ?? '') . ' ' . ($l['last_name'] ?? ''));
        $openDeals[] = [
            'id' => $l['id'] ?? '', 'name' => $name,
            'company' => $l['company'] ?? '', 'stage' => $stage, 'amount' => $amt,
            'currency' => normalizeCurrency($l['deal_currency'] ?? ''),
        ];
        $fu = ($l['followups'] ?? [])[$stage] ?? null;
        $fuDate = trim($fu['date'] ?? '');
        if ($fuDate !== '' && $fuDate <= $followupWindow) {
            $focusQueue[] = [
                'lead_id' => $l['id'] ?? '', 'name' => $name, 'company' => $l['company'] ?? '',
                'stage' => $stage, 'reason' => 'followup',
                'date' => $fuDate, 'notes' => trim($fu['notes'] ?? ''),
                'overdue' => $fuDate < $today, 'days_in_stage' => null,
            ];
        } else {
            $sla = calculateSLAStatus($l);
            if (!empty($sla['is_overdue'])) {
                $focusQueue[] = [
                    'lead_id' => $l['id'] ?? '', 'name' => $name, 'company' => $l['company'] ?? '',
                    'stage' => $stage, 'reason' => 'stale',
                    'date' => null, 'notes' => $sla['next_action'] ?? '',
                    'overdue' => true, 'days_in_stage' => $sla['days_in_stage'] ?? null,
                ];
            }
        }
    }
    // Closest to signature first.
    usort($openDeals, fn($a, $b) => stageOrder($b['stage']) <=> stageOrder($a['stage']) ?: $b['amount'] <=> $a['amount']);
    // Overdue first (needs chasing most), then soonest-due; stale entries
    // (no date) sort after any dated ones within the same overdue bucket.
    usort($focusQueue, fn($a, $b) => $b['overdue'] <=> $a['overdue'] ?: strcmp($a['date'] ?? '9999-99-99', $b['date'] ?? '9999-99-99'));

    $clients = getClientsStore()['clients'];
    // LKR-converted headline totals — additional, clearly-labelled
    // approximations for display only; the per-currency maps above are
    // untouched and remain the source of truth.
    $fx = getFxRates();
    respond(['success' => true, 'overview' => [
        'headline' => [
            'outstanding' => $outstanding,
            'overdue_amount' => $overdueAmount,
            'paid_this_month' => $paidThisMonth,
            'active_jobs' => count($activeJobs),
            'open_deal_value' => $openDealValue,
            'open_deals' => count($openDeals),
            'active_clients' => count(array_filter($clients, fn($c) => ($c['status'] ?? 'active') === 'active')),
            'open_tasks' => count($openTasks),
            'overdue_tasks' => $overdueTaskCount,
            'pending_docs' => count($pendingDocs),
            'focus_queue' => count($focusQueue),
        ],
        'headline_lkr' => [
            'outstanding' => fxConvertMapToLkr($outstanding, $fx['rates']),
            'overdue_amount' => fxConvertMapToLkr($overdueAmount, $fx['rates']),
            'paid_this_month' => fxConvertMapToLkr($paidThisMonth, $fx['rates']),
            'open_deal_value' => fxConvertMapToLkr($openDealValue, $fx['rates']),
        ],
        'fx_stale' => $fx['stale'],
        'fx_fetched_at' => $fx['fetched_at'] ? date('c', $fx['fetched_at']) : null,
        'overdue_invoices' => array_slice($overdueInvoices, 0, 6),
        'due_soon' => array_slice($dueSoon, 0, 6),
        'active_jobs_list' => $activeJobsList,
        'tasks' => array_slice($taskRows, 0, 8),
        'pending_docs' => array_slice($pendingDocs, 0, 6),
        'deals' => array_slice($openDeals, 0, 6),
        'focus_queue' => array_slice($focusQueue, 0, 8),
        'default_currency' => defaultCurrency(),
    ]]);
    break;

// ===== Job Registry (shared company-wide) =====
case 'jobs':
    if ($method !== 'GET') break;
    requireAuth();
    $store = getJobsStore();
    $jobs = array_map('decorateJob', $store['jobs']);
    // Newest first.
    usort($jobs, function ($a, $b) { return strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''); });
    respond(['success' => true, 'jobs' => $jobs, 'summary' => jobsSummary($store['jobs'])]);
    break;

case 'save-job':
    if ($method !== 'POST') break;
    $u = requireAuth();
    $store = getJobsStore();
    $now = date('c');
    $id = trim($input['id'] ?? '');

    if ($id !== '') {
        // Update existing job's fields (invoices managed via invoice endpoints).
        $found = false;
        foreach ($store['jobs'] as &$job) {
            if (($job['id'] ?? '') === $id) {
                $job = applyJobFields($job, $input);
                $job['updated_at'] = $now;
                $found = true;
                break;
            }
        }
        unset($job);
        if (!$found) respond(['success' => false, 'error' => 'Job not found'], 404);
        saveJobsStore($store);
        respond(['success' => true, 'id' => $id]);
    }

    // Create new job.
    $job = [
        'id' => 'job_' . bin2hex(random_bytes(8)),
        'job_no' => nextJobNo($store),
        'created_by' => $u['id'] ?? '',
        'created_at' => $now,
        'updated_at' => $now,
        'invoices' => [],
    ];
    $job = applyJobFields($job, $input);
    // Auto-build the default invoice schedule (advance+final, or monthly for retainers).
    $job['invoices'] = buildJobInvoices($store, $job, $input);
    $store['jobs'][] = $job;
    saveJobsStore($store);
    // Optionally spawn a starter task set linked to the job + client.
    $starterCount = 0;
    if (!empty($input['starter_tasks'])) {
        $titles = ($job['type'] ?? 'one_off') === 'retainer'
            ? ['Kickoff meeting with client', 'Send Month 1 invoice']
            : ['Kickoff meeting with client', 'Send advance invoice', 'Deliver work for client review', 'Send final invoice'];
        $tStore = getTasksStore();
        foreach ($titles as $t) {
            [$tStore, $tid] = nextTaskId($tStore);
            $tStore['tasks'][] = [
                'id' => $tid,
                'title' => $t,
                'assignee' => '',
                'client' => $job['client'] ?? '',
                'client_id' => $job['client_id'] ?? '',
                'job_id' => $job['id'],
                'job_no' => $job['job_no'],
                'due_date' => '',
                'notes' => 'Auto-created with ' . $job['job_no'] . ', ' . ($job['name'] ?? ''),
                'status' => 'open',
                'source' => 'manual',
                'clickup_doc_id' => '',
                'clickup_doc_title' => '',
                'clickup_task_id' => '',
                'clickup_task_url' => '',
                'created_at' => $now,
                'created_by' => $u['id'] ?? '',
            ];
            $starterCount++;
        }
        saveTasksStore($tStore);
    }
    respond(['success' => true, 'id' => $job['id'], 'job_no' => $job['job_no'], 'starter_tasks' => $starterCount]);
    break;

/**
 * Preview what winning a deal will create, without writing anything.
 * Feeds the confirm dialog shown when a deal is dragged/set to Won.
 */
case 'deal-win-preview':
    if ($method !== 'GET') break;
    $u = requireAuth();
    $leadId = $_GET['lead_id'] ?? '';
    $lead = null;
    foreach (getLeadsStore()['leads'] as $l) {
        if (($l['id'] ?? '') === $leadId) { $lead = $l; break; }
    }
    if (!$lead) respond(['success' => false, 'error' => 'Lead not found'], 404);

    $clientName = trim($lead['company'] ?? '') ?: trim(($lead['first_name'] ?? '') . ' ' . ($lead['last_name'] ?? ''));
    $existingClient = findClientByName(getClientsStore()['clients'], $clientName);
    $amount = dealMoney($lead['deal_amount'] ?? 0);

    // Documents already linked to this deal, so the job can inherit them.
    $cpNo = ''; $sowNo = '';
    foreach (getAllDocuments() as $d) {
        if (($d['id'] ?? '') === ($lead['cost_proposal_id'] ?? '')) $cpNo = $d['doc_no'] ?? '';
        if (($d['id'] ?? '') === ($lead['sow_id'] ?? '')) $sowNo = $d['doc_no'] ?? '';
    }

    respond(['success' => true, 'preview' => [
        'lead_id' => $lead['id'],
        'client_name' => $clientName,
        'client_exists' => (bool) $existingClient,
        'client_id' => $existingClient['id'] ?? '',
        'job_name' => trim($lead['project_context'] ?? '') ?: ($clientName . ' project'),
        'amount' => $amount,
        'currency' => normalizeCurrency($lead['deal_currency'] ?? ''),
        'linked_cost_proposal' => $cpNo,
        'linked_sow' => $sowNo,
        'already_registered' => !empty($lead['job_id']),
        'existing_job_no' => $lead['job_no'] ?? '',
    ]]);
    break;

/**
 * Win a deal: stamp the stage, ensure a client record exists, and register a
 * JOB-xxxx (with its invoice schedule) in the shared registry. This is the seam
 * where the sales pipeline hands over to delivery/finance.
 */
case 'win-deal':
    if ($method !== 'POST') break;
    $u = requireAuth();
    $leadsStore = getLeadsStore();
    $leadId = trim($input['lead_id'] ?? '');
    $targetIdx = null;
    foreach ($leadsStore['leads'] ?? [] as $i => $l) {
        if (($l['id'] ?? '') === $leadId) { $targetIdx = $i; break; }
    }
    if ($targetIdx === null) respond(['success' => false, 'error' => 'Lead not found'], 404);
    $lead = $leadsStore['leads'][$targetIdx];

    if (!empty($lead['job_id']) && jobRefById($lead['job_id'])) {
        respond(['success' => false, 'error' => 'This deal is already registered as ' . ($lead['job_no'] ?? 'a job')], 409);
    }

    // 1. Client — reuse the matching record, or create one from the lead.
    $clientName = trim($input['client_name'] ?? '') ?: (trim($lead['company'] ?? '') ?: trim(($lead['first_name'] ?? '') . ' ' . ($lead['last_name'] ?? '')));
    if ($clientName === '') respond(['success' => false, 'error' => 'A client name is required to register the job'], 400);
    $cStore = getClientsStore();
    $client = findClientByName($cStore['clients'], $clientName);
    $clientCreated = !$client;
    if (!$client) {
        $client = applyClientFields([
            'id' => 'client_' . bin2hex(random_bytes(8)),
            'client_no' => nextClientNo($cStore),
            'created_by' => $u['id'] ?? '',
            'created_at' => date('c'),
            'updated_at' => date('c'),
        ], [
            'name' => $clientName,
            'contact_name' => trim(($lead['first_name'] ?? '') . ' ' . ($lead['last_name'] ?? '')),
            'contact_email' => $lead['email'] ?? '',
            'contact_phone' => $lead['phone'] ?? '',
            'website' => $lead['website'] ?? '',
            'lead_id' => $lead['id'] ?? '',
        ]);
        $cStore['clients'][] = $client;
        saveClientsStore($cStore);
    }

    // 2. Deal amount + currency — the confirm dialog may adjust both before winning.
    if (isset($input['amount'])) setDealAmount($lead, $input['amount'], 'won', $u['id'] ?? null);
    if (isset($input['currency']) && trim($input['currency']) !== '') {
        $lead['deal_currency'] = normalizeCurrency($input['currency']);
    }

    // 3. Job + invoice schedule, inheriting the deal's linked CP/SOW.
    $jStore = getJobsStore();
    $now = date('c');
    $job = [
        'id' => 'job_' . bin2hex(random_bytes(8)),
        'job_no' => nextJobNo($jStore),
        'created_by' => $u['id'] ?? '',
        'created_at' => $now,
        'updated_at' => $now,
        'invoices' => [],
        'lead_id' => $lead['id'] ?? '',
    ];
    $job = applyJobFields($job, [
        'client_id' => $client['id'],
        'client' => $client['name'],
        'name' => trim($input['job_name'] ?? '') ?: ($clientName . ' project'),
        'category' => trim($input['category'] ?? ''),
        'type' => $input['type'] ?? 'one_off',
        'value' => dealMoney($lead['deal_amount'] ?? 0),
        // Inherits the deal's currency unless the confirm dialog overrode it.
        'currency' => normalizeCurrency($input['currency'] ?? ($lead['deal_currency'] ?? '')),
        'status' => 'open',
        'linked_cost_proposal' => trim($input['linked_cost_proposal'] ?? ''),
        'linked_sow' => trim($input['linked_sow'] ?? ''),
        'notes' => 'Registered from won deal: ' . trim(($lead['first_name'] ?? '') . ' ' . ($lead['last_name'] ?? '')),
    ]);
    $job['invoices'] = buildJobInvoices($jStore, $job, $input);
    $jStore['jobs'][] = $job;
    saveJobsStore($jStore);

    // 4. Stamp the lead: won, linked to its client and job.
    setLeadStage($lead, 'won', 'deal_won', $u['id'] ?? null);
    $lead['client_id'] = $client['id'];
    $lead['job_id'] = $job['id'];
    $lead['job_no'] = $job['job_no'];
    logActivity($lead, 'won', 'Deal won and registered as ' . $job['job_no']);
    $leadsStore['leads'][$targetIdx] = $lead;
    saveLeadsStore($leadsStore);

    respond(['success' => true, 'lead' => $lead, 'job_no' => $job['job_no'], 'job_id' => $job['id'], 'client_id' => $client['id'], 'client_created' => $clientCreated]);
    break;

/** Update a deal's amount at any stage (the value legitimately changes as it firms up). */
case 'set-deal-amount':
    if ($method !== 'POST') break;
    $u = requireAuth();
    $leadsStore = getLeadsStore();
    $leadId = trim($input['lead_id'] ?? '');
    $targetIdx = null;
    foreach ($leadsStore['leads'] ?? [] as $i => $l) {
        if (($l['id'] ?? '') === $leadId) { $targetIdx = $i; break; }
    }
    if ($targetIdx === null) respond(['success' => false, 'error' => 'Lead not found'], 404);
    $lead = $leadsStore['leads'][$targetIdx];
    $changed = setDealAmount($lead, $input['amount'] ?? 0, '', $u['id'] ?? null);
    if (isset($input['currency']) && trim($input['currency']) !== '') $lead['deal_currency'] = normalizeCurrency($input['currency']);
    $leadsStore['leads'][$targetIdx] = $lead;
    saveLeadsStore($leadsStore);
    respond(['success' => true, 'lead' => $lead, 'changed' => $changed]);
    break;

/** Link a generated CP/SOW document back to the deal it came from. */
case 'link-deal-document':
    if ($method !== 'POST') break;
    $u = requireAuth();
    $leadsStore = getLeadsStore();
    $leadId = trim($input['lead_id'] ?? '');
    $docId = trim($input['document_id'] ?? '');
    $targetIdx = null;
    foreach ($leadsStore['leads'] ?? [] as $i => $l) {
        if (($l['id'] ?? '') === $leadId) { $targetIdx = $i; break; }
    }
    if ($targetIdx === null) respond(['success' => false, 'error' => 'Lead not found'], 404);

    $doc = null;
    foreach (getAllDocuments() as $d) {
        if (($d['id'] ?? '') === $docId) { $doc = $d; break; }
    }
    if (!$doc) respond(['success' => false, 'error' => 'Document not found'], 404);

    $lead = $leadsStore['leads'][$targetIdx];
    $type = $doc['type'] ?? 'sow';
    // Linking a document normally also advances the deal to the matching
    // stage — the whole point of connecting the pipeline to the document
    // studio. The one exception: a Cost Proposal created from the Demo
    // stage's per-service Feasibility Check (advance_stage:false) links
    // without advancing, since a deal there can have several services in
    // flight and the "Deal Sent" button is what actually moves the stage,
    // only once every service has been resolved (feasible or disqualified
    // with a reason).
    $advanceStage = !array_key_exists('advance_stage', $input) || !empty($input['advance_stage']);
    $service = ''; // only ever set below for a CP link — stays empty for SOW links
    if (in_array($type, ['cp', 'cost-proposal', 'cost_proposal'], true)) {
        $lead['cost_proposal_id'] = $doc['id'];
        // A Demo-stage deal can have several services each generating their
        // OWN Cost Proposal from the Feasibility Check row — cost_proposal_id
        // above only ever holds the latest one, so also keep a per-service
        // map (service => doc id) so each row can show/link back to its own
        // CP specifically, not whichever was linked most recently overall.
        $service = trim($input['service'] ?? '');
        if ($service !== '') {
            $byService = $lead['cost_proposal_by_service'] ?? [];
            $byService[$service] = $doc['id'];
            $lead['cost_proposal_by_service'] = $byService;
            // Every CP version ever generated for this specific service
            // (not just the latest) — cost_proposal_by_service above only
            // ever holds the current one, but a service can go through
            // several negotiation rounds each with its own regenerated CP;
            // this is what the Cost Proposal stage's per-service row lists
            // under "Previous cost proposals". Append-only, newest last.
            $historyByService = $lead['cp_documents_by_service'] ?? [];
            $svcHistory = $historyByService[$service] ?? [];
            if (!in_array($doc['id'], $svcHistory, true)) $svcHistory[] = $doc['id'];
            $historyByService[$service] = $svcHistory;
            $lead['cp_documents_by_service'] = $historyByService;
            // This service's Deal Value on the Demo-stage Feasibility Check
            // row (requisitions["{service}::estimated_deal_value"]) now
            // comes from what was actually quoted in its Cost Proposal,
            // rather than staying pinned to whatever was estimated back at
            // Lead-stage qualification — the CP figure is the real number
            // once it exists. Same {amount, currency} shape
            // saveDemoFeasibilityDealValue() (index.html) writes.
            $svcAmt = extractDocumentInvestmentAmount($doc['input'] ?? []);
            if ($svcAmt !== null) {
                $requisitions = $lead['requisitions'] ?? [];
                $requisitions["{$service}::estimated_deal_value"] = ['amount' => (string) $svcAmt, 'currency' => $lead['deal_currency'] ?? defaultCurrency()];
                $lead['requisitions'] = $requisitions;
            }
        }
        if ($advanceStage && stageOrder(getLeadStage($lead)) < stageOrder('cost_proposal')) {
            setLeadStage($lead, 'cost_proposal', 'cost_proposal_linked:' . ($doc['doc_no'] ?? ''), $u['id'] ?? null);
        }
    } else {
        $lead['sow_id'] = $doc['id'];
        // Same per-service tracking as cost_proposal_by_service above — a
        // Cost-Proposal-stage deal can have several services each Won
        // independently, each generating its OWN SOW from that service's
        // negotiation row (see openSowPopupForService() in index.html).
        // sow_id only ever holds the latest one overall; this is what lets
        // each row show/link back to its own SOW specifically.
        $service = trim($input['service'] ?? '');
        if ($service !== '') {
            $byService = $lead['sow_by_service'] ?? [];
            $byService[$service] = $doc['id'];
            $lead['sow_by_service'] = $byService;
            // Every SOW version ever generated for this specific service —
            // same append-only history as cp_documents_by_service, so the
            // SOW stage's per-service card can list "Previous SOWs" the
            // same way Cost Proposal lists "Previous cost proposals".
            $sowHistoryByService = $lead['sow_documents_by_service'] ?? [];
            $svcSowHistory = $sowHistoryByService[$service] ?? [];
            if (!in_array($doc['id'], $svcSowHistory, true)) $svcSowHistory[] = $doc['id'];
            $sowHistoryByService[$service] = $svcSowHistory;
            $lead['sow_documents_by_service'] = $sowHistoryByService;
        }
        if ($advanceStage && stageOrder(getLeadStage($lead)) < stageOrder('sow')) {
            setLeadStage($lead, 'sow', 'sow_linked:' . ($doc['doc_no'] ?? ''), $u['id'] ?? null);
        }
    }
    // Sync the deal's amount to whatever figure is on the document being
    // linked — the CP/SOW is the real quote, so linking one should make the
    // deal's value match it rather than leaving a stale/zero amount sitting
    // on the deal independently of what was actually proposed. Skipped for a
    // per-service Demo Feasibility Check CP ($service set above): that
    // service's own figure was already folded into requisitions just above,
    // and the deal-level total for a multi-service deal must stay the SUM
    // across every feasible service (see sumServiceEstimatedDealValues() in
    // index.html) — overwriting it here with just this one CP's amount would
    // silently wipe out every other service's contribution to the total.
    if ($service === '') {
        $linkAmt = extractDocumentInvestmentAmount($doc['input'] ?? []);
        if ($linkAmt !== null) setDealAmount($lead, $linkAmt, '', $u['id'] ?? null);
    }
    $leadsStore['leads'][$targetIdx] = $lead;
    saveLeadsStore($leadsStore);
    respond(['success' => true, 'lead' => $lead, 'doc_no' => $doc['doc_no'] ?? '']);
    break;

case 'delete-job':
    if ($method !== 'POST') break;
    requireAuth();
    $id = $input['id'] ?? '';
    $store = getJobsStore();
    $before = count($store['jobs']);
    $store['jobs'] = array_values(array_filter($store['jobs'], function ($j) use ($id) {
        return ($j['id'] ?? '') !== $id;
    }));
    if (count($store['jobs']) === $before) respond(['success' => false, 'error' => 'Job not found'], 404);
    saveJobsStore($store);
    respond(['success' => true]);
    break;

case 'save-invoice':
    // Add a new invoice to a job, or update an existing one (amount/label/due/status).
    if ($method !== 'POST') break;
    requireAuth();
    $jobId = trim($input['job_id'] ?? '');
    $invId = trim($input['invoice_id'] ?? '');
    $store = getJobsStore();
    $target = null;
    foreach ($store['jobs'] as &$job) {
        if (($job['id'] ?? '') === $jobId) { $target = &$job; break; }
    }
    if ($target === null) respond(['success' => false, 'error' => 'Job not found'], 404);
    if (!isset($target['invoices']) || !is_array($target['invoices'])) $target['invoices'] = [];

    if ($invId !== '') {
        $found = false;
        foreach ($target['invoices'] as &$inv) {
            if (($inv['id'] ?? '') === $invId) {
                if (isset($input['label'])) $inv['label'] = trim($input['label']);
                if (isset($input['amount'])) $inv['amount'] = jobMoney($input['amount']);
                if (isset($input['due_date'])) $inv['due_date'] = trim($input['due_date']);
                if (isset($input['status'])) {
                    $st = $input['status'] === 'paid' ? 'paid' : 'unpaid';
                    $inv['status'] = $st;
                    $inv['paid_at'] = $st === 'paid' ? ($inv['paid_at'] ?? date('c')) : null;
                }
                $found = true;
                break;
            }
        }
        unset($inv);
        if (!$found) respond(['success' => false, 'error' => 'Invoice not found'], 404);
    } else {
        $target['invoices'][] = makeInvoice(
            $store,
            trim($input['label'] ?? 'Invoice'),
            $input['amount'] ?? 0,
            trim($input['due_date'] ?? ''),
            ($input['status'] ?? '') === 'paid' ? 'paid' : 'unpaid'
        );
    }
    $target['updated_at'] = date('c');
    unset($target);
    saveJobsStore($store);
    respond(['success' => true]);
    break;

case 'delete-invoice':
    if ($method !== 'POST') break;
    requireAuth();
    $jobId = trim($input['job_id'] ?? '');
    $invId = trim($input['invoice_id'] ?? '');
    $store = getJobsStore();
    foreach ($store['jobs'] as &$job) {
        if (($job['id'] ?? '') === $jobId) {
            $job['invoices'] = array_values(array_filter($job['invoices'] ?? [], function ($inv) use ($invId) {
                return ($inv['id'] ?? '') !== $invId;
            }));
            $job['updated_at'] = date('c');
            break;
        }
    }
    unset($job);
    saveJobsStore($store);
    respond(['success' => true]);
    break;

// ===== Clients (shared company-wide registry — the hub jobs/docs/tasks hang off) =====
/** ===== Channel Partners ===== */
case 'partners':
    if ($method !== 'GET') break;
    requireAuth();
    $store = getPartnersStore();
    $partners = $store['partners'];
    usort($partners, fn($a, $b) => strcasecmp($a['name'] ?? '', $b['name'] ?? ''));
    // Never expose the portal password hash or live session tokens to the
    // internal team's own view of the partner directory.
    $partners = array_map(function ($p) {
        unset($p['portal_password'], $p['portal_tokens']);
        return $p;
    }, $partners);
    respond(['success' => true, 'partners' => $partners]);
    break;

case 'save-partner':
    if ($method !== 'POST') break;
    $u = requireAuth();
    $store = getPartnersStore();
    $now = date('c');
    $id = trim($input['id'] ?? '');

    if ($id !== '') {
        $found = false;
        foreach ($store['partners'] as &$partner) {
            if (($partner['id'] ?? '') === $id) {
                $partner = applyPartnerFields($partner, $input);
                // Portal credentials: email always updates if sent; password
                // only changes if a new one was actually typed (an empty
                // field must never blank out an existing password).
                if (isset($input['portal_email']) || isset($input['portal_password'])) {
                    setPartnerPortalPassword($partner, $input['portal_email'] ?? ($partner['portal_email'] ?? ''), trim($input['portal_password'] ?? ''));
                }
                $partner['updated_at'] = $now;
                $found = true;
                $saved = $partner;
                break;
            }
        }
        unset($partner);
        if (!$found) respond(['success' => false, 'error' => 'Partner not found'], 404);
        savePartnersStore($store);
        // Never echo the password hash back to the browser.
        unset($saved['portal_password']);
        respond(['success' => true, 'partner' => $saved]);
    }

    // Create.
    $name = trim($input['name'] ?? '');
    if ($name === '') respond(['success' => false, 'error' => 'Partner name is required'], 400);
    $partner = applyPartnerFields([
        'id' => 'partner_' . bin2hex(random_bytes(8)),
        'partner_no' => nextPartnerNo($store),
        'created_by' => $u['id'] ?? '',
        'created_at' => $now,
        'updated_at' => $now,
    ], $input);
    if (isset($input['portal_email']) || isset($input['portal_password'])) {
        setPartnerPortalPassword($partner, $input['portal_email'] ?? '', trim($input['portal_password'] ?? ''));
    }
    $store['partners'][] = $partner;
    savePartnersStore($store);
    $returned = $partner;
    unset($returned['portal_password']);
    respond(['success' => true, 'partner' => $returned]);
    break;

case 'delete-partner':
    // Deals that reference this partner keep their own name/rate snapshot
    // (partnerRefFor falls back to "(deleted partner)"), so nothing else breaks.
    if ($method !== 'POST') break;
    requireAuth();
    $id = trim($input['id'] ?? '');
    if ($id === '') respond(['success' => false, 'error' => 'No partner id'], 400);
    $store = getPartnersStore();
    $before = count($store['partners']);
    $store['partners'] = array_values(array_filter($store['partners'], fn($p) => ($p['id'] ?? '') !== $id));
    if (count($store['partners']) === $before) respond(['success' => false, 'error' => 'Partner not found'], 404);
    savePartnersStore($store);
    respond(['success' => true]);
    break;

/**
 * Portal login for an external channel partner — a SEPARATE auth path from
 * the internal team's 'login' action above. A partner is never a `users`
 * row and never gets is_admin/is_super_admin; it only ever sees its own
 * record + its own referred deals (see dealsForPartner() in partners.php).
 */
case 'partner-login':
    if ($method !== 'POST') break;
    $email = trim(strtolower($input['email'] ?? ''));
    $password = $input['password'] ?? '';
    if ($email === '' || $password === '') respond(['success' => false, 'error' => 'Email and password are required'], 400);
    $store = getPartnersStore();
    foreach ($store['partners'] as &$partner) {
        if (($partner['portal_email'] ?? '') === $email && ($partner['status'] ?? 'active') === 'active'
            && !empty($partner['portal_password']) && password_verify($password, $partner['portal_password'])) {
            $token = bin2hex(random_bytes(32));
            addPartnerPortalToken($partner, $token);
            $partner['last_login_at'] = date('c');
            savePartnersStore($store);
            respond(['success' => true, 'partner' => ['id' => $partner['id'], 'name' => $partner['name']], 'token' => $token]);
        }
    }
    unset($partner);
    respond(['success' => false, 'error' => 'Invalid email or password'], 401);
    break;

case 'partner-me':
    if ($method !== 'GET') break;
    $partner = getCurrentPartner();
    if (!$partner) respond(['success' => false, 'error' => 'Not authenticated'], 401);
    respond(['success' => true, 'partner' => ['id' => $partner['id'], 'name' => $partner['name'], 'partner_no' => $partner['partner_no'] ?? '']]);
    break;

// The partner's own read-only view: their referred deals + live commission
// payout on each. Never their contact record, never other partners, never
// anything from the internal Clients/Jobs/Documents stores.
case 'partner-portal':
    if ($method !== 'GET') break;
    $partner = getCurrentPartner();
    if (!$partner) respond(['success' => false, 'error' => 'Not authenticated'], 401);
    $deals = dealsForPartner($partner['id']);
    $totalsByCurrency = [];
    foreach ($deals as $d) {
        if (($d['stage'] ?? '') === 'lost') continue;
        addToCurrencyBucket($totalsByCurrency, $d['currency'], $d['payout']);
    }
    respond(['success' => true, 'deals' => $deals, 'total_payout' => $totalsByCurrency]);
    break;

/**
 * Attach/change/clear the channel partner on a deal. Snapshots the partner's
 * current default rate onto the lead at the moment of attaching — see the
 * module docblock in partners.php for why the snapshot approach was chosen.
 */
case 'set-deal-partner':
    if ($method !== 'POST') break;
    $user = requireAuth();
    $leadsStore = getLeadsStore();
    $leadId = trim($input['id'] ?? '');
    $partnerId = trim($input['partner_id'] ?? '');

    foreach ($leadsStore['leads'] as &$lead) {
        if ($lead['id'] === $leadId) {
            if ($partnerId === '') {
                // Cleared back to a direct deal.
                $lead['partner_id'] = '';
                $lead['partner_rate_type'] = 'percentage';
                $lead['partner_rate_value'] = 0;
            } else {
                $partner = findPartnerById(getPartnersStore()['partners'], $partnerId);
                if (!$partner) respond(['success' => false, 'error' => 'Partner not found'], 404);
                $lead['partner_id'] = $partnerId;
                // Rate can be overridden per-deal (e.g. a one-off negotiated
                // rate); falls back to the partner's default.
                $rateType = $input['partner_rate_type'] ?? $partner['default_rate_type'] ?? 'percentage';
                $lead['partner_rate_type'] = $rateType === 'fixed' ? 'fixed' : 'percentage';
                $lead['partner_rate_value'] = clampPartnerRateValue(
                    $lead['partner_rate_type'],
                    $input['partner_rate_value'] ?? ($partner['default_rate_value'] ?? 0)
                );
            }
            $lead['updated_at'] = date('c');
            saveLeadsStore($leadsStore);
            respond(['success' => true, 'lead' => $lead]);
        }
    }
    respond(['success' => false, 'error' => 'Lead not found'], 404);
    break;

// A deal has one partner (who referred it), but different services on the
// same deal can carry different commission arrangements — e.g. Website
// Development at 10% and Branding at a flat LKR 15,000. Stored per-service
// in partner_rates_by_service, keyed by service name; a service with no
// entry there falls back to the deal-level partner_rate_type/value (the
// same default set-deal-partner manages), so existing single-rate deals
// keep working unchanged until a rep actually overrides one service.
case 'set-service-partner-rate':
    if ($method !== 'POST') break;
    $user = requireAuth();
    $leadsStore = getLeadsStore();
    $leadId = trim($input['id'] ?? '');
    $service = trim($input['service'] ?? '');
    if ($service === '') respond(['success' => false, 'error' => 'Missing service'], 400);

    foreach ($leadsStore['leads'] as &$lead) {
        if ($lead['id'] === $leadId) {
            if (empty($lead['partner_id'])) respond(['success' => false, 'error' => 'No partner on this deal'], 400);
            $rates = $lead['partner_rates_by_service'] ?? [];
            $rateType = ($input['rate_type'] ?? 'percentage') === 'fixed' ? 'fixed' : 'percentage';
            $rates[$service] = ['type' => $rateType, 'value' => clampPartnerRateValue($rateType, $input['rate_value'] ?? 0)];
            $lead['partner_rates_by_service'] = $rates;
            $lead['updated_at'] = date('c');
            saveLeadsStore($leadsStore);
            respond(['success' => true, 'lead' => $lead]);
        }
    }
    respond(['success' => false, 'error' => 'Lead not found'], 404);
    break;

case 'clients':
    if ($method !== 'GET') break;
    $u = requireAuth();
    $store = getClientsStore();
    // First visit on an existing install: promote the client names already on
    // jobs/documents/tasks into real client records so the page isn't empty.
    // Gated by 'backfilled_once' (not just an empty list) so deliberately
    // deleting every client doesn't resurrect them from old job/doc/task names
    // on the next page load.
    if (empty($store['clients']) && !$store['backfilled_once']) {
        $store['backfilled_once'] = true;
        backfillClients($store, $u['id'] ?? '');
        saveClientsStore($store);
    }
    $allJobs = getJobsStore()['jobs'];
    $allDocs = getAllDocuments();
    $allTasks = getTasksStore()['tasks'];
    $clients = $store['clients'];
    usort($clients, fn($a, $b) => strcmp(clientNameKey($a['name'] ?? ''), clientNameKey($b['name'] ?? '')));
    $clients = array_map(function ($c) use ($allJobs, $allDocs, $allTasks) {
        $c['rollup'] = clientRollup(
            array_values(array_filter($allJobs, fn($j) => clientOwnsRecord($c, $j))),
            array_values(array_filter($allDocs, fn($d) => clientOwnsRecord($c, $d))),
            array_values(array_filter($allTasks, fn($t) => clientOwnsRecord($c, $t)))
        );
        return $c;
    }, $clients);
    respond(['success' => true, 'clients' => $clients, 'summary' => clientsSummary($store['clients'], $allJobs)]);
    break;

case 'client-workspace':
    // Everything about one client in a single payload (record + jobs + invoices + documents + tasks).
    if ($method !== 'GET') break;
    requireAuth();
    $id = trim($_GET['id'] ?? '');
    foreach (getClientsStore()['clients'] as $c) {
        if (($c['id'] ?? '') === $id) respond(['success' => true] + clientWorkspace($c));
    }
    respond(['success' => false, 'error' => 'Client not found'], 404);
    break;

case 'save-client':
    if ($method !== 'POST') break;
    $u = requireAuth();
    $store = getClientsStore();
    $now = date('c');
    $id = trim($input['id'] ?? '');
    $name = trim($input['name'] ?? '');

    if ($id !== '') {
        // Update. If the name changes, rewrite it on every linked job/task/document.
        $found = false;
        foreach ($store['clients'] as &$client) {
            if (($client['id'] ?? '') === $id) {
                $oldName = $client['name'] ?? '';
                $client = applyClientFields($client, $input);
                $client['updated_at'] = $now;
                if ($client['name'] !== $oldName) {
                    $dupe = findClientByName($store['clients'], $client['name']);
                    if ($dupe && ($dupe['id'] ?? '') !== $id) respond(['success' => false, 'error' => 'Another client is already named "' . $client['name'] . '" (' . ($dupe['client_no'] ?? '') . ')'], 409);
                }
                $found = true;
                $saved = $client;
                break;
            }
        }
        unset($client);
        if (!$found) respond(['success' => false, 'error' => 'Client not found'], 404);
        saveClientsStore($store);
        if (($saved['name'] ?? '') !== ($oldName ?? '')) propagateClientRename($id, $saved['name']);
        respond(['success' => true, 'client' => $saved]);
    }

    // Create.
    if ($name === '') respond(['success' => false, 'error' => 'Client name is required'], 400);
    $existing = findClientByName($store['clients'], $name);
    if ($existing) {
        // Lead conversion passes if_exists=use to link to the existing record instead of erroring.
        if (($input['if_exists'] ?? '') === 'use') respond(['success' => true, 'client' => $existing, 'existing' => true]);
        respond(['success' => false, 'error' => 'A client named "' . $existing['name'] . '" already exists (' . ($existing['client_no'] ?? '') . ')'], 409);
    }
    $client = applyClientFields([
        'id' => 'client_' . bin2hex(random_bytes(8)),
        'client_no' => nextClientNo($store),
        'created_by' => $u['id'] ?? '',
        'created_at' => $now,
        'updated_at' => $now,
    ], $input);
    $store['clients'][] = $client;
    saveClientsStore($store);
    // Adopt any pre-existing jobs/docs/tasks whose typed client name matches.
    $adopt = getClientsStore();
    backfillClients($adopt, $u['id'] ?? '');
    saveClientsStore($adopt);
    respond(['success' => true, 'client' => $client]);
    break;

case 'delete-client':
    // Removes the registry record only — jobs/documents/tasks keep their client
    // name string, so nothing else is lost.
    if ($method !== 'POST') break;
    requireAuth();
    $id = trim($input['id'] ?? '');
    if ($id === '') respond(['success' => false, 'error' => 'No client id'], 400);
    $store = getClientsStore();
    $before = count($store['clients']);
    // Remove any uploaded client files from disk along with the record.
    foreach ($store['clients'] as $c) {
        if (($c['id'] ?? '') !== $id) continue;
        foreach (($c['files'] ?? []) as $f) {
            $fp = DATA_DIR . '/uploads/' . basename($f['file_path'] ?? '');
            if ($fp !== DATA_DIR . '/uploads/' && file_exists($fp)) @unlink($fp);
        }
    }
    $store['clients'] = array_values(array_filter($store['clients'], fn($c) => ($c['id'] ?? '') !== $id));
    if (count($store['clients']) === $before) respond(['success' => false, 'error' => 'Client not found'], 404);
    // A deliberate delete down to zero clients is not "never set up" — mark
    // backfill as already done so the next page load doesn't resurrect
    // whatever was just removed from old job/doc/task client names.
    $store['backfilled_once'] = true;
    saveClientsStore($store);
    respond(['success' => true]);
    break;

case 'backfill-clients':
    // Manual "Sync from existing data" — create client records for any client
    // names on jobs/documents/tasks that don't have one yet.
    if ($method !== 'POST') break;
    $u = requireAuth();
    $store = getClientsStore();
    $created = backfillClients($store, $u['id'] ?? '');
    saveClientsStore($store);
    respond(['success' => true, 'created' => $created, 'total' => count($store['clients'])]);
    break;

case 'upload-client-file':
    // Attach an arbitrary shared file (brief, contract, asset...) to a client.
    // Multipart form-data: id = client id, file = the upload.
    $u = requireAuth();
    $id = $_POST['id'] ?? '';
    if (!$id) respond(['success' => false, 'error' => 'Client ID required'], 400);
    if (empty($_FILES['file'])) respond(['success' => false, 'error' => 'No file uploaded'], 400);
    $file = $_FILES['file'];
    if ($file['error'] !== UPLOAD_ERR_OK) respond(['success' => false, 'error' => 'Upload error'], 400);
    if ($file['size'] > 20 * 1024 * 1024) respond(['success' => false, 'error' => 'File too large (max 20 MB)'], 400);
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowedExt = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'zip', 'txt', 'csv', 'md'];
    if (!in_array($ext, $allowedExt, true)) respond(['success' => false, 'error' => 'File type .' . $ext . ' is not allowed'], 400);
    $store = getClientsStore();
    $target = null;
    foreach ($store['clients'] as &$client) {
        if (($client['id'] ?? '') === $id) { $target = &$client; break; }
    }
    if ($target === null) respond(['success' => false, 'error' => 'Client not found'], 404);
    $uploadDir = DATA_DIR . '/uploads';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
    $fileId = 'cf_' . bin2hex(random_bytes(6));
    $filename = $id . '_' . $fileId . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $uploadDir . '/' . $filename)) respond(['success' => false, 'error' => 'Could not save file'], 500);
    if (!isset($target['files']) || !is_array($target['files'])) $target['files'] = [];
    $target['files'][] = [
        'id' => $fileId,
        'name' => $file['name'],
        'file_path' => $filename,
        'size' => (int) $file['size'],
        'uploaded_by' => docOwnerName($u['id'] ?? ''),
        'uploaded_at' => date('c'),
    ];
    $target['updated_at'] = date('c');
    unset($target, $client);
    saveClientsStore($store);
    respond(['success' => true, 'file_id' => $fileId]);
    break;

case 'download-client-file':
    // Streams a client file back to the browser (?client_id=..&file_id=..).
    requireAuth();
    $cid = trim($_GET['client_id'] ?? '');
    $fid = trim($_GET['file_id'] ?? '');
    $entry = null;
    foreach (getClientsStore()['clients'] as $c) {
        if (($c['id'] ?? '') !== $cid) continue;
        foreach (($c['files'] ?? []) as $f) {
            if (($f['id'] ?? '') === $fid) { $entry = $f; break 2; }
        }
    }
    if (!$entry) respond(['success' => false, 'error' => 'File not found'], 404);
    $fp = DATA_DIR . '/uploads/' . basename($entry['file_path']);
    if (!file_exists($fp)) respond(['success' => false, 'error' => 'File missing on server'], 404);
    $ext = strtolower(pathinfo($fp, PATHINFO_EXTENSION));
    $mimes = [
        'pdf' => 'application/pdf', 'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'gif' => 'image/gif', 'webp' => 'image/webp', 'zip' => 'application/zip',
        'txt' => 'text/plain', 'csv' => 'text/csv', 'md' => 'text/markdown',
    ];
    header('Content-Type: ' . ($mimes[$ext] ?? 'application/octet-stream'));
    header('Content-Disposition: attachment; filename="' . addslashes($entry['name'] ?? 'file.' . $ext) . '"');
    header('Content-Length: ' . filesize($fp));
    header('Cache-Control: private');
    readfile($fp);
    exit;

case 'delete-client-file':
    if ($method !== 'POST') break;
    requireAuth();
    $cid = trim($input['client_id'] ?? '');
    $fid = trim($input['file_id'] ?? '');
    $store = getClientsStore();
    $found = false;
    foreach ($store['clients'] as &$client) {
        if (($client['id'] ?? '') !== $cid) continue;
        foreach (($client['files'] ?? []) as $k => $f) {
            if (($f['id'] ?? '') === $fid) {
                $fp = DATA_DIR . '/uploads/' . basename($f['file_path'] ?? '');
                if (file_exists($fp)) @unlink($fp);
                array_splice($client['files'], $k, 1);
                $client['updated_at'] = date('c');
                $found = true;
                break 2;
            }
        }
    }
    unset($client);
    if (!$found) respond(['success' => false, 'error' => 'File not found'], 404);
    saveClientsStore($store);
    respond(['success' => true]);
    break;

// ===== Help & Support (feedback + support tickets) =====
case 'tickets':
    if ($method !== 'GET') break;
    $u = requireAuth();
    $isAdmin = !empty($u['is_admin']) || !empty($u['is_super_admin']);
    // Hub mode: this deployment is a central hub (ingest secret set). In hub mode
    // it's a client-ticket inbox — we don't file our own tickets, we only manage
    // tickets forwarded in from client (spoke) deployments.
    $adminCfg = getAdmin();
    $hubMode = trim($adminCfg['ticket_ingest_secret'] ?? '') !== '';
    // Admins may request the full company-wide queue; everyone else sees only their own.
    $scopeAll = $isAdmin && (($_GET['scope'] ?? '') === 'all');
    $store = getTicketsStore();
    $tickets = $store['tickets'];
    if ($hubMode && $isAdmin) {
        // On a hub, admins see client tickets: forwarded from a spoke
        // (source === 'client'), or logged manually on a client's behalf via
        // the Client/Brand field (ticketNeedsClosingChecklist() — same tag
        // either way, just a plain 'client' field with no 'source').
        $tickets = array_values(array_filter($tickets, function ($t) {
            return ($t['source'] ?? '') === 'client' || trim($t['client'] ?? '') !== '';
        }));
    } elseif (!$scopeAll) {
        // Otherwise, non-admins (and admins not requesting 'all') see only their own.
        $tickets = array_values(array_filter($tickets, function ($t) use ($u) {
            return ($t['created_by'] ?? '') === ($u['id'] ?? '');
        }));
    }
    // Summary reflects the full scoped set (so the "Closed" count on the stat
    // cards / "Closed Tickets" button is accurate) — filtering below only
    // affects which tickets are returned in the main list.
    $summary = ticketsSummary($tickets);
    // Closed tickets are done-and-dusted noise on the main queue: hidden by
    // default. The "Closed Tickets" button flips this to show ONLY closed
    // ones (view_closed=1), rather than mixing them back into the live queue.
    $viewClosed = ($_GET['view_closed'] ?? '') === '1';
    $tickets = array_values(array_filter($tickets, fn($t) => (($t['status'] ?? 'open') === 'closed') === $viewClosed));
    // Newest first.
    usort($tickets, function ($a, $b) { return strcmp($b['created_at'] ?? '', $a['created_at'] ?? ''); });
    respond([
        'success' => true,
        'tickets' => $tickets,
        'summary' => $summary,
        'is_admin' => $isAdmin,
        'hub_mode' => $hubMode,
        'scope' => $scopeAll ? 'all' : 'mine',
        'view_closed' => $viewClosed,
    ]);
    break;

case 'save-ticket':
    if ($method !== 'POST') break;
    $u = requireAuth();
    $store = getTicketsStore();
    $now = date('c');
    $id = trim($input['id'] ?? '');

    if ($id !== '') {
        // Edit an existing ticket's fields. Owner or admin only.
        $isAdmin = !empty($u['is_admin']) || !empty($u['is_super_admin']);
        $found = false;
        foreach ($store['tickets'] as &$ticket) {
            if (($ticket['id'] ?? '') === $id) {
                if (($ticket['created_by'] ?? '') !== ($u['id'] ?? '') && !$isAdmin) {
                    respond(['success' => false, 'error' => 'Not allowed'], 403);
                }
                $ticket = applyTicketFields($ticket, $input);
                $ticket['updated_at'] = $now;
                $found = true;
                break;
            }
        }
        unset($ticket);
        if (!$found) respond(['success' => false, 'error' => 'Ticket not found'], 404);
        saveTicketsStore($store);
        respond(['success' => true, 'id' => $id]);
    }

    // Create a new ticket.
    $isAdminCreator = !empty($u['is_admin']) || !empty($u['is_super_admin']);
    $ticket = [
        'id' => 'tkt_' . bin2hex(random_bytes(8)),
        'ticket_no' => nextTicketNo($store),
        'status' => 'open',
        'created_by' => $u['id'] ?? '',
        'created_by_name' => $u['name'] ?? '',
        'created_by_email' => $u['email'] ?? '',
        'created_at' => $now,
        'updated_at' => $now,
        'replies' => [],
        // An admin (a Brand Manager) may tag a manually-logged ticket with the
        // client/brand it's about — e.g. one the client raised by phone or email
        // rather than through their own deployment. This is what puts it under
        // the "Closing a client support ticket" SOP; see ticketNeedsClosingChecklist().
        'client' => $isAdminCreator ? trim($input['client'] ?? '') : '',
    ];
    $ticket = applyTicketFields($ticket, $input);
    if ($ticket['subject'] === '' || $ticket['message'] === '') {
        respond(['success' => false, 'error' => 'Subject and message are required'], 400);
    }
    $store['tickets'][] = $ticket;
    saveTicketsStore($store);
    // Best-effort email out to the vendor support address (never blocks submission).
    notifySupportEmail($ticket, 'new');
    // Light up the bell for admins on this deployment (locally-filed ticket).
    notifyAdminsOfTicket($ticket);
    // Best-effort: if this deployment is a spoke, forward a copy to the central hub.
    forwardTicketToHub($ticket);
    respond(['success' => true, 'id' => $ticket['id'], 'ticket_no' => $ticket['ticket_no']]);
    break;

case 'ingest-ticket':
    // HUB side: receive a ticket forwarded from a client (spoke) deployment.
    // Public endpoint (a server calls it, not a logged-in user) — authenticated
    // solely by the shared secret configured on this hub. Disabled unless a
    // 'ticket_ingest_secret' is set, so a standalone copy never accepts pushes.
    if ($method !== 'POST') break;
    $admin = getAdmin();
    $ingestSecret = trim($admin['ticket_ingest_secret'] ?? '');
    if ($ingestSecret === '') respond(['success' => false, 'error' => 'Ingest not enabled'], 404);
    if (!hash_equals($ingestSecret, trim($input['secret'] ?? ''))) {
        respond(['success' => false, 'error' => 'Invalid secret'], 403);
    }
    $client = trim($input['client'] ?? '');
    $remote = $input['ticket'] ?? null;
    $replyUrl = trim($input['reply_url'] ?? '');
    $created = ingestForwardedTicket($client, $remote, $replyUrl);
    if ($created === null) respond(['success' => false, 'error' => 'Invalid ticket payload'], 400);
    respond(['success' => true, 'id' => $created['id'], 'ticket_no' => $created['ticket_no']]);
    break;

case 'ingest-reply':
    // SPOKE side: receive a staff reply pushed back from the hub and append it to
    // the local ticket's thread, so the user sees the vendor's answer in-app.
    // Public endpoint, authenticated by the shared secret (same value the spoke
    // uses to talk to the hub: ticket_hub_secret).
    if ($method !== 'POST') break;
    $admin = getAdmin();
    $replySecret = trim($admin['ticket_hub_secret'] ?? '');
    if ($replySecret === '') respond(['success' => false, 'error' => 'Reply ingest not enabled'], 404);
    if (!hash_equals($replySecret, trim($input['secret'] ?? ''))) {
        respond(['success' => false, 'error' => 'Invalid secret'], 403);
    }
    $localId = trim($input['remote_id'] ?? '');  // the spoke's own ticket id
    $reply = $input['reply'] ?? null;
    $ok = ingestReplyFromHub($localId, $reply);
    if (!$ok) respond(['success' => false, 'error' => 'Ticket not found or empty reply'], 404);
    respond(['success' => true]);
    break;

case 'ingest-status':
    // SPOKE side: receive a status change pushed down from the hub (e.g. an
    // admin marked the hub's copy Resolved/Closed) and apply it to the local
    // ticket, so the client sees the same status without needing to reply
    // first. Same auth as 'ingest-reply': the shared ticket_hub_secret.
    if ($method !== 'POST') break;
    $admin = getAdmin();
    $statusSecret = trim($admin['ticket_hub_secret'] ?? '');
    if ($statusSecret === '') respond(['success' => false, 'error' => 'Status ingest not enabled'], 404);
    if (!hash_equals($statusSecret, trim($input['secret'] ?? ''))) {
        respond(['success' => false, 'error' => 'Invalid secret'], 403);
    }
    $localId = trim($input['remote_id'] ?? '');  // the spoke's own ticket id
    $status = trim($input['status'] ?? '');
    $ok = ingestStatusFromHub($localId, $status);
    if (!$ok) respond(['success' => false, 'error' => 'Ticket not found or invalid status'], 404);
    respond(['success' => true]);
    break;

case 'ingest-client-reply':
    // HUB side (Phase 3): receive a CLIENT user's reply pushed up from a spoke and
    // append it to the hub's copy of the ticket, so the vendor sees the follow-up
    // in the same thread. The mirror of 'ingest-reply'. Public endpoint, gated by
    // the same shared secret used to accept forwarded tickets.
    if ($method !== 'POST') break;
    $admin = getAdmin();
    $ingestSecret = trim($admin['ticket_ingest_secret'] ?? '');
    if ($ingestSecret === '') respond(['success' => false, 'error' => 'Ingest not enabled'], 404);
    if (!hash_equals($ingestSecret, trim($input['secret'] ?? ''))) {
        respond(['success' => false, 'error' => 'Invalid secret'], 403);
    }
    $remoteId = trim($input['remote_id'] ?? '');  // the spoke's own ticket id
    $reply = $input['reply'] ?? null;
    $ok = ingestClientReply($remoteId, $reply);
    if (!$ok) respond(['success' => false, 'error' => 'Ticket not found or empty reply'], 404);
    respond(['success' => true]);
    break;

case 'ticket-reply':
    if ($method !== 'POST') break;
    $u = requireAuth();
    $isAdmin = !empty($u['is_admin']) || !empty($u['is_super_admin']);
    $ticketId = trim($input['ticket_id'] ?? '');
    $message = trim($input['message'] ?? '');
    if ($message === '') respond(['success' => false, 'error' => 'Reply cannot be empty'], 400);
    $store = getTicketsStore();
    $target = null;
    foreach ($store['tickets'] as &$ticket) {
        if (($ticket['id'] ?? '') === $ticketId) { $target = &$ticket; break; }
    }
    if ($target === null) respond(['success' => false, 'error' => 'Ticket not found'], 404);
    // Owner or admin may post to the thread.
    if (($target['created_by'] ?? '') !== ($u['id'] ?? '') && !$isAdmin) {
        respond(['success' => false, 'error' => 'Not allowed'], 403);
    }
    if (!isset($target['replies']) || !is_array($target['replies'])) $target['replies'] = [];
    $reply = [
        'id' => 'rep_' . bin2hex(random_bytes(6)),
        'author_id' => $u['id'] ?? '',
        'author_name' => $u['name'] ?? '',
        'is_staff' => $isAdmin,
        'message' => $message,
        'created_at' => date('c'),
    ];
    $target['replies'][] = $reply;
    $target['updated_at'] = date('c');
    // Auto status, so triage reflects reality without a manual dropdown click:
    // - staff picks up an Open ticket by replying -> In Progress.
    // - the requester follows up on a Resolved/Closed ticket -> it's clearly not
    //   done, so it reopens and comes back into view (mirrors ingestClientReply(),
    //   the same behaviour already used for the hub/spoke reply path).
    if ($isAdmin && ($target['status'] ?? 'open') === 'open') {
        $target['status'] = 'in_progress';
    } elseif (!$isAdmin && in_array($target['status'] ?? '', ['resolved', 'closed'], true)) {
        $target['status'] = 'open';
    }
    $ticketCopy = $target;
    unset($target);
    saveTicketsStore($store);
    notifySupportEmail($ticketCopy, 'reply', $reply);
    // Live ticket thread: instantly append for anyone with this ticket open.
    if (function_exists('pusherTriggerTicket')) pusherTriggerTicket($ticketId, 'new-reply', $reply);
    // Live bell: notify the OTHER party about this reply.
    if ($isAdmin) {
        // Staff replied -> notify the ticket owner (if a local user).
        notifyOwnerOfReply($ticketCopy, $reply);
    } else {
        // Owner replied -> notify admins there's activity.
        notifyAdminsOfTicket($ticketCopy);
    }
    // Phase 2: if this is a client-originated (forwarded) ticket and the reply is
    // from staff, push it back to the spoke so the client's user sees it in-app.
    if (($ticketCopy['source'] ?? '') === 'client' && $isAdmin) {
        sendReplyToSpoke($ticketCopy, $reply);
    }
    // Phase 3 (the return leg): on a SPOKE, a reply from the ticket's own user
    // goes UP to the hub so the vendor sees the client's follow-up. Guarded to
    // locally-raised tickets ('source' is only 'client' on the hub's copies), so
    // a hub reply is never bounced back to itself.
    if (($ticketCopy['source'] ?? '') !== 'client' && !$isAdmin) {
        sendReplyToHub($ticketCopy, $reply);
    }
    respond(['success' => true]);
    break;

case 'update-ticket-status':
    // Change status/priority. Admins only (triage on the deployed system).
    // For client-tagged tickets, the "Closing a client support ticket" SOP
    // restricts WHO can move status (the assigned Brand Manager, or a
    // super-admin) and requires the closing checklist before Closed — see
    // ticketCanChangeStatus()/ticketChecklistComplete() in support.php.
    if ($method !== 'POST') break;
    $u = requireAdmin();
    global $VALID_TICKET_STATUS, $VALID_TICKET_PRIORITY;
    $ticketId = trim($input['id'] ?? '');
    $closingNote = trim($input['closing_note'] ?? '');
    $store = getTicketsStore();
    $found = false;
    $statusChanged = false;
    $ticketCopy = null;
    foreach ($store['tickets'] as &$ticket) {
        if (($ticket['id'] ?? '') === $ticketId) {
            if (isset($input['status']) && in_array($input['status'], $VALID_TICKET_STATUS, true)) {
                $newStatus = $input['status'];
                if ($newStatus !== $ticket['status']) {
                    if (!ticketCanChangeStatus($ticket, $u)) {
                        $ownerName = trim($ticket['assigned_to_name'] ?? '') ?: 'the assigned Brand Manager';
                        respond(['success' => false, 'error' => 'Only ' . $ownerName . ' can change this ticket\'s status'], 403);
                    }
                    if ($newStatus === 'closed' && ticketNeedsClosingChecklist($ticket)
                        && $closingNote === '' && !ticketChecklistComplete($ticket)) {
                        respond(['success' => false, 'error' => 'Complete the closing checklist first (fix verified, root cause, version, ClickUp task) — or close with a note if there was no client response.'], 400);
                    }
                    // Moving status on an unassigned client ticket claims ownership
                    // of it ("the Brand Manager ... receives the ticket").
                    if (trim($ticket['assigned_to'] ?? '') === '' && ticketNeedsClosingChecklist($ticket)) {
                        $ticket['assigned_to'] = $u['id'] ?? '';
                        $ticket['assigned_to_name'] = $u['name'] ?? '';
                    }
                }
                $statusChanged = $ticket['status'] !== $newStatus;
                $ticket['status'] = $newStatus;
                if ($newStatus === 'closed') {
                    $ticket['closed_at'] = date('c');
                    $ticket['closed_by'] = $u['id'] ?? '';
                    $ticket['closed_by_name'] = $u['name'] ?? '';
                    if ($closingNote !== '') $ticket['closing_note'] = $closingNote;
                }
            }
            if (isset($input['priority']) && in_array($input['priority'], $VALID_TICKET_PRIORITY, true)) {
                $ticket['priority'] = $input['priority'];
            }
            $ticket['updated_at'] = date('c');
            $ticketCopy = $ticket;
            $found = true;
            break;
        }
    }
    unset($ticket);
    if (!$found) respond(['success' => false, 'error' => 'Ticket not found'], 404);
    saveTicketsStore($store);
    // If this is a client-forwarded ticket, push the status change down to the
    // spoke so clicking Resolved/Closed/etc. on our copy updates their local
    // ticket too — the mirror of the reply push in 'ticket-reply'.
    if ($statusChanged && ($ticketCopy['source'] ?? '') === 'client') {
        sendStatusToSpoke($ticketCopy);
    }
    respond(['success' => true]);
    break;

case 'assign-ticket':
    // Sets the ticket's owning "Brand Manager" per the ticket-closing SOP.
    // Any admin may claim/reassign — ownership isn't a fixed role on the user
    // record, just whoever is on the hook for this ticket right now.
    if ($method !== 'POST') break;
    $u = requireAdmin();
    $ticketId = trim($input['id'] ?? '');
    $assigneeId = trim($input['assigned_to'] ?? '');
    $store = getTicketsStore();
    $found = false;
    foreach ($store['tickets'] as &$ticket) {
        if (($ticket['id'] ?? '') === $ticketId) {
            if ($assigneeId === '') {
                $ticket['assigned_to'] = '';
                $ticket['assigned_to_name'] = '';
            } else {
                $assignee = null;
                foreach (getUsers() as $usr) {
                    if (($usr['id'] ?? '') === $assigneeId) { $assignee = $usr; break; }
                }
                if (!$assignee) respond(['success' => false, 'error' => 'User not found'], 404);
                $ticket['assigned_to'] = $assigneeId;
                $ticket['assigned_to_name'] = $assignee['name'] ?? '';
            }
            $ticket['updated_at'] = date('c');
            $found = true;
            break;
        }
    }
    unset($ticket);
    if (!$found) respond(['success' => false, 'error' => 'Ticket not found'], 404);
    saveTicketsStore($store);
    respond(['success' => true]);
    break;

case 'mark-ticket-fix-ready':
    // The Fixer's step in the ticket-closing SOP ("Fix done"): log what
    // changed and the version, deployed and QA'd. Doesn't touch status or
    // contact the client — verifying and telling the client stays the
    // assigned Brand Manager's job.
    if ($method !== 'POST') break;
    $u = requireAdmin();
    $ticketId = trim($input['id'] ?? '');
    $version = trim($input['version'] ?? '');
    $note = trim($input['note'] ?? '');
    if ($version === '' || $note === '') {
        respond(['success' => false, 'error' => 'Version and a note about the change are required'], 400);
    }
    $store = getTicketsStore();
    $found = false;
    $ticketCopy = null;
    $reply = null;
    foreach ($store['tickets'] as &$ticket) {
        if (($ticket['id'] ?? '') === $ticketId) {
            $ticket['fix_version'] = $version;
            $ticket['fix_note'] = $note;
            $ticket['fix_by'] = $u['id'] ?? '';
            $ticket['fix_by_name'] = $u['name'] ?? '';
            $ticket['fix_at'] = date('c');
            if (!isset($ticket['replies']) || !is_array($ticket['replies'])) $ticket['replies'] = [];
            $reply = [
                'id' => 'rep_' . bin2hex(random_bytes(6)),
                'author_id' => $u['id'] ?? '',
                'author_name' => $u['name'] ?? '',
                'is_staff' => true,
                'is_fix_note' => true,
                'message' => 'Fix ready — v' . $version . ': ' . $note,
                'created_at' => date('c'),
            ];
            $ticket['replies'][] = $reply;
            $ticket['updated_at'] = date('c');
            $ticketCopy = $ticket;
            $found = true;
            break;
        }
    }
    unset($ticket);
    if (!$found) respond(['success' => false, 'error' => 'Ticket not found'], 404);
    saveTicketsStore($store);
    // Tell the assigned Brand Manager it's ready to verify on production.
    $ownerId = trim($ticketCopy['assigned_to'] ?? '');
    if ($ownerId !== '' && $ownerId !== ($u['id'] ?? '')) {
        addUserNotification($ownerId, [
            'notif_key' => 'ticket_fix_ready_' . $ticketId . '_' . $ticketCopy['fix_at'],
            'type' => 'ticket_reply',
            'title' => '🔧 Fix ready to verify',
            'body' => ($u['name'] ?? 'Dev') . ' shipped v' . $version . ' for ' . ($ticketCopy['ticket_no'] ?? 'your ticket'),
            'ticket_id' => $ticketId,
        ]);
    }
    if (function_exists('pusherTriggerTicket')) pusherTriggerTicket($ticketId, 'new-reply', $reply);
    respond(['success' => true]);
    break;

case 'update-ticket-checklist':
    // The closing checklist from the ticket-closing SOP. Same ownership rule
    // as status changes — only the assigned Brand Manager (or a super-admin)
    // may tick it, since ticking "fix verified" is what unlocks Closed.
    if ($method !== 'POST') break;
    $u = requireAdmin();
    $ticketId = trim($input['id'] ?? '');
    $store = getTicketsStore();
    $found = false;
    foreach ($store['tickets'] as &$ticket) {
        if (($ticket['id'] ?? '') === $ticketId) {
            if (!ticketCanChangeStatus($ticket, $u)) {
                $ownerName = trim($ticket['assigned_to_name'] ?? '') ?: 'the assigned Brand Manager';
                respond(['success' => false, 'error' => 'Only ' . $ownerName . ' can update this ticket\'s checklist'], 403);
            }
            $c = $ticket['checklist'] ?? [];
            if (array_key_exists('fix_verified', $input)) $c['fix_verified'] = (bool) $input['fix_verified'];
            if (array_key_exists('root_cause', $input)) $c['root_cause'] = trim((string) $input['root_cause']);
            if (array_key_exists('clickup_task', $input)) $c['clickup_task'] = trim((string) $input['clickup_task']);
            if (array_key_exists('changelog_updated', $input)) $c['changelog_updated'] = (bool) $input['changelog_updated'];
            if (array_key_exists('recurring_flagged', $input)) $c['recurring_flagged'] = (bool) $input['recurring_flagged'];
            $ticket['checklist'] = $c;
            $ticket['updated_at'] = date('c');
            $found = true;
            break;
        }
    }
    unset($ticket);
    if (!$found) respond(['success' => false, 'error' => 'Ticket not found'], 404);
    saveTicketsStore($store);
    respond(['success' => true]);
    break;

case 'delete-ticket':
    if ($method !== 'POST') break;
    $u = requireAuth();
    $isAdmin = !empty($u['is_admin']) || !empty($u['is_super_admin']);
    $id = trim($input['id'] ?? '');
    $store = getTicketsStore();
    // Owner or admin may delete.
    $target = null;
    foreach ($store['tickets'] as $t) {
        if (($t['id'] ?? '') === $id) { $target = $t; break; }
    }
    if ($target === null) respond(['success' => false, 'error' => 'Ticket not found'], 404);
    if (($target['created_by'] ?? '') !== ($u['id'] ?? '') && !$isAdmin) {
        respond(['success' => false, 'error' => 'Not allowed'], 403);
    }
    // Push the deletion to the spoke BEFORE removing our own copy — sendDeleteToSpoke()
    // needs the ticket's reply_url/remote_id, which only exist on this (hub) copy.
    if (($target['source'] ?? '') === 'client') {
        sendDeleteToSpoke($target);
    }
    $store['tickets'] = array_values(array_filter($store['tickets'], function ($t) use ($id) {
        return ($t['id'] ?? '') !== $id;
    }));
    saveTicketsStore($store);
    respond(['success' => true]);
    break;

case 'ingest-delete':
    // SPOKE side: receive a delete pushed down from the hub (an admin deleted
    // the hub's copy of a client-forwarded ticket) and remove the local copy
    // too. Same auth as 'ingest-reply'/'ingest-status': the shared
    // ticket_hub_secret.
    if ($method !== 'POST') break;
    $admin = getAdmin();
    $deleteSecret = trim($admin['ticket_hub_secret'] ?? '');
    if ($deleteSecret === '') respond(['success' => false, 'error' => 'Delete ingest not enabled'], 404);
    if (!hash_equals($deleteSecret, trim($input['secret'] ?? ''))) {
        respond(['success' => false, 'error' => 'Invalid secret'], 403);
    }
    $localId = trim($input['remote_id'] ?? '');  // the spoke's own ticket id
    $ok = ingestDeleteFromHub($localId);
    if (!$ok) respond(['success' => false, 'error' => 'Invalid ticket id'], 400);
    respond(['success' => true]);
    break;

case 'request-password-reset':
    if ($method !== 'POST') break;
    $email = trim(strtolower($input['email'] ?? ''));
    $users = getUsers();
    foreach ($users as &$u) {
        if ($u['email'] === $email) {
            $resetToken = bin2hex(random_bytes(32));
            $u['reset_token'] = $resetToken;
            $u['reset_expires'] = date('c', time() + 3600);
            saveUsers($users);
            // Return token to admin. In production, email the link.
            respond(['success' => true, 'message' => 'If this email exists, a reset link has been generated.', 'reset_token' => $resetToken]);
        }
    }
    // Same message for non-existent emails (security)
    respond(['success' => true, 'message' => 'If this email exists, a reset link has been generated.']);
    break;

case 'reset-password':
    if ($method !== 'POST') break;
    $resetToken = $input['reset_token'] ?? '';
    $newPassword = $input['new_password'] ?? '';
    if (strlen($newPassword) < 6) respond(['success' => false, 'error' => 'Password must be at least 6 characters'], 400);

    $users = getUsers();
    foreach ($users as &$u) {
        if (($u['reset_token'] ?? '') === $resetToken && !empty($u['reset_expires']) && strtotime($u['reset_expires']) > time()) {
            $u['password'] = password_hash($newPassword, PASSWORD_DEFAULT);
            unset($u['reset_token']);
            unset($u['reset_expires']);
            // Security: a password reset invalidates ALL existing device sessions.
            $u['tokens'] = [];
            unset($u['token']);
            saveUsers($users);
            respond(['success' => true, 'message' => 'Password reset successful. Please login with your new password.']);
        }
    }
    respond(['success' => false, 'error' => 'Invalid or expired reset token'], 400);
    break;

case 'login':
    if ($method !== 'POST') break;
    $email = trim(strtolower($input['email'] ?? ''));
    $password = $input['password'] ?? '';
    $users = getUsers();
    foreach ($users as &$user) {
        if ($user['email'] === $email && password_verify($password, $user['password'])) {
            $token = generateToken();
            addUserToken($user, $token); // multi-device: keep existing sessions alive
            $user['last_login_at'] = date('c');
            $user['session_start'] = date('c');
            $user['last_active_at'] = date('c');
            saveUsers($users);
            respond(['success' => true, 'user' => ['id' => $user['id'], 'name' => $user['name'], 'email' => $user['email'], 'is_admin' => ($user['is_admin'] ?? false) || ($user['is_super_admin'] ?? false), 'is_super_admin' => $user['is_super_admin'] ?? false], 'token' => $token]);
        }
    }
    respond(['success' => false, 'error' => 'Invalid email or password'], 401);
    break;

case 'me':
    if ($method !== 'GET') break;
    $user = getCurrentUser();
    if ($user) {
        $users = getUsers();
        foreach ($users as &$u) {
            if ($u['id'] === $user['id']) {
                $u['last_active_at'] = date('c');
                break;
            }
        }
        saveUsers($users);
        $userData = getUserData($user['id']);
        respond(['success' => true, 'user' => [
            'id' => $user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'is_admin' => ($user['is_admin'] ?? false) || ($user['is_super_admin'] ?? false),
            'is_super_admin' => $user['is_super_admin'] ?? false,
            'onboarding_completed' => $userData['onboarding_completed'] ?? true
        ], 'default_currency' => defaultCurrency(), 'currencies' => supportedCurrencies()]);
    }
    respond(['success' => false, 'error' => 'Not authenticated'], 401);
    break;

case 'complete-onboarding':
    if ($method !== 'POST') break;
    $user = requireAuth();
    $userData = getUserData($user['id']);
    $userData['onboarding_completed'] = true;
    saveUserData($user['id'], $userData);
    respond(['success' => true]);
    break;

case 'admin-settings':
    if ($method === 'GET') {
        requireAdmin();
        $admin = getAdmin();
        $masked = [];
        foreach ($admin as $k => $v) {
            // Mask API keys and secrets
            if ((strpos($k, '_key') !== false || strpos($k, '_secret') !== false || strpos($k, '_token') !== false) && $v && is_string($v)) {
                $masked[$k] = '********' . substr($v, -4);
            } else {
                $masked[$k] = $v;
            }
        }
        $masked['default_currency'] = defaultCurrency();
        respond(['success' => true, 'settings' => $masked, 'currencies' => supportedCurrencies()]);
    }
    if ($method === 'POST') {
        requireAdmin();
        $admin = getAdmin();

        // LLM API keys (+ ClickUp for Document Studio)
        foreach (['groq_key', 'cerebras_key', 'gemini_key', 'anthropic_key', 'clickup_token', 'resend_key'] as $k) {
            if (isset($input[$k]) && strpos($input[$k], '****') === false) $admin[$k] = trim($input[$k]);
        }
        // ClickUp workspace/list ids (not secrets, no masking needed).
        foreach (['clickup_workspace_id', 'clickup_list_id'] as $k) {
            if (isset($input[$k])) $admin[$k] = trim($input[$k]);
        }
        if (isset($input['default_provider'])) $admin['default_provider'] = $input['default_provider'];
        // Studio default currency — what new deals and jobs start as.
        if (isset($input['default_currency'])) {
            $c = strtoupper(trim($input['default_currency']));
            if (in_array($c, supportedCurrencies(), true)) $admin['default_currency'] = $c;
        }
        // Per-provider model choice (empty = use the current default for that provider).
        foreach (['groq_model', 'cerebras_model', 'gemini_model', 'anthropic_model'] as $k) {
            if (isset($input[$k])) $admin[$k] = trim($input[$k]);
        }
        if (isset($input['requisitions'])) {
            $admin['requisitions'] = $input['requisitions'];
            // Mark this as an explicit save so getAdmin()'s one-time v1 migration
            // never runs again and overwrites an intentionally cleared/edited list.
            $admin['requisitions_version'] = 'sales_discovery_v1';
        }
        if (isset($input['service_options']) && is_array($input['service_options'])) {
            $admin['service_options'] = array_values(array_filter(array_map('trim', $input['service_options'])));
        }

        // Help & Support: where user-submitted tickets are emailed (and the From address).
        if (isset($input['support_email'])) $admin['support_email'] = trim($input['support_email']);
        if (isset($input['support_from'])) $admin['support_from'] = trim($input['support_from']);
        // Ticket sync (Phase 1). SPOKE role: forward tickets to a central hub.
        if (isset($input['ticket_hub_url'])) $admin['ticket_hub_url'] = trim($input['ticket_hub_url']);
        if (isset($input['ticket_hub_secret']) && strpos($input['ticket_hub_secret'], '****') === false) $admin['ticket_hub_secret'] = trim($input['ticket_hub_secret']);
        if (isset($input['ticket_client_name'])) $admin['ticket_client_name'] = trim($input['ticket_client_name']);
        // HUB role: accept forwarded tickets when this secret is set (empty = disabled).
        if (isset($input['ticket_ingest_secret']) && strpos($input['ticket_ingest_secret'], '****') === false) $admin['ticket_ingest_secret'] = trim($input['ticket_ingest_secret']);
        // Sales outreach: verified From address for emails sent to leads from the system.
        if (isset($input['outreach_from'])) $admin['outreach_from'] = trim($input['outreach_from']);

        // Team Chat real-time (Pusher). key/secret auto-mask on GET (via _key/_secret
        // suffix); app_id/cluster are public and safe to expose to the client.
        foreach (['pusher_key', 'pusher_secret'] as $k) {
            if (isset($input[$k]) && strpos($input[$k], '****') === false) $admin[$k] = trim($input[$k]);
        }
        if (isset($input['pusher_app_id'])) $admin['pusher_app_id'] = trim($input['pusher_app_id']);
        if (isset($input['pusher_cluster'])) $admin['pusher_cluster'] = trim($input['pusher_cluster']);

        saveAdmin($admin);
        respond(['success' => true]);
    }
    break;

case 'get-requisitions':
    if ($method !== 'GET') break;
    requireAuth();
    $admin = getAdmin();
    respond(['success' => true, 'requisitions' => $admin['requisitions'] ?? []]);
    break;

case 'test-api':
    if ($method !== 'POST') break;
    $provider = $input['provider'] ?? 'groq';
    $apiKey = $input['api_key'] ?? '';
    if (!$apiKey || strpos($apiKey, '****') !== false) { $admin = getAdmin(); $apiKey = $admin[$provider . '_key'] ?? ''; }
    if (!$apiKey) respond(['success' => false, 'error' => 'No API key for ' . $provider]);
    // No fallback here: this must report on the exact key being tested, or a
    // broken key would look fine because another provider answered for it.
    $res = callLLM($provider, $apiKey, 'Say "Connection successful!" exactly.', true);
    respond($res['success'] ? ['success' => true, 'message' => 'Connection successful!'] : ['success' => false, 'error' => $res['error']]);
    break;

case 'users':
    if ($method !== 'GET') break;
    requireAdmin();
    $requestingUser = requireAdmin();
    $isSuperAdmin = $requestingUser['is_super_admin'] ?? false;
    $allUsers = getUsers();
    if (!$isSuperAdmin) {
        $allUsers = array_values(array_filter($allUsers, fn($u) => empty($u['is_super_admin'])));
    }
    $users = array_map(function($u) { return ['id' => $u['id'], 'name' => $u['name'], 'email' => $u['email'], 'is_admin' => $u['is_admin'] ?? false, 'is_super_admin' => $u['is_super_admin'] ?? false, 'created_at' => $u['created_at'] ?? '', 'last_login_at' => $u['last_login_at'] ?? null, 'session_start' => $u['session_start'] ?? null, 'last_active_at' => $u['last_active_at'] ?? null]; }, $allUsers);
    respond(['success' => true, 'users' => $users]);
    break;

// Lightweight team member list (id/name only) for assignee dropdowns — any
// authenticated user can call this, unlike 'users' which is admin-only.
case 'team-members':
    if ($method !== 'GET') break;
    requireAuth();
    $members = array_map(function($u) { return ['id' => $u['id'], 'name' => $u['name'] ?: $u['email']]; }, getUsers());
    respond(['success' => true, 'members' => $members]);
    break;

// Records a lightweight heartbeat (current page) so admins can see session activity.
case 'activity-ping':
    if ($method !== 'POST') break;
    $user = requireAuth();
    $page = trim($input['page'] ?? $_GET['page'] ?? '');
    recordActivityPing(
        $user['id'],
        $user['name'] ?? $user['email'] ?? '',
        !empty($user['is_super_admin']) ? 'super_admin' : (!empty($user['is_admin']) ? 'admin' : 'rep'),
        $page
    );
    respond(['success' => true]);
    break;

// Admin analytics: groups heartbeat pings into sessions (gap > 30 min starts a new session).
case 'user-sessions':
    if ($method !== 'GET') break;
    $requestingUser = requireAdmin();
    $isSuperAdmin = $requestingUser['is_super_admin'] ?? false;

    $days = intval($_GET['days'] ?? 7);
    if ($days < 1 || $days > 90) $days = 7;

    $allPings = loadActivityPings($days);

    $userPings = [];
    foreach ($allPings as $p) $userPings[$p['user_id']][] = $p;

    $userSessions = [];
    foreach ($userPings as $uid => $pings) {
        $sessions = [];
        $sessStart = null; $sessEnd = null; $sessPages = []; $prevT = null;
        foreach ($pings as $p) {
            $t = strtotime($p['pinged_at']);
            if ($prevT === null || ($t - $prevT) > 1800) {
                if ($sessStart !== null) {
                    $dur = max(1, round(($prevT - strtotime($sessStart)) / 60) + 1);
                    $sessions[] = ['start' => $sessStart, 'end' => $sessEnd, 'duration_mins' => $dur, 'pages' => array_values(array_unique($sessPages))];
                }
                $sessStart = $p['pinged_at']; $sessPages = [];
            }
            $sessEnd = $p['pinged_at'];
            if (!empty($p['page'])) $sessPages[] = $p['page'];
            $prevT = $t;
        }
        if ($sessStart !== null) {
            $dur = max(1, round(($prevT - strtotime($sessStart)) / 60) + 1);
            $sessions[] = ['start' => $sessStart, 'end' => $sessEnd, 'duration_mins' => $dur, 'pages' => array_values(array_unique($sessPages))];
        }
        $userSessions[$uid] = $sessions;
    }

    // Build per-user summary. Super admin sees everyone; regular admin excludes super admins.
    $result = [];
    $allUsersForSessions = getUsers();
    foreach ($allUsersForSessions as $u) {
        if (!$isSuperAdmin && !empty($u['is_super_admin'])) continue;
        $uid = $u['id'];
        $sessions = $userSessions[$uid] ?? [];
        $lastPing = !empty($sessions) ? end($sessions) : null;
        $today = date('Y-m-d');
        $todayMins = 0;
        foreach ($sessions as $s) {
            if (substr($s['start'], 0, 10) === $today) $todayMins += $s['duration_mins'];
        }
        $result[] = [
            'user_id' => $uid,
            'user_name' => $u['name'] ?? $u['email'],
            'user_role' => !empty($u['is_super_admin']) ? 'super_admin' : (!empty($u['is_admin']) ? 'admin' : 'rep'),
            'last_seen' => $lastPing['end'] ?? ($u['last_active_at'] ?? null),
            'last_page' => !empty($lastPing['pages']) ? end($lastPing['pages']) : null,
            'active_mins_today' => min($todayMins, 480),
            'sessions_this_period' => count($sessions),
            'sessions' => array_reverse($sessions),
        ];
    }
    usort($result, function($a, $b) { return strcmp($b['last_seen'] ?? '', $a['last_seen'] ?? ''); });
    respond(['success' => true, 'sessions' => $result, 'days' => $days]);
    break;

case 'create-user':
    if ($method !== 'POST') break;
    requireAdmin();
    $name = trim($input['name'] ?? '');
    $email = trim(strtolower($input['email'] ?? ''));
    $isAdmin = (bool)($input['is_admin'] ?? false);
    $isSuperAdmin = (bool)($input['is_super_admin'] ?? false);
    if ($isSuperAdmin) requireSuperAdmin();
    if (!$name || !$email) respond(['success' => false, 'error' => 'Name and email required'], 400);
    $users = getUsers();
    foreach ($users as $u) { if ($u['email'] === $email) respond(['success' => false, 'error' => 'Email exists'], 400); }
    // Use the admin-provided password if given (min 8 chars), otherwise auto-generate one.
    $customPassword = (string)($input['password'] ?? '');
    if ($customPassword !== '') {
        if (strlen($customPassword) < 8) respond(['success' => false, 'error' => 'Password must be at least 8 characters'], 400);
        $password = $customPassword;
    } else {
        $password = generatePassword();
    }
    $userId = generateId('user_');
    $users[] = ['id' => $userId, 'name' => $name, 'email' => $email, 'password' => password_hash($password, PASSWORD_DEFAULT), 'token' => '', 'created_at' => date('c'), 'is_admin' => $isAdmin, 'is_super_admin' => $isSuperAdmin];
    saveUsers($users);
    saveUserData($userId, ['leads' => [], 'settings' => ['sender_name' => $name, 'sender_company' => 'Levata', 'sender_title' => '', 'company_description' => '', 'value_proposition' => '', 'social_proof' => '', 'calendar_link' => '', 'email_tone' => 'professional', 'signature' => ''], 'onboarding_completed' => false]);
    respond(['success' => true, 'user' => ['id' => $userId, 'name' => $name, 'email' => $email, 'is_admin' => $isAdmin, 'is_super_admin' => $isSuperAdmin], 'password' => $password]);
    break;

case 'update-user':
    if ($method !== 'POST') break;
    $admin = requireAdmin();
    $userId = $input['id'] ?? '';
    $users = getUsers();
    $newPass = null;
    foreach ($users as &$u) {
        if ($u['id'] === $userId) {
            if (!empty($u['is_super_admin']) && empty($admin['is_super_admin'])) respond(['success' => false, 'error' => 'Cannot edit a super admin'], 403);
            if (!empty($input['name'])) $u['name'] = trim($input['name']);
            if (!empty($input['email'])) $u['email'] = trim(strtolower($input['email']));
            if (isset($input['is_admin'])) $u['is_admin'] = (bool)$input['is_admin'];
            if (isset($input['is_super_admin'])) { requireSuperAdmin(); $u['is_super_admin'] = (bool)$input['is_super_admin']; }
            // Set a specific password if provided (min 8 chars); else if reset_password is set, auto-generate one.
            if (!empty($input['new_password'])) {
                if (strlen((string)$input['new_password']) < 8) respond(['success' => false, 'error' => 'Password must be at least 8 characters'], 400);
                $u['password'] = password_hash((string)$input['new_password'], PASSWORD_DEFAULT);
                $newPass = (string)$input['new_password'];
            } elseif (!empty($input['reset_password'])) {
                $newPass = generatePassword();
                $u['password'] = password_hash($newPass, PASSWORD_DEFAULT);
            }
            saveUsers($users);
            $res = ['success' => true];
            if ($newPass) $res['new_password'] = $newPass;
            respond($res);
        }
    }
    respond(['success' => false, 'error' => 'User not found'], 404);
    break;

case 'delete-user':
    if ($method !== 'POST') break;
    $admin = requireAdmin();
    $userId = $input['id'] ?? '';
    if ($userId === $admin['id']) respond(['success' => false, 'error' => 'Cannot delete yourself'], 400);
    $target = null;
    foreach (getUsers() as $u) { if ($u['id'] === $userId) { $target = $u; break; } }
    if ($target && !empty($target['is_super_admin']) && empty($admin['is_super_admin'])) respond(['success' => false, 'error' => 'Cannot delete a super admin'], 403);
    $users = array_values(array_filter(getUsers(), function($u) use ($userId) { return $u['id'] !== $userId; }));
    saveUsers($users);
    respond(['success' => true]);
    break;

case 'impersonate':
    if ($method !== 'POST') break;
    requireSuperAdmin();
    $targetId = $input['user_id'] ?? '';
    $users = getUsers();
    foreach ($users as &$u) {
        if ($u['id'] === $targetId) {
            $token = bin2hex(random_bytes(32));
            addUserToken($u, $token); // multi-device: don't evict the target's real sessions
            saveUsers($users);
            respond(['success' => true, 'token' => $token, 'user' => ['id' => $u['id'], 'name' => $u['name'], 'email' => $u['email'], 'is_admin' => $u['is_admin'] ?? false, 'is_super_admin' => $u['is_super_admin'] ?? false]]);
        }
    }
    respond(['success' => false, 'error' => 'User not found'], 404);
    break;

case 'user-leads':
    if ($method !== 'GET') break;
    requireSuperAdmin();
    $targetId = $_GET['user_id'] ?? '';
    if (!$targetId) respond(['success' => false, 'error' => 'user_id required'], 400);
    $targetData = getUserData($targetId);
    respond(['success' => true, 'leads' => $targetData['leads'] ?? []]);
    break;

case 'reset-user-data':
    if ($method !== 'POST') break;
    requireSuperAdmin();
    $targetId = $input['user_id'] ?? '';
    if (!$targetId) respond(['success' => false, 'error' => 'user_id required'], 400);
    $existing = getUserData($targetId);
    $existing['leads'] = [];
    saveUserData($targetId, $existing);
    respond(['success' => true]);
    break;

case 'leads':
    if ($method !== 'GET') break;
    $user = requireAuth();
    $admin = getAdmin();
    // Team-wide by default (the pipeline is a shared store) — an optional
    // owner_id narrows to one rep's own leads, same filter shape as the
    // existing status/source_type/search filters below.
    $leads = getLeadsStore()['leads'];
    $ownerFilter = trim($_GET['owner_id'] ?? '');
    if ($ownerFilter !== '') {
        $leads = array_filter($leads, fn($l) => ($l['owner_id'] ?? '') === $ownerFilter);
    }

    // Filter out soft-deleted leads (unless requesting trash)
    $showTrash = ($_GET['trash'] ?? '') === 'true';
    if ($showTrash) {
        $leads = array_filter($leads, function($l) { return !empty($l['deleted_at']); });
    } else {
        $leads = array_filter($leads, function($l) { return empty($l['deleted_at']); });
    }

    $status = $_GET['status'] ?? $_GET['stage'] ?? null;
    if ($status && $status !== 'all') {
        $targetStage = legacyStatusToStage($status);
        $leads = array_filter($leads, function($l) use ($targetStage) { return getLeadStage($l) === $targetStage; });
    }

    // Direct (came to us ourselves) vs Partner (referred by a channel partner).
    $sourceType = $_GET['source_type'] ?? 'all';
    if ($sourceType === 'direct') {
        $leads = array_filter($leads, function($l) { return empty($l['partner_id']); });
    } elseif ($sourceType === 'partner') {
        $leads = array_filter($leads, function($l) { return !empty($l['partner_id']); });
    }

    // Search filter
    $search = $_GET['search'] ?? '';
    if ($search) {
        $search = strtolower($search);
        $leads = array_filter($leads, function($l) use ($search) {
            return stripos(($l['first_name'] ?? '') . ' ' . ($l['last_name'] ?? ''), $search) !== false
                || stripos($l['company'] ?? '', $search) !== false
                || stripos($l['email'] ?? '', $search) !== false;
        });
    }

    usort($leads, function($a, $b) { return strtotime($b['created_at'] ?? '0') - strtotime($a['created_at'] ?? '0'); });

    // Pagination
    $page = max(1, intval($_GET['page'] ?? 1));
    $perPage = min(100, max(10, intval($_GET['per_page'] ?? 50)));
    $total = count($leads);
    $totalPages = max(1, ceil($total / $perPage));
    $paginatedLeads = array_slice(array_values($leads), ($page - 1) * $perPage, $perPage);

    respond([
        'success' => true,
        'leads' => $paginatedLeads,
        'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'pages' => $totalPages],
        'requisitions' => $admin['requisitions'] ?? [],
        // So the pipeline table can show "Direct" vs. a partner name/payout
        // without a second round trip.
        'partners' => getPartnersStore()['partners'],
        'service_options' => $admin['service_options'] ?? []
    ]);
    break;

case 'lead':
    $user = requireAuth();
    $userData = getUserData($user['id']);
    
    if ($method === 'GET' && isset($_GET['id'])) {
        $leadsStore = getLeadsStore();
        foreach ($leadsStore['leads'] as $l) { if ($l['id'] === $_GET['id']) respond(['success' => true, 'lead' => $l]); }
        respond(['success' => false, 'error' => 'Not found'], 404);
    }
    
    if ($method === 'POST') {
        // Validate fields before creating lead
        $email = trim($input['email'] ?? '');
        $linkedin = trim($input['linkedin'] ?? '');
        $website = trim($input['website'] ?? '');
        $phone = trim($input['phone'] ?? '');
        $website = validateLeadContactFields($email, $linkedin, $website, $phone);

        $source = normalizeLeadSource($input['source'] ?? 'manual');
        $isWarm = !empty($input['warm']) || (($input['urgency_flag'] ?? '') === 'warm');
        $requestedStage = trim($input['stage'] ?? '');
        $initialStage = legacyStatusToStage($requestedStage ?: initialStageForSource($source, $isWarm));

        $lead = [
            'id' => generateId('lead_'),
            'first_name' => sanitizeInput($input['first_name'] ?? ''),
            'last_name' => sanitizeInput($input['last_name'] ?? ''),
            'email' => $email,
            'phone' => sanitizeInput($input['phone'] ?? ''),
            'company' => sanitizeInput($input['company'] ?? ''),
            'title' => sanitizeInput($input['title'] ?? ''),
            'industry' => sanitizeInput($input['industry'] ?? ''),
            'country' => sanitizeInput($input['country'] ?? ''),
            'website' => $website,
            'linkedin' => $linkedin,
            'company_size' => sanitizeInput($input['company_size'] ?? ''),
            'notes' => sanitizeInput($input['notes'] ?? ''),
            // Owner defaults to whoever's creating it, but can be assigned to
            // any real team member at creation (the Add Lead form's Owner
            // dropdown) — validated against the real users table so a bad id
            // can never silently orphan a lead to a nonexistent owner.
            'owner_id' => (function () use ($input, $user) {
                $requested = trim($input['owner_id'] ?? '');
                if ($requested === '') return $user['id'];
                foreach (getUsers() as $u) { if ($u['id'] === $requested) return $requested; }
                return $user['id'];
            })(),
            // Deal value stays zero/unset at creation (still starts at
            // Qualified — see the New Lead redesign). The channel partner
            // CAN be set at creation now (who referred this deal is known
            // up front), but the commission payout stays hidden in the UI
            // until the deal reaches Cost Proposal, since there's no deal
            // value yet to compute it against.
            'deal_amount' => dealMoney($input['deal_amount'] ?? 0),
            'deal_currency' => normalizeCurrency($input['deal_currency'] ?? ''),
            'partner_id' => '',
            'partner_rate_type' => 'percentage',
            'partner_rate_value' => 0,
            'services' => [],
            'services_other' => '',
            'engagement_method' => [],
            'generated_questions' => [],
            'qualified_research' => null,
            'meeting_link' => '',
            'demo_checklist' => [],
            'demo_checklist_answers' => [],
            'demo_transcript' => '',
            'demo_feasibility' => [],
            'enrichment' => '',
            'requisitions' => null,
            'source' => $source,
            'source_detail' => sanitizeInput($input['source_detail'] ?? ''),
            'assigned_to' => sanitizeInput($input['assigned_to'] ?? ''),
            'stage' => $initialStage,
            'status' => stageToLegacyStatus($initialStage),
            'stage_entered_at' => date('c'),
            'stage_history' => [],
            'urgency_flag' => $source === 'inbound' ? 'high' : sanitizeInput($input['urgency_flag'] ?? 'normal'),
            'call_history' => [],
            'rejection_reason' => '',
            'consultation_type' => sanitizeInput($input['consultation_type'] ?? ''),
            'emails_sent' => 0,
            'last_email_type' => null,
            'last_action' => 'created',
            'last_action_at' => date('c'),
            'email_history' => [],
            'created_at' => date('c'),
            'updated_at' => date('c'),
        ];
        // Channel partner at creation time — same validation/rate-fallback
        // logic as set-deal-partner, so a lead created with a partner
        // behaves identically to one that had a partner attached later.
        $partnerId = trim($input['partner_id'] ?? '');
        if ($partnerId !== '') {
            $partner = findPartnerById(getPartnersStore()['partners'], $partnerId);
            if ($partner) {
                $lead['partner_id'] = $partnerId;
                $rateType = $input['partner_rate_type'] ?? $partner['default_rate_type'] ?? 'percentage';
                $lead['partner_rate_type'] = $rateType === 'fixed' ? 'fixed' : 'percentage';
                $lead['partner_rate_value'] = clampPartnerRateValue(
                    $lead['partner_rate_type'],
                    $input['partner_rate_value'] ?? ($partner['default_rate_value'] ?? 0)
                );
            }
        }
        $lead = normalizeLeadForMapping($lead);
        $userData['leads'][] = $lead;
        saveUserData($user['id'], $userData);
        respond(['success' => true, 'lead' => $lead], 201);
    }
    
    if ($method === 'PUT' && isset($_GET['id'])) {
        // Validate fields before updating
        if (isset($input['email']) && !empty($input['email'])) {
            if (!filter_var(trim($input['email']), FILTER_VALIDATE_EMAIL)) {
                respond(['success' => false, 'error' => 'Invalid email format. Please enter a valid email address.'], 400);
            }
        }
        if (isset($input['linkedin']) && !empty($input['linkedin'])) {
            if (stripos($input['linkedin'], 'linkedin.com') === false) {
                respond(['success' => false, 'error' => 'Invalid LinkedIn URL. Please enter a valid LinkedIn profile URL.'], 400);
            }
        }
        // Auto-prepend https:// to website if missing
        if (isset($input['website']) && !empty($input['website'])) {
            if (!preg_match('/^https?:\/\/|^www\./i', $input['website'])) {
                $input['website'] = 'https://' . $input['website'];
            }
        }

        $leadsStore = getLeadsStore();
        foreach ($leadsStore['leads'] as &$lead) {
            if ($lead['id'] === $_GET['id']) {
                foreach (['first_name', 'last_name', 'email', 'phone', 'company', 'title', 'industry', 'country', 'website', 'linkedin', 'company_size', 'notes', 'enrichment', 'requisitions', 'source', 'source_detail', 'assigned_to', 'urgency_flag', 'rejection_reason', 'consultation_type'] as $f) {
                    if (isset($input[$f])) $lead[$f] = trim($input[$f]);
                }
                $requestedStage = trim($input['stage'] ?? $input['status'] ?? '');
                if ($requestedStage !== '') {
                    $prevStage = getLeadStage($lead);
                    setLeadStage($lead, $requestedStage, 'manual_update', $user['id']);
                    if ($prevStage !== $requestedStage) {
                        logActivity($lead, 'stage_change', 'Stage changed to ' . $requestedStage);
                    }
                }
                $lead = normalizeLeadForMapping($lead);
                $lead['updated_at'] = date('c');
                saveLeadsStore($leadsStore);
                respond(['success' => true, 'lead' => $lead]);
            }
        }
        respond(['success' => false, 'error' => 'Not found'], 404);
    }
    
    if ($method === 'DELETE' && isset($_GET['id'])) {
        $id = $_GET['id'];
        $leadsStore = getLeadsStore();
        foreach ($leadsStore['leads'] as &$l) {
            if ($l['id'] === $id) {
                $l['deleted_at'] = date('c');
                $l['deleted_by'] = $user['id'];
                logActivity($l, 'deleted', 'Moved to trash');
                break;
            }
        }
        unset($l);
        saveLeadsStore($leadsStore);
        respond(['success' => true, 'message' => 'Lead moved to trash']);
    }
    break;

case 'permanent-delete':
    if ($method !== 'POST') break;
    $user = requireAuth();
    $leadsStore = getLeadsStore();
    $leadId = $input['id'] ?? '';
    // Only allow permanent delete on trashed leads
    $found = false;
    foreach ($leadsStore['leads'] as $l) {
        if ($l['id'] === $leadId) {
            if (empty($l['deleted_at'])) respond(['success' => false, 'error' => 'Lead must be in trash first'], 400);
            $found = true;
            break;
        }
    }
    if (!$found) respond(['success' => false, 'error' => 'Lead not found'], 404);
    $leadsStore['leads'] = array_values(array_filter($leadsStore['leads'], function($l) use ($leadId) {
        return $l['id'] !== $leadId;
    }));
    saveLeadsStore($leadsStore);
    respond(['success' => true]);
    break;

case 'restore-lead':
    if ($method !== 'POST') break;
    $user = requireAuth();
    $leadsStore = getLeadsStore();
    $leadId = $input['id'] ?? '';

    foreach ($leadsStore['leads'] as &$l) {
        if ($l['id'] === $leadId) {
            unset($l['deleted_at']);
            unset($l['deleted_by']);
            $l['updated_at'] = date('c');
            logActivity($l, 'restored', 'Restored from trash');
            saveLeadsStore($leadsStore);
            respond(['success' => true, 'message' => 'Lead restored']);
        }
    }
    respond(['success' => false, 'error' => 'Lead not found'], 404);
    break;

case 'empty-trash':
    if ($method !== 'POST') break;
    $user = requireAuth();
    $leadsStore = getLeadsStore();
    $leadsStore['leads'] = array_values(array_filter($leadsStore['leads'], function($l) {
        return empty($l['deleted_at']);
    }));
    saveLeadsStore($leadsStore);
    respond(['success' => true, 'message' => 'Trash emptied']);
    break;

case 'save-requisitions':
    if ($method !== 'POST') break;
    $user = requireAuth();
    $leadsStore = getLeadsStore();
    $leadId = $input['lead_id'] ?? '';
    $requisitions = $input['requisitions'] ?? null;

    foreach ($leadsStore['leads'] as &$lead) {
        if ($lead['id'] === $leadId) {
            $lead['requisitions'] = $requisitions;
            $lead['updated_at'] = date('c');
            saveLeadsStore($leadsStore);
            respond(['success' => true, 'lead' => $lead]);
        }
    }
    respond(['success' => false, 'error' => 'Lead not found'], 404);
    break;

case 'save-call-outcome':
    if ($method !== 'POST') break;
    $user = requireAuth();
    $leadsStore = getLeadsStore();
    $leadId = $input['lead_id'] ?? '';

    foreach ($leadsStore['leads'] as &$lead) {
        if ($lead['id'] === $leadId) {
            $outcome = $input['outcome'] ?? '';
            $outcomeConfig = macktilesCallOutcomeConfig($outcome);
            if (!$outcomeConfig) {
                respond(['success' => false, 'error' => 'Invalid call outcome'], 400);
            }
            $lead['call_outcome'] = $outcome;
            $lead['requisitions'] = $input['requisitions'] ?? $lead['requisitions'];
            $lead['call_notes'] = $input['notes'] ?? '';
            $lead['next_action'] = $input['next_action'] ?? $outcomeConfig['next_action'];
            $lead['followup_date'] = $input['followup_date'] ?? null;
            if ($outcome === 'not_right_time_park_90' && empty($lead['followup_date'])) {
                $lead['followup_date'] = date('Y-m-d', strtotime('+90 days'));
            }
            if (in_array($outcome, ['not_interested', 'wrong_number', 'not_right_time_park_90'])) {
                $lead['rejection_reason'] = $outcomeConfig['label'];
                $lead['disqualified_reason'] = $outcomeConfig['label'];
            }
            if ($outcome === 'consultation_booked') {
                $lead['consultation_type'] = $input['consultation_type'] ?? $lead['consultation_type'] ?? 'phone consultation';
            }
            $targetStage = $input['stage'] ?? $input['status'] ?? $outcomeConfig['stage'];
            setLeadStage($lead, $targetStage, 'call_outcome:' . $outcome, $user['id']);
            $lead['last_action'] = 'call_logged';
            $lead['last_action_at'] = date('c');
            $lead['updated_at'] = date('c');
            $callHistory = $lead['call_history'] ?? [];
            $pendingIndex = null;
            for ($i = count($callHistory) - 1; $i >= 0; $i--) {
                if (($callHistory[$i]['status'] ?? '') === 'pending') {
                    $pendingIndex = $i;
                    break;
                }
            }
            $entry = [
                'id' => $pendingIndex !== null ? $callHistory[$pendingIndex]['id'] : 'call_' . bin2hex(random_bytes(8)),
                'started_at' => $pendingIndex !== null ? ($callHistory[$pendingIndex]['started_at'] ?? date('c')) : ($lead['call_started_at'] ?? date('c')),
                'completed_at' => date('c'),
                'status' => 'completed',
                'outcome' => $outcome,
                'outcome_label' => $outcomeConfig['label'],
                'notes' => $lead['call_notes'],
                'rep_id' => $user['id'],
                'rep_name' => $user['name'] ?? $user['email']
            ];
            if ($pendingIndex !== null) $callHistory[$pendingIndex] = $entry;
            else $callHistory[] = $entry;
            $lead['call_history'] = $callHistory;
            logActivity($lead, 'call_logged', 'Call outcome: ' . ($outcomeConfig['label'] ?? $outcome));
            saveLeadsStore($leadsStore);

            respond(['success' => true, 'lead' => $lead]);
        }
    }
    respond(['success' => false, 'error' => 'Lead not found'], 404);
    break;

/**
 * Add a timestamped note to a deal. Notes are append-only history (who wrote
 * what, when) rather than a single overwritable field — replaces the old
 * call-outcome "Notes" box, which was wiped on every save.
 */
case 'add-lead-note':
    if ($method !== 'POST') break;
    $user = requireAuth();
    $leadsStore = getLeadsStore();
    $leadId = $input['id'] ?? '';
    $text = trim($input['note'] ?? '');
    if ($text === '') respond(['success' => false, 'error' => 'Note cannot be empty'], 400);

    foreach ($leadsStore['leads'] as &$lead) {
        if ($lead['id'] === $leadId) {
            logActivity($lead, 'note', $text, ['actor' => $user['name'] ?? $user['email'] ?? '']);
            $lead['updated_at'] = date('c');
            saveLeadsStore($leadsStore);
            respond(['success' => true, 'lead' => $lead]);
        }
    }
    respond(['success' => false, 'error' => 'Lead not found'], 404);
    break;

case 'update-lead':
    if ($method !== 'POST') break;
    $user = requireAuth();
    $leadsStore = getLeadsStore();
    $leadId = $input['id'] ?? '';

    if (isset($input['meeting_link']) && trim($input['meeting_link']) !== '' && !filter_var(trim($input['meeting_link']), FILTER_VALIDATE_URL)) {
        respond(['success' => false, 'error' => 'Meeting link must be a valid URL'], 400);
    }

    // Same email/LinkedIn/website checks the create path applies, so an
    // edit from the Profile tab can't save an invalid value the create form
    // would have rejected. Only runs when one of these fields is actually
    // being changed — untouched fields don't need re-validating.
    if (isset($input['email']) || isset($input['linkedin']) || isset($input['website']) || isset($input['phone'])) {
        $leadForValidation = null;
        foreach ($leadsStore['leads'] as $lv) { if ($lv['id'] === $leadId) { $leadForValidation = $lv; break; } }
        $emailToCheck = trim($input['email'] ?? ($leadForValidation['email'] ?? ''));
        $linkedinToCheck = trim($input['linkedin'] ?? ($leadForValidation['linkedin'] ?? ''));
        $websiteToCheck = trim($input['website'] ?? ($leadForValidation['website'] ?? ''));
        $phoneToCheck = trim($input['phone'] ?? ($leadForValidation['phone'] ?? ''));
        $normalizedWebsite = validateLeadContactFields($emailToCheck, $linkedinToCheck, $websiteToCheck, $phoneToCheck);
        if (isset($input['website'])) $input['website'] = $normalizedWebsite;
    }

    foreach ($leadsStore['leads'] as &$lead) {
        if ($lead['id'] === $leadId) {
            // Update allowed fields — includes the Profile-tab contact/
            // company fields (first/last name, email, phone, company,
            // title, industry, country, website, linkedin, company_size),
            // validated above when email/linkedin/website change.
            $allowedFields = ['call_anchor', 'email_skipped', 'followup_date', 'notes', 'source', 'source_detail', 'assigned_to', 'urgency_flag', 'rejection_reason', 'consultation_type', 'services', 'services_other', 'engagement_method', 'engagement_date', 'engagement_status', 'engagement_note', 'meeting_link', 'demo_checklist_answers', 'demo_transcript', 'demo_feasibility', 'first_name', 'last_name', 'email', 'phone', 'company', 'title', 'industry', 'country', 'website', 'linkedin', 'company_size', 'demo_services', 'demo_request_sent', 'demo_feasibility_by_service', 'demo_disqualified_services', 'sales_materials_link', 'calendly_email_subject', 'calendly_email_body', 'calendly_custom_instructions', 'qualified_research_skipped', 'followups', 'meeting_confirmed_at'];
            // calendly_email_subject/body are plain multi-line email text —
            // the frontend already HTML-escapes them at render time (esc())
            // and reads them back as plain text for mailto/copy, so running
            // them through sanitizeInput()'s htmlspecialchars() here would
            // double-encode apostrophes into literal "&#039;" on screen.
            // Tags are still stripped/trimmed, just not entity-encoded.
            $plainTextFields = ['calendly_email_subject', 'calendly_email_body'];
            foreach ($allowedFields as $field) {
                if (isset($input[$field])) {
                    if (!is_string($input[$field])) {
                        $lead[$field] = $input[$field];
                    } elseif (in_array($field, $plainTextFields, true)) {
                        $lead[$field] = trim(strip_tags($input[$field]));
                    } else {
                        $lead[$field] = sanitizeInput($input[$field]);
                    }
                }
            }
            // Setting a follow-up date from the Cost Proposal panel also
            // best-effort creates a ClickUp task due on that date, so it
            // shows up in the team's ClickUp focus queue — only when the
            // caller explicitly asks for it (every other followup_date
            // writer in the app stays exactly as before).
            if (isset($input['followup_date']) && !empty($input['create_clickup_reminder'])) {
                $cuAdmin = getAdmin();
                $cuToken = $cuAdmin['clickup_token'] ?? '';
                $cuList = $cuAdmin['clickup_list_id'] ?? '';
                if ($cuToken && $cuList) {
                    $cuTitle = 'Follow up: ' . trim(($lead['first_name'] ?? '') . ' ' . ($lead['last_name'] ?? '')) . ' (' . ($lead['company'] ?? '') . ')';
                    clickupCreateTask($cuToken, $cuList, $cuTitle, ['due_date' => $input['followup_date']]);
                    // Best-effort: a ClickUp failure here should never block saving the follow-up date locally.
                }
            }
            // Reassigning ownership: validate against the real users table so a
            // bad id can never silently orphan a lead (same rule as creation).
            if (isset($input['owner_id']) && trim($input['owner_id']) !== '') {
                foreach (getUsers() as $u) {
                    if ($u['id'] === $input['owner_id']) { $lead['owner_id'] = $input['owner_id']; break; }
                }
            }
            // The deal amount can be changed at any stage.
            if (isset($input['deal_amount'])) setDealAmount($lead, $input['deal_amount'], '', $user['id']);
            if (isset($input['deal_currency']) && trim($input['deal_currency']) !== '') {
                $lead['deal_currency'] = normalizeCurrency($input['deal_currency']);
            }
            $requestedStage = trim($input['stage'] ?? $input['status'] ?? '');
            if ($requestedStage !== '') {
                $targetStage = legacyStatusToStage($requestedStage);
                // Winning must go through win-deal so a client + job get created;
                // this endpoint would otherwise leave the registry out of sync.
                if ($targetStage === 'won' && getLeadStage($lead) !== 'won') {
                    respond(['success' => false, 'error' => 'needs_win_confirm', 'lead_id' => $lead['id']], 409);
                }
                // Leaving the Lead stage forward is locked server-side too (the
                // UI already blocks it) until services + all generated
                // questions + engagement method are filled in — mirrors
                // leadQualifyChecklistDone() in index.html.
                if (getLeadStage($lead) === 'lead' && $targetStage !== 'lost' && $targetStage !== 'lead' && !leadQualifyChecklistDone($lead)) {
                    respond(['success' => false, 'error' => 'Complete the qualification checklist (services, questions, engagement method) before moving this deal forward'], 400);
                }
                // Leaving Qualified forward needs the demo/meeting request
                // marked sent first (Demo/Meeting Request Sent -> Meeting
                // Confirmed) — mirrors the equivalent Lead-stage gate above.
                if (getLeadStage($lead) === 'qualified' && $targetStage !== 'lost' && $targetStage !== 'qualified' && empty($lead['demo_request_sent'])) {
                    respond(['success' => false, 'error' => 'Mark the demo/meeting request as sent before moving this deal to Demo'], 400);
                }
                // Leaving Demo forward is the "Deal Sent" action (see
                // dealSentFromDemo() in index.html) — it requires at least
                // one service on the deal, since a reason must be given for
                // every service not marked feasible before this is called.
                if (getLeadStage($lead) === 'demo' && $targetStage !== 'lost' && $targetStage !== 'demo' && empty($lead['services']) && empty($lead['demo_services'])) {
                    respond(['success' => false, 'error' => 'Add at least one service before sending the deal forward'], 400);
                }
                // Leaving Cost Proposal forward is the "Move to SOW" action
                // (see startSowFromCostProposal() in index.html) — every
                // service must be resolved Won or Lost first (mirrors the
                // Demo -> Cost Proposal gate above); SOW documents are then
                // generated per-service, AFTER the deal has already moved to
                // the SOW stage, not before — so this no longer requires one
                // to already exist.
                if (getLeadStage($lead) === 'cost_proposal' && $targetStage !== 'lost' && $targetStage !== 'cost_proposal') {
                    $cpServices = $lead['services'] ?? [];
                    if (empty($cpServices)) {
                        respond(['success' => false, 'error' => 'Add at least one service before moving to SOW'], 400);
                    }
                    $answers = $lead['requisitions'] ?? [];
                    $unresolved = array_filter($cpServices, function ($s) use ($answers) {
                        return ($answers["{$s}::cp_negotiation_status"] ?? 'negotiating') === 'negotiating';
                    });
                    if (!empty($unresolved)) {
                        respond(['success' => false, 'error' => 'Mark every service Won or Lost before moving to SOW'], 400);
                    }
                    $wonServices = array_filter($cpServices, function ($s) use ($answers) {
                        return ($answers["{$s}::cp_negotiation_status"] ?? '') === 'won';
                    });
                    if (empty($wonServices)) {
                        respond(['success' => false, 'error' => 'At least one service needs to be Won before moving to SOW'], 400);
                    }
                }
                // A deal never regresses through this endpoint. The stage
                // rail lets a rep VIEW an earlier stage's panel (and even
                // edit its fields — services, answers, transcript, etc. all
                // still save), but clicking that panel's own action button
                // (e.g. "Qualify") must never actually move the deal
                // backward just because an old panel happened to be on
                // screen. Only Lost is allowed from anywhere.
                if ($targetStage !== 'lost' && stageOrder($targetStage) < stageOrder(getLeadStage($lead))) {
                    respond(['success' => false, 'error' => 'This deal has already moved past that stage'], 400);
                }
                setLeadStage($lead, $requestedStage, 'manual_update', $user['id']);
            }
            $lead = normalizeLeadForMapping($lead);
            $lead['updated_at'] = date('c');
            saveLeadsStore($leadsStore);
            respond(['success' => true, 'lead' => $lead]);
        }
    }
    respond(['success' => false, 'error' => 'Lead not found'], 404);
    break;

case 'import':
    if ($method !== 'POST') break;
    $user = requireAuth();
    $userData = getUserData($user['id']);
    $csvData = $input['data'] ?? [];
    $skipDuplicates = $input['skip_duplicates'] ?? true;
    $source = normalizeLeadSource($input['source'] ?? 'firmable');

    if (!is_array($csvData) || empty($csvData)) respond(['success' => false, 'error' => 'No data provided'], 400);

    // Build sets for duplicate detection
    $existingEmails = [];
    $existingZohoIds = [];
    $existingNameKeys = [];
    foreach ($userData['leads'] as $lead) {
        if (!empty($lead['deleted_at'])) continue;
        if (!empty($lead['email'])) $existingEmails[strtolower($lead['email'])] = true;
        if (!empty($lead['zoho_id'])) $existingZohoIds[$lead['zoho_id']] = true;
        if (empty($lead['email']) && (!empty($lead['first_name']) || !empty($lead['last_name']))) {
            $existingNameKeys[strtolower(($lead['first_name']??'').'|'.($lead['last_name']??'').'|'.($lead['company']??''))] = true;
        }
    }

    $imported = 0;
    $skipped = 0;
    $duplicates = [];

    foreach ($csvData as $row) {
        if (!is_array($row)) continue;
        $email = trim(str_replace(['"', "'"], '', $row['email'] ?? $row['Email'] ?? $row['EMAIL'] ?? ''));
        if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) $email = '';

        // Must have at least a name, email, company or phone to be a valid lead
        $firstName = trim($row['first_name'] ?? $row['First name'] ?? $row['firstname'] ?? '');
        $lastName = trim($row['last_name'] ?? $row['Last name'] ?? $row['lastname'] ?? '');
        $company = trim($row['company'] ?? $row['Company'] ?? $row['company_name'] ?? $row['Company name'] ?? '');
        $phone = trim($row['phone'] ?? $row['Phone'] ?? $row['mobile'] ?? $row['Mobile'] ?? '');
        if (!$email && !$firstName && !$lastName && !$company && !$phone) continue;

        // Extract Zoho ID if present (common Zoho export fields)
        $zohoId = trim($row['zoho_id'] ?? $row['ZOHO_ID'] ?? $row['Record Id'] ?? $row['RECORDID'] ?? $row['id'] ?? '');

        // Check for duplicates
        $isDuplicate = false;
        $duplicateReason = '';

        if ($skipDuplicates) {
            // Check by Zoho ID first (most reliable)
            if (!empty($zohoId) && isset($existingZohoIds[$zohoId])) {
                $isDuplicate = true;
                $duplicateReason = 'zoho_id';
            }
            // Then check by email (only if email exists)
            elseif (!empty($email) && isset($existingEmails[strtolower($email)])) {
                $isDuplicate = true;
                $duplicateReason = 'email';
            }
            // Check by name + company if no email
            elseif (empty($email) && (!empty($firstName) || !empty($lastName))) {
                $nameCompanyKey = strtolower($firstName . '|' . $lastName . '|' . $company);
                if (isset($existingNameKeys[$nameCompanyKey])) {
                    $isDuplicate = true;
                    $duplicateReason = 'name_company';
                }
            }
        }

        if ($isDuplicate) {
            $skipped++;
            $duplicates[] = ['email' => $email, 'reason' => $duplicateReason];
            continue;
        }

        $rowSource = normalizeLeadSource($row['source'] ?? $row['Source'] ?? $source);
        $warm = !empty($row['warm']) || stripos($row['lead_temperature'] ?? $row['Lead Temperature'] ?? '', 'warm') !== false;
        $initialStage = initialStageForSource($rowSource, $warm);

        // Create new lead
        $newLead = array_merge([
            'id' => generateId('lead_'),
            'first_name' => sanitizeInput($row['first_name'] ?? $row['First Name'] ?? $row['firstname'] ?? $row['First_Name'] ?? ''),
            'last_name' => sanitizeInput($row['last_name'] ?? $row['Last Name'] ?? $row['lastname'] ?? $row['Last_Name'] ?? ''),
            'email' => $email,
            'phone' => sanitizeInput($row['phone'] ?? $row['Phone'] ?? $row['phone_number'] ?? $row['Mobile'] ?? $row['Phone_Number'] ?? ''),
            'company' => sanitizeInput($row['company'] ?? $row['Company'] ?? $row['company_name'] ?? $row['Account_Name'] ?? $row['Company_Name'] ?? ''),
            'title' => sanitizeInput($row['title'] ?? $row['Title'] ?? $row['job_title'] ?? $row['Designation'] ?? ''),
            'industry' => sanitizeInput($row['industry'] ?? $row['Industry'] ?? ''),
            'country' => sanitizeInput($row['country'] ?? $row['Country'] ?? $row['location'] ?? $row['Mailing_Country'] ?? ''),
            'website' => trim($row['website'] ?? $row['Website'] ?? $row['Company_Website'] ?? ''),
            'linkedin' => trim($row['linkedin'] ?? $row['LinkedIn'] ?? $row['LinkedIn_URL'] ?? ''),
            'company_size' => sanitizeInput($row['company_size'] ?? $row['employees'] ?? $row['No_of_Employees'] ?? ''),
            'notes' => sanitizeInput($row['notes'] ?? $row['Notes'] ?? $row['Description'] ?? ''),
            'enrichment' => '',
            'requisitions' => null,
            'source' => $rowSource,
            'source_detail' => sanitizeInput($row['source_detail'] ?? $row['Source Detail'] ?? $row['Lead_Source'] ?? $row['lead_source'] ?? ''),
            'assigned_to' => sanitizeInput($row['assigned_to'] ?? $row['Assigned To'] ?? ''),
            'stage' => $initialStage,
            'status' => stageToLegacyStatus($initialStage),
            'stage_entered_at' => date('c'),
            'stage_history' => [],
            'urgency_flag' => $rowSource === 'inbound' ? 'high' : 'normal',
            'call_history' => [],
            'rejection_reason' => '',
            'consultation_type' => sanitizeInput($row['consultation_type'] ?? $row['Consultation Type'] ?? ''),
            'emails_sent' => 0,
            'last_email_type' => null,
            'last_action' => 'imported',
            'last_action_at' => date('c'),
            'email_history' => [],
            'created_at' => date('c'),
            'updated_at' => date('c'),
            // Zoho-specific fields for tracking
            'zoho_id' => $zohoId ?: null,
            'import_source' => $rowSource,
            'imported_at' => date('c'),
            // Additional Zoho fields if present
            'lead_source' => trim($row['Lead_Source'] ?? $row['lead_source'] ?? ''),
            'lead_status_zoho' => trim($row['Lead_Status'] ?? $row['Status'] ?? ''),
            'annual_revenue' => trim($row['Annual_Revenue'] ?? $row['annual_revenue'] ?? ''),
            'rating' => trim($row['Rating'] ?? $row['rating'] ?? '')
        ], getDefaultStageFields());

        $newLead = normalizeLeadForMapping($newLead);
        logActivity($newLead, 'created', 'Lead imported');

        $userData['leads'][] = $newLead;

        // Track for duplicate detection in this batch
        if (!empty($email)) $existingEmails[strtolower($email)] = true;
        if (empty($email) && (!empty($firstName) || !empty($lastName))) {
            $existingNameKeys[strtolower($firstName . '|' . $lastName . '|' . $company)] = true;
        }
        if (!empty($zohoId)) $existingZohoIds[$zohoId] = true;

        $imported++;
    }

    saveUserData($user['id'], $userData);

    $response = [
        'success' => true,
        'imported' => $imported,
        'skipped' => $skipped,
        'message' => "Imported $imported leads" . ($skipped > 0 ? ", skipped $skipped duplicates" : "")
    ];

    if ($skipped > 0 && count($duplicates) <= 10) {
        $response['duplicates'] = $duplicates;
    }

    respond($response);
    break;

case 'delete-lead':
    if ($method !== 'POST') break;
    $user = requireAuth();
    $leadsStore = getLeadsStore();
    $id = $input['id'] ?? '';
    if (!$id) respond(['success' => false, 'error' => 'No ID'], 400);
    foreach ($leadsStore['leads'] as &$l) {
        if ($l['id'] === $id) {
            $l['deleted_at'] = date('c');
            $l['deleted_by'] = $user['id'];
            logActivity($l, 'deleted', 'Moved to trash');
            saveLeadsStore($leadsStore);
            respond(['success' => true]);
        }
    }
    respond(['success' => false, 'error' => 'Lead not found'], 404);
    break;

case 'bulk-delete':
    if ($method !== 'POST') break;
    $user = requireAuth();
    $leadsStore = getLeadsStore();
    $ids = $input['ids'] ?? [];
    $count = 0;
    foreach ($leadsStore['leads'] as &$l) {
        if (in_array($l['id'], $ids)) {
            $l['deleted_at'] = date('c');
            $l['deleted_by'] = $user['id'];
            logActivity($l, 'deleted', 'Moved to trash (bulk delete)');
            $count++;
        }
    }
    unset($l);
    saveLeadsStore($leadsStore);
    respond(['success' => true, 'deleted' => $count]);
    break;

case 'export':
    if ($method !== 'POST') break;
    $user = requireAuth();
    $userData = getUserData($user['id']);
    $ids = $input['ids'] ?? [];
    $leadsToExport = $userData['leads'] ?? [];
    if (!empty($ids)) $leadsToExport = array_filter($leadsToExport, function($l) use ($ids) { return in_array($l['id'], $ids); });
    
    $zohoData = array_map(function($l) {
        $req = $l['requisitions'] ?? [];
        return [
            'First Name' => $l['first_name'] ?? '', 'Last Name' => $l['last_name'] ?? '',
            'Email' => $l['email'] ?? '', 'Phone' => $l['phone'] ?? '',
            'Company' => $l['company'] ?? '', 'Title' => $l['title'] ?? '',
            'Industry' => $l['industry'] ?? '', 'Country' => $l['country'] ?? '',
            'Website' => $l['website'] ?? '', 'LinkedIn' => $l['linkedin'] ?? '',
            'Company Size' => $l['company_size'] ?? '', 'Notes' => $l['notes'] ?? '',
            'Lead Status' => ucfirst(str_replace('_', ' ', $l['status'] ?? 'new')),
            'Emails Sent' => $l['emails_sent'] ?? 0,
            'Business Type' => is_array($req['business_type'] ?? null) ? implode(', ', $req['business_type']) : ($req['business_type'] ?? ''),
            'Team Size' => $req['team_size'] ?? '',
            'Project Volume' => $req['project_volume'] ?? '',
            'Current Solution' => is_array($req['current_solution'] ?? null) ? implode(', ', $req['current_solution']) : ($req['current_solution'] ?? ''),
            'Pain Point' => $req['pain_point'] ?? '',
            'Market Segment' => $req['market_segment'] ?? '',
            'Location' => is_array($req['location'] ?? null) ? implode(', ', $req['location']) : ($req['location'] ?? ''),
            'Req Notes' => $req['other_notes'] ?? ''
        ];
    }, array_values($leadsToExport));
    respond(['success' => true, 'data' => $zohoData, 'count' => count($zohoData)]);
    break;

case 'export-csv':
    if ($method !== 'POST') break;
    $user = requireAuth();
    $userData = getUserData($user['id']);
    $leadIds = $input['lead_ids'] ?? null;

    $exportLeads = array_filter($userData['leads'] ?? [], function($l) {
        return empty($l['deleted_at']);
    });
    if ($leadIds) {
        $exportLeads = array_filter($exportLeads, function($l) use ($leadIds) {
            return in_array($l['id'], $leadIds);
        });
    }

    $csvFields = ['first_name','last_name','email','phone','company','title','industry','country','website','linkedin','company_size','status','fit_grade','fit_score','emails_sent','calls_made','call_outcome','notes','created_at','updated_at'];

    $rows = [];
    $rows[] = $csvFields;
    foreach (array_values($exportLeads) as $lead) {
        $row = [];
        foreach ($csvFields as $field) {
            $row[] = $lead[$field] ?? '';
        }
        $rows[] = $row;
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="leads_export_' . date('Y-m-d') . '.csv"');
    $fp = fopen('php://output', 'w');
    foreach ($rows as $row) {
        fputcsv($fp, $row);
    }
    fclose($fp);
    exit;
    break;

case 'generate-email':
    if ($method !== 'POST') break;
    $user = requireAuth();
    $userData = getUserData($user['id']);
    $admin = getAdmin();
    $provider = $admin['default_provider'] ?? 'groq';
    $apiKey = $admin[$provider . '_key'] ?? '';
    if (!$apiKey) respond(['success' => false, 'error' => 'AI not configured'], 400);

    $lead = $input['lead'] ?? [];
    $emailType = $input['type'] ?? 'initial';
    $previousEmail = $input['previous_email'] ?? '';
    $enrichment = $input['enrichment'] ?? $lead['enrichment'] ?? '';

    // Stage gating warning (soft - we still allow but warn)
    $stageWarning = null;
    if (empty($enrichment) && $emailType === 'initial') {
        $stageWarning = 'This lead has not been researched. For best results, research the lead first to get personalized insights.';
    }
    $includeSignature = $input['include_signature'] ?? true;
    
    // Get email history for context
    $emailHistory = $lead['email_history'] ?? [];
    $lastSentEmail = '';
    if (!empty($emailHistory)) {
        $lastSentEmail = $emailHistory[count($emailHistory) - 1]['content'] ?? '';
    }
    
    // Use provided previous email or fall back to last sent
    if (empty($previousEmail) && !empty($lastSentEmail)) {
        $previousEmail = $lastSentEmail;
    }
    
    // Use new template-based generator
    $res = generateEmailContent(
        $provider, 
        $apiKey, 
        $lead, 
        $emailType, 
        $input['custom_instructions'] ?? '', 
        $enrichment, 
        $userData['settings'] ?? [], 
        $previousEmail,
        $includeSignature
    );
    
    if ($res['success']) {
        $leadId = $lead['id'] ?? '';
        if ($leadId) {
            $leadsStore = getLeadsStore();
            foreach ($leadsStore['leads'] as &$l) {
                if ($l['id'] === $leadId) {
                    $l['last_action'] = 'email_generated';
                    $l['last_action_at'] = date('c');
                    $l['last_email_type'] = $emailType;
                    break;
                }
            }
            unset($l);
            saveLeadsStore($leadsStore);
        }
        $response = ['success' => true, 'email' => $res['content']];
        if ($stageWarning) {
            $response['warning'] = $stageWarning;
        }
        respond($response);
    }
    respond(['success' => false, 'error' => $res['error'] ?? 'Generation failed'], 500);
    break;

case 'generate-calendly-email':
    if ($method !== 'POST') break;
    $user = requireAuth();
    $userData = getUserData($user['id']);
    $admin = getAdmin();
    $provider = $admin['default_provider'] ?? 'groq';
    $apiKey = $admin[$provider . '_key'] ?? '';
    if (!$apiKey) respond(['success' => false, 'error' => 'AI not configured'], 400);

    $lead = $input['lead'] ?? [];
    $calendarLink = trim($input['calendar_link'] ?? '');
    if (!$calendarLink) respond(['success' => false, 'error' => 'Missing calendar link'], 400);

    $res = generateCalendlyEmailContent(
        $provider,
        $apiKey,
        $lead,
        $calendarLink,
        $input['custom_instructions'] ?? '',
        $userData['settings'] ?? []
    );

    if ($res['success']) respond(['success' => true, 'email' => $res['email']]);
    respond(['success' => false, 'error' => $res['error'] ?? 'Generation failed'], 500);
    break;

case 'generate-call-pitch':
    if ($method !== 'POST') break;
    $user = requireAuth();
    $userData = getUserData($user['id']);
    $admin = getAdmin();
    $provider = $admin['default_provider'] ?? 'groq';
    $apiKey = $admin[$provider . '_key'] ?? '';
    if (!$apiKey) respond(['success' => false, 'error' => 'AI not configured'], 400);

    $name = $input['name'] ?? '';
    $title = $input['title'] ?? '';
    $company = $input['company'] ?? '';
    $industry = $input['industry'] ?? '';
    $pitchType = $input['pitch_type'] ?? 'cold';
    $customInstructions = $input['custom_instructions'] ?? '';
    if (!$name) respond(['success' => false, 'error' => 'Name is required'], 400);

    $res = generateCallPitch(
        $provider,
        $apiKey,
        $name,
        $title,
        $company,
        $industry,
        $pitchType,
        $customInstructions,
        $userData['settings'] ?? []
    );

    if ($res['success']) {
        respond(['success' => true, 'title' => $res['title'], 'pitch' => $res['pitch']]);
    }
    respond(['success' => false, 'error' => $res['error'] ?? 'Generation failed'], 500);
    break;

case 'enrich-lead':
    if ($method !== 'POST') break;
    $user = requireAuth();
    $userData = getUserData($user['id']);
    $admin = getAdmin();
    $provider = $admin['default_provider'] ?? 'groq';
    $apiKey = $admin[$provider . '_key'] ?? '';
    if (!$apiKey) respond(['success' => false, 'error' => 'AI not configured'], 400);
    
    $lead = $input['lead'] ?? [];
    $prompt = buildResearchPrompt($lead, $userData['settings'] ?? []);
    $res = callLLM($provider, $apiKey, $prompt);
    
    if ($res['success']) {
        // Clean up the response - extract JSON if wrapped in markdown
        $content = $res['content'];
        if (preg_match('/```(?:json)?\s*([\s\S]*?)```/', $content, $matches)) {
            $content = trim($matches[1]);
        }
        
        // Parse the JSON to extract scoring and sources
        $parsed = json_decode($content, true);
        $researchScore = null;
        $sources = [];
        
        if ($parsed) {
            // Extract research_score
            if (isset($parsed['research_score'])) {
                $researchScore = $parsed['research_score'];
            }
            // Extract sources
            if (isset($parsed['sources']) && is_array($parsed['sources'])) {
                $sources = $parsed['sources'];
            }
        }
        
        $leadId = $lead['id'] ?? '';
        $updatedLead = null;
        if ($leadId) {
            $leadsStore = getLeadsStore();
            foreach ($leadsStore['leads'] as &$l) {
                if ($l['id'] === $leadId) {
                    $l['enrichment'] = $content;
                    $l['last_action'] = 'researched';
                    $l['last_action_at'] = date('c');
                    $l['updated_at'] = date('c');
                    // Record a permanent activity entry so Team Activity counts this
                    // research durably (last_action gets overwritten by later actions
                    // like email generation, so it cannot be the source of truth).
                    $l['activities'] = logLeadActivity($l, 'research', 'AI research completed');
                    // Research is activity within the Lead stage, not a stage of its
                    // own — the deal only advances when a human qualifies it.

                    // Auto-calculate ICP grade using shared function
                    $gradeResult = calculateLeadGrade($l, $admin, $parsed);
                    $l['fit_grade'] = $gradeResult['grade'];
                    $l['fit_score'] = $gradeResult['score'];

                    logActivity($l, 'researched', 'Lead researched (Grade ' . ($l['fit_grade'] ?? '?') . ')');
                    $updatedLead = $l;
                    break;
                }
            }
            unset($l);
            saveLeadsStore($leadsStore);
        }
        respond([
            'success' => true,
            'enrichment' => $content, 
            'lead' => $updatedLead,
            'research_score' => $researchScore,
            'sources' => $sources
        ]);
    }
    respond(['success' => false, 'error' => $res['error']], 500);
    break;

case 'generate-qualification-questions':
    if ($method !== 'POST') break;
    $user = requireAuth();
    $admin = getAdmin();
    $provider = $admin['default_provider'] ?? 'groq';
    $apiKey = $admin[$provider . '_key'] ?? '';
    if (!$apiKey) respond(['success' => false, 'error' => 'AI not configured'], 400);

    $leadId = $input['lead_id'] ?? '';
    $services = is_array($input['services'] ?? null) ? array_values(array_filter(array_map('trim', $input['services']))) : [];
    $servicesOther = trim($input['services_other'] ?? '');
    if ($servicesOther !== '') $services[] = $servicesOther;
    if (empty($services)) respond(['success' => false, 'error' => 'Select at least one service first'], 400);

    $leadsStore = getLeadsStore();
    $lead = null;
    foreach ($leadsStore['leads'] as &$l) {
        if ($l['id'] === $leadId) { $lead = &$l; break; }
    }
    if (!$lead) respond(['success' => false, 'error' => 'Lead not found'], 404);

    $serviceList = implode(', ', $services);
    $company = $lead['company'] ?? 'the prospect';
    $industry = $lead['industry'] ?? 'unknown industry';

    $prompt = <<<PROMPT
You are a sales qualification assistant for Levata, a design and engineering studio that builds websites, software systems and brand identities.

A new lead has come in for {$company} (industry: {$industry}), interested in: {$serviceList}.

Generate 6 to 10 short, concrete qualifying questions a rep should ask this lead before moving them to the Qualified stage. Questions should surface things like: budget/deal size indicators, current systems or tools in use, urgency/timeline, decision-making process, team size, project scope or volume, and anything specific to the selected service(s) that would change how the project is scoped or priced. Steer toward questions relevant to {$serviceList} specifically, not generic filler.

Return ONLY a JSON array (no markdown, no commentary), where each item has this shape:
{"id": "short_snake_case_id", "title": "The question text", "type": "text|single|multi", "options": ["Option A", "Option B"]}

Rules:
- "type" is "text" for free-text answers, "single" for pick-one, "multi" for pick-many.
- Only include "options" when type is "single" or "multi"; omit it entirely for "text".
- Each "id" must be unique within the array.
- Return between 6 and 10 questions.
PROMPT;

    $res = callLLM($provider, $apiKey, $prompt);
    if (!$res['success']) respond(['success' => false, 'error' => $res['error']], 500);

    $content = $res['content'];
    if (preg_match('/```(?:json)?\s*([\s\S]*?)```/', $content, $matches)) {
        $content = trim($matches[1]);
    }
    $questions = json_decode($content, true);
    if (!is_array($questions) || empty($questions)) {
        respond(['success' => false, 'error' => 'AI returned an unusable response. Try again.'], 500);
    }

    // Normalize + de-dupe ids defensively (LLM output isn't fully trustworthy).
    $seenIds = [];
    $clean = [];
    foreach ($questions as $i => $q) {
        if (!is_array($q) || empty($q['title'])) continue;
        $id = trim($q['id'] ?? '') ?: ('q' . ($i + 1));
        if (isset($seenIds[$id])) $id = $id . '_' . ($i + 1);
        $seenIds[$id] = true;
        $type = in_array($q['type'] ?? '', ['text', 'single', 'multi']) ? $q['type'] : 'text';
        $entry = ['id' => $id, 'title' => trim($q['title']), 'type' => $type];
        if ($type !== 'text' && !empty($q['options']) && is_array($q['options'])) {
            $entry['options'] = array_values(array_map('strval', $q['options']));
        }
        $clean[] = $entry;
    }
    if (empty($clean)) respond(['success' => false, 'error' => 'AI returned an unusable response. Try again.'], 500);

    $lead['generated_questions'] = $clean;
    $lead['services'] = $services;
    if ($servicesOther !== '') $lead['services_other'] = $servicesOther;
    $lead['updated_at'] = date('c');
    unset($l);
    saveLeadsStore($leadsStore);

    respond(['success' => true, 'questions' => $clean, 'lead' => $lead]);
    break;

case 'generate-lead-research':
    // Qualified-stage research: service + industry best-practice briefing for
    // the rep ahead of the demo call. Deliberately separate from enrich-lead
    // (the outbound ICP/scoring feature, hidden behind OUTBOUND_ENABLED) —
    // this one is always on and has nothing to do with lead scoring.
    if ($method !== 'POST') break;
    $user = requireAuth();
    $admin = getAdmin();
    $provider = $admin['default_provider'] ?? 'groq';
    $apiKey = $admin[$provider . '_key'] ?? '';
    if (!$apiKey) respond(['success' => false, 'error' => 'AI not configured'], 400);

    $leadId = $input['lead_id'] ?? '';
    $leadsStore = getLeadsStore();
    $lead = null;
    foreach ($leadsStore['leads'] as &$l) {
        if ($l['id'] === $leadId) { $lead = &$l; break; }
    }
    if (!$lead) respond(['success' => false, 'error' => 'Lead not found'], 404);

    $services = $lead['services'] ?? [];
    if (!empty($lead['services_other']) && !in_array($lead['services_other'], $services)) $services[] = $lead['services_other'];
    if (empty($services)) respond(['success' => false, 'error' => 'This lead has no services selected yet'], 400);

    // Research is per-service, not one shared briefing blending every
    // service on the deal — a deal with both Website Development and
    // Branding needs two different briefings. The rep picks which one from
    // a dropdown client-side (see qfResearchSelectedService in index.html);
    // default to the first service only if none was specified, so older
    // callers (or a deal with just one service) still work with no
    // required param.
    $service = trim($input['service'] ?? '');
    if ($service === '' || !in_array($service, $services, true)) $service = $services[0];

    $company = trim($lead['company'] ?? '');

    $answers = $lead['requisitions'] ?? [];
    // Industry is per-service where the service's own form captures it
    // ("{service}::industry" — see the industry:true forms in
    // SERVICE_QUALIFICATION_FORMS), falling back to the deal-level industry
    // field so older leads or services without their own industry question
    // still work.
    $industry = trim($answers[$service . '::industry'] ?? '');
    if ($industry === '') $industry = trim($lead['industry'] ?? '');

    // Branding/Design/General Custom Requirement are never a top-level
    // service name — they're only ever picked as the "need" on a Custom
    // Services instance, so $service here is "Custom Services" / "Custom
    // Services #2", not "Branding". Resolve to the picked need first,
    // mirroring the frontend's serviceTabLabel().
    $resolvedServiceName = $service;
    if (preg_match('/^Custom Services( #\d+)?$/', $service)) {
        $need = trim($answers[$service . '::need'] ?? '');
        if ($need !== '' && $need !== 'Other') $resolvedServiceName = $need;
    }

    // Creative/brief-driven services (Branding, Design/Creative, General
    // Custom Requirement) don't have "industry best practices" in the same
    // sense a website or software build does — the briefing for these leans
    // on the client's own brand/market position and qualification answers
    // instead of forcing an industry-trends angle that may not exist.
    $creativeServices = ['Branding', 'Design / Creative', 'General Custom Requirement'];
    $isCreative = in_array($resolvedServiceName, $creativeServices, true);

    if ($industry === '') {
        // Non-creative services still have something real to research even
        // with no company/industry on file — the service itself is a
        // genuine market/technology topic (e.g. "Sales Intelligence
        // Platform" trends). Creative services aren't — "Branding" isn't an
        // industry, so with nothing else to go on there is truly nothing to
        // research (the frontend shows Skip in this exact case).
        if ($isCreative && $company === '') {
            respond(['success' => false, 'error' => 'Add a company name first, or skip research for this service'], 400);
        }
        $industry = $resolvedServiceName;
    }

    $answersText = '';
    foreach ($answers as $key => $v) {
        // Only this service's own qualification answers ("{service}::{qid}")
        // — the same flat requisitions map every other service's questions
        // also live in, so without this prefix filter a service's briefing
        // would pull in unrelated answers from every other service too.
        if (strpos($key, $service . '::') !== 0) continue;
        if (empty($v)) continue;
        $qid = substr($key, strlen($service) + 2);
        $answersText .= '- ' . $qid . ': ' . (is_array($v) ? (isset($v['amount']) ? $v['amount'] . ' ' . ($v['currency'] ?? '') : implode(', ', $v)) : $v) . "\n";
    }

    $companyOrIndustry = $company !== '' ? "{$company}'s" : "this industry's";
    $angleNote = $isCreative
        ? "This is brand/creative work, not a technical build — lean on {$companyOrIndustry} market position, audience and existing brand presence rather than generic industry trend statements. If there isn't much to go on, keep sections short rather than inventing filler."
        : "Ground everything in the service and industry given, not generic sales advice.";

    // Website is fetched for real (see fetchWebsiteTextForResearch()) so the
    // briefing can reference what the company's own site actually says
    // rather than guessing from the name and industry alone. LinkedIn can't
    // be fetched (blocks non-logged-in requests) so it's passed as a plain
    // reference the model can reason about, same as company/industry.
    $websiteContext = '';
    $website = trim($lead['website'] ?? '');
    if ($website !== '') {
        $siteText = fetchWebsiteTextForResearch($website);
        if ($siteText !== '') $websiteContext = "\nCOMPANY WEBSITE ({$website}):\n{$siteText}\n";
    }
    $linkedin = trim($lead['linkedin'] ?? '');
    $linkedinContext = $linkedin !== '' ? "\nProspect's LinkedIn: {$linkedin}\n" : '';

    // No company name to hang the briefing on — research falls back to the
    // industry alone (a rep chose to skip past the missing company rather
    // than skip research outright). Framed as a general industry briefing
    // instead of naming a company that isn't there.
    $prospectLine = $company !== ''
        ? "Prepare a short research briefing for a rep ahead of a demo call with {$company} (industry: {$industry}), who are interested in: {$resolvedServiceName}."
        : "No company name is available yet, so prepare a general research briefing on the {$industry} industry for a rep ahead of a demo call, focused on prospects interested in: {$resolvedServiceName}.";

    $prompt = <<<PROMPT
You are a sales research assistant for Levata, a design and engineering studio that builds websites, software systems and brand identities.

{$prospectLine}
{$websiteContext}{$linkedinContext}
{$answersText}

Return ONLY a single JSON object (no markdown, no commentary) with this exact shape:
{
  "industry_context": "2-3 sentences on what matters right now, relevant to the service requested",
  "best_practices": ["short best-practice point relevant to {$resolvedServiceName}", "..."],
  "talking_points": ["a specific, non-generic talking point for this demo", "..."],
  "questions_to_probe": ["a sharp follow-up question the rep should ask on the call", "..."]
}

Rules:
- 3 to 5 items in each array.
- {$angleNote}
- If the company website text above is provided, ground talking points and industry context in what it actually says (their positioning, products, tone) rather than generic assumptions.
- If qualification answers were provided above, reference them specifically rather than restating generic industry facts.
PROMPT;

    $res = callLLM($provider, $apiKey, $prompt);
    if (!$res['success']) respond(['success' => false, 'error' => $res['error']], 500);

    $content = $res['content'];
    if (preg_match('/```(?:json)?\s*([\s\S]*?)```/', $content, $matches)) {
        $content = trim($matches[1]);
    }
    $research = json_decode($content, true);
    if (!is_array($research) || empty($research)) {
        respond(['success' => false, 'error' => 'AI returned an unusable response. Try again.'], 500);
    }

    $clean = [
        'industry_context' => trim($research['industry_context'] ?? ''),
        'best_practices' => array_values(array_filter(array_map('trim', $research['best_practices'] ?? []))),
        'talking_points' => array_values(array_filter(array_map('trim', $research['talking_points'] ?? []))),
        'questions_to_probe' => array_values(array_filter(array_map('trim', $research['questions_to_probe'] ?? []))),
        'generated_at' => date('c'),
    ];

    // Keyed by service — see the per-service comment above. Old records
    // with a single flat qualified_research from before this change are
    // left as-is (unused going forward, not migrated); the frontend only
    // ever reads qualified_research_by_service now.
    if (!isset($lead['qualified_research_by_service']) || !is_array($lead['qualified_research_by_service'])) {
        $lead['qualified_research_by_service'] = [];
    }
    $lead['qualified_research_by_service'][$service] = $clean;
    $lead['updated_at'] = date('c');
    unset($l);
    saveLeadsStore($leadsStore);

    respond(['success' => true, 'research' => $clean, 'service' => $service, 'lead' => $lead]);
    break;

case 'generate-demo-checklist':
    // Demo-stage requirement-gathering checklist: what to nail down on the
    // demo call itself, service-specific (SIP/web/custom per the storyboard).
    // Same callLLM pattern as generate-qualification-questions, kept as its
    // own endpoint since the two checklists serve different moments
    // (pre-qualification vs. requirement-gathering during the demo).
    if ($method !== 'POST') break;
    $user = requireAuth();
    $admin = getAdmin();
    $provider = $admin['default_provider'] ?? 'groq';
    $apiKey = $admin[$provider . '_key'] ?? '';
    if (!$apiKey) respond(['success' => false, 'error' => 'AI not configured'], 400);

    $leadId = $input['lead_id'] ?? '';
    $leadsStore = getLeadsStore();
    $lead = null;
    foreach ($leadsStore['leads'] as &$l) {
        if ($l['id'] === $leadId) { $lead = &$l; break; }
    }
    if (!$lead) respond(['success' => false, 'error' => 'Lead not found'], 404);

    $services = $lead['services'] ?? [];
    if (!empty($lead['services_other']) && !in_array($lead['services_other'], $services)) $services[] = $lead['services_other'];
    if (empty($services)) respond(['success' => false, 'error' => 'This lead has no services selected yet'], 400);
    $serviceList = implode(', ', $services);
    $company = $lead['company'] ?? 'the prospect';

    $prompt = <<<PROMPT
You are a project scoping assistant for Levata, a design and engineering studio that builds websites, software systems and brand identities.

A rep is about to run a demo call with {$company}, who need: {$serviceList}.

Generate 6 to 10 short, concrete requirement-gathering checklist items the rep must nail down DURING this demo call before a cost proposal can be scoped accurately. Think: specific dates/deadlines, technical specifics (e.g. hosting, integrations, existing systems), stakeholders/decision-makers, exact scope boundaries, content/asset readiness, and anything specific to {$serviceList} that changes pricing or scope.

Return ONLY a JSON array (no markdown, no commentary), where each item has this shape:
{"id": "short_snake_case_id", "title": "The checklist item"}

Rules:
- Each item is a single concrete thing to confirm or gather, phrased as an action (e.g. "Confirm the go-live date", not "Go-live date?").
- Each "id" must be unique within the array.
- Return between 6 and 10 items.
PROMPT;

    $res = callLLM($provider, $apiKey, $prompt);
    if (!$res['success']) respond(['success' => false, 'error' => $res['error']], 500);

    $content = $res['content'];
    if (preg_match('/```(?:json)?\s*([\s\S]*?)```/', $content, $matches)) {
        $content = trim($matches[1]);
    }
    $items = json_decode($content, true);
    if (!is_array($items) || empty($items)) {
        respond(['success' => false, 'error' => 'AI returned an unusable response. Try again.'], 500);
    }

    $seenIds = [];
    $clean = [];
    foreach ($items as $i => $it) {
        if (!is_array($it) || empty($it['title'])) continue;
        $id = trim($it['id'] ?? '') ?: ('c' . ($i + 1));
        if (isset($seenIds[$id])) $id = $id . '_' . ($i + 1);
        $seenIds[$id] = true;
        $clean[] = ['id' => $id, 'title' => trim($it['title'])];
    }
    if (empty($clean)) respond(['success' => false, 'error' => 'AI returned an unusable response. Try again.'], 500);

    $lead['demo_checklist'] = $clean;
    $lead['updated_at'] = date('c');
    unset($l);
    saveLeadsStore($leadsStore);

    respond(['success' => true, 'checklist' => $clean, 'lead' => $lead]);
    break;

case 'save-email':
    if ($method !== 'POST') break;
    $user = requireAuth();
    $leadsStore = getLeadsStore();
    $leadId = $input['lead_id'] ?? '';
    $emailType = $input['type'] ?? 'initial';

    foreach ($leadsStore['leads'] as &$lead) {
        if ($lead['id'] === $leadId) {
            // Save both subject and content in email_history
            $lead['email_history'][] = [
                'type' => $emailType,
                'subject' => $input['subject'] ?? '',
                'content' => $input['content'] ?? '',
                'sent_at' => date('c')
            ];
            $lead['emails_sent'] = count($lead['email_history']);
            $lead['last_email_type'] = $emailType;
            $lead['last_action'] = 'email_sent';
            $lead['last_action_at'] = date('c');
            $lead['updated_at'] = date('c');
            // Sending an email is activity within the Lead stage, not a stage move.
            logActivity($lead, 'email_sent', ucfirst(str_replace('_', ' ', $emailType)) . ' email sent');
            saveLeadsStore($leadsStore);
            respond(['success' => true, 'lead' => $lead]);
        }
    }
    respond(['success' => false, 'error' => 'Lead not found'], 404);
    break;

case 'send-email':
    // Actually send the generated email to the lead via Resend, then record it
    // (same bookkeeping as 'save-email'). The system sends from the configured
    // outreach address; replies go to the rep's own inbox via Reply-To.
    if ($method !== 'POST') break;
    $user = requireAuth();
    $userData = getUserData($user['id']);
    $admin = getAdmin();
    $leadId = $input['lead_id'] ?? '';
    $emailType = $input['type'] ?? 'initial';
    $subject = trim($input['subject'] ?? '');
    $bodyText = trim($input['content'] ?? '');

    if ($subject === '' || $bodyText === '') {
        respond(['success' => false, 'error' => 'Subject and message are required'], 400);
    }

    // Find the lead and validate its email address up front.
    $leadsStore = getLeadsStore();
    $target = null;
    foreach ($leadsStore['leads'] as $l) {
        if ($l['id'] === $leadId) { $target = $l; break; }
    }
    if (!$target) respond(['success' => false, 'error' => 'Lead not found'], 404);
    $toEmail = trim($target['email'] ?? '');
    if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        respond(['success' => false, 'error' => 'This lead has no valid email address'], 400);
    }

    // Sales outreach has its OWN sender config, independent of Help & Support.
    // From: the verified outreach address ('outreach_from'); Reply-To: the rep's own
    // account email so replies land in their inbox, not a shared mailbox.
    $resendKey = trim($admin['resend_key'] ?? '');
    if ($resendKey === '') {
        respond(['success' => false, 'error' => 'Email sending is not configured (no Resend API key)'], 400);
    }
    $settings = $userData['settings'] ?? [];
    $repName = trim($settings['sender_name'] ?? '') ?: trim($user['name'] ?? '') ?: 'Levata';
    // For now lead outreach reuses the support sender; set 'outreach_from' later to split them.
    $fromAddr = trim($admin['outreach_from'] ?? '') ?: (trim($admin['support_from'] ?? '') ?: 'onboarding@resend.dev');
    $replyTo = trim($user['email'] ?? '');

    // Build an HTML version from the plain text (paragraphs), plus a small footer
    // with the sender and an unsubscribe line so cold outreach stays CAN-SPAM clean.
    $e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
    $paras = preg_split('/\n{2,}/', $bodyText);
    $htmlBody = '';
    foreach ($paras as $p) {
        $p = trim($p);
        if ($p === '') continue;
        $htmlBody .= '<p style="margin:0 0 12px;">' . nl2br($e($p)) . '</p>';
    }
    $footer = '<p style="margin:18px 0 0;font-size:12px;color:#9ca3af;line-height:1.5;">'
        . 'Sent by ' . $e($repName) . ' at ' . $e(trim($settings['sender_company'] ?? '') ?: 'Levata') . '. '
        . 'If this reached you in error, reply with "unsubscribe" and we will remove you.</p>';
    $html = '<div style="font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;'
        . 'max-width:560px;margin:0 auto;color:#111827;font-size:14px;line-height:1.6;">'
        . $htmlBody . $footer . '</div>';
    $textWithFooter = $bodyText . "\n\n--\nSent by " . $repName . '. Reply "unsubscribe" to opt out.';

    // Send via Resend over HTTPS (self-contained; the lead system does not share
    // any sending code with Help & Support).
    $payload = [
        'from' => $repName . ' <' . $fromAddr . '>',
        'to' => [$toEmail],
        'subject' => $subject,
        'text' => $textWithFooter,
        'html' => $html,
    ];
    if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
        $payload['reply_to'] = $replyTo;
    }
    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $resendKey,
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT => 15,
    ]);
    $sendResponse = curl_exec($ch);
    $sendCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $sendDecoded = json_decode($sendResponse, true);
    if ($sendCode < 200 || $sendCode >= 300) {
        $err = is_array($sendDecoded) && !empty($sendDecoded['message'])
            ? $sendDecoded['message'] : ('Resend returned HTTP ' . $sendCode);
        respond(['success' => false, 'error' => 'Email not sent: ' . $err], 502);
    }
    $messageId = is_array($sendDecoded) ? ($sendDecoded['id'] ?? null) : null;

    // Delivered. Record it exactly like 'save-email' does.
    foreach ($leadsStore['leads'] as &$lead) {
        if ($lead['id'] === $leadId) {
            $lead['email_history'][] = [
                'type' => $emailType,
                'subject' => $subject,
                'content' => $bodyText,
                'sent_at' => date('c'),
                'channel' => 'system',
                'message_id' => $messageId,
            ];
            $lead['emails_sent'] = count($lead['email_history']);
            $lead['last_email_type'] = $emailType;
            $lead['last_action'] = 'email_sent';
            $lead['last_action_at'] = date('c');
            $lead['updated_at'] = date('c');
            logActivity($lead, 'email_sent', ucfirst(str_replace('_', ' ', $emailType)) . ' email sent from system');
            saveLeadsStore($leadsStore);
            respond(['success' => true, 'lead' => $lead]);
        }
    }
    respond(['success' => false, 'error' => 'Lead not found'], 404);
    break;

case 'email-outcome':
    if ($method !== 'POST') break;
    $user = requireAuth();
    $leadsStore = getLeadsStore();
    $leadId = $input['lead_id'] ?? '';
    $emailIndex = intval($input['email_index'] ?? -1);
    $outcome = $input['outcome'] ?? ''; // replied, bounced, no_response, meeting_booked

    $validOutcomes = ['replied', 'bounced', 'no_response', 'meeting_booked'];
    if (!in_array($outcome, $validOutcomes)) {
        respond(['success' => false, 'error' => 'Invalid outcome. Use: ' . implode(', ', $validOutcomes)], 400);
    }

    foreach ($leadsStore['leads'] as &$lead) {
        if ($lead['id'] === $leadId) {
            if ($emailIndex >= 0 && isset($lead['email_history'][$emailIndex])) {
                $lead['email_history'][$emailIndex]['outcome'] = $outcome;
                $lead['email_history'][$emailIndex]['outcome_at'] = date('c');
            } else {
                // Apply to most recent email
                $lastIdx = count($lead['email_history']) - 1;
                if ($lastIdx >= 0) {
                    $lead['email_history'][$lastIdx]['outcome'] = $outcome;
                    $lead['email_history'][$lastIdx]['outcome_at'] = date('c');
                }
            }

            // Update lead status based on outcome
            if ($outcome === 'meeting_booked') {
                setLeadStage($lead, 'demo', 'email_outcome:meeting_booked', $user['id']);
                $lead['last_action'] = 'meeting_booked';
            } elseif ($outcome === 'replied') {
                setLeadStage($lead, 'qualified', 'email_outcome:replied', $user['id']);
                $lead['last_action'] = 'email_replied';
            }
            $lead['last_action_at'] = date('c');
            $lead['updated_at'] = date('c');

            saveLeadsStore($leadsStore);
            respond(['success' => true, 'lead' => $lead]);
        }
    }
    respond(['success' => false, 'error' => 'Lead not found'], 404);
    break;

case 'start-call':
    if ($method !== 'POST') break;
    $user = requireAuth();
    $leadsStore = getLeadsStore();
    $leadId = $input['lead_id'] ?? '';

    foreach ($leadsStore['leads'] as &$lead) {
        if ($lead['id'] === $leadId) {
            $lead['call_started_at'] = date('c');
            $lead['calls_made'] = ($lead['calls_made'] ?? 0) + 1;
            $lead['last_action'] = 'call_started';
            $lead['last_action_at'] = date('c');
            $lead['updated_at'] = date('c');
            // Permanent activity entry so Team Activity counts this call durably
            // (last_action gets overwritten by later actions on the lead).
            $lead['activities'] = logLeadActivity($lead, 'call_started', 'Call started');
            $lead['call_history'] = $lead['call_history'] ?? [];
            $lead['call_history'][] = [
                'id' => 'call_' . bin2hex(random_bytes(8)),
                'started_at' => date('c'),
                'status' => 'pending',
                'rep_id' => $user['id'],
                'rep_name' => $user['name'] ?? $user['email']
            ];
            // Starting a call is activity within the Lead stage, not a stage move.
            saveLeadsStore($leadsStore);
            respond(['success' => true, 'lead' => $lead]);
        }
    }
    respond(['success' => false, 'error' => 'Lead not found'], 404);
    break;

case 'grade-override':
    if ($method !== 'POST') break;
    $user = requireAuth();
    $leadsStore = getLeadsStore();
    $leadId = $input['lead_id'] ?? '';

    foreach ($leadsStore['leads'] as &$lead) {
        if ($lead['id'] === $leadId) {
            $lead['grade_override'] = [
                'enabled' => true,
                'channel' => $input['channel'] ?? 'all',
                'reason' => $input['reason'] ?? '',
                'notes' => $input['notes'] ?? '',
                'overridden_by' => $user['name'] ?? $user['email'],
                'overridden_at' => date('c'),
                'original_grade' => $lead['fit_grade'] ?? ''
            ];
            $lead['updated_at'] = date('c');
            saveLeadsStore($leadsStore);
            respond(['success' => true, 'lead' => $lead]);
        }
    }
    respond(['success' => false, 'error' => 'Lead not found'], 404);
    break;

case 'recalculate-grade':
    if ($method !== 'POST') break;
    $user = requireAuth();
    $leadsStore = getLeadsStore();
    $admin = getAdmin();
    $leadId = $input['lead_id'] ?? '';

    foreach ($leadsStore['leads'] as &$lead) {
        if ($lead['id'] === $leadId) {
            // Check if lead has enrichment data
            if (empty($lead['enrichment'])) {
                respond(['success' => false, 'error' => 'Lead has no research data. Research the lead first.'], 400);
            }

            // Calculate ICP grade using shared function
            $gradeResult = calculateLeadGrade($lead, $admin);
            $lead['fit_grade'] = $gradeResult['grade'];
            $lead['fit_score'] = $gradeResult['score'];
            $lead['updated_at'] = date('c');

            saveLeadsStore($leadsStore);
            respond(['success' => true, 'lead' => $lead, 'grade' => $gradeResult['grade'], 'score' => $gradeResult['score']]);
        }
    }
    respond(['success' => false, 'error' => 'Lead not found'], 404);
    break;

// ============ PROACTIVE INTELLIGENCE ENDPOINTS ============

case 'log-activity':
    if ($method !== 'POST') break;
    $user = requireAuth();
    $leadsStore = getLeadsStore();
    $admin = getAdmin();
    $leadId = $input['lead_id'] ?? '';
    $activityType = $input['type'] ?? '';
    $details = $input['details'] ?? null;

    if (!$activityType) {
        respond(['success' => false, 'error' => 'Activity type is required'], 400);
    }

    foreach ($leadsStore['leads'] as &$lead) {
        if ($lead['id'] === $leadId) {
            // Add activity to the lead's activities array
            $lead['activities'] = logLeadActivity($lead, $activityType, $details);
            $lead['last_action'] = $activityType;
            $lead['last_action_at'] = date('c');
            $lead['updated_at'] = date('c');

            // Recalculate all scores
            $scores = recalculateAllLeadScores($lead, $admin);
            $lead = array_merge($lead, $scores);

            saveLeadsStore($leadsStore);
            respond([
                'success' => true,
                'lead' => $lead,
                'scores' => $scores
            ]);
        }
    }
    respond(['success' => false, 'error' => 'Lead not found'], 404);
    break;

case 'recalculate-all-scores':
    if ($method !== 'POST') break;
    $user = requireAuth();
    $leadsStore = getLeadsStore();
    $admin = getAdmin();
    $leadId = $input['lead_id'] ?? null; // Optional: recalculate single lead

    $updated = 0;
    foreach ($leadsStore['leads'] as &$lead) {
        // If lead_id provided, only update that lead
        if ($leadId && $lead['id'] !== $leadId) continue;

        // Recalculate scores
        $scores = recalculateAllLeadScores($lead, $admin);
        $lead = array_merge($lead, $scores);
        $updated++;

        // If single lead requested, respond immediately
        if ($leadId) {
            saveLeadsStore($leadsStore);
            respond(['success' => true, 'lead' => $lead, 'scores' => $scores]);
        }
    }

    saveLeadsStore($leadsStore);
    respond(['success' => true, 'updated' => $updated, 'message' => "Recalculated scores for $updated leads"]);
    break;

case 'dashboard-briefing':
    if ($method !== 'GET') break;
    $user = requireAuth();
    $userData = getUserData($user['id']);
    $admin = getAdmin();
    $leads = $userData['leads'] ?? [];

    // First, recalculate all scores to ensure they're current
    foreach ($leads as &$lead) {
        $scores = recalculateAllLeadScores($lead, $admin);
        $lead = array_merge($lead, $scores);
    }
    // Save updated scores
    $userData['leads'] = $leads;
    saveUserData($user['id'], $userData);

    // Group leads by temperature
    $temperatureGroups = [
        'on_fire' => [],
        'hot' => [],
        'warm' => [],
        'cold' => []
    ];

    foreach ($leads as $lead) {
        $temp = $lead['temperature'] ?? 'cold';
        if (isset($temperatureGroups[$temp])) {
            $temperatureGroups[$temp][] = [
                'id' => $lead['id'],
                'name' => trim(($lead['first_name'] ?? '') . ' ' . ($lead['last_name'] ?? '')),
                'company' => $lead['company'] ?? '',
                'status' => $lead['status'] ?? 'new',
                'fit_grade' => $lead['fit_grade'] ?? '',
                'engagement_score' => $lead['engagement_score'] ?? 0,
                'velocity' => $lead['velocity'] ?? 'stalled',
                'days_since_activity' => calculateDaysSinceLastActivity($lead)
            ];
        }
    }

    // Generate focus queue
    $focusQueue = generateFocusQueue($leads, $admin, 10);

    // Find attention-needed leads
    $attentionNeeded = findAttentionNeeded($leads);

    // Generate summary stats
    $summary = [
        'total_leads' => count($leads),
        'on_fire_count' => count($temperatureGroups['on_fire']),
        'hot_count' => count($temperatureGroups['hot']),
        'warm_count' => count($temperatureGroups['warm']),
        'cold_count' => count($temperatureGroups['cold']),
        'callbacks_due' => count(array_filter($leads, function($l) {
            return !empty($l['followup_date']) && strtotime($l['followup_date']) <= strtotime('today');
        })),
        'overdue_count' => count(array_filter($leads, function($l) {
            return !empty($l['followup_date']) && strtotime($l['followup_date']) < strtotime('today');
        })),
        'emails_ready' => count(array_filter($leads, function($l) {
            return getLeadStage($l) === 'research';
        }))
    ];

    respond([
        'success' => true,
        'summary' => $summary,
        'temperature_groups' => $temperatureGroups,
        'focus_queue' => $focusQueue,
        'attention_needed' => $attentionNeeded
    ]);
    break;

case 'focus-queue':
    if ($method !== 'GET') break;
    $user = requireAuth();
    $userData = getUserData($user['id']);
    $admin = getAdmin();
    $leads = $userData['leads'] ?? [];
    $limit = intval($_GET['limit'] ?? 10);

    $focusQueue = generateFocusQueue($leads, $admin, $limit);
    respond(['success' => true, 'focus_queue' => $focusQueue]);
    break;

case 'skip-focus-item':
    if ($method !== 'POST') break;
    $user = requireAuth();
    $leadsStore = getLeadsStore();
    $leadId = $input['lead_id'] ?? '';
    $duration = $input['duration'] ?? '1day'; // 1hour, 1day, 3days, 1week

    $skipDurations = [
        '1hour' => '+1 hour',
        '1day' => '+1 day',
        '3days' => '+3 days',
        '1week' => '+1 week'
    ];

    $skipUntil = date('c', strtotime($skipDurations[$duration] ?? '+1 day'));

    foreach ($leadsStore['leads'] as &$lead) {
        if ($lead['id'] === $leadId) {
            $lead['skipped_until'] = $skipUntil;
            $lead['updated_at'] = date('c');
            saveLeadsStore($leadsStore);
            respond(['success' => true, 'skipped_until' => $skipUntil]);
        }
    }
    respond(['success' => false, 'error' => 'Lead not found'], 404);
    break;

case 'drop-lead':
    if ($method !== 'POST') break;
    $user = requireAuth();
    $leadsStore = getLeadsStore();
    $leadId = $input['lead_id'] ?? '';
    $reason = $input['reason'] ?? '';

    foreach ($leadsStore['leads'] as &$lead) {
        if ($lead['id'] === $leadId) {
            setLeadStage($lead, 'lost', 'drop_lead', $user['id']);
            $lead['disqualified_reason'] = $reason;
            $lead['rejection_reason'] = $reason;
            $lead['disqualified_at'] = date('c');
            $lead['last_action'] = 'disqualified';
            $lead['last_action_at'] = date('c');
            $lead['updated_at'] = date('c');
            saveLeadsStore($leadsStore);
            respond(['success' => true, 'lead' => $lead]);
        }
    }
    respond(['success' => false, 'error' => 'Lead not found'], 404);
    break;

case 'next-best-action':
    if ($method !== 'GET') break;
    $user = requireAuth();
    $userData = getUserData($user['id']);
    $admin = getAdmin();
    $leadId = $_GET['lead_id'] ?? '';
    $useAI = ($_GET['use_ai'] ?? 'false') === 'true';

    foreach ($userData['leads'] as $lead) {
        if ($lead['id'] === $leadId) {
            if ($useAI) {
                $nba = generateNextBestAction($lead, $admin);
            } else {
                $daysSince = calculateDaysSinceLastActivity($lead);
                $nba = generateRuleBasedNBA($lead, $daysSince);
            }
            respond(['success' => true, 'recommendation' => $nba]);
        }
    }
    respond(['success' => false, 'error' => 'Lead not found'], 404);
    break;

// ==================== TEAM CHAT ====================
case 'chat-channels':
    $user = requireAuth();
    if ($method === 'GET') {
        $channels = getChatChannels();
        $unread = getChatUnreadCounts($user['id']);
        $isAdmin = isChatAdmin($user);
        $channels = array_values(array_filter($channels, function($ch) use ($user, $isAdmin) {
            $members = $ch['members'] ?? [];
            return empty($members) || $isAdmin || in_array($user['id'], $members, true);
        }));
        foreach ($channels as &$ch) $ch['unread'] = $unread[$ch['id']] ?? 0;
        respond(['success' => true, 'channels' => $channels]);
    }
    if ($method === 'POST') {
        if (!isChatAdmin($user)) respond(['success' => false, 'error' => 'Only admins can create channels'], 403);
        $name = strtolower(trim(preg_replace('/[^a-zA-Z0-9_\-]/', '', $input['name'] ?? '')));
        if (!$name) respond(['success' => false, 'error' => 'Channel name required'], 400);
        $channels = getChatChannels();
        foreach ($channels as $ch) {
            if ($ch['name'] === $name) respond(['success' => false, 'error' => 'Channel already exists'], 400);
        }
        $members = array_values(array_filter((array)($input['members'] ?? []), fn($id) => is_string($id) && $id !== ''));
        $newChannel = ['id' => 'channel_' . bin2hex(random_bytes(4)), 'name' => $name, 'description' => $input['description'] ?? '', 'members' => $members, 'created_by' => $user['id'], 'created_at' => date('c')];
        $channels[] = $newChannel;
        saveChatChannels($channels);
        respond(['success' => true, 'channel' => $newChannel]);
    }
    if ($method === 'DELETE') {
        if (empty($user['is_super_admin'])) respond(['success' => false, 'error' => 'Only super admin can delete channels'], 403);
        $channelId = $input['id'] ?? '';
        if ($channelId === 'channel_general') respond(['success' => false, 'error' => 'Cannot delete the general channel'], 400);
        if (!$channelId) respond(['success' => false, 'error' => 'Channel ID required'], 400);
        $channels = array_values(array_filter(getChatChannels(), fn($ch) => $ch['id'] !== $channelId));
        saveChatChannels($channels);
        respond(['success' => true]);
    }
    break;

case 'chat-channel-members':
    $user = requireAuth();
    if (!isChatAdmin($user)) respond(['success' => false, 'error' => 'Admin only'], 403);
    if ($method === 'POST') {
        $channelId = $input['channel_id'] ?? '';
        $members = array_values(array_filter((array)($input['members'] ?? []), fn($id) => is_string($id) && $id !== ''));
        $channels = getChatChannels();
        $found = false;
        foreach ($channels as &$ch) {
            if ($ch['id'] === $channelId) {
                $ch['members'] = $members;
                if (!empty($input['name'])) {
                    $newName = strtolower(trim(preg_replace('/[^a-zA-Z0-9_\-]/', '', $input['name'])));
                    if ($newName) $ch['name'] = $newName;
                }
                if (isset($input['description'])) $ch['description'] = trim($input['description']);
                $found = true;
                break;
            }
        }
        unset($ch);
        if (!$found) respond(['success' => false, 'error' => 'Channel not found'], 404);
        saveChatChannels($channels);
        respond(['success' => true]);
    }
    break;

case 'chat-messages':
    $user = requireAuth();
    $threadId = $_GET['thread'] ?? $input['thread'] ?? '';
    if (!$threadId) respond(['success' => false, 'error' => 'Thread required'], 400);
    if (!canAccessChatThread($user, $threadId)) respond(['success' => false, 'error' => 'Not authorized for this thread'], 403);
    $safe = preg_replace('/[^a-zA-Z0-9_]/', '', $threadId);

    if ($method === 'GET') {
        $messages = getChannelMessages($safe);
        $since = $_GET['since'] ?? null;
        if ($since) $messages = array_values(array_filter($messages, fn($m) => ($m['sent_at'] ?? '') > $since));
        if (empty($_GET['peek'])) dbSetLastRead($user['id'], $safe, date('c'));
        respond(['success' => true, 'messages' => array_slice($messages, -100)]);
    }

    if ($method === 'POST') {
        $text = trim($input['text'] ?? '');
        if (!$text) respond(['success' => false, 'error' => 'Message required'], 400);
        $messages = getChannelMessages($safe);
        $msg = [
            'id'        => 'msg_' . bin2hex(random_bytes(6)),
            'user_id'   => $user['id'],
            'user_name' => $user['name'] ?? $user['email'],
            'text'      => htmlspecialchars($text, ENT_QUOTES, 'UTF-8'),
            'sent_at'   => date('c'),
            'reactions' => []
        ];
        $messages[] = $msg;
        saveChannelMessages($safe, $messages);

        pusherTrigger($safe, 'new-message', $msg);
        notifyChatThread($user, $threadId, $safe, $msg, substr($text, 0, 100));
        // @mentions only make sense in channels, not DMs
        if (strpos($threadId, 'dm_') !== 0) {
            $threadLabel = '#' . $safe;
            foreach (getChatChannels() as $ch) {
                if ($ch['id'] === $threadId) { $threadLabel = '#' . $ch['name']; break; }
            }
            notifyChatMentions($user, $text, $threadId, $threadLabel);
        }
        respond(['success' => true, 'message' => $msg]);
    }
    break;

case 'chat-react':
    $user = requireAuth();
    if ($method !== 'POST') break;
    $threadId = preg_replace('/[^a-zA-Z0-9_]/', '', $input['thread'] ?? '');
    $msgId    = $input['message_id'] ?? '';
    $emoji    = $input['emoji'] ?? '';
    if (!$threadId || !$msgId || !$emoji) respond(['success' => false, 'error' => 'Missing fields'], 400);
    if (!canAccessChatThread($user, $threadId)) respond(['success' => false, 'error' => 'Not authorized for this thread'], 403);
    $messages = getChannelMessages($threadId);
    foreach ($messages as &$m) {
        if ($m['id'] === $msgId) {
            if (!isset($m['reactions'])) $m['reactions'] = [];
            $existing = false;
            foreach ($m['reactions'] as &$r) {
                if ($r['emoji'] === $emoji) {
                    if (in_array($user['id'], $r['users'])) {
                        $r['users'] = array_values(array_filter($r['users'], fn($u) => $u !== $user['id']));
                    } else {
                        $r['users'][] = $user['id'];
                    }
                    if (empty($r['users'])) $m['reactions'] = array_values(array_filter($m['reactions'], fn($rx) => $rx['emoji'] !== $emoji));
                    $existing = true;
                    break;
                }
            }
            unset($r);
            if (!$existing) $m['reactions'][] = ['emoji' => $emoji, 'users' => [$user['id']]];
            break;
        }
    }
    unset($m);
    saveChannelMessages($threadId, $messages);
    pusherTrigger($threadId, 'thread-updated', ['reason' => 'react']);
    respond(['success' => true]);
    break;

case 'chat-dm-threads':
    $user = requireAuth();
    if ($method !== 'GET') break;
    $unread = getChatUnreadCounts($user['id']);
    $threads = [];
    foreach (getUsers() as $u) {
        if ($u['id'] === $user['id']) continue;
        $threadId = getDmThreadId($user['id'], $u['id']);
        $threads[] = [
            'thread_id' => $threadId,
            'user_id'   => $u['id'],
            'user_name' => $u['name'] ?? $u['email'],
            'unread'    => $unread[$threadId] ?? 0
        ];
    }
    respond(['success' => true, 'threads' => $threads]);
    break;

case 'chat-delete-message':
    if ($method !== 'POST') break;
    $user = requireAuth();
    $threadId = preg_replace('/[^a-zA-Z0-9_]/', '', $input['thread'] ?? '');
    $msgId = $input['message_id'] ?? '';
    if (!$threadId || !$msgId) respond(['success' => false, 'error' => 'Missing fields'], 400);
    if (!canAccessChatThread($user, $threadId)) respond(['success' => false, 'error' => 'Not authorized for this thread'], 403);
    $messages = getChannelMessages($threadId);
    $found = false;
    $isAdminUser = isChatAdmin($user);
    foreach ($messages as $m) {
        if ($m['id'] === $msgId) {
            if ($m['user_id'] !== $user['id'] && !$isAdminUser) {
                respond(['success' => false, 'error' => 'You can only delete your own messages'], 403);
            }
            $found = true;
            break;
        }
    }
    if (!$found) respond(['success' => false, 'error' => 'Message not found'], 404);
    $messages = array_values(array_filter($messages, fn($m) => $m['id'] !== $msgId));
    saveChannelMessages($threadId, $messages);
    pusherTrigger($threadId, 'thread-updated', ['reason' => 'delete']);
    respond(['success' => true]);
    break;

case 'chat-pin-message':
    if ($method !== 'POST') break;
    $user = requireAuth();
    $threadId = preg_replace('/[^a-zA-Z0-9_]/', '', $input['thread'] ?? '');
    $msgId    = $input['message_id'] ?? '';
    $unpin    = !empty($input['unpin']);
    if (!$threadId) respond(['success' => false, 'error' => 'Thread required'], 400);
    if (!canAccessChatThread($user, $threadId)) respond(['success' => false, 'error' => 'Not authorized for this thread'], 403);
    $messages = getChannelMessages($threadId);

    if ($unpin) {
        foreach ($messages as &$m) {
            if ($m['id'] === $msgId) { $m['pinned'] = false; $m['pinned_at'] = null; $m['pinned_by'] = null; break; }
        }
        unset($m);
        saveChannelMessages($threadId, $messages);
        pusherTrigger($threadId, 'thread-updated', ['reason' => 'unpin']);
        respond(['success' => true]);
    } else {
        $replacedName = null;
        foreach ($messages as &$m) {
            if (!empty($m['pinned']) && $m['id'] !== $msgId) {
                if (($m['pinned_by'] ?? '') !== $user['id'] && !empty($m['pinned_by_name'])) $replacedName = $m['pinned_by_name'];
                $m['pinned'] = false; $m['pinned_at'] = null; $m['pinned_by'] = null;
            }
        }
        unset($m);
        foreach ($messages as &$m) {
            if ($m['id'] === $msgId) {
                $m['pinned'] = true;
                $m['pinned_at'] = date('c');
                $m['pinned_by'] = $user['id'];
                $m['pinned_by_name'] = $user['name'] ?? $user['email'];
                break;
            }
        }
        unset($m);
        saveChannelMessages($threadId, $messages);
        pusherTrigger($threadId, 'thread-updated', ['reason' => 'pin']);
        respond(['success' => true, 'replaced' => $replacedName]);
    }
    break;

case 'chat-upload':
    if ($method !== 'POST') break;
    $user = requireAuth();
    $threadId = preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['thread'] ?? '');
    if (!$threadId) respond(['success' => false, 'error' => 'Thread required'], 400);
    if (!canAccessChatThread($user, $threadId)) respond(['success' => false, 'error' => 'Not authorized for this thread'], 403);
    if (empty($_FILES['file'])) respond(['success' => false, 'error' => 'No file uploaded'], 400);

    $file = $_FILES['file'];
    if ($file['size'] > 5 * 1024 * 1024) respond(['success' => false, 'error' => 'File too large (max 5MB)'], 400);

    $allowed = ['image/jpeg','image/png','image/gif','image/webp','application/pdf','text/plain','text/csv'];
    if (!in_array($file['type'], $allowed)) respond(['success' => false, 'error' => 'File type not allowed'], 400);

    $uploadDir = DATA_DIR . '/uploads/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = bin2hex(random_bytes(8)) . '.' . strtolower($ext);
    if (!move_uploaded_file($file['tmp_name'], $uploadDir . $filename)) respond(['success' => false, 'error' => 'Upload failed'], 500);

    $caption = trim($_POST['caption'] ?? '');
    $messages = getChannelMessages($threadId);
    $isImage = strpos($file['type'], 'image/') === 0;
    $msg = [
        'id'        => 'msg_' . bin2hex(random_bytes(6)),
        'user_id'   => $user['id'],
        'user_name' => $user['name'] ?? $user['email'],
        'text'      => $caption !== '' ? htmlspecialchars($caption, ENT_QUOTES, 'UTF-8') : '',
        'file'      => ['name' => $file['name'], 'path' => 'data/uploads/' . $filename, 'type' => $file['type'], 'size' => $file['size'], 'is_image' => $isImage],
        'sent_at'   => date('c'),
        'reactions' => []
    ];
    $messages[] = $msg;
    saveChannelMessages($threadId, $messages);

    pusherTrigger($threadId, 'new-message', $msg);
    $notifBody = $isImage ? '📷 Image' : '📎 ' . $file['name'];
    if ($caption !== '') $notifBody .= ': ' . substr($caption, 0, 80);
    notifyChatThread($user, $threadId, $threadId, $msg, $notifBody);

    respond(['success' => true, 'message' => $msg]);
    break;

case 'chat-unread':
    $user = requireAuth();
    if ($method !== 'GET') break;
    $counts = getChatUnreadCounts($user['id']);
    respond(['success' => true, 'total' => array_sum($counts), 'counts' => $counts]);
    break;

case 'chat-assistant':
    // AI Assistant — a function-calling agent over app data. Runs on the first
    // configured provider (Cerebras / Groq / Gemini) and fails over to the next
    // on a rate limit or outage; see assistantProviderChain() in chatbot.php.
    // Body: { message: string, history?: Gemini contents[] }. Returns the reply
    // plus the updated history so the client can carry the conversation forward.
    $user = requireAuth();
    if ($method !== 'POST') break;
    $message = trim($input['message'] ?? '');
    if ($message === '') respond(['success' => false, 'error' => 'Message is required'], 400);
    $history = is_array($input['history'] ?? null) ? $input['history'] : [];
    $result = runAssistant($message, $history, $user);
    if (isset($result['error'])) respond(['success' => false, 'error' => $result['error']], 400);
    respond([
        'success'       => true,
        'reply'         => $result['reply'],
        'tools_used'    => $result['tools_used'],
        'history'       => $result['history'],
        'pending_action'=> $result['pending_action'] ?? null,
        'provider'      => $result['provider'] ?? null,
        'fell_back_from'=> $result['fell_back_from'] ?? null,
    ]);
    break;

case 'assistant-execute':
    // Perform a write the assistant PROPOSED, after the user clicked Confirm.
    // Body: { type: string, spec: object }. Re-validated server-side; reuses the
    // same write helpers as the normal save-* endpoints.
    $user = requireAuth();
    if ($method !== 'POST') break;
    $type = trim($input['type'] ?? '');
    $spec = is_array($input['spec'] ?? null) ? $input['spec'] : [];
    if ($type === '') respond(['success' => false, 'error' => 'Action type is required'], 400);
    $r = executeAssistantAction($type, $spec, $user);
    if (isset($r['error'])) respond(['success' => false, 'error' => $r['error']], 400);
    respond(['success' => true, 'message' => $r['message'] ?? 'Done.']);
    break;

case 'pusher-config':
    // Returns only the PUBLIC Pusher creds (key + cluster) to authed clients so
    // the browser can subscribe. The secret NEVER leaves the server.
    requireAuth();
    if ($method !== 'GET') break;
    $c = pusherConfig();
    respond([
        'success' => true,
        'enabled' => pusherEnabled(),
        'key'     => $c['key'],
        'cluster' => $c['cluster'],
    ]);
    break;

case 'notifications':
    if ($method === 'GET') {
        $user = requireAuth();
        $userData = getUserData($user['id']);
        $notifications = $userData['notifications'] ?? [];

        // Sort by created_at descending
        usort($notifications, function($a, $b) {
            return strtotime($b['created_at'] ?? 0) - strtotime($a['created_at'] ?? 0);
        });

        // Ensure all notifications have title + body fields for UI
        foreach ($notifications as &$n) {
            if (empty($n['title']) && !empty($n['message'])) {
                $n['title'] = $n['message'];
                $n['body']  = '';
            }
        }

        respond(['success' => true, 'notifications' => $notifications]);
    }
    if ($method === 'POST') {
        $user = requireAuth();
        $userData = getUserData($user['id']);
        $action = $input['action'] ?? '';
        $notifId = $input['notification_id'] ?? '';

        // Mutate $userData['notifications'] IN PLACE by index. (Using
        // `foreach (($userData['notifications'] ?? []) as &$n)` writes to a
        // throwaway copy of the ?? expression, so read-flags never persisted —
        // that was the "notification reappears after the next poll" bug.)
        if (!isset($userData['notifications']) || !is_array($userData['notifications'])) {
            $userData['notifications'] = [];
        }

        if ($action === 'mark_read') {
            foreach ($userData['notifications'] as $i => $n) {
                if (($n['id'] ?? '') === $notifId) $userData['notifications'][$i]['read'] = true;
            }
        } elseif ($action === 'mark_thread_read') {
            // Reading a chat thread clears ALL its bell notifications at once, so
            // opening a conversation dismisses its notifications everywhere.
            $tid = $input['thread_id'] ?? '';
            if ($tid !== '') {
                foreach ($userData['notifications'] as $i => $n) {
                    if (($n['thread_id'] ?? '') === $tid) $userData['notifications'][$i]['read'] = true;
                }
            }
        } elseif ($action === 'mark_all_read') {
            foreach ($userData['notifications'] as $i => $n) {
                $userData['notifications'][$i]['read'] = true;
            }
        } elseif ($action === 'dismiss') {
            $userData['notifications'] = array_values(array_filter(
                $userData['notifications'],
                function($n) use ($notifId) { return ($n['id'] ?? '') !== $notifId; }
            ));
        }

        saveUserData($user['id'], $userData);
        respond(['success' => true]);
    }
    break;

case 'generate-notifications':
    if ($method !== 'POST') break;
    $user = requireAuth();
    $userData = getUserData($user['id']);
    $leads = array_filter($userData['leads'] ?? [], fn($l) => empty($l['deleted_at']));
    $allExisting = $userData['notifications'] ?? [];

    // Generate new ones — pass ALL existing so duplicates are prevented
    $newNotifications = generateNotifications($leads, $allExisting);

    if (!empty($newNotifications)) {
        // Keep manual notifications (mentions etc.) + add new ones
        $userData['notifications'] = array_merge($allExisting, $newNotifications);
        // Keep max 50, newest first
        usort($userData['notifications'], fn($a,$b) => strtotime($b['created_at']??'0') - strtotime($a['created_at']??'0'));
        $userData['notifications'] = array_slice($userData['notifications'], 0, 50);
        saveUserData($user['id'], $userData);
    }

    respond(['success' => true, 'new_count' => count($newNotifications), 'notifications' => $newNotifications]);
    break;

case 'settings':
    $user = requireAuth();
    $userData = getUserData($user['id']);
    if ($method === 'GET') {
        $admin = getAdmin();
        $settings = $userData['settings'] ?? [];
        $settings['api_configured'] = !empty($admin[($admin['default_provider'] ?? 'groq') . '_key']);
        respond(['success' => true, 'settings' => $settings]);
    }
    if ($method === 'POST') {
        $userData['settings'] = [
            'sender_name' => trim($input['sender_name'] ?? ''),
            'sender_company' => trim($input['sender_company'] ?? 'Levata'),
            'sender_title' => trim($input['sender_title'] ?? ''),
            'company_description' => trim($input['company_description'] ?? ''),
            'value_proposition' => trim($input['value_proposition'] ?? ''),
            'social_proof' => trim($input['social_proof'] ?? ''),
            'calendar_link' => trim($input['calendar_link'] ?? ''),
            'email_tone' => $input['email_tone'] ?? 'professional',
            'signature' => $input['signature'] ?? ''
        ];
        saveUserData($user['id'], $userData);
        respond(['success' => true]);
    }
    break;

case 'activity-feed':
    if ($method !== 'GET') break;
    $user = requireAuth();
    $limit = min(50, intval($_GET['limit'] ?? 20));

    $iconMap = [
        'created'      => '➕',
        'deleted'      => '🗑️',
        'restored'     => '♻️',
        'stage_change' => '🔄',
        'call_logged'  => '📞',
        'email_sent'   => '📧',
        'researched'   => '🔍',
    ];

    // Admin sees all users' activity; rep sees only their own; super admins see everyone
    $isSuperAdmin = $user['is_super_admin'] ?? false;
    if ($user['is_admin'] ?? false) {
        $allUsers = $isSuperAdmin ? getUsers() : array_values(array_filter(getUsers(), fn($u) => empty($u['is_super_admin'])));
    } else {
        $allUsers = [$user];
    }
    $userMap = [];
    foreach ($allUsers as $u) $userMap[$u['id']] = $u['name'] ?? $u['email'];

    $activities = [];
    foreach ($allUsers as $u) {
        $uData = getUserData($u['id']);
        $repName = $u['name'] ?? $u['email'];
        foreach ($uData['leads'] ?? [] as $lead) {
            $name = trim(($lead['first_name'] ?? '') . ' ' . ($lead['last_name'] ?? ''));
            $company = $lead['company'] ?? '';

            foreach ($lead['activity_log'] ?? [] as $entry) {
                $type = $entry['type'] ?? 'update';
                $activities[] = [
                    'type'      => $type,
                    'icon'      => $iconMap[$type] ?? '📝',
                    'lead_id'   => $lead['id'],
                    'lead_name' => $name,
                    'company'   => $company,
                    'detail'    => $entry['detail'] ?? '',
                    'rep'       => $repName,
                    'timestamp' => $entry['timestamp'] ?? $lead['updated_at'],
                ];
            }

            // Legacy: leads with no activity_log yet
            if (empty($lead['activity_log'])) {
                foreach ($lead['email_history'] ?? [] as $email) {
                    $activities[] = [
                        'type'      => 'email_sent',
                        'icon'      => '📧',
                        'lead_id'   => $lead['id'],
                        'lead_name' => $name,
                        'company'   => $company,
                        'detail'    => ucfirst(str_replace('_', ' ', $email['type'] ?? 'initial')) . ' email sent',
                        'rep'       => $repName,
                        'timestamp' => $email['sent_at'] ?? $lead['updated_at'],
                    ];
                }
                if (!empty($lead['enrichment'])) {
                    $activities[] = [
                        'type'      => 'researched',
                        'icon'      => '🔍',
                        'lead_id'   => $lead['id'],
                        'lead_name' => $name,
                        'company'   => $company,
                        'detail'    => 'Lead researched',
                        'rep'       => $repName,
                        'timestamp' => $lead['last_action_at'] ?? $lead['updated_at'],
                    ];
                }
            }
        }
    }

    usort($activities, function($a, $b) {
        return strtotime($b['timestamp'] ?? '0') - strtotime($a['timestamp'] ?? '0');
    });

    respond(['success' => true, 'activities' => array_slice($activities, 0, $limit)]);
    break;

case 'stats':
    if ($method !== 'GET') break;
    $user = requireAuth();
    // Team-wide by default, matching the 'leads' action (same optional
    // owner_id narrowing, mirrored so the stage strip's counts always match
    // whatever the pipeline table below it is actually showing).
    $leads = getLeadsStore()['leads'];
    $ownerFilter = trim($_GET['owner_id'] ?? '');
    if ($ownerFilter !== '') {
        $leads = array_filter($leads, fn($l) => ($l['owner_id'] ?? '') === $ownerFilter);
    }
    $leads = array_filter($leads, function($l) { return empty($l['deleted_at']); });
    // Direct (came to us ourselves) vs Partner (referred by a channel partner)
    // — mirrors the same filter on the `leads` list, so the stage strip above
    // the pipeline table matches whatever rows are actually showing below it.
    $sourceType = $_GET['source_type'] ?? 'all';
    if ($sourceType === 'direct') {
        $leads = array_filter($leads, function($l) { return empty($l['partner_id']); });
    } elseif ($sourceType === 'partner') {
        $leads = array_filter($leads, function($l) { return !empty($l['partner_id']); });
    }
    $stats = ['total' => count($leads)];
    foreach (array_keys(getMacktilesStages()) as $s) $stats[$s] = 0;
    // Legacy aliases kept so older callers/exports don't break.
    foreach (['new', 'researched', 'call_due', 'outcome_logged', 'qualified', 'disqualified'] as $s) {
        if (!isset($stats[$s])) $stats[$s] = 0;
    }
    // Deal value per stage, plus open/won totals — every stage carries an
    // amount. Grouped by currency (never summed across currencies — a deal in
    // USD and one in LKR must not be added into one meaningless number).
    $stageValue = [];
    foreach (array_keys(getMacktilesStages()) as $s) $stageValue[$s] = [];
    $openValue = []; $wonValue = [];
    foreach ($leads as $l) {
        $stage = getLeadStage($l);
        if (isset($stats[$stage])) $stats[$stage]++;
        $legacy = stageToLegacyStatus($stage);
        if ($legacy !== $stage && isset($stats[$legacy])) $stats[$legacy]++;
        $amt = dealMoney($l['deal_amount'] ?? 0);
        $cur = $l['deal_currency'] ?? '';
        if (isset($stageValue[$stage])) addToCurrencyBucket($stageValue[$stage], $cur, $amt);
        if ($stage === 'won') addToCurrencyBucket($wonValue, $cur, $amt);
        elseif ($stage !== 'lost') addToCurrencyBucket($openValue, $cur, $amt);
    }
    $stats['stage_value'] = $stageValue;
    $stats['open_value'] = $openValue;
    $stats['won_value'] = $wonValue;

    // LKR-converted totals — an ADDITIONAL, clearly-labelled approximation
    // for display only (never replaces the per-currency breakdown above).
    // Source deal amounts are never touched.
    $fx = getFxRates();
    $stats['fx_stale'] = $fx['stale'];
    $stats['fx_fetched_at'] = $fx['fetched_at'] ? date('c', $fx['fetched_at']) : null;
    $stageValueLkr = [];
    foreach ($stageValue as $s => $byCur) $stageValueLkr[$s] = fxConvertMapToLkr($byCur, $fx['rates']);
    $stats['stage_value_lkr'] = $stageValueLkr;
    $stats['open_value_lkr'] = fxConvertMapToLkr($openValue, $fx['rates']);
    $stats['won_value_lkr'] = fxConvertMapToLkr($wonValue, $fx['rates']);

    // Per-stage KPIs: count, avg days spent in that stage (from
    // stage_history — how long a lead sat there before moving on, or how
    // long it's been sitting there so far if it's still current), and
    // conversion rate (of deals that ever reached this stage, what share
    // moved forward vs. ended in Lost).
    $kpi = [];
    foreach (array_keys(getMacktilesStages()) as $s) {
        if ($s === 'lost') continue;
        $kpi[$s] = ['count' => 0, 'durations_days' => [], 'reached' => 0, 'lost_from_here' => 0, 'advanced' => 0];
    }
    foreach ($leads as $l) {
        $stage = getLeadStage($l);
        if (isset($kpi[$stage])) $kpi[$stage]['count']++;
        $history = is_array($l['stage_history'] ?? null) ? $l['stage_history'] : [];
        // Walk consecutive history entries to time how long the lead sat in
        // each "from" stage before the next transition.
        for ($i = 0; $i < count($history); $i++) {
            $from = $history[$i]['from'] ?? null;
            $to = $history[$i]['to'] ?? null;
            $at = $history[$i]['timestamp'] ?? null;
            if (!$from || !$to || !$at || !isset($kpi[$from])) continue;
            $prevAt = $i > 0 ? ($history[$i - 1]['timestamp'] ?? null) : ($l['created_at'] ?? null);
            if ($prevAt) {
                $days = (strtotime($at) - strtotime($prevAt)) / 86400;
                if ($days >= 0) $kpi[$from]['durations_days'][] = $days;
            }
            $kpi[$from]['reached']++;
            if ($to === 'lost') $kpi[$from]['lost_from_here']++;
            else $kpi[$from]['advanced']++;
        }
        // A lead currently sitting in a stage (hasn't moved on yet) still
        // counts as having "reached" it, and contributes an in-progress
        // duration so early-stage KPIs aren't blind to deals still active.
        if (isset($kpi[$stage])) {
            $kpi[$stage]['reached']++;
            $enteredAt = $l['stage_entered_at'] ?? ($l['created_at'] ?? null);
            if ($enteredAt) {
                $days = (time() - strtotime($enteredAt)) / 86400;
                if ($days >= 0) $kpi[$stage]['durations_days'][] = $days;
            }
        }
    }
    $stageKpi = [];
    foreach ($kpi as $s => $k) {
        $avgDays = count($k['durations_days']) ? round(array_sum($k['durations_days']) / count($k['durations_days']), 1) : null;
        $conversionRate = $k['reached'] > 0 ? round(($k['advanced'] / $k['reached']) * 100) : null;
        $stageKpi[$s] = [
            'count' => $k['count'],
            'avg_days_in_stage' => $avgDays,
            'conversion_rate' => $conversionRate,
        ];
    }
    $stats['stage_kpi'] = $stageKpi;

    respond(['success' => true, 'stats' => $stats]);
    break;

// ============ SALES EXCELLENCE SYSTEM ============

case 'command-center':
    if ($method !== 'GET') break;
    $user = requireAuth();
    $allUsers = getUsers();
    $isSuperAdmin = $user['is_super_admin'] ?? false;
    $users = $isSuperAdmin ? $allUsers : array_values(array_filter($allUsers, fn($u) => empty($u['is_super_admin'])));
    $teamActivity = [];
    $leads = [];
    $settings = [];
    if (!empty($user['is_admin'])) {
        // Single shared-store read instead of looping getUserData() per team
        // member — leads already carry owner_id now that they live in one
        // shared store (leads.php), so this collapses to a filter+tag pass.
        $usersById = [];
        foreach ($users as $teamUser) {
            $usersById[$teamUser['id']] = $teamUser;
            $teamActivity[$teamUser['id']] = [
                'user_id' => $teamUser['id'],
                'name' => $teamUser['name'] ?? 'User',
                'calls' => 0,
                'emails' => 0,
                'research' => 0,
                'outcomes' => 0,
                'consultations' => 0,
                'leads_owned' => 0
            ];
        }
        foreach (getLeadsStore()['leads'] as $lead) {
            if (!empty($lead['deleted_at'])) continue;
            $ownerId = $lead['owner_id'] ?? '';
            if (!isset($usersById[$ownerId])) continue; // not a team member in scope (e.g. filtered-out super admin)
            $lead['_owner_id'] = $ownerId;
            $lead['_owner_name'] = $usersById[$ownerId]['name'] ?? 'User';
            $leads[] = $lead;
            $teamActivity[$ownerId]['leads_owned']++;
        }
        $settings = getUserData($user['id'])['settings'] ?? [];
    } else {
        $userData = getUserData($user['id']);
        foreach (($userData['leads'] ?? []) as $lead) {
            if (empty($lead['deleted_at'])) $leads[] = $lead;
        }
        $settings = $userData['settings'] ?? [];
        $teamActivity[$user['id']] = [
            'user_id' => $user['id'],
            'name' => $user['name'] ?? 'User',
            'calls' => 0,
            'emails' => 0,
            'research' => 0,
            'outcomes' => 0,
            'consultations' => 0,
            'leads_owned' => count($leads)
        ];
    }
    $today = date('Y-m-d');

    // Total counts
    $total = count($leads);

    // Grade distribution
    $grades = ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'ungraded' => 0];
    foreach ($leads as $l) {
        $grade = $l['fit_grade'] ?? '';
        if (isset($grades[$grade])) {
            $grades[$grade]++;
        } else {
            $grades['ungraded']++;
        }
    }

    // Temperature distribution
    $temperatures = ['on_fire' => 0, 'hot' => 0, 'warm' => 0, 'cold' => 0];
    foreach ($leads as $l) {
        $temp = $l['temperature'] ?? 'cold';
        if (isset($temperatures[$temp])) {
            $temperatures[$temp]++;
        }
    }

    // Velocity distribution
    $velocities = ['accelerating' => 0, 'stable' => 0, 'slowing' => 0, 'stalled' => 0];
    foreach ($leads as $l) {
        $vel = $l['velocity'] ?? 'stalled';
        if (isset($velocities[$vel])) {
            $velocities[$vel]++;
        }
    }

    // Status counts for funnel (where deals sit right now)
    $statuses = [];
    foreach (array_keys(getMacktilesStages()) as $s) $statuses[$s] = 0;
    $stageValue = [];
    foreach (array_keys(getMacktilesStages()) as $s) $stageValue[$s] = 0.0;
    foreach ($leads as $l) {
        $stage = getLeadStage($l);
        if (isset($statuses[$stage])) $statuses[$stage]++;
        if (isset($stageValue[$stage])) $stageValue[$stage] += dealMoney($l['deal_amount'] ?? 0);
    }

    // Conversion funnel: how many deals ever REACHED each stage (via peak_stage),
    // so each step is a true drop-off from the one before it.
    $funnel = [];
    $stageOrder = ['lead', 'qualified', 'demo', 'cost_proposal', 'sow', 'won'];
    $reached = array_fill_keys($stageOrder, 0);
    $reachedValue = array_fill_keys($stageOrder, 0.0);
    foreach ($leads as $l) {
        $peak = $l['peak_stage'] ?? getLeadStage($l);
        if (!isset($reached[$peak])) $peak = 'lead';
        $amt = dealMoney($l['deal_amount'] ?? 0);
        // A deal that reached SOW also passed through every earlier stage.
        foreach ($stageOrder as $s) {
            if (stageOrder($s) <= stageOrder($peak)) { $reached[$s]++; $reachedValue[$s] += $amt; }
        }
    }
    $topOfFunnel = $reached['lead'];
    $prev = null;
    foreach ($stageOrder as $stage) {
        $count = $reached[$stage];
        $funnel[] = [
            'stage' => $stage,
            'count' => $count,
            'current' => $statuses[$stage] ?? 0,
            'value' => $reachedValue[$stage],
            // Conversion from the previous stage, plus share of all deals.
            'rate' => ($prev === null || $prev === 0) ? null : round(($count / $prev) * 100),
            'overall_rate' => $topOfFunnel > 0 ? round(($count / $topOfFunnel) * 100) : null,
        ];
        $prev = $count;
    }

    // Today's activity tracking
    $todaysCalls = 0;
    $todaysEmails = 0;
    $todaysResearch = 0;
    $todaysOutcomes = 0;

    foreach ($leads as $l) {
        $ownerId = $l['_owner_id'] ?? $user['id'];
        // Count today's emails
        $emailHistory = $l['email_history'] ?? [];
        foreach ($emailHistory as $email) {
            if (isset($email['sent_at']) && substr($email['sent_at'], 0, 10) === $today) {
                $todaysEmails++;
                if (isset($teamActivity[$ownerId])) $teamActivity[$ownerId]['emails']++;
            }
        }

        // Count today's activities (calls, research, outcomes). Track which
        // categories the activities[] log already covered for this lead, so the
        // last_action fallback below does not double-count the same action.
        $countedCall = false; $countedResearch = false; $countedOutcome = false;
        $activities = $l['activities'] ?? [];
        foreach ($activities as $act) {
            if (isset($act['timestamp']) && substr($act['timestamp'], 0, 10) === $today) {
                if (isset($act['type'])) {
                    if ($act['type'] === 'call' || $act['type'] === 'call_started') {
                        $todaysCalls++;
                        if (isset($teamActivity[$ownerId])) $teamActivity[$ownerId]['calls']++;
                        $countedCall = true;
                    } elseif ($act['type'] === 'research' || $act['type'] === 'enriched') {
                        $todaysResearch++;
                        if (isset($teamActivity[$ownerId])) $teamActivity[$ownerId]['research']++;
                        $countedResearch = true;
                    } elseif (strpos($act['type'], 'outcome') !== false) {
                        $todaysOutcomes++;
                        if (isset($teamActivity[$ownerId])) $teamActivity[$ownerId]['outcomes']++;
                        $countedOutcome = true;
                    }
                }
            }
        }

        // Fallback: count from last_action ONLY for categories the activities[]
        // log did not already cover (e.g. research/start-call, which set
        // last_action but do not write an activities[] entry).
        if (!empty($l['last_action_at']) && substr($l['last_action_at'], 0, 10) === $today) {
            if (!empty($l['last_action'])) {
                if (!$countedCall && strpos($l['last_action'], 'call') !== false) {
                    $todaysCalls++;
                    if (isset($teamActivity[$ownerId])) $teamActivity[$ownerId]['calls']++;
                } elseif (!$countedResearch && (strpos($l['last_action'], 'research') !== false || strpos($l['last_action'], 'enrich') !== false)) {
                    $todaysResearch++;
                    if (isset($teamActivity[$ownerId])) $teamActivity[$ownerId]['research']++;
                }
                if (!$countedOutcome && (strpos($l['last_action'], 'outcome') !== false || strpos($l['last_action'], 'call_outcome') !== false)) {
                    $todaysOutcomes++;
                    if (isset($teamActivity[$ownerId])) $teamActivity[$ownerId]['outcomes']++;
                }
            }
        }
        if (in_array(getLeadStage($l), ['demo','cost_proposal','sow'], true) && isset($teamActivity[$ownerId])) {
            $teamActivity[$ownerId]['consultations']++;
        }
    }

    // Daily targets from user settings
    $dailyTargets = $settings['daily_targets'] ?? ['calls' => 40, 'emails' => 40, 'followups' => 25, 'research' => 25, 'weekly_imports' => 75, 'enabled' => true];

    $dailyProgress = [
        'calls' => ['done' => $todaysCalls, 'target' => $dailyTargets['calls'] ?? 40],
        'emails' => ['done' => $todaysEmails, 'target' => $dailyTargets['emails'] ?? 40],
        'research' => ['done' => $todaysResearch, 'target' => $dailyTargets['research'] ?? 25],
        'outcomes' => ['done' => $todaysOutcomes, 'target' => $dailyTargets['calls'] ?? 40]
    ];

    // Success rates by grade (qualified / total for each grade)
    $successByGrade = [];
    foreach (['A', 'B', 'C', 'D'] as $g) {
        $gradeLeads = array_filter($leads, fn($l) => ($l['fit_grade'] ?? '') === $g);
        $qualified = count(array_filter($gradeLeads, fn($l) => in_array(getLeadStage($l), ['demo', 'cost_proposal', 'sow', 'won'], true)));
        $gradeTotal = count($gradeLeads);
        $successByGrade[$g] = $gradeTotal > 0 ? round(($qualified / $gradeTotal) * 100) : 0;
    }

    // Success rates by temperature
    $successByTemp = [];
    foreach (['on_fire', 'hot', 'warm', 'cold'] as $t) {
        $tempLeads = array_filter($leads, fn($l) => ($l['temperature'] ?? 'cold') === $t);
        $qualified = count(array_filter($tempLeads, fn($l) => in_array(getLeadStage($l), ['demo', 'cost_proposal', 'sow', 'won'], true)));
        $tempTotal = count($tempLeads);
        $successByTemp[$t] = $tempTotal > 0 ? round(($qualified / $tempTotal) * 100) : 0;
    }

    // Overdue leads count
    $overdueCount = 0;
    $now = time();
    foreach ($leads as $l) {
        if (!empty($l['followup_date'])) {
            $followupTime = strtotime($l['followup_date']);
            if ($followupTime && $followupTime < $now && !in_array(getLeadStage($l), ['won', 'lost'])) {
                $overdueCount++;
            }
        }
    }

    $sourceSplit = [];
    $parkedReasons = [];
    $stageAges = [];
    $leadsAddedThisWeek = 0;
    $consultationsMtd = 0;
    $emailedCount = 0;
    $repliedCount = 0;
    $staleCount = 0;
    $monthStart = date('Y-m-01');
    $weekStart = date('Y-m-d', strtotime('monday this week'));
    foreach ($leads as $l) {
        $source = $l['source'] ?? 'firmable';
        $sourceSplit[$source] = ($sourceSplit[$source] ?? 0) + 1;
        $stage = getLeadStage($l);
        $entered = strtotime($l['stage_entered_at'] ?? $l['created_at'] ?? 'now');
        $stageAges[$stage][] = max(0, floor(($now - $entered) / 86400));
        $sla = calculateSLAStatus($l);
        if (!empty($sla['is_overdue']) && !in_array($stage, ['won', 'lost'])) $staleCount++;
        if (substr($l['created_at'] ?? '', 0, 10) >= $weekStart) $leadsAddedThisWeek++;
        if (in_array($stage, ['demo','cost_proposal','sow'], true) && substr($l['stage_entered_at'] ?? $l['updated_at'] ?? '', 0, 10) >= $monthStart) $consultationsMtd++;
        if (!empty($l['email_history'])) {
            $emailedCount++;
            foreach ($l['email_history'] as $eh) {
                if (($eh['outcome'] ?? '') === 'replied' || ($eh['outcome'] ?? '') === 'meeting_booked') {
                    $repliedCount++;
                    break;
                }
            }
        }
        if (in_array($stage, ['lost'])) {
            $reason = $l['rejection_reason'] ?? $l['disqualified_reason'] ?? 'No reason logged';
            $parkedReasons[$reason] = ($parkedReasons[$reason] ?? 0) + 1;
        }
    }
    $avgTimeInStage = [];
    foreach ($stageAges as $stage => $ages) {
        $avgTimeInStage[$stage] = count($ages) ? round(array_sum($ages) / count($ages), 1) : 0;
    }

    // Calculate streak (consecutive days hitting targets)
    $streak = 0;
    $dailyPerformance = $settings['daily_performance'] ?? [];
    $checkDate = new DateTime('yesterday');
    for ($i = 0; $i < 30; $i++) {
        $dateStr = $checkDate->format('Y-m-d');
        if (isset($dailyPerformance[$dateStr])) {
            $dayData = $dailyPerformance[$dateStr];
            $callsHit = ($dayData['calls_done'] ?? 0) >= ($dayData['calls_target'] ?? 40);
            $emailsHit = ($dayData['emails_done'] ?? 0) >= ($dayData['emails_target'] ?? 40);
            if ($callsHit && $emailsHit) {
                $streak++;
            } else {
                break;
            }
        } else {
            break;
        }
        $checkDate->modify('-1 day');
    }

    respond([
        'success' => true,
        'totals' => [
            'all' => $total,
            'grades' => $grades,
            'qualified' => $statuses['qualified'] + $statuses['demo'] + $statuses['cost_proposal'] + $statuses['sow'] + $statuses['won'],
            'disqualified' => $statuses['lost'],
            'consultation_booked' => $statuses['demo'],
            'nurture_parked' => $statuses['lost'],
            'overdue' => $overdueCount,
            'stale' => $staleCount,
            // Deal value across the pipeline (every stage carries an amount).
            'open_value' => array_sum(array_diff_key($stageValue, ['won' => 0, 'lost' => 0])),
            'won_value' => $stageValue['won'] ?? 0,
            'stage_value' => $stageValue
        ],
        'temperature' => $temperatures,
        'velocity' => $velocities,
        'funnel' => $funnel,
        'daily_progress' => $dailyProgress,
        'success_rates' => [
            'by_grade' => $successByGrade,
            'by_temperature' => $successByTemp
        ],
        'management' => [
            'consultations_mtd' => $consultationsMtd,
            'leads_added_this_week' => $leadsAddedThisWeek,
            'response_rate' => $emailedCount > 0 ? round(($repliedCount / $emailedCount) * 100) : 0,
            'average_time_in_stage' => $avgTimeInStage,
            'parked_reasons' => $parkedReasons,
            'lead_source_split' => $sourceSplit,
            'team_activity' => array_values($teamActivity)
        ],
        'streak' => $streak,
        'date' => $today
    ]);
    break;

case 'lead-batches':
    if ($method !== 'GET') break;
    $user = requireAuth();
    $userData = getUserData($user['id']);
    $leads = $userData['leads'] ?? [];
    $today = date('Y-m-d');
    $now = time();

    $batches = [
        'needs_research' => [],
        'needs_outreach' => [],
        'needs_calling' => [],
        'needs_followup' => [],
        'hot_focus' => [],
        'overdue' => [],
        'inbound_urgent' => []
    ];

    foreach ($leads as $l) {
        if (!empty($l['deleted_at'])) continue;
        $status = getLeadStage($l);
        if (($l['fit_grade'] ?? '') === 'Disqualified' && !in_array($status, ['lost'])) continue;
        $enrichment = $l['enrichment'] ?? '';
        $emailsSent = $l['emails_sent'] ?? 0;
        $callsMade = $l['calls_made'] ?? 0;
        $temperature = $l['temperature'] ?? 'cold';
        $velocity = $l['velocity'] ?? 'stalled';

        if (in_array($status, ['won', 'lost'])) continue;

        if (($l['source'] ?? '') === 'inbound' && $status === 'lead') {
            $batches['inbound_urgent'][] = $l;
        }

        // Needs Research: No enrichment data
        if (empty($enrichment)) {
            $batches['needs_research'][] = $l;
        }
        // Needs Outreach: Has research but zero emails sent
        elseif ($emailsSent === 0) {
            $batches['needs_outreach'][] = $l;
        }
        // Needs Calling: Has emails but zero calls
        elseif ($callsMade === 0) {
            $batches['needs_calling'][] = $l;
        }

        // Needs Follow-up: Stalled velocity (re-engage)
        if ($velocity === 'stalled' && !empty($enrichment) && !in_array($status, ['demo', 'cost_proposal', 'sow', 'won', 'lost'])) {
            $batches['needs_followup'][] = $l;
        }

        // Hot Focus: On fire or hot temperature
        if (in_array($temperature, ['on_fire', 'hot']) && !in_array($status, ['demo', 'cost_proposal', 'sow', 'won', 'lost'])) {
            $batches['hot_focus'][] = $l;
        }

        // Overdue: Followup date in the past
        if (!empty($l['followup_date'])) {
            $followupTime = strtotime($l['followup_date']);
            if ($followupTime && $followupTime < $now && !in_array($status, ['won', 'lost'])) {
                $batches['overdue'][] = $l;
            }
        }
    }

    // Sort batches by priority (hot leads first, then by days since activity)
    foreach ($batches as $key => &$batch) {
        usort($batch, function($a, $b) {
            // Priority: on_fire > hot > warm > cold
            $tempOrder = ['on_fire' => 0, 'hot' => 1, 'warm' => 2, 'cold' => 3];
            $aTemp = $tempOrder[$a['temperature'] ?? 'cold'] ?? 3;
            $bTemp = $tempOrder[$b['temperature'] ?? 'cold'] ?? 3;
            if ($aTemp !== $bTemp) return $aTemp - $bTemp;

            // Then by fit grade
            $gradeOrder = ['A' => 0, 'B' => 1, 'C' => 2, 'D' => 3, '' => 4];
            $aGrade = $gradeOrder[$a['fit_grade'] ?? ''] ?? 4;
            $bGrade = $gradeOrder[$b['fit_grade'] ?? ''] ?? 4;
            return $aGrade - $bGrade;
        });
    }

    // Return counts and limited lead previews
    $batchSummary = [];
    foreach ($batches as $key => $batch) {
        $batchSummary[$key] = [
            'count' => count($batch),
            'leads' => array_slice(array_map(function($l) {
                return [
                    'id' => $l['id'],
                    'name' => trim(($l['first_name'] ?? '') . ' ' . ($l['last_name'] ?? '')),
                    'company' => $l['company'] ?? '',
                    'status' => stageToLegacyStatus(getLeadStage($l)),
                    'stage' => getLeadStage($l),
                    'temperature' => $l['temperature'] ?? 'cold',
                    'fit_grade' => $l['fit_grade'] ?? '',
                    'days_inactive' => calculateDaysSinceLastActivity($l)
                ];
            }, $batch), 0, 20) // Limit to 20 per batch for performance
        ];
    }

    respond(['success' => true, 'batches' => $batchSummary]);
    break;

case 'daily-targets':
    $user = requireAuth();
    $userData = getUserData($user['id']);

    if ($method === 'GET') {
        $targets = $userData['settings']['daily_targets'] ?? [
            'calls' => 40,
            'emails' => 40,
            'followups' => 25,
            'research' => 25,
            'weekly_imports' => 75,
            'call_outcomes_required' => true,
            'stage_updates_required' => true,
            'enabled' => true
        ];
        respond(['success' => true, 'targets' => $targets]);
    }

    if ($method === 'POST') {
        if (!isset($userData['settings'])) {
            $userData['settings'] = [];
        }
        $userData['settings']['daily_targets'] = [
            'calls' => intval($input['calls'] ?? 40),
            'emails' => intval($input['emails'] ?? 40),
            'followups' => intval($input['followups'] ?? 25),
            'research' => intval($input['research'] ?? 25),
            'weekly_imports' => intval($input['weekly_imports'] ?? 75),
            'call_outcomes_required' => $input['call_outcomes_required'] ?? true,
            'stage_updates_required' => $input['stage_updates_required'] ?? true,
            'enabled' => $input['enabled'] ?? true
        ];
        saveUserData($user['id'], $userData);
        respond(['success' => true, 'targets' => $userData['settings']['daily_targets']]);
    }
    break;

case 'daily-commitments':
    $user = requireAuth();
    $userData = getUserData($user['id']);
    $today = date('Y-m-d');

    if ($method === 'GET') {
        $commitments = $userData['settings']['daily_commitments'] ?? [];
        $leads = $userData['leads'] ?? [];

        // Filter to today's commitments
        $todaysCommitments = array_filter($commitments, function($c) use ($today) {
            return isset($c['date']) && $c['date'] === $today;
        });

        // Enrich with lead info
        $todaysCommitments = array_map(function($c) use ($leads) {
            if (!empty($c['lead_id'])) {
                foreach ($leads as $l) {
                    if ($l['id'] === $c['lead_id']) {
                        $c['lead_name'] = trim(($l['first_name'] ?? '') . ' ' . ($l['last_name'] ?? ''));
                        $c['lead_company'] = $l['company'] ?? '';
                        break;
                    }
                }
            }
            return $c;
        }, array_values($todaysCommitments));

        // Also get incomplete from previous days (carry-over)
        $carryOver = array_filter($commitments, function($c) use ($today) {
            return isset($c['date']) && $c['date'] < $today && ($c['status'] ?? 'pending') === 'pending';
        });

        // Get targets
        $targets = $userData['settings']['daily_targets'] ?? ['calls' => 40, 'emails' => 40, 'followups' => 25, 'research' => 25, 'weekly_imports' => 75];

        // Calculate streak
        $streak = 0;
        $dailyPerformance = $userData['settings']['daily_performance'] ?? [];
        $checkDate = new DateTime('yesterday');
        for ($i = 0; $i < 30; $i++) {
            $dateStr = $checkDate->format('Y-m-d');
            if (isset($dailyPerformance[$dateStr])) {
                $dayData = $dailyPerformance[$dateStr];
                $callsHit = ($dayData['calls_done'] ?? 0) >= ($dayData['calls_target'] ?? 40);
                $emailsHit = ($dayData['emails_done'] ?? 0) >= ($dayData['emails_target'] ?? 40);
                if ($callsHit && $emailsHit) {
                    $streak++;
                } else {
                    break;
                }
            } else {
                break;
            }
            $checkDate->modify('-1 day');
        }

        respond([
            'success' => true,
            'commitments' => $todaysCommitments,
            'carryover' => array_values($carryOver),
            'targets' => $targets,
            'streak' => $streak,
            'date' => $today
        ]);
    }

    if ($method === 'POST') {
        // Add a new commitment
        if (!isset($userData['settings']['daily_commitments'])) {
            $userData['settings']['daily_commitments'] = [];
        }

        $newCommitment = [
            'id' => 'commit_' . bin2hex(random_bytes(4)),
            'lead_id' => $input['lead_id'] ?? null,
            'action' => $input['action'] ?? 'call',
            'description' => $input['description'] ?? '',
            'due_time' => $input['due_time'] ?? '09:00',
            'status' => 'pending',
            'date' => $today,
            'created_at' => date('c')
        ];

        $userData['settings']['daily_commitments'][] = $newCommitment;
        saveUserData($user['id'], $userData);
        respond(['success' => true, 'commitment' => $newCommitment]);
    }

    if ($method === 'PUT') {
        // Update commitment status
        $commitmentId = $input['commitment_id'] ?? '';
        $newStatus = $input['status'] ?? 'completed';

        if (!isset($userData['settings']['daily_commitments'])) {
            respond(['success' => false, 'error' => 'No commitments found'], 404);
        }

        $found = false;
        foreach ($userData['settings']['daily_commitments'] as &$c) {
            if ($c['id'] === $commitmentId) {
                $c['status'] = $newStatus;
                $c['completed_at'] = $newStatus === 'completed' ? date('c') : null;
                $found = true;
                break;
            }
        }

        if (!$found) {
            respond(['success' => false, 'error' => 'Commitment not found'], 404);
        }

        saveUserData($user['id'], $userData);
        respond(['success' => true]);
    }

    if ($method === 'DELETE') {
        // Delete commitment
        $commitmentId = $input['commitment_id'] ?? '';

        if (!isset($userData['settings']['daily_commitments'])) {
            respond(['success' => false, 'error' => 'No commitments found'], 404);
        }

        $userData['settings']['daily_commitments'] = array_filter(
            $userData['settings']['daily_commitments'],
            fn($c) => $c['id'] !== $commitmentId
        );
        $userData['settings']['daily_commitments'] = array_values($userData['settings']['daily_commitments']);

        saveUserData($user['id'], $userData);
        respond(['success' => true]);
    }
    break;

case 'daily-progress':
    if ($method !== 'GET') break;
    $user = requireAuth();
    $userData = getUserData($user['id']);
    $leads = $userData['leads'] ?? [];
    $settings = $userData['settings'] ?? [];
    $today = date('Y-m-d');

    // Count today's activities
    $todaysCalls = 0;
    $todaysEmails = 0;
    $todaysResearch = 0;

    foreach ($leads as $l) {
        // Count emails sent today
        foreach (($l['email_history'] ?? []) as $email) {
            if (isset($email['sent_at']) && substr($email['sent_at'], 0, 10) === $today) {
                $todaysEmails++;
            }
        }
        // Count activities today
        foreach (($l['activities'] ?? []) as $act) {
            if (isset($act['timestamp']) && substr($act['timestamp'], 0, 10) === $today) {
                $type = $act['type'] ?? '';
                if (in_array($type, ['call', 'call_started', 'call_made'])) $todaysCalls++;
                if (in_array($type, ['research', 'enriched', 'research_completed'])) $todaysResearch++;
            }
        }
    }

    // Get targets
    $targets = $settings['daily_targets'] ?? ['calls' => 40, 'emails' => 40, 'followups' => 25, 'research' => 25, 'weekly_imports' => 75];

    // Get commitments completed today
    $commitments = $settings['daily_commitments'] ?? [];
    $todaysCommitments = array_filter($commitments, fn($c) => ($c['date'] ?? '') === $today);
    $completedCommitments = array_filter($todaysCommitments, fn($c) => ($c['status'] ?? '') === 'completed');

    // Calculate streak
    $streak = 0;
    $dailyPerformance = $settings['daily_performance'] ?? [];
    $checkDate = new DateTime('yesterday');
    for ($i = 0; $i < 30; $i++) {
        $dateStr = $checkDate->format('Y-m-d');
        if (isset($dailyPerformance[$dateStr])) {
            $d = $dailyPerformance[$dateStr];
            if (($d['calls_done'] ?? 0) >= ($d['calls_target'] ?? 40) && ($d['emails_done'] ?? 0) >= ($d['emails_target'] ?? 40)) {
                $streak++;
            } else {
                break;
            }
        } else {
            break;
        }
        $checkDate->modify('-1 day');
    }

    // Calculate progress status
    $callsTarget = $targets['calls'] ?? 40;
    $emailsTarget = $targets['emails'] ?? 40;
    $researchTarget = $targets['research'] ?? 25;

    $progress = [
        'calls' => [
            'done' => $todaysCalls,
            'target' => $callsTarget,
            'percent' => $callsTarget > 0 ? round(($todaysCalls / $callsTarget) * 100) : 0,
            'status' => $todaysCalls >= $callsTarget ? 'complete' : ($todaysCalls >= $callsTarget * 0.7 ? 'on_track' : 'behind')
        ],
        'emails' => [
            'done' => $todaysEmails,
            'target' => $emailsTarget,
            'percent' => $emailsTarget > 0 ? round(($todaysEmails / $emailsTarget) * 100) : 0,
            'status' => $todaysEmails >= $emailsTarget ? 'complete' : ($todaysEmails >= $emailsTarget * 0.7 ? 'on_track' : 'behind')
        ],
        'research' => [
            'done' => $todaysResearch,
            'target' => $researchTarget,
            'percent' => $researchTarget > 0 ? round(($todaysResearch / $researchTarget) * 100) : 0,
            'status' => $todaysResearch >= $researchTarget ? 'complete' : ($todaysResearch >= $researchTarget * 0.7 ? 'on_track' : 'behind')
        ],
        'commitments' => [
            'done' => count($completedCommitments),
            'total' => count($todaysCommitments)
        ]
    ];

    respond([
        'success' => true,
        'progress' => $progress,
        'streak' => $streak,
        'date' => $today
    ]);
    break;

case 'save-daily-performance':
    if ($method !== 'POST') break;
    $user = requireAuth();
    $userData = getUserData($user['id']);
    $today = date('Y-m-d');

    // This is called at end of day or when user logs out
    // Saves today's performance for streak tracking
    if (!isset($userData['settings']['daily_performance'])) {
        $userData['settings']['daily_performance'] = [];
    }

    // Get today's actual counts
    $leads = $userData['leads'] ?? [];
    $todaysCalls = 0;
    $todaysEmails = 0;

    foreach ($leads as $l) {
        foreach (($l['email_history'] ?? []) as $email) {
            if (isset($email['sent_at']) && substr($email['sent_at'], 0, 10) === $today) {
                $todaysEmails++;
            }
        }
        foreach (($l['activities'] ?? []) as $act) {
            if (isset($act['timestamp']) && substr($act['timestamp'], 0, 10) === $today) {
                if (in_array($act['type'] ?? '', ['call', 'call_started', 'call_made'])) {
                    $todaysCalls++;
                }
            }
        }
    }

    $targets = $userData['settings']['daily_targets'] ?? ['calls' => 40, 'emails' => 40];

    $userData['settings']['daily_performance'][$today] = [
        'calls_done' => $todaysCalls,
        'calls_target' => $targets['calls'] ?? 40,
        'emails_done' => $todaysEmails,
        'emails_target' => $targets['emails'] ?? 40,
        'saved_at' => date('c')
    ];

    // Keep only last 90 days of performance data
    $cutoff = date('Y-m-d', strtotime('-90 days'));
    $userData['settings']['daily_performance'] = array_filter(
        $userData['settings']['daily_performance'],
        fn($date) => $date >= $cutoff,
        ARRAY_FILTER_USE_KEY
    );

    saveUserData($user['id'], $userData);
    respond(['success' => true]);
    break;
// ============ ADMIN ICP CONFIG ENDPOINTS ============

case 'save-icp-config':
    if ($method !== 'POST') break;
    requireAdmin();
    $admin = getAdmin();

    if (isset($input['icp_config'])) {
        $admin['icp_config'] = $input['icp_config'];
    }
    if (isset($input['stage_validation_rules'])) {
        $admin['stage_validation_rules'] = $input['stage_validation_rules'];
    }
    if (isset($input['outreach_rules'])) {
        $admin['outreach_rules'] = $input['outreach_rules'];
    }

    saveAdmin($admin);
    respond(['success' => true, 'admin' => $admin]);
    break;

case 'get-icp-config':
    if ($method !== 'GET') break;
    requireAdmin();
    $admin = getAdmin();
    respond([
        'success' => true,
        'icp_config' => $admin['icp_config'] ?? [],
        'stage_validation_rules' => $admin['stage_validation_rules'] ?? [],
        'outreach_rules' => $admin['outreach_rules'] ?? []
    ]);
    break;

// ============ MIGRATION ENDPOINT ============

case 'migrate-leads-to-stage-model':
    if ($method !== 'POST') break;
    requireAdmin();
    $leadsStore = getLeadsStore();
    $migratedCount = 0;

    foreach ($leadsStore['leads'] as &$lead) {
        $before = json_encode($lead);
        foreach (getDefaultStageFields() as $field => $value) {
            if (!isset($lead[$field])) $lead[$field] = $value;
        }
        $lead = normalizeLeadForMapping($lead);
        if ($before !== json_encode($lead)) {
            $migratedCount++;
        }
    }
    unset($lead);

    saveLeadsStore($leadsStore);

    respond(['success' => true, 'migrated' => $migratedCount, 'message' => "Migrated {$migratedCount} leads to stage model"]);
    break;

default:
    respond(['success' => false, 'error' => 'Invalid endpoint'], 404);
}

// ============ LLM FUNCTIONS ============

/**
 * Single-shot LLM call, with automatic provider fallback.
 *
 * $provider/$apiKey are the CALLER'S PREFERENCE (nearly every call site passes
 * the configured default_provider and its key). The call starts there, then
 * fails over to the other configured providers if that one is rate-limited or
 * down. Signature and return shape are unchanged, so every existing call site
 * gained fallback without modification.
 *
 * Pass $noFallback = true to force exactly one provider (used by `test-api`,
 * which must report on the specific key being tested).
 */
function callLLM($provider, $apiKey, $prompt, $noFallback = false) {
    if ($noFallback) return callLLMOnce($provider, $apiKey, $prompt);
    return llmWithFallback(function ($p, $key) use ($prompt) {
        return callLLMOnce($p, $key, $prompt);
    }, $provider);
}

/** One attempt against one provider. */
function callLLMOnce($provider, $apiKey, $prompt) {
    switch ($provider) {
        case 'groq': return callGroq($apiKey, $prompt);
        case 'cerebras': return callCerebras($apiKey, $prompt);
        case 'gemini': return callGemini($apiKey, $prompt);
        case 'anthropic': return callAnthropic($apiKey, $prompt);
        default: return ['success' => false, 'error' => 'Unknown provider'];
    }
}

function callGroq($apiKey, $prompt) {
    return callOpenAICompatible('https://api.groq.com/openai/v1/chat/completions', $apiKey, chosenModelFor('groq'), $prompt);
}

function callCerebras($apiKey, $prompt) {
    return callOpenAICompatible('https://api.cerebras.ai/v1/chat/completions', $apiKey, chosenModelFor('cerebras'), $prompt);
}

/**
 * Shared OpenAI-shaped chat call (Groq and Cerebras speak the same dialect).
 * Reports 'retryable' so callLLM's fallback chain can tell a rate limit
 * (worth trying elsewhere) from a bad key (worth surfacing immediately).
 */
function callOpenAICompatible($url, $apiKey, $model, $prompt) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'model' => $model,
            'messages' => [['role' => 'user', 'content' => $prompt]],
            'max_tokens' => 2048,
            'temperature' => 0.7
        ]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
        CURLOPT_TIMEOUT => 120
    ]);
    $response = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($error) return ['success' => false, 'error' => $error, 'retryable' => true];
    $r = json_decode($response, true);
    if (isset($r['choices'][0]['message']['content'])) {
        return ['success' => true, 'content' => $r['choices'][0]['message']['content']];
    }
    // Groq nests the message under "error"; Cerebras returns it at the top level.
    $detail = $r['error']['message'] ?? ($r['message'] ?? null);
    return [
        'success' => false,
        'error' => $detail ?: 'API error',
        'retryable' => llmIsRetryableStatus($status),
    ];
}

function callGemini($apiKey, $prompt) {
    $ch = curl_init('https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode(chosenModelFor('gemini')) . ':generateContent?key=' . urlencode($apiKey));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, 
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['contents' => [['parts' => [['text' => $prompt]]]]]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'], 
        CURLOPT_TIMEOUT => 120
    ]);
    $response = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($error) return ['success' => false, 'error' => $error, 'retryable' => true];
    $r = json_decode($response, true);
    if (isset($r['candidates'][0]['content']['parts'][0]['text'])) {
        return ['success' => true, 'content' => $r['candidates'][0]['content']['parts'][0]['text']];
    }
    return [
        'success' => false,
        'error' => $r['error']['message'] ?? 'API error',
        'retryable' => llmIsRetryableStatus($status),
    ];
}

function callAnthropic($apiKey, $prompt) {
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'model' => chosenModelFor('anthropic'),
            'max_tokens' => 2048,
            'system' => 'You are a B2B sales intelligence AI. Always return valid JSON when asked for structured data. Be specific, practical, and focused on driving conversions.',
            'messages' => [['role' => 'user', 'content' => $prompt]]
        ]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-api-key: ' . $apiKey, 'anthropic-version: 2023-06-01'],
        CURLOPT_TIMEOUT => 120
    ]);
    $response = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($error) return ['success' => false, 'error' => $error, 'retryable' => true];
    $r = json_decode($response, true);
    if (isset($r['content'][0]['text'])) {
        return ['success' => true, 'content' => $r['content'][0]['text']];
    }
    return [
        'success' => false,
        'error' => $r['error']['message'] ?? 'API error',
        'retryable' => llmIsRetryableStatus($status),
    ];
}


// ============ LEGACY RESEARCH PROMPT ============
// CRITICAL: JSON structure MUST match frontend renderResearch() expectations EXACTLY

function buildResearchPrompt($lead, $settings = []) {
    $name = trim(($lead['first_name'] ?? '') . ' ' . ($lead['last_name'] ?? ''));
    $company = $lead['company'] ?? 'Unknown';
    $title = $lead['title'] ?? 'Unknown';
    $industry = $lead['industry'] ?? 'Unknown';
    $country = $lead['country'] ?? 'Unknown';
    $website = $lead['website'] ?? '';
    $size = $lead['company_size'] ?? 'Unknown';

    // Brand context comes from the user's settings (configurable), not hardcoded.
    $ourCompany = $settings['sender_company'] ?? 'Levata';
    $ourDesc = $settings['company_description'] ?? 'Levata is a design and engineering studio that builds websites, software systems, and brand identities for clients.';
    $ourValue = $settings['value_proposition'] ?? 'We turn a brief into a polished, conversion-focused product, handling execution end to end.';

    return "You are a B2B sales intelligence analyst. Research this prospect for {$ourCompany}. {$ourDesc} Value proposition: {$ourValue} The goal is to book a discovery call or consultation, not to close a deal in the first outreach.

PROSPECT:
- Name: {$name}
- Title: {$title}
- Company: {$company}
- Industry: {$industry}
- Country: {$country}
- Website: {$website}
- Company Size: {$size}

IMPORTANT: Return ONLY valid JSON. No markdown code blocks. No backticks. No explanations before or after. Just the raw JSON object.

The JSON must have this EXACT structure:

{
  \"research_score\": {
    \"score\": 75,
    \"quality\": \"Good\",
    \"factors\": [\"Has company website\", \"Known industry\", \"Clear job title\"]
  },
  \"sources\": [
    {\"title\": \"Company Website\", \"url\": \"https://example.com\", \"description\": \"Official company information\"},
    {\"title\": \"LinkedIn Profile\", \"url\": \"https://linkedin.com/company/example\", \"description\": \"Company overview and employee data\"},
    {\"title\": \"Industry Report\", \"url\": \"https://example.com/report\", \"description\": \"Market analysis and trends\"}
  ],
  \"company_profile\": {
    \"description\": \"2-3 sentences describing what this company does, their business model, who they serve\",
    \"key_products_services\": [\"Product/Service 1\", \"Product/Service 2\", \"Product/Service 3\"],
    \"market_position\": \"Their market position and competitive standing\",
    \"growth_stage\": \"Startup/Growth/Mature/Enterprise\"
  },
  \"industry_intelligence\": {
    \"top_challenges\": [
      {\"challenge\": \"A challenge they face relevant to what we offer\", \"impact\": \"How this affects their timelines or costs\"},
      {\"challenge\": \"A quality, capability, or delivery gap with their current provider\", \"impact\": \"Business impact on their clients or projects\"},
      {\"challenge\": \"Pricing, margins, or availability challenge\", \"impact\": \"Why this matters to their bottom line\"}
    ],
    \"trends\": [\"Industry trend 1\", \"Industry trend 2\"],
    \"competitive_pressures\": \"What competitors are doing\"
  },
  \"prospect_analysis\": {
    \"pain_points\": [
      {\"pain\": \"Specific pain point for this role\", \"evidence\": \"Why they likely have this pain\"},
      {\"pain\": \"Another relevant pain point\", \"evidence\": \"Evidence or reasoning\"}
    ],
    \"responsibilities\": [\"Key responsibility 1\", \"Key responsibility 2\", \"Key responsibility 3\"],
    \"success_metrics\": [\"KPI 1\", \"KPI 2\"],
    \"buying_power\": \"Decision Maker / Influencer / Evaluator / End User\"
  },
  \"sales_strategy\": {
    \"opening_hooks\": [
      {\"hook\": \"Attention-grabbing opener for this prospect\", \"why\": \"Why this works\"},
      {\"hook\": \"Another personalized opening line\", \"why\": \"Why this resonates\"}
    ],
    \"value_angles\": [
      {\"angle\": \"Value proposition most relevant to them\", \"connects_to\": \"Which pain it addresses\"},
      {\"angle\": \"Secondary value angle\", \"connects_to\": \"Related pain point\"}
    ],
    \"discovery_questions\": [
      \"How do you currently handle this in your business?\",
      \"What matters most to you when choosing a provider for this?\",
      \"What does your current workload or pipeline look like for this?\",
      \"Have you ever had a project held up by a provider or tooling gap?\"
    ],
    \"objections\": [{\"objection\": \"Likely objection\", \"response\": \"How to handle it\"}],
    \"avoid\": [\"Thing NOT to say\", \"Another thing to avoid\"]
  }
}

SCORING GUIDELINES:
- Grade A eligible: a strong fit for our ideal customer; decision-maker identified; clear need and workable timeline.
- Grade B eligible: segment match with decision-maker unclear, purchasing-team buyer, or timeline uncertainty.
- Grade C eligible: segment match with significant unknowns, interstate location, or timeline too short for current stock.
- Disqualify or flag: wrong segment, not contactable, immediate larger supply beyond current stock capacity, or explicit no-contact request.
- Score quality should reflect evidence completeness, not whether the lead should be called.

SOURCES: Generate realistic, plausible source URLs based on the company name and industry. Include 2-4 sources that would logically provide the research data.

Generate the research now with real, specific insights based on the prospect information. Include call hooks that lead toward a second engagement: a discovery call, a consultation, or a defined follow-up.";
}

// ============ EMAIL GENERATION ============
// Template-based approach: LLM fills specific parts, we assemble the structure

function generateEmailContent($provider, $apiKey, $lead, $emailType, $customInstructions, $enrichment, $settings, $previousEmail = '', $includeSignature = true) {
    $firstName = $lead['first_name'] ?? 'there';
    $lastName = $lead['last_name'] ?? '';
    $title = $lead['title'] ?? '';
    $company = $lead['company'] ?? '';
    $industry = $lead['industry'] ?? '';
    
    $senderName = $settings['sender_name'] ?? 'Your Name';
    $senderTitle = $settings['sender_title'] ?? '';
    $senderCompany = $settings['sender_company'] ?? '';
    $companyDesc = $settings['company_description'] ?? 'Levata is a design and engineering studio that builds websites, software systems, and brand identities for clients';
    $valueProp = $settings['value_proposition'] ?? '';
    $socialProof = $settings['social_proof'] ?? '';
    $signature = $settings['signature'] ?? '';

    // Parse research data
    $research = [];
    if ($enrichment) {
        $parsed = json_decode($enrichment, true);
        if ($parsed) $research = $parsed;
    }

    // Extract useful bits from research
    $companyInfo = $research['company_profile']['description'] ?? '';
    $products = $research['company_profile']['key_products_services'] ?? [];
    $challenges = $research['industry_intelligence']['top_challenges'] ?? [];
    $painPoints = $research['prospect_analysis']['pain_points'] ?? [];
    $hooks = $research['sales_strategy']['opening_hooks'] ?? [];
    $valueAngles = $research['sales_strategy']['value_angles'] ?? [];
    
    // Build context for LLM
    $context = "PROSPECT: {$firstName} {$lastName}, {$title} at {$company} ({$industry})
COMPANY INFO: {$companyInfo}
PRODUCTS/SERVICES: " . implode(', ', array_slice($products, 0, 3)) . "
THEIR CHALLENGES: " . ($challenges[0]['challenge'] ?? 'operational efficiency') . "
THEIR PAIN POINTS: " . ($painPoints[0]['pain'] ?? 'manual processes') . "
SUGGESTED HOOK: " . ($hooks[0]['hook'] ?? '') . "
VALUE ANGLE: " . ($valueAngles[0]['angle'] ?? $valueProp) . "

SENDER: {$senderName}, {$senderTitle} at {$senderCompany}
WHAT WE SELL: {$companyDesc}
SOCIAL PROOF: {$socialProof}";

    if ($customInstructions) {
        $context .= "\nSPECIAL INSTRUCTIONS: {$customInstructions}";
    }


    // Add email performance context from outcomes
    if (!empty($lead['email_history'])) {
        $replied = 0; $noResponse = 0; $bounced = 0; $meetingBooked = 0;
        foreach ($lead['email_history'] as $eh) {
            $o = $eh['outcome'] ?? '';
            if ($o === 'replied') $replied++;
            elseif ($o === 'no_response') $noResponse++;
            elseif ($o === 'bounced') $bounced++;
            elseif ($o === 'meeting_booked') $meetingBooked++;
        }
        if ($noResponse > 0) {
            $context .= "\nEMAIL HISTORY: {$noResponse} email(s) got no response. Try a different angle or shorter message.";
        }
        if ($replied > 0) {
            $context .= "\nEMAIL HISTORY: Previous email(s) got replies - maintain similar conversational tone.";
        }
        if ($meetingBooked > 0) {
            $context .= "\nEMAIL HISTORY: A meeting was booked before. Reference the relationship.";
        }
    }

    // Different prompts based on email type
    // Language style guidelines applied to all email types
    $languageRules = "
WRITING STYLE:
- Use simple, clear English that is easy to read and understand
- Keep sentences short (under 20 words where possible)
- Avoid jargon, buzzwords, and corporate-speak
- Use proper capitalization: capitalize only the first word of sentences and proper nouns (names, company names)
- Do NOT use all caps except for acronyms
- No exclamation marks - keep it calm and professional
- Write conversationally, like a colleague, not a salesperson
- Australian business English: direct, practical, and not overly formal
- The goal is the next conversation, not closing the deal in the email
- Never attach or offer to attach a company profile or product catalogue in a cold email
- Never use the subject line to make a sales claim
- Do not mention Sri Lanka or manufacturing origin in cold emails
- If manufacturing comes up, frame credibility as Italian robotics, Italian digital printing inks, and Italian production process
";

    switch ($emailType) {
        case 'initial':
            $prompt = "{$context}
{$languageRules}

Generate these 4 parts for a cold email. Be SPECIFIC using the research above. No generic filler.

1. SUBJECT (4-6 words, lowercase except proper nouns, specific to their business, creates curiosity, and does not make a sales claim - specific to their business)

2. OPENER (1-2 sentences that reference something SPECIFIC about their company, role, or situation. Use the research. Write simply and directly. Reference something concrete from the research.)

3. PROBLEM_BRIDGE (1-2 sentences: Name a specific problem they likely face, then connect it to what we do and our value proposition. No hard sell.)

4. CTA (1 clear, low-friction question that points to a discovery call or consultation.)

Return ONLY in this exact format:
SUBJECT: [your subject]
OPENER: [your opener]
PROBLEM_BRIDGE: [your problem and bridge]
CTA: [your question]";
            break;

        case 'followup1':
            $prompt = "{$context}
{$languageRules}

PREVIOUS EMAIL SENT:
{$previousEmail}

Generate these 3 parts for follow-up #1 (3-4 days after initial). Reference the previous email naturally.

1. SUBJECT (either \"Re: [original topic]\" or a new angle - use lowercase except proper nouns; no sales claim)

2. RECONNECT_VALUE (2-3 sentences: Brief casual reconnect referencing your last email, then share something useful relevant to them. No hard sell.)

3. CTA (1 direct question, more specific than first email)

Return ONLY in this exact format:
SUBJECT: [your subject]
RECONNECT_VALUE: [your reconnect and new value]
CTA: [your question]";
            break;

        case 'followup2':
            $prompt = "{$context}
{$languageRules}

PREVIOUS EMAIL SENT:
{$previousEmail}

Generate these 3 parts for follow-up #2 (final value-add before breakup).

1. SUBJECT (continue thread or case study hook - use lowercase except proper nouns)

2. ACKNOWLEDGE_PROOF (2-3 sentences: Acknowledge they are busy, then lead with one practical proof point or value angle. Keep it brief.)

3. CTA_EASYOUT (1-2 sentences: Ask for the meeting but give them an easy out)

Return ONLY in this exact format:
SUBJECT: [your subject]
ACKNOWLEDGE_PROOF: [your acknowledgment and proof]
CTA_EASYOUT: [your ask with easy out]";
            break;

        case 'breakup':
            $prompt = "{$context}
{$languageRules}

Generate these 2 parts for a breakup email (final, closing the loop).

1. SUBJECT (\"closing the loop\" or \"should I close your file?\" - use lowercase)

2. BODY (3-4 sentences: Acknowledge you have reached out without response - no guilt. Give permission to say no. Leave door open for future. Keep it dignified and simple.)

Return ONLY in this exact format:
SUBJECT: [your subject]
BODY: [your complete body]";
            break;

        default:
            $prompt = "{$context}\n\nWrite a brief professional email.";
    }

    // Call LLM
    $res = callLLM($provider, $apiKey, $prompt);
    
    if (!$res['success']) {
        return $res;
    }

    // Parse LLM response
    $llmOutput = $res['content'];
    $parts = [];
    
    // Extract parts using regex
    if (preg_match('/SUBJECT:\s*(.+?)(?=\n[A-Z_]+:|$)/s', $llmOutput, $m)) {
        $parts['subject'] = trim($m[1]);
    }
    if (preg_match('/OPENER:\s*(.+?)(?=\n[A-Z_]+:|$)/s', $llmOutput, $m)) {
        $parts['opener'] = trim($m[1]);
    }
    if (preg_match('/PROBLEM_BRIDGE:\s*(.+?)(?=\n[A-Z_]+:|$)/s', $llmOutput, $m)) {
        $parts['problem_bridge'] = trim($m[1]);
    }
    if (preg_match('/CTA:\s*(.+?)(?=\n[A-Z_]+:|$)/s', $llmOutput, $m)) {
        $parts['cta'] = trim($m[1]);
    }
    if (preg_match('/RECONNECT_VALUE:\s*(.+?)(?=\n[A-Z_]+:|$)/s', $llmOutput, $m)) {
        $parts['reconnect_value'] = trim($m[1]);
    }
    if (preg_match('/ACKNOWLEDGE_PROOF:\s*(.+?)(?=\n[A-Z_]+:|$)/s', $llmOutput, $m)) {
        $parts['acknowledge_proof'] = trim($m[1]);
    }
    if (preg_match('/CTA_EASYOUT:\s*(.+?)(?=\n[A-Z_]+:|$)/s', $llmOutput, $m)) {
        $parts['cta_easyout'] = trim($m[1]);
    }
    if (preg_match('/BODY:\s*(.+?)(?=\n[A-Z_]+:|$)/s', $llmOutput, $m)) {
        $parts['body'] = trim($m[1]);
    }

    // Assemble final email with guaranteed structure
    $subject = $parts['subject'] ?? 'Quick question';
    $subject = trim(str_replace(['—', '–', '"', '"'], ['', '', '"', '"'], $subject));
    $subject = ucfirst($subject); // Capitalize first letter
    
    switch ($emailType) {
        case 'initial':
            $body = "Hi {$firstName},\n\n";
            $body .= ($parts['opener'] ?? "Hope you're doing well.") . "\n\n";
            $body .= ($parts['problem_bridge'] ?? '') . "\n\n";
            $body .= ($parts['cta'] ?? 'Would this be worth a conversation?') . "\n\n";
            $body .= $senderName;
            if ($senderTitle) $body .= "\n{$senderTitle}";
            if ($senderCompany) $body .= "\n{$senderCompany}";
            break;

        case 'followup1':
            $body = "Hi {$firstName},\n\n";
            $body .= ($parts['reconnect_value'] ?? 'Following up on my previous note.') . "\n\n";
            $body .= ($parts['cta'] ?? 'Worth a quick call?') . "\n\n";
            $body .= $senderName;
            break;

        case 'followup2':
            $body = "Hi {$firstName},\n\n";
            $body .= ($parts['acknowledge_proof'] ?? 'I know you\'re busy, so I\'ll keep this short.') . "\n\n";
            $body .= ($parts['cta_easyout'] ?? 'If the timing isn\'t right, no worries at all.') . "\n\n";
            $body .= $senderName;
            break;

        case 'breakup':
            $body = "Hi {$firstName},\n\n";
            $body .= ($parts['body'] ?? 'I\'ve reached out a few times without hearing back. Should I close your file, or would it make sense to reconnect in a few months?') . "\n\n";
            $body .= $senderName;
            break;

        default:
            $body = "Hi {$firstName},\n\n{$llmOutput}\n\n{$senderName}";
    }

    // Clean up the body - remove em-dashes and excessive punctuation
    $body = str_replace(['—', '–'], [',', ','], $body);
    $body = preg_replace('/\n{3,}/', "\n\n", $body);

    // Add signature if enabled and exists
    if ($includeSignature && !empty($signature)) {
        $body .= "\n\n" . $signature;
    }

    return [
        'success' => true,
        'content' => "SUBJECT: {$subject}\n\n{$body}"
    ];
}

// ============ CALL PITCH GENERATION ============

function generateCallPitch($provider, $apiKey, $name, $title, $company, $industry, $pitchType, $customInstructions, $settings) {
    $senderName = $settings['sender_name'] ?? '';
    $senderTitle = $settings['sender_title'] ?? 'Business Development Manager';
    $senderCompany = $settings['sender_company'] ?? 'Levata';
    $companyDesc = $settings['company_description'] ?? 'Levata is a design and engineering studio that builds websites, software systems, and brand identities for clients';
    $valueProp = $settings['value_proposition'] ?? '';

    $emailSent = ($pitchType === 'cold_with_email' || $pitchType === 'email_sent');

    $context = "PROSPECT: {$name}" . ($title ? ", {$title}" : "") . ($company ? " at {$company}" : "") . ($industry ? " ({$industry})" : "") . "
CALLER: {$senderName}, {$senderTitle} from {$senderCompany}
WHAT WE DO: {$companyDesc}
EMAIL ALREADY SENT: " . ($emailSent ? 'Yes' : 'No');

    if ($customInstructions) {
        $context .= "\n\nCUSTOM CONTEXT:\n{$customInstructions}";
    }


    $reasonA = "I will be really quick. I am calling about an email I sent earlier. We design and build websites, software systems, and brand identities, and I wanted to see if you had a chance to look at it.";

    $reasonB = "I will be really quick. We are Levata. We design and build websites, software systems, and brand identities. We have been working with businesses in your space and I wanted to see if you are looking at a project like that right now.";

    $prompt = "{$context}

WRITING STYLE FOR CALL SCRIPT:
- Use simple, clear English that is easy to say out loud
- Keep sentences short and natural
- Avoid jargon and corporate buzzwords
- Write conversationally, like you are talking to a colleague
- Use contractions naturally (I am, we are, you are)
- The goal of every first call is a second engagement: a discovery call, a consultation, or a defined follow-up

- Mention concrete proof points or capabilities only where relevant


Generate a call script following the EXACT structure below. Use the visual formatting exactly as shown - the emojis, boxes, and arrows help the sales person navigate during a live call.

╔══════════════════════════════════════════════════════════════╗
║  📞 OPENING                                                   ║
╚══════════════════════════════════════════════════════════════╝

\"Hey {$name}, it's {$senderName} here from {$senderCompany}. I'm the {$senderTitle}. How are you doing today?\"

(If they ask): \"I'm doing well, thanks for asking.\"

╔══════════════════════════════════════════════════════════════╗
║  🎯 REASON FOR CALL                                           ║
╚══════════════════════════════════════════════════════════════╝

" . ($emailSent ? "\"{$reasonA}\"" : "\"{$reasonB}\"") . "

┌─────────────────────────────────────────────────────────────┐
│  → THEY SAY NO / UNSURE                                     │
└─────────────────────────────────────────────────────────────┘
\"No problem at all. If you could spare 30 seconds, I'll explain why I'm calling. If it's not relevant, we can leave it there. Does that sound fair?\"

      ↳ They say YES → Continue to WHO WE ARE ⬇️
      ↳ They say NO  → Skip to GRACEFUL EXIT 🚪

╔══════════════════════════════════════════════════════════════╗
║  🏢 WHO WE ARE                                                ║
╚══════════════════════════════════════════════════════════════╝

\"At {$senderCompany}, {$companyDesc}.

We work with businesses to deliver this end to end.\"

╔══════════════════════════════════════════════════════════════╗
║  ⚡ PAIN POINTS                                               ║
╚══════════════════════════════════════════════════════════════╝

\"What we commonly hear from businesses like yours is:
• Inconsistent quality or results from their current provider
• Slow delivery or missed timelines
• Having to juggle multiple suppliers to get the full range you need
• [ADD 2 INDUSTRY-SPECIFIC PAIN POINTS FOR {$industry}]

Does any of that sound familiar?\"

┌─────────────────────────────────────────────────────────────┐
│  → THEY SAY NO (nothing familiar)                           │
└─────────────────────────────────────────────────────────────┘
\"Understood. Just out of curiosity:
• Are you getting the quality and capability you need from your current provider?
• Are deliveries reliable and on time for your projects?
• Are you happy with the pricing you are getting right now?\"

      ↳ Something resonates → Continue to THE ASK ⬇️
      ↳ Still no           → Skip to GRACEFUL EXIT 🚪

┌─────────────────────────────────────────────────────────────┐
│  → THEY SAY YES (something resonates)                       │
└─────────────────────────────────────────────────────────────┘
\"That is helpful. If you could fix one thing about how this is handled today, better quality, faster delivery, or better value, which would you pick?\"

[LISTEN]

\"I know you're busy, so I won't take more time. Based on what you've shared, there may be an opportunity worth exploring.\"

╔══════════════════════════════════════════════════════════════╗
║  📅 THE ASK                                                   ║
╚══════════════════════════════════════════════════════════════╝

\"What I would suggest is a quick next step, a short discovery call or consultation about a current or upcoming project.

In that conversation we can:
• Check which designs fit your project
• Talk through stock timing and lead time
• Show how we could help with your current priorities

No pressure at all, just a practical look at whether there is a fit. Would you be open to that?\"

┌─────────────────────────────────────────────────────────────┐
│  ✅ THEY SAY YES → BOOK IT                                   │
└─────────────────────────────────────────────────────────────┘
\"Great. I've got my calendar open. Would [Day] work, or would [Day] be better?\"

[THEY PICK]

\"Perfect. I'll send a calendar invite. Your email is [confirm], correct?\"

\"Thanks {$name}, looking forward to [Day]. Have a great day.\"

┌─────────────────────────────────────────────────────────────┐
│  🚪 GRACEFUL EXIT (if not interested)                        │
└─────────────────────────────────────────────────────────────┘
\"No problem at all. Would it be better if I checked back in a few months, or should I leave it there for now?\"

\"Thanks for your time, {$name}. Have a great day.\"

══════════════════════════════════════════════════════════════

INSTRUCTIONS:
1. Keep this EXACT structure with all the box formatting
2. Replace [ADD 2 INDUSTRY-SPECIFIC PAIN POINTS] with real pain points for {$industry}
3. Keep all the navigation arrows (↳ ⬇️ 🚪) so the caller knows where to go
4. Everything in quotes is what to say out loud";

    $res = callLLM($provider, $apiKey, $prompt);
    
    if (!$res['success']) {
        return $res;
    }

    $pitchTitles = [
        'cold_with_email' => 'Cold Call Script (Email Sent)',
        'email_sent' => 'Cold Call Script (Email Sent)',
        'cold_no_email' => 'Cold Call Script (No Prior Email)',
        'no_email' => 'Cold Call Script (No Prior Email)',
        'callback' => 'Call Back Script',
        'discovery' => 'Discovery Call Script',
        'demo' => 'Demo Introduction Script'
    ];

    return [
        'success' => true,
        'title' => $pitchTitles[$pitchType] ?? 'Call Script',
        'pitch' => $res['content']
    ];
}

/**
 * AI-generated version of the Qualified-stage "share the booking link"
 * email. Was previously a fixed client-side string template (name/company/
 * link slotted into hardcoded sentences) with no way to steer the wording —
 * this follows the same custom-instructions pattern as generateEmailContent()/
 * generateCallPitch() so a rep's specific notes actually change the output,
 * not just get appended after a rigid template. Deliberately its own,
 * simpler prompt rather than reusing generateEmailContent(): that one is
 * tuned for cold outbound (research-driven hooks, "opener" structure) which
 * reads oddly for someone already warm and qualified who just needs a
 * booking link.
 */
/**
 * The default (no custom instructions) Calendly email is a FIXED template,
 * not an AI call — a rep clicking "Generate" with nothing typed must get the
 * exact same email back every time, not a differently-worded LLM re-roll.
 * The AI is only invoked once a rep types custom instructions, to adapt
 * THIS template's content/tone — see generateCalendlyEmailContent() below.
 */
function calendlyEmailDefaultTemplate($lead, $calendarLink, $settings) {
    $firstName = $lead['first_name'] ?? 'there';
    $company = $lead['company'] ?? '';
    $senderName = $settings['sender_name'] ?? '';
    $senderTitle = $settings['sender_title'] ?? '';
    $senderCompany = $settings['sender_company'] ?? 'Levata';

    $subject = "Scheduling a demo";
    $signOff = $senderName ?: 'there';
    if ($senderTitle) $signOff .= "\n{$senderTitle}";

    $body = "Hi {$firstName},\n\n"
        . "Thank you for taking the time to speak with me and for sharing an overview of your requirements.\n\n"
        . "Based on our conversation, we believe {$senderCompany} could meet your requirements, and we'd like to take the next step with a detailed demo. This will give us an opportunity to understand your requirements in greater detail and discuss how {$senderCompany} can be tailored to your specific needs.\n\n"
        . "Below is a link to my calendar. Please feel free to choose whichever time is most convenient for you, and the meeting will be booked automatically.\n\n"
        . "{$calendarLink}\n\n"
        . "Looking forward to speaking with you.\n\n"
        . "Best,\n{$signOff}";

    return ['subject' => $subject, 'body' => $body];
}

function generateCalendlyEmailContent($provider, $apiKey, $lead, $calendarLink, $customInstructions, $settings) {
    $default = calendlyEmailDefaultTemplate($lead, $calendarLink, $settings);

    if (!$customInstructions) {
        return ['success' => true, 'email' => "SUBJECT: {$default['subject']}\n\n{$default['body']}"];
    }

    $prompt = "Adapt the email below per the rep's instructions. Keep the same overall structure and intent (thanking them for sharing their requirements, proposing a detailed demo as the next step, sharing the booking link, professional sign-off) unless the instructions say otherwise.

DEFAULT EMAIL:
SUBJECT: {$default['subject']}

{$default['body']}

REP'S INSTRUCTIONS (apply to content and tone, but never in a way that breaks the rules below): {$customInstructions}

WRITING STYLE (non-negotiable, applies no matter what the rep asked for above):
- Use simple, clear English that is easy to read
- Keep sentences short
- Warm and professional, like following up with someone you've already spoken to - NOT a cold sales pitch
- No exclamation marks
- Australian business English: direct, practical, not overly formal
- Never invent claims, discounts, dates, or commitments that were not given to you
- The booking link must appear exactly as given in the default email above, unedited, on its own line
- Always end with the sender's name and title (if given) on separate lines

Format your response EXACTLY as:
SUBJECT: [subject line]

[body]";

    $res = callLLM($provider, $apiKey, $prompt);
    if (!$res['success']) return $res;

    return ['success' => true, 'email' => $res['content']];
}
