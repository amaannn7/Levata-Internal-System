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
define('USERS_FILE', DATA_DIR . '/users.json');
define('ADMIN_FILE', DATA_DIR . '/admin.json');

if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0755, true);

// Document Studio: SOW generation + shared infra.
require_once __DIR__ . '/sow.php';
// Document Studio: Cost Proposal generation (depends on sow.php).
require_once __DIR__ . '/cp.php';
// Job Registry (shared company-wide jobs/invoices) — added module.
require_once __DIR__ . '/jobs.php';

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

if (!file_exists(ADMIN_FILE)) {
    file_put_contents(ADMIN_FILE, json_encode([
        'groq_key' => '',
        'gemini_key' => '',
        'anthropic_key' => '',
        'default_provider' => 'groq',
        'requisitions' => $defaultRequisitions,
        'icp_config' => $defaultIcpConfig,
        'stage_validation_rules' => $defaultStageRules,
        'outreach_rules' => $defaultOutreachRules
    ], JSON_PRETTY_PRINT));
}

if (!file_exists(USERS_FILE)) {
    $adminId = 'user_' . bin2hex(random_bytes(8));
    $defaultAdmin = [
        'id' => $adminId,
        'name' => 'Admin',
        'email' => 'admin@levatahq.com',
        'password' => password_hash('password', PASSWORD_DEFAULT),
        'token' => bin2hex(random_bytes(32)),
        'created_at' => date('c'),
        'is_admin' => true
    ];
    file_put_contents(USERS_FILE, json_encode([$defaultAdmin], JSON_PRETTY_PRINT));
    file_put_contents(DATA_DIR . "/user_{$adminId}.json", json_encode([
        'leads' => [],
        'settings' => ['sender_name' => 'Admin', 'sender_company' => 'Levata', 'sender_title' => '', 'company_description' => '', 'value_proposition' => '', 'social_proof' => '', 'calendar_link' => '', 'email_tone' => 'professional', 'signature' => '']
    ], JSON_PRETTY_PRINT));
}

function getUsers() { return json_decode(file_get_contents(USERS_FILE), true) ?: []; }
function saveUsers($users) {
    $fp = fopen(USERS_FILE, 'c');
    if (flock($fp, LOCK_EX)) {
        ftruncate($fp, 0);
        fwrite($fp, json_encode($users, JSON_PRETTY_PRINT));
        fflush($fp);
        flock($fp, LOCK_UN);
    }
    fclose($fp);
}

function getMacktilesStages() {
    return [
        'new_lead' => ['label' => 'New Lead', 'legacy' => 'new'],
        'research' => ['label' => 'Research', 'legacy' => 'researched'],
        'email_sent' => ['label' => 'Email Sent', 'legacy' => 'email_sent'],
        'call_attempted' => ['label' => 'Call Attempted', 'legacy' => 'call_due'],
        'engaged' => ['label' => 'Engaged', 'legacy' => 'outcome_logged'],
        'consultation_booked' => ['label' => 'Consultation Booked', 'legacy' => 'qualified'],
        'nurture_parked' => ['label' => 'Nurture / Parked', 'legacy' => 'disqualified'],
        'won' => ['label' => 'Won', 'legacy' => 'qualified'],
        'lost' => ['label' => 'Lost', 'legacy' => 'disqualified']
    ];
}

function legacyStatusToStage($status) {
    $map = [
        'new' => 'new_lead',
        'researched' => 'research',
        'email_sent' => 'email_sent',
        'call_due' => 'call_attempted',
        'outcome_logged' => 'call_attempted',
        'qualified' => 'consultation_booked',
        'meeting_booked' => 'consultation_booked',
        'replied' => 'engaged',
        'contacted' => 'engaged',
        'not_interested' => 'nurture_parked',
        'disqualified' => 'nurture_parked',
        'won' => 'won',
        'lost' => 'lost'
    ];
    return $map[$status ?: 'new'] ?? (isset(getMacktilesStages()[$status]) ? $status : 'new_lead');
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
    if ($source === 'inbound') return 'call_attempted';
    if ($source === 'manual') return $warm ? 'engaged' : 'research';
    return 'new_lead';
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
    $lead['updated_at'] = date('c');
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
    return $lead;
}

function macktilesCallOutcomeConfig($outcome) {
    $config = [
        'no_answer_retry' => ['label' => 'No answer, will retry', 'stage' => 'call_attempted', 'next_action' => 'followup_date'],
        'left_voicemail' => ['label' => 'No answer, left voicemail', 'stage' => 'call_attempted', 'next_action' => 'followup_date'],
        'gatekeeper' => ['label' => 'Gatekeeper, could not reach decision-maker', 'stage' => 'call_attempted', 'next_action' => 'followup_date'],
        'wrong_number' => ['label' => 'Wrong number / person no longer at company', 'stage' => 'nurture_parked', 'next_action' => 'disqualify'],
        'not_interested' => ['label' => 'Not interested, asked not to be contacted', 'stage' => 'nurture_parked', 'next_action' => 'disqualify'],
        'interested_followup' => ['label' => 'Interested, follow-up scheduled', 'stage' => 'engaged', 'next_action' => 'followup_date'],
        'consultation_booked' => ['label' => 'Consultation booked', 'stage' => 'consultation_booked', 'next_action' => 'qualify_zoho'],
        'callback_requested' => ['label' => 'Call back requested, date/time noted', 'stage' => 'call_attempted', 'next_action' => 'followup_date'],
        'not_right_time_park_90' => ['label' => 'Spoke, not the right time; park for 90 days', 'stage' => 'nurture_parked', 'next_action' => 'park']
    ];
    return $config[$outcome] ?? null;
}
function getAdmin() {
    global $defaultRequisitions, $defaultIcpConfig, $defaultStageRules, $defaultOutreachRules;
    $admin = json_decode(file_get_contents(ADMIN_FILE), true) ?: [];
    if (!isset($admin['requisitions'])) $admin['requisitions'] = $defaultRequisitions;
    $reqIds = array_map(fn($r) => $r['id'] ?? '', $admin['requisitions'] ?? []);
    if (!in_array('project_context', $reqIds, true) || !in_array('next_step_agreed', $reqIds, true)) {
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
    $fp = fopen(ADMIN_FILE, 'c');
    if (flock($fp, LOCK_EX)) {
        ftruncate($fp, 0);
        fwrite($fp, json_encode($admin, JSON_PRETTY_PRINT));
        fflush($fp);
        flock($fp, LOCK_UN);
    }
    fclose($fp);
}

function getUserData($userId) {
    $file = DATA_DIR . "/user_{$userId}.json";
    if (!file_exists($file)) {
        $default = ['leads' => [], 'settings' => ['sender_name' => '', 'sender_company' => 'Levata', 'sender_title' => '', 'company_description' => '', 'value_proposition' => '', 'social_proof' => '', 'calendar_link' => '', 'email_tone' => 'professional', 'signature' => '']];
        file_put_contents($file, json_encode($default, JSON_PRETTY_PRINT));
        return $default;
    }
    $data = json_decode(file_get_contents($file), true) ?: [];

    // Migrate existing leads with default values for new fields
    if (!empty($data['leads'])) {
        $changed = false;
        foreach ($data['leads'] as &$lead) {
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
        if ($changed) {
            saveUserData($userId, $data);
        }
    }
    return $data;
}

function saveUserData($userId, $data) {
    $file = DATA_DIR . "/user_{$userId}.json";
    $fp = fopen($file, 'c');
    if (flock($fp, LOCK_EX)) {
        ftruncate($fp, 0);
        fwrite($fp, json_encode($data, JSON_PRETTY_PRINT));
        fflush($fp);
        flock($fp, LOCK_UN);
    }
    fclose($fp);
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
        if (in_array(getLeadStage($lead), ['consultation_booked', 'won'])) {
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
        'new_lead' => ['max_days' => 1, 'next_action' => 'Verify contact details and begin research', 'action_type' => 'research'],
        'research' => ['max_days' => 1, 'next_action' => 'Complete AI research and send initial email', 'action_type' => 'email'],
        'email_sent' => ['max_days' => 5, 'next_action' => 'Make follow-up call', 'action_type' => 'call'],
        'call_attempted' => ['max_days' => 3, 'next_action' => 'Log outcome or complete scheduled follow-up', 'action_type' => 'outcome'],
        'engaged' => ['max_days' => 7, 'next_action' => 'Book consultation or schedule next conversation', 'action_type' => 'consultation'],
        'consultation_booked' => ['max_days' => 14, 'next_action' => 'Complete consultation and mark won/lost/nurture', 'action_type' => 'consultation'],
        'nurture_parked' => ['max_days' => 90, 'next_action' => 'Light-touch re-engagement when cooling-off period ends', 'action_type' => 'nurture']
    ];

    $status = getLeadStage($lead);
    $daysSinceAction = calculateDaysSinceLastActivity($lead);

    if (($lead['source'] ?? '') === 'inbound' && !empty($lead['created_at']) && $status === 'call_attempted') {
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

    $rule = $slaRules[$status] ?? $slaRules['new_lead'];
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
        if ($fitGrade === 'disqualified' && !in_array($stage, ['nurture_parked', 'lost'])) continue;

        // Skip leads that are snoozed (skipped_until)
        if (!empty($lead['skipped_until']) && strtotime($lead['skipped_until']) > $now) continue;

        // Calculate priority score
        $priorityScore = 0;
        $reason = '';
        $suggestedAction = '';
        $urgency = 'normal';

        // Source/SLA priority from the Levata mapping.
        if (($lead['source'] ?? '') === 'inbound' && $stage === 'call_attempted') {
            $priorityScore += 250;
            $reason = 'Inbound booking lead - call within 2 hours';
            $suggestedAction = 'call';
            $urgency = 'critical';
        }

        // Stage/source now drive priority; temperature remains a light secondary signal only.
        $stageScores = [
            'call_attempted' => 60,
            'email_sent' => 45,
            'engaged' => 40,
            'consultation_booked' => 35,
            'research' => 25,
            'new_lead' => 20,
            'nurture_parked' => -20
        ];
        $priorityScore += $stageScores[$stage] ?? 0;
        $tempScores = ['on_fire' => 20, 'hot' => 12, 'warm' => 6, 'cold' => 0];
        $temp = $lead['temperature'] ?? 'cold';
        $priorityScore += $tempScores[$temp] ?? 0;

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
                case 'new_lead':
                    $reason = "New lead - needs research";
                    $suggestedAction = 'research';
                    break;
                case 'research':
                    $reason = "Research complete - ready for outreach";
                    $suggestedAction = 'email';
                    $priorityScore += 15; // Boost researched leads
                    break;
                case 'email_sent':
                    if ($daysSinceActivity >= 3) {
                        $reason = "Email sent {$daysSinceActivity} days ago - follow up";
                        $suggestedAction = 'followup';
                    } else {
                        $reason = "Waiting for response";
                        $suggestedAction = 'wait';
                        $priorityScore -= 20; // Lower priority for waiting
                    }
                    break;
                case 'call_attempted':
                    $reason = "Call scheduled";
                    $suggestedAction = 'call';
                    $priorityScore += 25;
                    $urgency = 'high';
                    break;
                case 'engaged':
                    $outcome = $lead['call_outcome'] ?? '';
                    if (in_array($outcome, ['no_answer_retry', 'callback_requested', 'left_voicemail'])) {
                        $reason = "Last call: {$outcome} - retry";
                        $suggestedAction = 'call';
                    } else {
                        $reason = "Engaged - book consultation or next step";
                        $suggestedAction = 'consultation';
                    }
                    break;
                case 'consultation_booked':
                    $reason = "Consultation booked - prepare and confirm";
                    $suggestedAction = 'consultation';
                    $priorityScore += 40;
                    $urgency = 'critical';
                    break;
                case 'nurture_parked':
                    $reason = "Parked lead - review cooling-off timing";
                    $suggestedAction = 'nurture';
                    $priorityScore -= 40;
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
        if (in_array(getLeadStage($lead), ['nurture_parked', 'won', 'lost'])) continue;

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
        if ($status === 'research' && $daysSinceActivity >= 5) {
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
    if ($status === 'new_lead') {
        return [
            'action' => 'research',
            'urgency' => 'medium',
            'reason' => 'New lead needs research before outreach',
            'talking_point' => 'Run AI research to understand their business',
            'risk_if_delayed' => 'Lead may go to competitors',
            'source' => 'rules'
        ];
    }

    if ($status === 'research') {
        return [
            'action' => 'email',
            'urgency' => $temp === 'hot' ? 'high' : 'medium',
            'reason' => 'Research complete - time to reach out',
            'talking_point' => 'Use research insights for personalized email',
            'risk_if_delayed' => 'Research becomes stale after 5 days',
            'source' => 'rules'
        ];
    }

    if ($status === 'email_sent' && $daysSinceActivity >= 3) {
        return [
            'action' => 'followup',
            'urgency' => $daysSinceActivity >= 7 ? 'high' : 'medium',
            'reason' => "No response after {$daysSinceActivity} days",
            'talking_point' => 'Reference previous email, add new value',
            'risk_if_delayed' => 'Lead forgets about you',
            'source' => 'rules'
        ];
    }

    if ($status === 'call_attempted' || ($outcome === 'callback_requested')) {
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
        if (in_array(getLeadStage($lead), ['nurture_parked', 'won', 'lost'])) continue;

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
             'body' => "{$name} from {$company} — " . floor(($now - strtotime($lead['followup_date'])) / 86400) . " day(s) overdue"],

            ['cond' => in_array($temp, ['hot','warm']) && $daysSinceActivity >= 7 && $daysSinceActivity < 14,
             'key'  => "going_cold_{$leadId}", 'type' => 'going_cold',
             'title'=> "❄️ Lead going cold",
             'body' => "{$name} from {$company} — {$daysSinceActivity} days inactive"],

            ['cond' => $status === 'research' && $daysSinceActivity >= 3,
             'key'  => "stale_research_{$leadId}", 'type' => 'stale_research',
             'title'=> "🔍 Send outreach to {$name}",
             'body' => "Research done {$daysSinceActivity} days ago — {$company}"],

            ['cond' => $status === 'email_sent' && $daysSinceActivity >= 3,
             'key'  => "followup_call_{$leadId}", 'type' => 'callback_due',
             'title'=> "📞 Follow-up call needed",
             'body' => "{$name} from {$company} — email sent {$daysSinceActivity} days ago"],

            ['cond' => $status === 'new_lead' && $daysSinceActivity >= 2,
             'key'  => "new_lead_idle_{$leadId}", 'type' => 'stale_research',
             'title'=> "⏰ New lead needs attention",
             'body' => "{$name} from {$company} — added {$daysSinceActivity} days ago"],
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
    $provider = $admin['default_provider'] ?? 'groq';
    $apiKey = $admin[$provider . '_key'] ?? '';
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
    $provider = $admin['default_provider'] ?? 'groq';
    $apiKey = $admin[$provider . '_key'] ?? '';
    if (!$apiKey) respond(['success' => false, 'error' => 'AI not configured'], 400);

    $cpInput = $input['input'] ?? [];
    if (empty($cpInput['serviceType']) && empty($cpInput['clientName'])) {
        respond(['success' => false, 'error' => 'Add at least a service type or client name'], 400);
    }
    $res = generateCostProposal($provider, $apiKey, $cpInput);
    if (!$res['success']) respond(['success' => false, 'error' => $res['error']], 502);
    respond(['success' => true, 'markdown' => $res['content']]);
    break;

case 'refine-sow':
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
    $res = refineSow($provider, $apiKey, $markdown, $instruction);
    if (!$res['success']) respond(['success' => false, 'error' => $res['error']], 502);
    respond(['success' => true, 'markdown' => $res['content']]);
    break;

case 'refine-cost-proposal':
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
    $res = refineCostProposal($provider, $apiKey, $markdown, $instruction);
    if (!$res['success']) respond(['success' => false, 'error' => $res['error']], 502);
    respond(['success' => true, 'markdown' => $res['content']]);
    break;

case 'fireflies-meetings':
    if ($method !== 'POST') break;
    requireAuth();
    $admin = getAdmin();
    $ffKey = $admin['fireflies_key'] ?? '';
    if (!$ffKey) respond(['success' => false, 'error' => 'Add a Fireflies API key in Settings first'], 400);
    $res = firefliesListMeetings($ffKey, 15);
    if (!$res['success']) respond(['success' => false, 'error' => $res['error']], 502);
    respond(['success' => true, 'meetings' => $res['meetings']]);
    break;

case 'fireflies-transcript':
    if ($method !== 'POST') break;
    requireAuth();
    $admin = getAdmin();
    $ffKey = $admin['fireflies_key'] ?? '';
    if (!$ffKey) respond(['success' => false, 'error' => 'Add a Fireflies API key in Settings first'], 400);
    $id = $input['id'] ?? '';
    if (!trim($id)) respond(['success' => false, 'error' => 'No meeting selected'], 400);
    $res = firefliesFetchTranscript($ffKey, $id);
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
    $client = trim($input['client'] ?? '') ?: trim($docInput['clientName'] ?? '');
    $id = trim($input['id'] ?? '');

    if ($id !== '') {
        // Update existing.
        $found = false;
        foreach ($store['documents'] as &$d) {
            if (($d['id'] ?? '') === $id) {
                $d['markdown'] = $markdown;
                $d['input'] = $docInput;
                $d['title'] = $title;
                $d['client'] = $client;
                $d['type'] = $type;
                // Allow updating the linked CP (e.g. set on the SOW form after first save).
                if (array_key_exists('linked_cost_proposal', $input)) {
                    $d['linked_cost_proposal'] = trim($input['linked_cost_proposal']);
                }
                $d['updated_at'] = $now;
                $found = true;
                break;
            }
        }
        unset($d);
        if (!$found) $id = '';
    }
    if ($id === '') {
        $id = 'doc_' . bin2hex(random_bytes(8));
        // Human-readable, per-type sequential document number (e.g. SOW-0001, CP-0001),
        // global across the company so numbers never collide between users.
        $docNo = nextDocumentNumber($store['documents'], $type);
        // Optional link to an approved cost proposal (set when a SOW is created from a CP).
        $linkedCp = trim($input['linked_cost_proposal'] ?? '');
        $store['documents'][] = [
            'id' => $id,
            'doc_no' => $docNo,
            'type' => $type,
            'title' => $title,
            'client' => $client,
            'linked_cost_proposal' => $linkedCp,
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
    respond(['success' => true, 'id' => $job['id'], 'job_no' => $job['job_no']]);
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
        ]]);
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
        respond(['success' => true, 'settings' => $masked]);
    }
    if ($method === 'POST') {
        requireAdmin();
        $admin = getAdmin();

        // LLM API keys (+ Fireflies for Document Studio)
        foreach (['groq_key', 'gemini_key', 'anthropic_key', 'fireflies_key'] as $k) {
            if (isset($input[$k]) && strpos($input[$k], '****') === false) $admin[$k] = trim($input[$k]);
        }
        if (isset($input['default_provider'])) $admin['default_provider'] = $input['default_provider'];
        // Per-provider model choice (empty = use the current default for that provider).
        foreach (['groq_model', 'gemini_model', 'anthropic_model'] as $k) {
            if (isset($input[$k])) $admin[$k] = trim($input[$k]);
        }
        if (isset($input['requisitions'])) $admin['requisitions'] = $input['requisitions'];

        // Zoho CRM settings
        if (isset($input['zoho_client_id']) && strpos($input['zoho_client_id'], '****') === false) {
            $admin['zoho_client_id'] = trim($input['zoho_client_id']);
        }
        if (isset($input['zoho_client_secret']) && strpos($input['zoho_client_secret'], '****') === false) {
            $admin['zoho_client_secret'] = trim($input['zoho_client_secret']);
        }
        if (isset($input['zoho_datacenter'])) {
            $admin['zoho_datacenter'] = $input['zoho_datacenter'];
        }
        if (isset($input['zoho_enabled_modules'])) {
            $admin['zoho_enabled_modules'] = $input['zoho_enabled_modules'];
        }
        if (isset($input['zoho_auto_sync'])) {
            $admin['zoho_auto_sync'] = (bool)$input['zoho_auto_sync'];
        }

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
    $res = callLLM($provider, $apiKey, 'Say "Connection successful!" exactly.');
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
    $userData = getUserData($user['id']);
    $admin = getAdmin();
    $leads = $userData['leads'] ?? [];

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
        'requisitions' => $admin['requisitions'] ?? []
    ]);
    break;

case 'lead':
    $user = requireAuth();
    $userData = getUserData($user['id']);
    
    if ($method === 'GET' && isset($_GET['id'])) {
        foreach ($userData['leads'] as $l) { if ($l['id'] === $_GET['id']) respond(['success' => true, 'lead' => $l]); }
        respond(['success' => false, 'error' => 'Not found'], 404);
    }
    
    if ($method === 'POST') {
        // Validate fields before creating lead
        $email = trim($input['email'] ?? '');
        $linkedin = trim($input['linkedin'] ?? '');
        $website = trim($input['website'] ?? '');

        // Email validation (if provided)
        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            respond(['success' => false, 'error' => 'Invalid email format. Please enter a valid email address.'], 400);
        }

        // LinkedIn URL validation (if provided)
        if (!empty($linkedin) && stripos($linkedin, 'linkedin.com') === false) {
            respond(['success' => false, 'error' => 'Invalid LinkedIn URL. Please enter a valid LinkedIn profile URL.'], 400);
        }

        // Website URL validation (if provided)
        if (!empty($website) && !preg_match('/^https?:\/\/|^www\./i', $website)) {
            // Auto-prepend https:// if missing
            $website = 'https://' . $website;
        }

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

            // === INTELLIGENCE STAGE TRACKING ===
            'intelligence_stage' => 1,
            'stage_history' => [],

            // === STAGE 1: ACCOUNT INTELLIGENCE ===
            'account_intelligence' => [
                'completed' => false,
                'completed_at' => null,
                'validation_score' => 0,
                'validation_errors' => [],
                'company_profile' => [
                    'description' => '',
                    'key_products_services' => [],
                    'market_position' => '',
                    'growth_stage' => '',
                    'business_model' => '',
                    'revenue_model' => ''
                ],
                'operating_environment' => [
                    'tech_stack_assumptions' => [],
                    'system_constraints' => [],
                    'operational_complexity' => '',
                    'geographic_footprint' => ''
                ],
                'trigger_signals' => [
                    'growth_signals' => [],
                    'cost_pressure_signals' => [],
                    'regulatory_signals' => [],
                    'competitive_signals' => [],
                    'technology_signals' => []
                ],
                'industry_context' => [
                    'sector_challenges' => [],
                    'market_trends' => [],
                    'competitive_landscape' => ''
                ],
                'sources' => []
            ],

            // === STAGE 2: PERSONA INTELLIGENCE ===
            'persona_intelligence' => [
                'completed' => false,
                'completed_at' => null,
                'validation_score' => 0,
                'validation_errors' => [],
                'persona_profile' => [
                    'role_category' => '',
                    'function' => '',
                    'seniority_level' => ''
                ],
                'kpis' => [
                    'primary_metrics' => [],
                    'secondary_metrics' => [],
                    'time_horizons' => ''
                ],
                'decision_profile' => [
                    'authority_level' => '',
                    'budget_influence' => '',
                    'buying_stage' => ''
                ],
                'objection_profile' => [
                    'likely_objections' => [],
                    'risk_tolerance' => ''
                ],
                'success_drivers' => [
                    'career_motivations' => [],
                    'professional_goals' => [],
                    'personal_wins' => []
                ]
            ],

            // === STAGE 3: PAIN HYPOTHESIS ===
            'pain_hypothesis' => [
                'completed' => false,
                'completed_at' => null,
                'validation_score' => 0,
                'validation_errors' => [],
                'hypotheses' => [],
                'commercial_impact' => [
                    'quantified_impact' => '',
                    'impact_timeframe' => '',
                    'impact_confidence' => ''
                ],
                'value_levers' => [],
                'alignment_check' => [
                    'company_pain_fit' => 0,
                    'persona_pain_fit' => 0,
                    'solution_relevance' => 0
                ]
            ],

            // === STAGE 4: FIT SCORING ===
            'fit_score' => [
                'completed' => false,
                'completed_at' => null,
                'icp_fit' => ['score' => 0, 'factors' => [], 'disqualifiers' => []],
                'authority_score' => ['score' => 0, 'rationale' => ''],
                'urgency_score' => ['score' => 0, 'signals' => []],
                'commercial_potential' => ['score' => 0, 'deal_size_estimate' => ''],
                'complexity_score' => ['score' => 0, 'factors' => []],
                'overall_score' => 0,
                'overall_grade' => '',
                'outreach_eligibility' => '',
                'recommended_action' => ''
            ],

            // === STAGE 5: OUTREACH DATA ===
            'outreach_data' => [
                'unlocked' => false,
                'unlocked_at' => null,
                'unlock_grade' => '',
                'channels_enabled' => [],
                'sequence_stage' => 0,
                'email_history' => [],
                'call_history' => [],
                'linkedin_history' => []
            ]
        ];
        if (!$lead['email']) respond(['success' => false, 'error' => 'Email required'], 400);
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

        foreach ($userData['leads'] as &$lead) {
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
                saveUserData($user['id'], $userData);
                respond(['success' => true, 'lead' => $lead]);
            }
        }
        respond(['success' => false, 'error' => 'Not found'], 404);
    }
    
    if ($method === 'DELETE' && isset($_GET['id'])) {
        $id = $_GET['id'];
        foreach ($userData['leads'] as &$l) {
            if ($l['id'] === $id) {
                $l['deleted_at'] = date('c');
                $l['deleted_by'] = $user['id'];
                logActivity($l, 'deleted', 'Moved to trash');
                break;
            }
        }
        saveUserData($user['id'], $userData);
        respond(['success' => true, 'message' => 'Lead moved to trash']);
    }
    break;

case 'permanent-delete':
    if ($method !== 'POST') break;
    $user = requireAuth();
    $userData = getUserData($user['id']);
    $leadId = $input['id'] ?? '';
    // Only allow permanent delete on trashed leads
    $found = false;
    foreach ($userData['leads'] as $l) {
        if ($l['id'] === $leadId) {
            if (empty($l['deleted_at'])) respond(['success' => false, 'error' => 'Lead must be in trash first'], 400);
            $found = true;
            break;
        }
    }
    if (!$found) respond(['success' => false, 'error' => 'Lead not found'], 404);
    $userData['leads'] = array_values(array_filter($userData['leads'], function($l) use ($leadId) {
        return $l['id'] !== $leadId;
    }));
    saveUserData($user['id'], $userData);
    respond(['success' => true]);
    break;

case 'restore-lead':
    if ($method !== 'POST') break;
    $user = requireAuth();
    $userData = getUserData($user['id']);
    $leadId = $input['id'] ?? '';

    foreach ($userData['leads'] as &$l) {
        if ($l['id'] === $leadId) {
            unset($l['deleted_at']);
            unset($l['deleted_by']);
            $l['updated_at'] = date('c');
            logActivity($l, 'restored', 'Restored from trash');
            saveUserData($user['id'], $userData);
            respond(['success' => true, 'message' => 'Lead restored']);
        }
    }
    respond(['success' => false, 'error' => 'Lead not found'], 404);
    break;

case 'empty-trash':
    if ($method !== 'POST') break;
    $user = requireAuth();
    $userData = getUserData($user['id']);
    $userData['leads'] = array_values(array_filter($userData['leads'], function($l) {
        return empty($l['deleted_at']);
    }));
    saveUserData($user['id'], $userData);
    respond(['success' => true, 'message' => 'Trash emptied']);
    break;

case 'save-requisitions':
    if ($method !== 'POST') break;
    $user = requireAuth();
    $userData = getUserData($user['id']);
    $leadId = $input['lead_id'] ?? '';
    $requisitions = $input['requisitions'] ?? null;
    
    foreach ($userData['leads'] as &$lead) {
        if ($lead['id'] === $leadId) {
            $lead['requisitions'] = $requisitions;
            $lead['updated_at'] = date('c');
            saveUserData($user['id'], $userData);
            respond(['success' => true, 'lead' => $lead]);
        }
    }
    respond(['success' => false, 'error' => 'Lead not found'], 404);
    break;

case 'save-call-outcome':
    if ($method !== 'POST') break;
    $user = requireAuth();
    $userData = getUserData($user['id']);
    $leadId = $input['lead_id'] ?? '';
    
    foreach ($userData['leads'] as &$lead) {
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
            saveUserData($user['id'], $userData);

            respond(['success' => true, 'lead' => $lead]);
        }
    }
    respond(['success' => false, 'error' => 'Lead not found'], 404);
    break;

case 'update-lead':
    if ($method !== 'POST') break;
    $user = requireAuth();
    $userData = getUserData($user['id']);
    $leadId = $input['id'] ?? '';
    
    foreach ($userData['leads'] as &$lead) {
        if ($lead['id'] === $leadId) {
            // Update allowed fields
            $allowedFields = ['call_anchor', 'email_skipped', 'followup_date', 'notes', 'source', 'source_detail', 'assigned_to', 'urgency_flag', 'rejection_reason', 'consultation_type'];
            foreach ($allowedFields as $field) {
                if (isset($input[$field])) {
                    $lead[$field] = $input[$field];
                }
            }
            $requestedStage = trim($input['stage'] ?? $input['status'] ?? '');
            if ($requestedStage !== '') {
                setLeadStage($lead, $requestedStage, 'manual_update', $user['id']);
            }
            $lead = normalizeLeadForMapping($lead);
            $lead['updated_at'] = date('c');
            saveUserData($user['id'], $userData);
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
    $userData = getUserData($user['id']);
    $id = $input['id'] ?? '';
    if (!$id) respond(['success' => false, 'error' => 'No ID'], 400);
    foreach ($userData['leads'] as &$l) {
        if ($l['id'] === $id) {
            $l['deleted_at'] = date('c');
            $l['deleted_by'] = $user['id'];
            logActivity($l, 'deleted', 'Moved to trash');
            saveUserData($user['id'], $userData);
            respond(['success' => true]);
        }
    }
    respond(['success' => false, 'error' => 'Lead not found'], 404);
    break;

case 'bulk-delete':
    if ($method !== 'POST') break;
    $user = requireAuth();
    $userData = getUserData($user['id']);
    $ids = $input['ids'] ?? [];
    $count = 0;
    foreach ($userData['leads'] as &$l) {
        if (in_array($l['id'], $ids)) {
            $l['deleted_at'] = date('c');
            $l['deleted_by'] = $user['id'];
            logActivity($l, 'deleted', 'Moved to trash (bulk delete)');
            $count++;
        }
    }
    saveUserData($user['id'], $userData);
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
            foreach ($userData['leads'] as &$l) {
                if ($l['id'] === $leadId) {
                    $l['last_action'] = 'email_generated';
                    $l['last_action_at'] = date('c');
                    $l['last_email_type'] = $emailType;
                    break;
                }
            }
            saveUserData($user['id'], $userData);
        }
        $response = ['success' => true, 'email' => $res['content']];
        if ($stageWarning) {
            $response['warning'] = $stageWarning;
        }
        respond($response);
    }
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
    $leadId = $input['lead_id'] ?? '';

    if (!$name) respond(['success' => false, 'error' => 'Name is required'], 400);

    // Find the lead to get stage intelligence data
    $leadData = null;
    if ($leadId) {
        foreach ($userData['leads'] as $l) {
            if ($l['id'] === $leadId) {
                $leadData = $l;
                break;
            }
        }
    }

    $res = generateCallPitch(
        $provider,
        $apiKey,
        $name,
        $title,
        $company,
        $industry,
        $pitchType,
        $customInstructions,
        $userData['settings'] ?? [],
        $leadData
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
            foreach ($userData['leads'] as &$l) {
                if ($l['id'] === $leadId) {
                    $l['enrichment'] = $content;
                    $l['last_action'] = 'researched';
                    $l['last_action_at'] = date('c');
                    $l['updated_at'] = date('c');
                    if (in_array(getLeadStage($l), ['new_lead', 'research'])) {
                        setLeadStage($l, 'research', 'research_completed', $user['id']);
                    }

                    // Auto-calculate ICP grade using shared function
                    $gradeResult = calculateLeadGrade($l, $admin, $parsed);
                    $l['fit_grade'] = $gradeResult['grade'];
                    $l['fit_score'] = $gradeResult['score'];

                    // Auto-populate stage intelligence from enrichment
                    if ($parsed) {
                        // Stage 1: Account Intelligence
                        if (!empty($parsed['company_profile'])) {
                            if (!isset($l['account_intelligence'])) $l['account_intelligence'] = [];
                            $l['account_intelligence']['company_profile'] = $parsed['company_profile'];
                            $l['account_intelligence']['completed'] = true;
                            $l['account_intelligence']['completed_at'] = date('c');
                        }
                        if (!empty($parsed['industry_intelligence'])) {
                            if (!isset($l['account_intelligence'])) $l['account_intelligence'] = [];
                            $l['account_intelligence']['industry_context'] = [
                                'sector_challenges' => array_map(function($c) {
                                    return is_array($c) ? ($c['challenge'] ?? $c['text'] ?? reset($c) ?? '') : $c;
                                }, $parsed['industry_intelligence']['top_challenges'] ?? []),
                                'market_trends' => $parsed['industry_intelligence']['trends'] ?? [],
                                'competitive_landscape' => $parsed['industry_intelligence']['competitive_pressures'] ?? ''
                            ];
                            if (!empty($parsed['sources'])) {
                                $l['account_intelligence']['sources'] = $parsed['sources'];
                            }
                        }

                        // Stage 2: Persona Intelligence
                        if (!empty($parsed['prospect_analysis'])) {
                            if (!isset($l['persona_intelligence'])) $l['persona_intelligence'] = [];
                            $l['persona_intelligence']['kpis'] = [
                                'primary_metrics' => $parsed['prospect_analysis']['success_metrics'] ?? []
                            ];
                            $l['persona_intelligence']['decision_profile'] = [
                                'authority_level' => $parsed['prospect_analysis']['buying_power'] ?? '',
                                'responsibilities' => $parsed['prospect_analysis']['responsibilities'] ?? []
                            ];
                            $l['persona_intelligence']['completed'] = true;
                            $l['persona_intelligence']['completed_at'] = date('c');
                        }
                        if (!empty($parsed['sales_strategy']['objections'])) {
                            if (!isset($l['persona_intelligence'])) $l['persona_intelligence'] = [];
                            $l['persona_intelligence']['objection_profile'] = [
                                'likely_objections' => array_map(function($o) {
                                    return is_array($o) ? ($o['objection'] ?? $o['text'] ?? reset($o) ?? '') : $o;
                                }, $parsed['sales_strategy']['objections'])
                            ];
                        }

                        // Stage 3: Pain Hypothesis
                        if (!empty($parsed['prospect_analysis']['pain_points'])) {
                            if (!isset($l['pain_hypothesis'])) $l['pain_hypothesis'] = [];
                            $l['pain_hypothesis']['hypotheses'] = array_map(function($p) {
                                return is_array($p) ? $p : ['pain' => $p, 'evidence' => ''];
                            }, $parsed['prospect_analysis']['pain_points']);
                            $l['pain_hypothesis']['completed'] = true;
                            $l['pain_hypothesis']['completed_at'] = date('c');
                        }

                        // Mark all stages complete
                        $l['intelligence_stage'] = 4;
                    }

                    logActivity($l, 'researched', 'Lead researched (Grade ' . ($l['fit_grade'] ?? '?') . ')');
                    $updatedLead = $l;
                    break;
                }
            }
            saveUserData($user['id'], $userData);
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

case 'save-email':
    if ($method !== 'POST') break;
    $user = requireAuth();
    $userData = getUserData($user['id']);
    $leadId = $input['lead_id'] ?? '';
    $emailType = $input['type'] ?? 'initial';

    foreach ($userData['leads'] as &$lead) {
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
            // Update canonical stage to email sent for cold outreach.
            if (in_array(getLeadStage($lead), ['new_lead', 'research', 'engaged'])) {
                setLeadStage($lead, 'email_sent', 'email_sent:' . $emailType, $user['id']);
            }
            logActivity($lead, 'email_sent', ucfirst(str_replace('_', ' ', $emailType)) . ' email sent');
            saveUserData($user['id'], $userData);
            respond(['success' => true, 'lead' => $lead]);
        }
    }
    respond(['success' => false, 'error' => 'Lead not found'], 404);
    break;

case 'email-outcome':
    if ($method !== 'POST') break;
    $user = requireAuth();
    $userData = getUserData($user['id']);
    $leadId = $input['lead_id'] ?? '';
    $emailIndex = intval($input['email_index'] ?? -1);
    $outcome = $input['outcome'] ?? ''; // replied, bounced, no_response, meeting_booked

    $validOutcomes = ['replied', 'bounced', 'no_response', 'meeting_booked'];
    if (!in_array($outcome, $validOutcomes)) {
        respond(['success' => false, 'error' => 'Invalid outcome. Use: ' . implode(', ', $validOutcomes)], 400);
    }

    foreach ($userData['leads'] as &$lead) {
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
                setLeadStage($lead, 'consultation_booked', 'email_outcome:meeting_booked', $user['id']);
                $lead['last_action'] = 'meeting_booked';
            } elseif ($outcome === 'replied') {
                setLeadStage($lead, 'engaged', 'email_outcome:replied', $user['id']);
                $lead['last_action'] = 'email_replied';
            }
            $lead['last_action_at'] = date('c');
            $lead['updated_at'] = date('c');

            saveUserData($user['id'], $userData);
            respond(['success' => true, 'lead' => $lead]);
        }
    }
    respond(['success' => false, 'error' => 'Lead not found'], 404);
    break;

case 'start-call':
    if ($method !== 'POST') break;
    $user = requireAuth();
    $userData = getUserData($user['id']);
    $leadId = $input['lead_id'] ?? '';

    foreach ($userData['leads'] as &$lead) {
        if ($lead['id'] === $leadId) {
            $lead['call_started_at'] = date('c');
            $lead['calls_made'] = ($lead['calls_made'] ?? 0) + 1;
            $lead['last_action'] = 'call_started';
            $lead['last_action_at'] = date('c');
            $lead['updated_at'] = date('c');
            $lead['call_history'] = $lead['call_history'] ?? [];
            $lead['call_history'][] = [
                'id' => 'call_' . bin2hex(random_bytes(8)),
                'started_at' => date('c'),
                'status' => 'pending',
                'rep_id' => $user['id'],
                'rep_name' => $user['name'] ?? $user['email']
            ];
            if (in_array(getLeadStage($lead), ['email_sent', 'research', 'new_lead'])) {
                setLeadStage($lead, 'call_attempted', 'call_started', $user['id']);
            }
            saveUserData($user['id'], $userData);
            respond(['success' => true, 'lead' => $lead]);
        }
    }
    respond(['success' => false, 'error' => 'Lead not found'], 404);
    break;

case 'grade-override':
    if ($method !== 'POST') break;
    $user = requireAuth();
    $userData = getUserData($user['id']);
    $leadId = $input['lead_id'] ?? '';

    foreach ($userData['leads'] as &$lead) {
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
            saveUserData($user['id'], $userData);
            respond(['success' => true, 'lead' => $lead]);
        }
    }
    respond(['success' => false, 'error' => 'Lead not found'], 404);
    break;

case 'recalculate-grade':
    if ($method !== 'POST') break;
    $user = requireAuth();
    $userData = getUserData($user['id']);
    $admin = getAdmin();
    $leadId = $input['lead_id'] ?? '';

    foreach ($userData['leads'] as &$lead) {
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

            saveUserData($user['id'], $userData);
            respond(['success' => true, 'lead' => $lead, 'grade' => $gradeResult['grade'], 'score' => $gradeResult['score']]);
        }
    }
    respond(['success' => false, 'error' => 'Lead not found'], 404);
    break;

// ============ PROACTIVE INTELLIGENCE ENDPOINTS ============

case 'log-activity':
    if ($method !== 'POST') break;
    $user = requireAuth();
    $userData = getUserData($user['id']);
    $admin = getAdmin();
    $leadId = $input['lead_id'] ?? '';
    $activityType = $input['type'] ?? '';
    $details = $input['details'] ?? null;

    if (!$activityType) {
        respond(['success' => false, 'error' => 'Activity type is required'], 400);
    }

    foreach ($userData['leads'] as &$lead) {
        if ($lead['id'] === $leadId) {
            // Add activity to the lead's activities array
            $lead['activities'] = logLeadActivity($lead, $activityType, $details);
            $lead['last_action'] = $activityType;
            $lead['last_action_at'] = date('c');
            $lead['updated_at'] = date('c');

            // Recalculate all scores
            $scores = recalculateAllLeadScores($lead, $admin);
            $lead = array_merge($lead, $scores);

            saveUserData($user['id'], $userData);
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
    $userData = getUserData($user['id']);
    $admin = getAdmin();
    $leadId = $input['lead_id'] ?? null; // Optional: recalculate single lead

    $updated = 0;
    foreach ($userData['leads'] as &$lead) {
        // If lead_id provided, only update that lead
        if ($leadId && $lead['id'] !== $leadId) continue;

        // Recalculate scores
        $scores = recalculateAllLeadScores($lead, $admin);
        $lead = array_merge($lead, $scores);
        $updated++;

        // If single lead requested, respond immediately
        if ($leadId) {
            saveUserData($user['id'], $userData);
            respond(['success' => true, 'lead' => $lead, 'scores' => $scores]);
        }
    }

    saveUserData($user['id'], $userData);
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
    $userData = getUserData($user['id']);
    $leadId = $input['lead_id'] ?? '';
    $duration = $input['duration'] ?? '1day'; // 1hour, 1day, 3days, 1week

    $skipDurations = [
        '1hour' => '+1 hour',
        '1day' => '+1 day',
        '3days' => '+3 days',
        '1week' => '+1 week'
    ];

    $skipUntil = date('c', strtotime($skipDurations[$duration] ?? '+1 day'));

    foreach ($userData['leads'] as &$lead) {
        if ($lead['id'] === $leadId) {
            $lead['skipped_until'] = $skipUntil;
            $lead['updated_at'] = date('c');
            saveUserData($user['id'], $userData);
            respond(['success' => true, 'skipped_until' => $skipUntil]);
        }
    }
    respond(['success' => false, 'error' => 'Lead not found'], 404);
    break;

case 'drop-lead':
    if ($method !== 'POST') break;
    $user = requireAuth();
    $userData = getUserData($user['id']);
    $leadId = $input['lead_id'] ?? '';
    $reason = $input['reason'] ?? '';

    foreach ($userData['leads'] as &$lead) {
        if ($lead['id'] === $leadId) {
            setLeadStage($lead, 'nurture_parked', 'drop_lead', $user['id']);
            $lead['disqualified_reason'] = $reason;
            $lead['rejection_reason'] = $reason;
            $lead['disqualified_at'] = date('c');
            $lead['last_action'] = 'disqualified';
            $lead['last_action_at'] = date('c');
            $lead['updated_at'] = date('c');
            saveUserData($user['id'], $userData);
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

        if ($action === 'mark_read') {
            foreach (($userData['notifications'] ?? []) as &$notif) {
                if ($notif['id'] === $notifId) {
                    $notif['read'] = true;
                }
            }
        } elseif ($action === 'mark_all_read') {
            foreach (($userData['notifications'] ?? []) as &$notif) {
                $notif['read'] = true;
            }
        } elseif ($action === 'dismiss') {
            $userData['notifications'] = array_values(array_filter(
                $userData['notifications'] ?? [],
                function($n) use ($notifId) { return $n['id'] !== $notifId; }
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
    $userData = getUserData($user['id']);
    $leads = array_filter($userData['leads'] ?? [], function($l) { return empty($l['deleted_at']); });
    $stats = [
        'total' => count($leads),
        'new_lead' => 0,
        'research' => 0,
        'email_sent' => 0,
        'call_attempted' => 0,
        'engaged' => 0,
        'consultation_booked' => 0,
        'nurture_parked' => 0,
        'won' => 0,
        'lost' => 0,
        // Legacy aliases for backwards compatibility
        'new' => 0,
        'researched' => 0,
        'call_due' => 0,
        'outcome_logged' => 0,
        'qualified' => 0,
        'disqualified' => 0,
        // Legacy statuses for backwards compatibility
        'contacted' => 0,
        'replied' => 0,
        'meeting_booked' => 0,
        'not_interested' => 0
    ];
    foreach ($leads as $l) {
        $stage = getLeadStage($l);
        if (isset($stats[$stage])) $stats[$stage]++;
        $legacy = stageToLegacyStatus($stage);
        if ($legacy !== $stage && isset($stats[$legacy])) $stats[$legacy]++;
    }
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
        foreach ($users as $teamUser) {
            $teamData = getUserData($teamUser['id']);
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
            foreach (($teamData['leads'] ?? []) as $lead) {
                if (!empty($lead['deleted_at'])) continue;
                $lead['_owner_id'] = $teamUser['id'];
                $lead['_owner_name'] = $teamUser['name'] ?? 'User';
                $leads[] = $lead;
                $teamActivity[$teamUser['id']]['leads_owned']++;
            }
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

    // Status counts for funnel
    $statuses = [
        'new_lead' => 0, 'research' => 0, 'email_sent' => 0,
        'call_attempted' => 0, 'engaged' => 0, 'consultation_booked' => 0,
        'nurture_parked' => 0, 'won' => 0, 'lost' => 0
    ];
    foreach ($leads as $l) {
        $stage = getLeadStage($l);
        if (isset($statuses[$stage])) {
            $statuses[$stage]++;
        }
    }

    // Calculate conversion funnel rates
    $funnel = [];
    $stageOrder = ['new_lead', 'research', 'email_sent', 'call_attempted', 'engaged', 'consultation_booked', 'nurture_parked'];
    $cumulative = 0;
    foreach ($stageOrder as $i => $stage) {
        $count = $statuses[$stage];
        $cumulative += $count;
        $rate = null;
        if ($i > 0 && $cumulative > 0) {
            // Calculate rate from previous cumulative stage
            $previousCumulative = 0;
            for ($j = 0; $j < $i; $j++) {
                $previousCumulative += $statuses[$stageOrder[$j]];
            }
            if ($previousCumulative > 0) {
                $rate = round(($cumulative / ($previousCumulative + $count)) * 100);
            }
        }
        $funnel[] = ['stage' => $stage, 'count' => $statuses[$stage], 'cumulative' => $cumulative, 'rate' => $rate];
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

        // Count today's activities (calls, research)
        $activities = $l['activities'] ?? [];
        foreach ($activities as $act) {
            if (isset($act['timestamp']) && substr($act['timestamp'], 0, 10) === $today) {
                if (isset($act['type'])) {
                    if ($act['type'] === 'call' || $act['type'] === 'call_started') {
                        $todaysCalls++;
                        if (isset($teamActivity[$ownerId])) $teamActivity[$ownerId]['calls']++;
                    } elseif ($act['type'] === 'research' || $act['type'] === 'enriched') {
                        $todaysResearch++;
                        if (isset($teamActivity[$ownerId])) $teamActivity[$ownerId]['research']++;
                    } elseif (strpos($act['type'], 'outcome') !== false) {
                        $todaysOutcomes++;
                        if (isset($teamActivity[$ownerId])) $teamActivity[$ownerId]['outcomes']++;
                    }
                }
            }
        }

        // Also check last_action for today
        if (!empty($l['last_action_at']) && substr($l['last_action_at'], 0, 10) === $today) {
            if (!empty($l['last_action'])) {
                if (strpos($l['last_action'], 'call') !== false) {
                    $todaysCalls++;
                    if (isset($teamActivity[$ownerId])) $teamActivity[$ownerId]['calls']++;
                } elseif (strpos($l['last_action'], 'research') !== false || strpos($l['last_action'], 'enrich') !== false) {
                    $todaysResearch++;
                    if (isset($teamActivity[$ownerId])) $teamActivity[$ownerId]['research']++;
                }
                if (strpos($l['last_action'], 'outcome') !== false || strpos($l['last_action'], 'call_outcome') !== false) {
                    $todaysOutcomes++;
                    if (isset($teamActivity[$ownerId])) $teamActivity[$ownerId]['outcomes']++;
                }
            }
        }
        if (getLeadStage($l) === 'consultation_booked' && isset($teamActivity[$ownerId])) {
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
        $qualified = count(array_filter($gradeLeads, fn($l) => getLeadStage($l) === 'consultation_booked' || getLeadStage($l) === 'won'));
        $gradeTotal = count($gradeLeads);
        $successByGrade[$g] = $gradeTotal > 0 ? round(($qualified / $gradeTotal) * 100) : 0;
    }

    // Success rates by temperature
    $successByTemp = [];
    foreach (['on_fire', 'hot', 'warm', 'cold'] as $t) {
        $tempLeads = array_filter($leads, fn($l) => ($l['temperature'] ?? 'cold') === $t);
        $qualified = count(array_filter($tempLeads, fn($l) => getLeadStage($l) === 'consultation_booked' || getLeadStage($l) === 'won'));
        $tempTotal = count($tempLeads);
        $successByTemp[$t] = $tempTotal > 0 ? round(($qualified / $tempTotal) * 100) : 0;
    }

    // Overdue leads count
    $overdueCount = 0;
    $now = time();
    foreach ($leads as $l) {
        if (!empty($l['followup_date'])) {
            $followupTime = strtotime($l['followup_date']);
            if ($followupTime && $followupTime < $now && !in_array(getLeadStage($l), ['consultation_booked', 'nurture_parked', 'won', 'lost'])) {
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
        if (!empty($sla['is_overdue']) && !in_array($stage, ['won', 'lost', 'nurture_parked'])) $staleCount++;
        if (substr($l['created_at'] ?? '', 0, 10) >= $weekStart) $leadsAddedThisWeek++;
        if ($stage === 'consultation_booked' && substr($l['stage_entered_at'] ?? $l['updated_at'] ?? '', 0, 10) >= $monthStart) $consultationsMtd++;
        if (!empty($l['email_history'])) {
            $emailedCount++;
            foreach ($l['email_history'] as $eh) {
                if (($eh['outcome'] ?? '') === 'replied' || ($eh['outcome'] ?? '') === 'meeting_booked') {
                    $repliedCount++;
                    break;
                }
            }
        }
        if (in_array($stage, ['nurture_parked', 'lost'])) {
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
            'qualified' => $statuses['consultation_booked'] + $statuses['won'],
            'disqualified' => $statuses['nurture_parked'] + $statuses['lost'],
            'consultation_booked' => $statuses['consultation_booked'],
            'nurture_parked' => $statuses['nurture_parked'],
            'overdue' => $overdueCount,
            'stale' => $staleCount
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
        if (($l['fit_grade'] ?? '') === 'Disqualified' && !in_array($status, ['nurture_parked', 'lost'])) continue;
        $enrichment = $l['enrichment'] ?? '';
        $emailsSent = $l['emails_sent'] ?? 0;
        $callsMade = $l['calls_made'] ?? 0;
        $temperature = $l['temperature'] ?? 'cold';
        $velocity = $l['velocity'] ?? 'stalled';

        if (in_array($status, ['won', 'lost'])) continue;

        if (($l['source'] ?? '') === 'inbound' && $status === 'call_attempted') {
            $batches['inbound_urgent'][] = $l;
        }

        // Needs Research: No enrichment data
        if ($status === 'new_lead' || empty($enrichment)) {
            $batches['needs_research'][] = $l;
        }
        // Needs Outreach: Has research but zero emails sent
        elseif ($status === 'research' || $emailsSent === 0) {
            $batches['needs_outreach'][] = $l;
        }
        // Needs Calling: Has emails but zero calls
        elseif ($status === 'email_sent' || $callsMade === 0) {
            $batches['needs_calling'][] = $l;
        }

        // Needs Follow-up: Stalled velocity (re-engage)
        if ($velocity === 'stalled' && !empty($enrichment) && !in_array($status, ['consultation_booked', 'nurture_parked'])) {
            $batches['needs_followup'][] = $l;
        }

        // Hot Focus: On fire or hot temperature
        if (in_array($temperature, ['on_fire', 'hot']) && !in_array($status, ['consultation_booked', 'nurture_parked'])) {
            $batches['hot_focus'][] = $l;
        }

        // Overdue: Followup date in the past
        if (!empty($l['followup_date'])) {
            $followupTime = strtotime($l['followup_date']);
            if ($followupTime && $followupTime < $now && !in_array($status, ['consultation_booked', 'nurture_parked', 'won', 'lost'])) {
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
    $users = getUsers();
    $migratedCount = 0;

    foreach ($users as $u) {
        $userData = getUserData($u['id']);
        foreach ($userData['leads'] as &$lead) {
            $before = json_encode($lead);
            foreach (getDefaultStageFields() as $field => $value) {
                if (!isset($lead[$field])) $lead[$field] = $value;
            }
            $lead = normalizeLeadForMapping($lead);
            if ($before !== json_encode($lead)) {
                $migratedCount++;
            }
        }

        saveUserData($u['id'], $userData);
    }

    respond(['success' => true, 'migrated' => $migratedCount, 'message' => "Migrated {$migratedCount} leads to stage model"]);
    break;

// ============ ZOHO CRM INTEGRATION ============

case 'zoho-auth-url':
    requireAdmin();
    $admin = getAdmin();

    $clientId = $admin['zoho_client_id'] ?? '';
    if (empty($clientId)) {
        respond(['success' => false, 'error' => 'Zoho Client ID not configured. Please add it in Admin Settings.'], 400);
    }

    $datacenter = $admin['zoho_datacenter'] ?? 'com';
    $redirectUri = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/api.php?action=zoho-oauth-callback';

    $scopes = 'ZohoCRM.modules.ALL,ZohoCRM.settings.fields.ALL,ZohoCRM.settings.modules.READ,ZohoCRM.users.READ';

    $authUrl = "https://accounts.zoho.{$datacenter}/oauth/v2/auth?" . http_build_query([
        'scope' => $scopes,
        'client_id' => $clientId,
        'response_type' => 'code',
        'access_type' => 'offline',
        'redirect_uri' => $redirectUri,
        'prompt' => 'consent'
    ]);

    respond(['success' => true, 'auth_url' => $authUrl, 'redirect_uri' => $redirectUri]);
    break;

case 'zoho-oauth-callback':
    // This handles the OAuth callback from Zoho
    $code = $_GET['code'] ?? '';
    $error = $_GET['error'] ?? '';

    if ($error) {
        // Redirect to admin page with error
        header('Location: index.html?zoho_error=' . urlencode($error));
        exit;
    }

    if (empty($code)) {
        header('Location: index.html?zoho_error=no_code');
        exit;
    }

    $admin = getAdmin();
    $clientId = $admin['zoho_client_id'] ?? '';
    $clientSecret = $admin['zoho_client_secret'] ?? '';
    $datacenter = $admin['zoho_datacenter'] ?? 'com';

    if (empty($clientId) || empty($clientSecret)) {
        header('Location: index.html?zoho_error=missing_credentials');
        exit;
    }

    $redirectUri = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/api.php?action=zoho-oauth-callback';

    // Exchange code for tokens
    $tokenResult = exchangeZohoCode($code, $clientId, $clientSecret, $redirectUri, $datacenter);

    if ($tokenResult['success']) {
        $admin['zoho_access_token'] = $tokenResult['access_token'];
        $admin['zoho_refresh_token'] = $tokenResult['refresh_token'];
        $admin['zoho_token_expires'] = date('c', time() + ($tokenResult['expires_in'] ?? 3600));
        $admin['zoho_connected'] = true;
        $admin['zoho_connected_at'] = date('c');
        saveAdmin($admin);

        header('Location: index.html?zoho_connected=1');
    } else {
        header('Location: index.html?zoho_error=' . urlencode($tokenResult['error'] ?? 'token_exchange_failed'));
    }
    exit;
    break;

case 'zoho-disconnect':
    requireAdmin();
    $admin = getAdmin();

    // Clear Zoho credentials
    unset($admin['zoho_access_token']);
    unset($admin['zoho_refresh_token']);
    unset($admin['zoho_token_expires']);
    $admin['zoho_connected'] = false;

    saveAdmin($admin);
    respond(['success' => true, 'message' => 'Disconnected from Zoho CRM']);
    break;

case 'zoho-status':
    requireAdmin();
    $admin = getAdmin();

    $tokenExpired = !empty($admin['zoho_token_expires']) && strtotime($admin['zoho_token_expires']) < time();
    $tokenExpiresIn = !empty($admin['zoho_token_expires']) ? max(0, strtotime($admin['zoho_token_expires']) - time()) : 0;

    respond([
        'success' => true,
        'connected' => $admin['zoho_connected'] ?? false,
        'connected_at' => $admin['zoho_connected_at'] ?? null,
        'datacenter' => $admin['zoho_datacenter'] ?? 'com',
        'last_sync' => $admin['zoho_last_sync'] ?? null,
        'sync_history' => array_slice($admin['zoho_sync_history'] ?? [], 0, 10),
        'token_expired' => $tokenExpired,
        'token_expires_in_seconds' => $tokenExpiresIn
    ]);
    break;

case 'zoho-refresh-token':
    requireAdmin();
    $admin = getAdmin();

    if (empty($admin['zoho_refresh_token'])) {
        respond(['success' => false, 'error' => 'No refresh token available. Please reconnect to Zoho.'], 400);
    }

    $refreshResult = refreshZohoToken($admin);
    if ($refreshResult['success']) {
        $admin['zoho_access_token'] = $refreshResult['access_token'];
        $admin['zoho_token_expires'] = date('c', time() + ($refreshResult['expires_in'] ?? 3600));
        saveAdmin($admin);
        respond(['success' => true, 'message' => 'Token refreshed successfully', 'expires' => $admin['zoho_token_expires']]);
    }
    respond(['success' => false, 'error' => 'Refresh failed: ' . ($refreshResult['error'] ?? 'Unknown error. You may need to reconnect.')], 500);
    break;

case 'zoho-get-modules':
    requireAdmin();
    $admin = getAdmin();

    if (empty($admin['zoho_connected'])) {
        respond(['success' => false, 'error' => 'Not connected to Zoho CRM'], 400);
    }

    $result = callZohoAPI('GET', '/settings/modules', null, $admin);

    if ($result['success']) {
        // Filter to relevant modules
        $relevantModules = ['Leads', 'Contacts', 'Accounts', 'Deals', 'Calls', 'Meetings', 'Tasks'];
        $modules = array_filter($result['data']['modules'] ?? [], function($m) use ($relevantModules) {
            return in_array($m['api_name'], $relevantModules);
        });
        respond(['success' => true, 'modules' => array_values($modules)]);
    } else {
        respond($result);
    }
    break;

case 'zoho-get-fields':
    requireAdmin();
    $admin = getAdmin();
    $module = $_GET['module'] ?? 'Leads';

    if (empty($admin['zoho_connected'])) {
        respond(['success' => false, 'error' => 'Not connected to Zoho CRM'], 400);
    }

    $result = callZohoAPI('GET', "/settings/fields?module={$module}", null, $admin);

    if ($result['success']) {
        respond(['success' => true, 'fields' => $result['data']['fields'] ?? [], 'module' => $module]);
    } else {
        respond($result);
    }
    break;

case 'zoho-save-mapping':
    requireAdmin();
    $input = json_decode(file_get_contents('php://input'), true);
    $admin = getAdmin();

    $module = $input['module'] ?? '';
    $mapping = $input['mapping'] ?? [];

    if (empty($module)) {
        respond(['success' => false, 'error' => 'Module name required'], 400);
    }

    if (!isset($admin['zoho_field_mappings'])) {
        $admin['zoho_field_mappings'] = [];
    }

    $admin['zoho_field_mappings'][$module] = $mapping;
    saveAdmin($admin);

    respond(['success' => true, 'message' => "Field mapping saved for {$module}"]);
    break;

case 'zoho-get-mapping':
    requireAdmin();
    $admin = getAdmin();
    $module = $_GET['module'] ?? '';

    if (empty($module)) {
        respond(['success' => true, 'mappings' => $admin['zoho_field_mappings'] ?? []]);
    } else {
        respond(['success' => true, 'mapping' => $admin['zoho_field_mappings'][$module] ?? getDefaultZohoMapping($module)]);
    }
    break;

case 'zoho-sync-pull':
    $user = requireAuth();
    $admin = getAdmin();
    $input = json_decode(file_get_contents('php://input'), true);

    if (empty($admin['zoho_connected'])) {
        respond(['success' => false, 'error' => 'Not connected to Zoho CRM'], 400);
    }

    $module = $input['module'] ?? 'Leads';
    $since = $input['since'] ?? null;

    $result = pullFromZoho($module, $admin, $user['id'], $since);

    // Log sync
    if ($result['success']) {
        $admin['zoho_last_sync'] = date('c');
        if (!isset($admin['zoho_sync_history'])) $admin['zoho_sync_history'] = [];
        array_unshift($admin['zoho_sync_history'], [
            'id' => 'sync_' . bin2hex(random_bytes(4)),
            'timestamp' => date('c'),
            'direction' => 'pull',
            'module' => $module,
            'created' => $result['created'] ?? 0,
            'updated' => $result['updated'] ?? 0,
            'skipped' => $result['skipped'] ?? 0,
            'errors' => $result['errors'] ?? []
        ]);
        $admin['zoho_sync_history'] = array_slice($admin['zoho_sync_history'], 0, 50);
        saveAdmin($admin);
    }

    respond($result);
    break;

case 'zoho-sync-push':
    $user = requireAuth();
    $admin = getAdmin();
    $input = json_decode(file_get_contents('php://input'), true);

    if (empty($admin['zoho_connected'])) {
        respond(['success' => false, 'error' => 'Not connected to Zoho CRM'], 400);
    }

    $module = $input['module'] ?? 'Leads';
    $leadIds = $input['lead_ids'] ?? null; // null = all dirty leads

    $userData = getUserData($user['id']);
    $leadsToSync = [];

    foreach ($userData['leads'] as $lead) {
        if ($leadIds === null) {
            // Sync all leads that are dirty or have no zoho_id
            if (!empty($lead['zoho_dirty']) || empty($lead['zoho_id'])) {
                $leadsToSync[] = $lead;
            }
        } else {
            // Sync specific leads
            if (in_array($lead['id'], $leadIds)) {
                $leadsToSync[] = $lead;
            }
        }
    }

    $result = pushToZoho($module, $admin, $leadsToSync, $user['id']);

    // Log sync
    if ($result['success']) {
        $admin['zoho_last_sync'] = date('c');
        if (!isset($admin['zoho_sync_history'])) $admin['zoho_sync_history'] = [];
        array_unshift($admin['zoho_sync_history'], [
            'id' => 'sync_' . bin2hex(random_bytes(4)),
            'timestamp' => date('c'),
            'direction' => 'push',
            'module' => $module,
            'created' => $result['created'] ?? 0,
            'updated' => $result['updated'] ?? 0,
            'skipped' => $result['skipped'] ?? 0,
            'errors' => $result['errors'] ?? []
        ]);
        $admin['zoho_sync_history'] = array_slice($admin['zoho_sync_history'], 0, 50);
        saveAdmin($admin);
    }

    respond($result);
    break;

case 'zoho-sync-lead':
    $user = requireAuth();
    $admin = getAdmin();
    $input = json_decode(file_get_contents('php://input'), true);

    if (empty($admin['zoho_connected'])) {
        respond(['success' => false, 'error' => 'Not connected to Zoho CRM'], 400);
    }

    $leadId = $input['lead_id'] ?? '';
    $direction = $input['direction'] ?? 'push'; // 'push', 'pull', 'both'

    if (empty($leadId)) {
        respond(['success' => false, 'error' => 'Lead ID required'], 400);
    }

    $userData = getUserData($user['id']);
    $lead = null;
    $leadIndex = null;

    foreach ($userData['leads'] as $idx => $l) {
        if ($l['id'] === $leadId) {
            $lead = $l;
            $leadIndex = $idx;
            break;
        }
    }

    if (!$lead) {
        respond(['success' => false, 'error' => 'Lead not found'], 404);
    }

    $result = ['success' => true, 'push' => null, 'pull' => null];

    if ($direction === 'push' || $direction === 'both') {
        $pushResult = pushToZoho('Leads', $admin, [$lead], $user['id']);
        $result['push'] = $pushResult;
    }

    if (($direction === 'pull' || $direction === 'both') && !empty($lead['zoho_id'])) {
        $pullResult = pullSingleFromZoho('Leads', $admin, $lead['zoho_id'], $user['id']);
        $result['pull'] = $pullResult;
    }

    respond($result);
    break;

case 'lead-comment':
    $user = requireAuth();
    $leadId = $input['lead_id'] ?? $_GET['lead_id'] ?? '';
    if (!$leadId) respond(['success' => false, 'error' => 'lead_id required'], 400);

    if ($method === 'GET') {
        $userData = getUserData($user['id']);
        foreach ($userData['leads'] as $l) {
            if ($l['id'] === $leadId) respond(['success' => true, 'comments' => $l['comments'] ?? []]);
        }
        respond(['success' => false, 'error' => 'Lead not found'], 404);
    }

    if ($method === 'POST' && !empty($input['delete'])) {
        $commentId = $input['comment_id'] ?? '';
        $userData = getUserData($user['id']);
        foreach ($userData['leads'] as &$l) {
            if ($l['id'] === $leadId) {
                $l['comments'] = array_values(array_filter($l['comments'] ?? [], function($c) use ($commentId, $user) {
                    if ($c['id'] !== $commentId) return true;
                    return !($c['user_id'] === $user['id'] || ($user['is_admin'] ?? false));
                }));
                saveUserData($user['id'], $userData);
                respond(['success' => true]);
            }
        }
        respond(['success' => false, 'error' => 'Lead not found'], 404);
    }

    if ($method === 'POST') {
        $text = trim($input['text'] ?? '');
        if (!$text) respond(['success' => false, 'error' => 'Comment required'], 400);
        $userData = getUserData($user['id']);
        foreach ($userData['leads'] as &$l) {
            if ($l['id'] === $leadId) {
                if (!isset($l['comments'])) $l['comments'] = [];
                $comment = [
                    'id'        => 'cmt_' . bin2hex(random_bytes(6)),
                    'user_id'   => $user['id'],
                    'user_name' => $user['name'] ?? $user['email'],
                    'text'      => htmlspecialchars($text, ENT_QUOTES, 'UTF-8'),
                    'created_at'=> date('c')
                ];
                $l['comments'][] = $comment;
                if (function_exists('logActivity')) logActivity($l, 'comment', 'Comment on lead: ' . substr($text, 0, 50));
                $l['updated_at'] = date('c');
                saveUserData($user['id'], $userData);

                // @mention notifications
                $leadName = trim(($l['first_name'] ?? '') . ' ' . ($l['last_name'] ?? ''));
                if (preg_match_all('/@([a-zA-Z0-9_\- ]+)/', $text, $matches)) {
                    $senderName = $user['name'] ?? $user['email'];
                    foreach ($matches[1] as $mentionedName) {
                        $mentionedName = trim($mentionedName);
                        foreach (getUsers() as $u) {
                            if ($u['id'] === $user['id']) continue;
                            $uName = trim($u['name'] ?? '');
                            if ($uName && (stripos($uName, $mentionedName) !== false || stripos($mentionedName, $uName) !== false)) {
                                $uData = getUserData($u['id']);
                                $uData['notifications'] = $uData['notifications'] ?? [];
                                $notifKey = "leadcomment_{$comment['id']}_{$u['id']}";
                                $dup = false;
                                foreach ($uData['notifications'] as $n) { if (($n['notif_key'] ?? '') === $notifKey) { $dup = true; break; } }
                                if (!$dup) {
                                    $uData['notifications'][] = [
                                        'id' => 'notif_' . bin2hex(random_bytes(6)), 'notif_key' => $notifKey,
                                        'type' => 'mention', 'lead_id' => $leadId,
                                        'title' => "{$senderName} mentioned you",
                                        'body' => "On lead {$leadName}: " . substr($text, 0, 70),
                                        'created_at' => date('c'), 'read' => false
                                    ];
                                    usort($uData['notifications'], fn($a,$b) => strtotime($b['created_at']??'0') - strtotime($a['created_at']??'0'));
                                    $uData['notifications'] = array_slice($uData['notifications'], 0, 50);
                                    saveUserData($u['id'], $uData);
                                }
                            }
                        }
                    }
                }

                respond(['success' => true, 'comment' => $comment]);
            }
        }
        respond(['success' => false, 'error' => 'Lead not found'], 404);
    }

    if ($method === 'DELETE') {
        $commentId = $input['comment_id'] ?? '';
        $userData = getUserData($user['id']);
        foreach ($userData['leads'] as &$l) {
            if ($l['id'] === $leadId) {
                $l['comments'] = array_values(array_filter($l['comments'] ?? [], function($c) use ($commentId, $user) {
                    if ($c['id'] !== $commentId) return true;
                    // Remove only if owner or admin
                    return !($c['user_id'] === $user['id'] || ($user['is_admin'] ?? false));
                }));
                saveUserData($user['id'], $userData);
                respond(['success' => true]);
            }
        }
        respond(['success' => false, 'error' => 'Lead not found'], 404);
    }
    break;

default:
    respond(['success' => false, 'error' => 'Invalid endpoint'], 404);
}

// ============ ZOHO API HELPER FUNCTIONS ============

function getZohoBaseUrl($datacenter = 'com') {
    return "https://www.zohoapis.{$datacenter}/crm/v2";
}

function exchangeZohoCode($code, $clientId, $clientSecret, $redirectUri, $datacenter = 'com') {
    $tokenUrl = "https://accounts.zoho.{$datacenter}/oauth/v2/token";

    $ch = curl_init($tokenUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type' => 'authorization_code',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri' => $redirectUri,
            'code' => $code
        ]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT => 30
    ]);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        return ['success' => false, 'error' => $error];
    }

    $data = json_decode($response, true);

    if (isset($data['access_token'])) {
        return [
            'success' => true,
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? '',
            'expires_in' => $data['expires_in'] ?? 3600
        ];
    }

    return ['success' => false, 'error' => $data['error'] ?? 'Unknown error'];
}

function refreshZohoToken($admin) {
    $datacenter = $admin['zoho_datacenter'] ?? 'com';
    $tokenUrl = "https://accounts.zoho.{$datacenter}/oauth/v2/token";

    $ch = curl_init($tokenUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type' => 'refresh_token',
            'client_id' => $admin['zoho_client_id'],
            'client_secret' => $admin['zoho_client_secret'],
            'refresh_token' => $admin['zoho_refresh_token']
        ]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT => 30
    ]);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        return ['success' => false, 'error' => $error];
    }

    $data = json_decode($response, true);

    if (isset($data['access_token'])) {
        return [
            'success' => true,
            'access_token' => $data['access_token'],
            'expires_in' => $data['expires_in'] ?? 3600
        ];
    }

    return ['success' => false, 'error' => $data['error'] ?? 'Token refresh failed'];
}

function callZohoAPI($method, $endpoint, $data = null, &$admin = null) {
    // Check if token needs refresh
    if ($admin && !empty($admin['zoho_token_expires'])) {
        $expiresAt = strtotime($admin['zoho_token_expires']);
        if ($expiresAt && $expiresAt < time() + 300) { // Refresh if expires in < 5 minutes
            $refreshResult = refreshZohoToken($admin);
            if ($refreshResult['success']) {
                $admin['zoho_access_token'] = $refreshResult['access_token'];
                $admin['zoho_token_expires'] = date('c', time() + ($refreshResult['expires_in'] ?? 3600));
                saveAdmin($admin);
            } else {
                return ['success' => false, 'error' => 'Token refresh failed: ' . ($refreshResult['error'] ?? 'Unknown')];
            }
        }
    }

    $datacenter = $admin['zoho_datacenter'] ?? 'com';
    $baseUrl = getZohoBaseUrl($datacenter);
    $url = $baseUrl . $endpoint;

    $ch = curl_init($url);
    $headers = [
        'Authorization: Zoho-oauthtoken ' . ($admin['zoho_access_token'] ?? ''),
        'Content-Type: application/json'
    ];

    $curlOpts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 60
    ];

    if ($method === 'POST') {
        $curlOpts[CURLOPT_POST] = true;
        if ($data) $curlOpts[CURLOPT_POSTFIELDS] = json_encode($data);
    } elseif ($method === 'PUT') {
        $curlOpts[CURLOPT_CUSTOMREQUEST] = 'PUT';
        if ($data) $curlOpts[CURLOPT_POSTFIELDS] = json_encode($data);
    } elseif ($method === 'DELETE') {
        $curlOpts[CURLOPT_CUSTOMREQUEST] = 'DELETE';
    }

    curl_setopt_array($ch, $curlOpts);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        return ['success' => false, 'error' => $error];
    }

    $responseData = json_decode($response, true);

    // Handle Zoho API errors
    if ($httpCode >= 400) {
        $errorMsg = $responseData['message'] ?? $responseData['error']['message'] ?? "HTTP {$httpCode}";
        return ['success' => false, 'error' => $errorMsg, 'code' => $httpCode];
    }

    return ['success' => true, 'data' => $responseData];
}

function callZohoAPIWithRetry($method, $endpoint, $data = null, &$admin = null, $maxRetries = 2) {
    $lastResult = null;
    for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
        $result = callZohoAPI($method, $endpoint, $data, $admin);

        if ($result['success']) return $result;

        $lastResult = $result;
        $httpCode = $result['code'] ?? 0;

        // On 401 (token expired), force refresh and retry
        if ($httpCode === 401 && $attempt < $maxRetries && $admin) {
            $refreshResult = refreshZohoToken($admin);
            if ($refreshResult['success']) {
                $admin['zoho_access_token'] = $refreshResult['access_token'];
                $admin['zoho_token_expires'] = date('c', time() + ($refreshResult['expires_in'] ?? 3600));
                saveAdmin($admin);
                continue;
            }
        }

        // On 429 (rate limit), wait and retry
        if ($httpCode === 429 && $attempt < $maxRetries) {
            sleep(2);
            continue;
        }

        // Other errors: don't retry
        break;
    }
    return $lastResult;
}

function getDefaultZohoMapping($module) {
    $mappings = [
        'Leads' => [
            'macktiles_to_zoho' => [
                'first_name' => 'First_Name',
                'last_name' => 'Last_Name',
                'email' => 'Email',
                'title' => 'Title',
                'website' => 'Website',
                'phone' => 'Phone',
                'linkedin' => 'Linkedin',
                'company' => 'Company',
                'industry' => 'Industry',
                'country' => 'Country',
                'notes' => 'Description',
                'fit_grade' => 'ICP_Fit',
                'fit_score' => 'Lead_Qualification_Score',
                'company_size' => 'Company_Size_Bucket',
                'status' => 'Lead_Status',
                'temperature' => 'Deal_Stage'
            ],
            'sync_direction' => 'bidirectional',
            'conflict_resolution' => 'latest_wins'
        ],
        'Contacts' => [
            'macktiles_to_zoho' => [
                'first_name' => 'First_Name',
                'last_name' => 'Last_Name',
                'email' => 'Email',
                'title' => 'Title',
                'phone' => 'Phone',
                'linkedin' => 'Linkedin',
                'company' => 'Account_Name',
                'country' => 'Mailing_Country',
                'notes' => 'Description'
            ],
            'sync_direction' => 'bidirectional',
            'conflict_resolution' => 'latest_wins'
        ],
        'Accounts' => [
            'macktiles_to_zoho' => [
                'company' => 'Account_Name',
                'industry' => 'Industry',
                'website' => 'Website',
                'phone' => 'Phone',
                'country' => 'Billing_Country',
                'company_size' => 'Employees',
                'notes' => 'Description'
            ],
            'sync_direction' => 'bidirectional',
            'conflict_resolution' => 'latest_wins'
        ],
        'Deals' => [
            'macktiles_to_zoho' => [
                'company' => 'Account_Name',
                'fit_grade' => 'ICP_Fit',
                'fit_score' => 'Lead_Qualification_Score',
                'company_size' => 'Company_Size_Bucket'
            ],
            'sync_direction' => 'to_zoho',
            'conflict_resolution' => 'zoho_wins'
        ]
    ];

    return $mappings[$module] ?? ['macktiles_to_zoho' => [], 'sync_direction' => 'bidirectional', 'conflict_resolution' => 'latest_wins'];
}

function mapMacktilesToZoho($lead, $mapping) {
    $zohoRecord = [];
    $fieldMap = $mapping['macktiles_to_zoho'] ?? [];

    foreach ($fieldMap as $macktileField => $zohoField) {
        // Handle nested fields like requisitions.consulting_service
        if (strpos($macktileField, '.') !== false) {
            $parts = explode('.', $macktileField);
            $value = $lead;
            foreach ($parts as $part) {
                $value = $value[$part] ?? null;
                if ($value === null) break;
            }
        } else {
            $value = $lead[$macktileField] ?? null;
        }

        if ($value !== null && $value !== '') {
            $zohoRecord[$zohoField] = $value;
        }
    }

    return $zohoRecord;
}

function mapZohoToMacktiles($zohoRecord, $mapping) {
    $lead = [];
    $fieldMap = $mapping['macktiles_to_zoho'] ?? [];

    // Reverse the mapping
    foreach ($fieldMap as $macktileField => $zohoField) {
        $value = $zohoRecord[$zohoField] ?? null;

        if ($value !== null && $value !== '') {
            // Handle nested fields
            if (strpos($macktileField, '.') !== false) {
                $parts = explode('.', $macktileField);
                $ref = &$lead;
                foreach ($parts as $i => $part) {
                    if ($i === count($parts) - 1) {
                        $ref[$part] = $value;
                    } else {
                        if (!isset($ref[$part])) $ref[$part] = [];
                        $ref = &$ref[$part];
                    }
                }
            } else {
                $lead[$macktileField] = $value;
            }
        }
    }

    return $lead;
}

function pullFromZoho($module, $admin, $userId, $since = null) {
    $mapping = $admin['zoho_field_mappings'][$module] ?? getDefaultZohoMapping($module);

    // Build query
    $endpoint = "/{$module}";
    if ($since) {
        $endpoint .= "?modified_time=" . urlencode($since);
    }

    $result = callZohoAPIWithRetry('GET', $endpoint, null, $admin);

    if (!$result['success']) {
        return $result;
    }

    $records = $result['data']['data'] ?? [];
    $userData = getUserData($userId);

    $stats = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];

    // Build index of existing leads by zoho_id
    $existingByZohoId = [];
    foreach ($userData['leads'] as $idx => $lead) {
        if (!empty($lead['zoho_id'])) {
            $existingByZohoId[$lead['zoho_id']] = $idx;
        }
    }

    foreach ($records as $zohoRecord) {
        $zohoId = $zohoRecord['id'] ?? '';
        if (empty($zohoId)) continue;

        try {
            $macktileData = mapZohoToMacktiles($zohoRecord, $mapping);

            if (isset($existingByZohoId[$zohoId])) {
                // Update existing
                $idx = $existingByZohoId[$zohoId];
                $existingLead = $userData['leads'][$idx];

                // Apply conflict resolution
                $strategy = $mapping['conflict_resolution'] ?? 'latest_wins';
                if ($strategy === 'zoho_wins' || ($strategy === 'latest_wins' && strtotime($zohoRecord['Modified_Time'] ?? '') > strtotime($existingLead['updated_at'] ?? ''))) {
                    $userData['leads'][$idx] = array_merge($existingLead, $macktileData, [
                        'zoho_synced_at' => date('c'),
                        'zoho_dirty' => false,
                        'updated_at' => date('c')
                    ]);
                    $stats['updated']++;
                } else {
                    $stats['skipped']++;
                }
            } else {
                // Create new lead
                $newLead = array_merge([
                    'id' => 'lead_' . bin2hex(random_bytes(8)),
                    'zoho_id' => $zohoId,
                    'zoho_module' => $module,
                    'zoho_synced_at' => date('c'),
                    'zoho_dirty' => false,
                    'source' => 'zoho',
                    'stage' => 'new_lead',
                    'status' => stageToLegacyStatus('new_lead'),
                    'stage_entered_at' => date('c'),
                    'stage_history' => [],
                    'urgency_flag' => 'normal',
                    'call_history' => [],
                    'rejection_reason' => '',
                    'consultation_type' => '',
                    'created_at' => date('c'),
                    'updated_at' => date('c'),
                    'requisitions' => []
                ], $macktileData, getDefaultStageFields());

                $userData['leads'][] = $newLead;
                $stats['created']++;
            }
        } catch (Exception $e) {
            $stats['errors'][] = "Record {$zohoId}: " . $e->getMessage();
        }
    }

    saveUserData($userId, $userData);

    return array_merge(['success' => true], $stats);
}

function pullSingleFromZoho($module, $admin, $zohoId, $userId) {
    $mapping = $admin['zoho_field_mappings'][$module] ?? getDefaultZohoMapping($module);

    $result = callZohoAPIWithRetry('GET', "/{$module}/{$zohoId}", null, $admin);

    if (!$result['success']) {
        return $result;
    }

    $zohoRecord = $result['data']['data'][0] ?? null;
    if (!$zohoRecord) {
        return ['success' => false, 'error' => 'Record not found in Zoho'];
    }

    $userData = getUserData($userId);
    $macktileData = mapZohoToMacktiles($zohoRecord, $mapping);

    // Find and update the lead
    foreach ($userData['leads'] as &$lead) {
        if ($lead['zoho_id'] === $zohoId) {
            $lead = array_merge($lead, $macktileData, [
                'zoho_synced_at' => date('c'),
                'zoho_dirty' => false,
                'updated_at' => date('c')
            ]);
            saveUserData($userId, $userData);
            return ['success' => true, 'lead' => $lead];
        }
    }

    return ['success' => false, 'error' => 'Lead not found locally'];
}

function pushToZoho($module, $admin, $leads, $userId) {
    $mapping = $admin['zoho_field_mappings'][$module] ?? getDefaultZohoMapping($module);

    $stats = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];

    if (empty($leads)) {
        return array_merge(['success' => true, 'message' => 'No leads to sync'], $stats);
    }

    $userData = getUserData($userId);

    // Build index for updating leads after push
    $leadIndex = [];
    foreach ($userData['leads'] as $idx => $lead) {
        $leadIndex[$lead['id']] = $idx;
    }

    // Batch records for Zoho (max 100 per request)
    $createRecords = [];
    $updateRecords = [];
    $leadIdToZohoData = [];

    foreach ($leads as $lead) {
        $zohoData = mapMacktilesToZoho($lead, $mapping);

        if (empty($zohoData)) {
            $stats['skipped']++;
            continue;
        }

        if (!empty($lead['zoho_id'])) {
            $zohoData['id'] = $lead['zoho_id'];
            $updateRecords[] = $zohoData;
        } else {
            $createRecords[] = $zohoData;
        }

        $leadIdToZohoData[$lead['id']] = $zohoData;
    }

    // Create new records
    if (!empty($createRecords)) {
        $chunks = array_chunk($createRecords, 100);
        foreach ($chunks as $chunk) {
            $result = callZohoAPIWithRetry('POST', "/{$module}", ['data' => $chunk], $admin);

            if ($result['success'] && !empty($result['data']['data'])) {
                foreach ($result['data']['data'] as $i => $response) {
                    if ($response['status'] === 'success' && !empty($response['details']['id'])) {
                        // Find the lead by matching fields and update zoho_id
                        $createdZohoId = $response['details']['id'];

                        // Match by index position in the chunk
                        foreach ($leads as $lead) {
                            if (empty($lead['zoho_id']) && isset($leadIndex[$lead['id']])) {
                                $idx = $leadIndex[$lead['id']];
                                if (!isset($userData['leads'][$idx]['zoho_id']) || empty($userData['leads'][$idx]['zoho_id'])) {
                                    $userData['leads'][$idx]['zoho_id'] = $createdZohoId;
                                    $userData['leads'][$idx]['zoho_module'] = $module;
                                    $userData['leads'][$idx]['zoho_synced_at'] = date('c');
                                    $userData['leads'][$idx]['zoho_dirty'] = false;
                                    $stats['created']++;
                                    break;
                                }
                            }
                        }
                    } else {
                        $stats['errors'][] = $response['message'] ?? 'Create failed';
                    }
                }
            } elseif (!$result['success']) {
                $stats['errors'][] = $result['error'] ?? 'Batch create failed';
            }
        }
    }

    // Update existing records
    if (!empty($updateRecords)) {
        $chunks = array_chunk($updateRecords, 100);
        foreach ($chunks as $chunk) {
            $result = callZohoAPIWithRetry('PUT', "/{$module}", ['data' => $chunk], $admin);

            if ($result['success'] && !empty($result['data']['data'])) {
                foreach ($result['data']['data'] as $response) {
                    if ($response['status'] === 'success') {
                        $zohoId = $response['details']['id'];
                        // Update local lead
                        foreach ($userData['leads'] as &$lead) {
                            if ($lead['zoho_id'] === $zohoId) {
                                $lead['zoho_synced_at'] = date('c');
                                $lead['zoho_dirty'] = false;
                                break;
                            }
                        }
                        $stats['updated']++;
                    } else {
                        $stats['errors'][] = $response['message'] ?? 'Update failed';
                    }
                }
            } elseif (!$result['success']) {
                $stats['errors'][] = $result['error'] ?? 'Batch update failed';
            }
        }
    }

    saveUserData($userId, $userData);

    return array_merge(['success' => true], $stats);
}

// ============ LLM FUNCTIONS ============

function callLLM($provider, $apiKey, $prompt) {
    switch ($provider) {
        case 'groq': return callGroq($apiKey, $prompt);
        case 'gemini': return callGemini($apiKey, $prompt);
        case 'anthropic': return callAnthropic($apiKey, $prompt);
        default: return ['success' => false, 'error' => 'Unknown provider'];
    }
}

function callGroq($apiKey, $prompt) {
    $ch = curl_init('https://api.groq.com/openai/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, 
        CURLOPT_POST => true, 
        CURLOPT_POSTFIELDS => json_encode([
            'model' => chosenModelFor('groq'),
            'messages' => [['role' => 'user', 'content' => $prompt]],
            'max_tokens' => 2048,
            'temperature' => 0.7
        ]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey], 
        CURLOPT_TIMEOUT => 120
    ]);
    $response = curl_exec($ch); 
    $error = curl_error($ch); 
    curl_close($ch);
    if ($error) return ['success' => false, 'error' => $error];
    $r = json_decode($response, true);
    return isset($r['choices'][0]['message']['content']) 
        ? ['success' => true, 'content' => $r['choices'][0]['message']['content']] 
        : ['success' => false, 'error' => $r['error']['message'] ?? 'API error'];
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
    curl_close($ch);
    if ($error) return ['success' => false, 'error' => $error];
    $r = json_decode($response, true);
    return isset($r['candidates'][0]['content']['parts'][0]['text']) 
        ? ['success' => true, 'content' => $r['candidates'][0]['content']['parts'][0]['text']] 
        : ['success' => false, 'error' => $r['error']['message'] ?? 'API error'];
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
    curl_close($ch);
    if ($error) return ['success' => false, 'error' => $error];
    $r = json_decode($response, true);
    return isset($r['content'][0]['text'])
        ? ['success' => true, 'content' => $r['content'][0]['text']]
        : ['success' => false, 'error' => $r['error']['message'] ?? 'API error'];
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

    // Add stage intelligence context if available
    if (!empty($lead['pain_hypothesis']['hypotheses'])) {
        $primaryPain = $lead['pain_hypothesis']['hypotheses'][0]['pain'] ?? '';
        $impact = $lead['pain_hypothesis']['commercial_impact']['quantified_impact'] ?? '';
        $context .= "\nVALIDATED PAIN HYPOTHESIS: {$primaryPain}";
        if ($impact) $context .= " (Commercial Impact: {$impact})";
    }
    if (!empty($lead['persona_intelligence']['kpis']['primary_metrics'])) {
        $kpis = implode(', ', $lead['persona_intelligence']['kpis']['primary_metrics']);
        $context .= "\nPERSONA KPIs: {$kpis}";
    }
    if (!empty($lead['persona_intelligence']['objection_profile']['likely_objections'])) {
        $objections = implode(', ', array_slice($lead['persona_intelligence']['objection_profile']['likely_objections'], 0, 2));
        $context .= "\nLIKELY OBJECTIONS: {$objections}";
    }
    if (!empty($lead['account_intelligence']['trigger_signals'])) {
        $signals = [];
        foreach (['growth_signals', 'cost_pressure_signals', 'technology_signals'] as $type) {
            if (!empty($lead['account_intelligence']['trigger_signals'][$type])) {
                $signals = array_merge($signals, array_slice($lead['account_intelligence']['trigger_signals'][$type], 0, 1));
            }
        }
        if ($signals) {
            $context .= "\nTRIGGER SIGNALS: " . implode(', ', $signals);
        }
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

function generateCallPitch($provider, $apiKey, $name, $title, $company, $industry, $pitchType, $customInstructions, $settings, $leadData = null) {
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

    // Add stage intelligence context if available
    if ($leadData) {
        if (!empty($leadData['pain_hypothesis']['hypotheses'])) {
            $painList = [];
            foreach (array_slice($leadData['pain_hypothesis']['hypotheses'], 0, 3) as $h) {
                $painList[] = $h['pain'] ?? '';
            }
            $context .= "\n\nVALIDATED PAIN POINTS:\n- " . implode("\n- ", array_filter($painList));
        }
        if (!empty($leadData['persona_intelligence']['kpis']['primary_metrics'])) {
            $context .= "\n\nPERSONA KPIs: " . implode(', ', $leadData['persona_intelligence']['kpis']['primary_metrics']);
        }
        if (!empty($leadData['persona_intelligence']['objection_profile']['likely_objections'])) {
            $context .= "\n\nLIKELY OBJECTIONS:\n- " . implode("\n- ", array_slice($leadData['persona_intelligence']['objection_profile']['likely_objections'], 0, 3));
        }
        if (!empty($leadData['account_intelligence']['trigger_signals'])) {
            $signals = [];
            foreach (['growth_signals', 'cost_pressure_signals', 'technology_signals'] as $type) {
                if (!empty($leadData['account_intelligence']['trigger_signals'][$type])) {
                    $signals = array_merge($signals, array_slice($leadData['account_intelligence']['trigger_signals'][$type], 0, 1));
                }
            }
            if ($signals) {
                $context .= "\n\nTRIGGER SIGNALS: " . implode(', ', $signals);
            }
        }
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

\"Perfect. I'll send a calendar invite. Your email is [confirm] — correct?\"

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
