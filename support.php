<?php
/**
 * Help & Support — feedback + support tickets raised by users inside a deployed
 * Levata system. Included by api.php. Stores everything in data/tickets.json
 * (one shared, company-wide file, like jobs.json), which is THIS deployment's own
 * record of what its users have raised.
 *
 * On creation (and on each reply) a ticket is also emailed out to the vendor
 * support address configured in Admin -> Settings (admin.json 'support_email'),
 * so the people running the deployed system don't have to log in to see them.
 *
 * A TICKET is one request from a user:
 *   - type: feedback (suggestion/praise/complaint) or support (something needs help)
 *   - category, subject, message
 *   - priority (low/normal/high) and status (open/in_progress/resolved/closed)
 *   - a replies[] thread (user <-> admin back-and-forth, kept in-app)
 *
 * Ticket number format: TKT-0001 (sequential, per store).
 */

/** Read the shared ticket store. Shape: ['tickets' => [...], 'seq' => ['ticket'=>n]]. */
function getTicketsStore() {
    $store = dbGetBlob('tickets', null);
    if ($store === null) return ['tickets' => [], 'seq' => ['ticket' => 0]];
    if (!isset($store['tickets']) || !is_array($store['tickets'])) $store['tickets'] = [];
    if (!isset($store['seq']) || !is_array($store['seq'])) $store['seq'] = ['ticket' => 0];
    $store['seq']['ticket'] = (int) ($store['seq']['ticket'] ?? 0);
    return $store;
}

/** Write the shared ticket store (mirrors saveJobsStore), plus refresh the reporting projection. */
function saveTicketsStore($store) {
    dbSaveBlob('tickets', $store);
    dbSyncReportingTable('tickets', $store['tickets'] ?? [], [
        'type' => fn($t) => $t['type'] ?? 'support',
        'status' => fn($t) => $t['status'] ?? 'open',
        'priority' => fn($t) => $t['priority'] ?? 'normal',
        'created_at' => fn($t) => $t['created_at'] ?? null,
        'updated_at' => fn($t) => $t['updated_at'] ?? null,
    ]);
}

function nextTicketNo(&$store) {
    $store['seq']['ticket']++;
    return sprintf('TKT-%04d', $store['seq']['ticket']);
}

/* ---- In-app notifications for ticket events (surface in the existing bell) ----
 * Notifications are stored per-user in getUserData()['notifications']; the
 * frontend polls them every few seconds, so adding one here makes the bell
 * badge light up "live" without any websockets. */

/** Append one notification to a user's notifications list (capped, newest kept). */
function addUserNotification($userId, $notif) {
    if ($userId === '') return;
    $data = getUserData($userId);
    if (!isset($data['notifications']) || !is_array($data['notifications'])) $data['notifications'] = [];
    $notif += ['id' => 'ntf_' . bin2hex(random_bytes(6)), 'read' => false, 'created_at' => date('c')];
    $data['notifications'][] = $notif;
    // Keep newest 50.
    usort($data['notifications'], fn($a, $b) => strtotime($b['created_at'] ?? '0') - strtotime($a['created_at'] ?? '0'));
    $data['notifications'] = array_slice($data['notifications'], 0, 50);
    saveUserData($userId, $data);
}

/** Notify every admin/super-admin (used when a new ticket arrives at the hub). */
function notifyAdminsOfTicket($ticket) {
    $from = trim($ticket['client'] ?? '') ?: (trim($ticket['created_by_name'] ?? '') ?: 'a user');
    $isFeedback = ($ticket['type'] ?? 'support') === 'feedback';
    foreach (getUsers() as $u) {
        if (empty($u['is_admin']) && empty($u['is_super_admin'])) continue;
        addUserNotification($u['id'] ?? '', [
            'notif_key' => 'ticket_new_' . ($ticket['id'] ?? ''),
            'type' => 'ticket_new',
            'title' => ($isFeedback ? '💬 New feedback' : '🎫 New support ticket'),
            'body' => $from . ': ' . (trim($ticket['subject'] ?? '') ?: '(no subject)'),
            'ticket_id' => $ticket['id'] ?? '',
        ]);
    }
}

/** Notify the ticket owner that a reply was added (used on the spoke/local side). */
function notifyOwnerOfReply($ticket, $reply) {
    $ownerId = trim($ticket['created_by'] ?? '');
    if ($ownerId === '') return; // forwarded tickets have no local owner
    addUserNotification($ownerId, [
        'notif_key' => 'ticket_reply_' . ($ticket['id'] ?? '') . '_' . ($reply['id'] ?? ''),
        'type' => 'ticket_reply',
        'title' => '↩️ New reply on your ticket',
        'body' => (trim($reply['author_name'] ?? '') ?: 'Support') . ': ' . mb_substr(trim($reply['message'] ?? ''), 0, 80),
        'ticket_id' => $ticket['id'] ?? '',
    ]);
}

$VALID_TICKET_TYPE     = ['feedback', 'support'];
// Categories are type-specific. The frontend shows the right set per type;
// the backend accepts the union of both (plus the shared 'other').
$SUPPORT_CATEGORIES    = ['bug', 'how_to', 'account', 'performance', 'other'];
$FEEDBACK_CATEGORIES   = ['feature', 'improvement', 'complaint', 'praise', 'other'];
$VALID_TICKET_CATEGORY = array_values(array_unique(array_merge($SUPPORT_CATEGORIES, $FEEDBACK_CATEGORIES)));
$VALID_TICKET_PRIORITY = ['low', 'normal', 'high'];
$VALID_TICKET_STATUS   = ['open', 'in_progress', 'resolved', 'closed'];

// Human-readable labels for emails/notifications (must mirror the frontend dropdowns).
$TICKET_CATEGORY_LABELS = [
    'bug' => 'Bug / something broken',
    'how_to' => 'How do I…? / question',
    'account' => 'Account / login',
    'performance' => 'Slow / not loading',
    'feature' => 'Feature request',
    'improvement' => 'Improvement / suggestion',
    'complaint' => 'Complaint',
    'praise' => 'Praise / what works well',
    'other' => 'Other',
];
$TICKET_PRIORITY_LABELS = ['low' => 'Low', 'normal' => 'Normal', 'high' => 'High'];
$TICKET_STATUS_LABELS = [
    'open' => 'Open', 'in_progress' => 'In progress',
    'resolved' => 'Resolved', 'closed' => 'Closed',
];

/** Map a stored code to its human label, falling back to a tidied version of the code. */
function ticketLabel($map, $code) {
    if (isset($map[$code])) return $map[$code];
    return $code === '' ? '—' : ucfirst(str_replace('_', ' ', $code));
}

/** Build/validate a ticket's user-editable fields. Used by create and edit. */
function applyTicketFields($ticket, $input) {
    global $VALID_TICKET_TYPE, $VALID_TICKET_CATEGORY, $VALID_TICKET_PRIORITY;
    $type = $input['type'] ?? ($ticket['type'] ?? 'support');
    $ticket['type'] = in_array($type, $VALID_TICKET_TYPE, true) ? $type : 'support';
    $cat = $input['category'] ?? ($ticket['category'] ?? 'other');
    $ticket['category'] = in_array($cat, $VALID_TICKET_CATEGORY, true) ? $cat : 'other';
    $pri = $input['priority'] ?? ($ticket['priority'] ?? 'normal');
    $ticket['priority'] = in_array($pri, $VALID_TICKET_PRIORITY, true) ? $pri : 'normal';
    $ticket['subject'] = trim($input['subject'] ?? ($ticket['subject'] ?? ''));
    $ticket['message'] = trim($input['message'] ?? ($ticket['message'] ?? ''));
    return $ticket;
}

/** Company-wide summary across tickets, for the stat cards. */
function ticketsSummary($tickets) {
    $open = 0; $inProgress = 0; $resolved = 0; $closed = 0;
    foreach ($tickets as $t) {
        $s = $t['status'] ?? 'open';
        if ($s === 'open') $open++;
        elseif ($s === 'in_progress') $inProgress++;
        elseif ($s === 'resolved') $resolved++;
        elseif ($s === 'closed') $closed++;
    }
    return [
        'open' => $open,
        'in_progress' => $inProgress,
        'resolved' => $resolved,
        'closed' => $closed,
        'total' => count($tickets),
    ];
}

/**
 * Email a ticket out to the configured vendor support address.
 * Sends via the Resend API (https://resend.com) over HTTPS, the same cURL pattern
 * the app uses for its LLM calls, so mail is DKIM-signed and lands in the inbox
 * rather than spam. Requires admin.json 'resend_key'.
 *
 * Best-effort: returns false (and never throws) if the key or recipient is missing,
 * so an email problem can never block a user from filing a ticket.
 *
 * $event is 'new' (just created) or 'reply' (a reply was added).
 */
function notifySupportEmail($ticket, $event = 'new', $reply = null) {
    $admin = getAdmin();
    $to = trim($admin['support_email'] ?? '');
    $apiKey = trim($admin['resend_key'] ?? '');
    // Need both a recipient and an API key to send. Otherwise no-op (ticket still saved).
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL) || $apiKey === '') return false;

    global $TICKET_CATEGORY_LABELS, $TICKET_PRIORITY_LABELS, $TICKET_STATUS_LABELS;

    // Human-readable values (no raw codes like "how_to" or "in_progress").
    $isFeedback = ($ticket['type'] ?? 'support') === 'feedback';
    $typeLabel = $isFeedback ? 'Feedback' : 'Support';
    $no = $ticket['ticket_no'] ?? '';
    $subjectText = trim($ticket['subject'] ?? '') ?: '(no subject)';
    $catLabel = ticketLabel($TICKET_CATEGORY_LABELS, $ticket['category'] ?? '');
    $priLabel = ticketLabel($TICKET_PRIORITY_LABELS, $ticket['priority'] ?? '');
    $statusLabel = ticketLabel($TICKET_STATUS_LABELS, $ticket['status'] ?? '');
    $fromName = trim($ticket['created_by_name'] ?? '') ?: 'Unknown';
    $fromEmail = trim($ticket['created_by_email'] ?? '');
    $fromLine = $fromEmail !== '' ? ($fromName . ' (' . $fromEmail . ')') : $fromName;

    $subject = ($event === 'reply' ? 'Re: ' : '') . '[' . $typeLabel . '] ' . $no . ' - ' . $subjectText;

    // A one-line summary so the email reads as a sentence, not a data dump.
    if ($event === 'reply') {
        $intro = ($reply['is_staff'] ?? false)
            ? 'Levata Support replied to ' . $fromName . "'s " . strtolower($typeLabel) . ' ticket.'
            : $fromName . ' replied to their ' . strtolower($typeLabel) . ' ticket.';
    } else {
        $intro = $isFeedback
            ? $fromName . ' sent feedback: "' . $subjectText . '".'
            : $fromName . ' raised a support request: "' . $subjectText . '".';
    }

    // The message that matters for this email (the reply, or the original message).
    $isReply = ($event === 'reply' && $reply);
    $msgHeading = $isReply ? ('Reply from ' . ($reply['author_name'] ?? 'Unknown')) : 'Message';
    $msgBody = $isReply ? ($reply['message'] ?? '') : ($ticket['message'] ?? '');

    // ---- Plain-text version (fallback) ----
    $lines = [];
    $lines[] = $intro;
    $lines[] = '';
    $lines[] = str_repeat('-', 48);
    $lines[] = 'Ticket    : ' . $no;
    $lines[] = 'Type      : ' . $typeLabel;
    $lines[] = 'Category  : ' . $catLabel;
    $lines[] = 'Priority  : ' . $priLabel;
    $lines[] = 'Status    : ' . $statusLabel;
    $lines[] = 'From      : ' . $fromLine;
    $lines[] = 'Subject   : ' . $subjectText;
    $lines[] = str_repeat('-', 48);
    $lines[] = '';
    $lines[] = $msgHeading . ':';
    $lines[] = $msgBody;
    $lines[] = '';
    $lines[] = 'You can reply to this email to reach ' . $fromName . ' directly,';
    $lines[] = 'or open the ticket in Levata under Help & Support.';
    $body = implode("\n", $lines);

    // ---- HTML version (renders cleanly in Gmail/Outlook) ----
    $e = function ($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); };
    $accent = $isFeedback ? '#7c3aed' : '#2563eb';
    $row = function ($label, $value) use ($e) {
        return '<tr><td style="padding:4px 12px 4px 0;color:#6b7280;font-size:13px;white-space:nowrap;vertical-align:top;">' . $e($label)
            . '</td><td style="padding:4px 0;color:#111827;font-size:13px;font-weight:600;">' . $e($value) . '</td></tr>';
    };
    $html = '<div style="font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;max-width:560px;margin:0 auto;color:#111827;">'
        . '<div style="background:' . $accent . ';color:#fff;padding:14px 18px;border-radius:8px 8px 0 0;font-size:15px;font-weight:600;">'
        . $e($typeLabel) . ' ticket ' . $e($no) . '</div>'
        . '<div style="border:1px solid #e5e7eb;border-top:none;border-radius:0 0 8px 8px;padding:18px;">'
        . '<p style="margin:0 0 14px;font-size:14px;line-height:1.5;">' . $e($intro) . '</p>'
        . '<table style="border-collapse:collapse;margin-bottom:16px;">'
        . $row('Category', $catLabel) . $row('Priority', $priLabel) . $row('Status', $statusLabel) . $row('From', $fromLine)
        . '</table>'
        . '<div style="font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:#6b7280;margin-bottom:6px;">' . $e($msgHeading) . '</div>'
        . '<div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;padding:12px 14px;font-size:14px;line-height:1.6;white-space:pre-wrap;">' . $e($msgBody) . '</div>'
        . '<p style="margin:16px 0 0;font-size:12px;color:#6b7280;line-height:1.5;">Reply to this email to reach ' . $e($fromName) . ' directly, or open the ticket in Levata under <strong>Help &amp; Support</strong>.</p>'
        . '</div></div>';

    // Sender must be on a Resend-verified domain, or Resend's shared test sender
    // 'onboarding@resend.dev' (which only delivers to your own Resend account email).
    $fromAddr = trim($admin['support_from'] ?? '') ?: 'onboarding@resend.dev';
    $payload = [
        'from' => 'Levata Support <' . $fromAddr . '>',
        'to' => [$to],
        'subject' => $subject,
        'text' => $body,
        'html' => $html,
    ];
    // Reply-To the submitter, so replying from the support inbox reaches the user.
    $replyTo = trim($ticket['created_by_email'] ?? '');
    if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
        $payload['reply_to'] = $replyTo;
    }

    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT => 15,
    ]);
    $response = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    // Resend returns 200 with {"id":"..."} on success; anything else is a soft failure.
    return $code >= 200 && $code < 300;
}

/* =====================================================================
 * Ticket sync (spoke <-> hub)
 *
 * The SAME codebase runs on every deployment. A deployment's role is set
 * purely by its admin_config:
 *
 *  - SPOKE (e.g. a client like Macktiles): has 'ticket_hub_url' + 'ticket_hub_secret'
 *    set. When a user files a ticket, the spoke forwards a copy to the hub, and
 *    includes a callback URL so the hub can push replies back. Replies from the
 *    hub arrive on the spoke's 'ingest-reply' endpoint and appear in the user's
 *    ticket thread.
 *
 *  - HUB (our central Levata system): has 'ticket_ingest_secret' set. It accepts
 *    forwarded tickets on 'ingest-ticket' (secret-checked), tags them by client,
 *    and remembers each ticket's callback URL. When an admin replies to a
 *    client-originated ticket, the hub pushes that reply back to the spoke.
 *
 * A deployment can be neither (standalone), a spoke, or a hub. All cross-system
 * calls are best-effort: they never block or fail the user's action.
 *
 * Phase 1 = ticket goes spoke -> hub. Phase 2 = staff reply goes hub -> spoke.
 * ===================================================================== */

/** This deployment's own name, used to tag forwarded tickets at the hub. */
function ticketClientName() {
    $admin = getAdmin();
    $name = trim($admin['ticket_client_name'] ?? '');
    if ($name !== '') return $name;
    // Fall back to the sender company set for outreach, else a generic label.
    return trim($admin['sender_company'] ?? '') ?: 'Client';
}

/**
 * Best guess at this deployment's own public base URL to api.php, so a spoke can
 * tell the hub where to send replies back. Prefer an explicitly configured value;
 * otherwise derive from the current request.
 */
function selfApiUrl() {
    $admin = getAdmin();
    $configured = trim($admin['self_api_url'] ?? '');
    if ($configured !== '') return $configured;
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') == 443);
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
    // Path to api.php (this script), e.g. /api.php.
    $path = $_SERVER['SCRIPT_NAME'] ?? '/api.php';
    return $scheme . '://' . $host . $path;
}

/**
 * SPOKE side: forward a freshly-created ticket to the configured hub.
 * Best-effort; returns false quietly if this deployment isn't a spoke or the
 * hub is unreachable. Never throws.
 */
function forwardTicketToHub($ticket) {
    $admin = getAdmin();
    $hubUrl = trim($admin['ticket_hub_url'] ?? '');
    $secret = trim($admin['ticket_hub_secret'] ?? '');
    if ($hubUrl === '' || $secret === '') return false; // not a spoke

    $payload = [
        'secret' => $secret,
        'client' => ticketClientName(),
        // Where the hub should POST replies back to (this spoke's ingest-reply).
        'reply_url' => selfApiUrl() . '?action=ingest-reply',
        'ticket' => [
            // Carry the spoke's own id + number so the hub can link back later (Phase 2).
            'remote_id' => $ticket['id'] ?? '',
            'remote_ticket_no' => $ticket['ticket_no'] ?? '',
            'type' => $ticket['type'] ?? 'support',
            'category' => $ticket['category'] ?? 'other',
            'priority' => $ticket['priority'] ?? 'normal',
            'status' => $ticket['status'] ?? 'open',
            'subject' => $ticket['subject'] ?? '',
            'message' => $ticket['message'] ?? '',
            'created_by_name' => $ticket['created_by_name'] ?? '',
            'created_by_email' => $ticket['created_by_email'] ?? '',
            'created_at' => $ticket['created_at'] ?? date('c'),
        ],
    ];

    $ch = curl_init($hubUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 10,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code >= 200 && $code < 300;
}

/**
 * HUB side: store a ticket forwarded by a spoke.
 * Assigns a local hub ticket number, tags it with the sending client, and keeps
 * the spoke's origin (remote_id / remote_ticket_no + client) so a reply can be
 * routed home in Phase 2. Returns the created local ticket, or null on bad input.
 */
function ingestForwardedTicket($client, $remote, $replyUrl = '') {
    if (!is_array($remote)) return null;
    global $VALID_TICKET_TYPE, $VALID_TICKET_CATEGORY, $VALID_TICKET_PRIORITY, $VALID_TICKET_STATUS;

    $subject = trim($remote['subject'] ?? '');
    $message = trim($remote['message'] ?? '');
    if ($subject === '' || $message === '') return null;

    $store = getTicketsStore();
    $now = date('c');
    $type = in_array($remote['type'] ?? '', $VALID_TICKET_TYPE, true) ? $remote['type'] : 'support';
    $cat = in_array($remote['category'] ?? '', $VALID_TICKET_CATEGORY, true) ? $remote['category'] : 'other';
    $pri = in_array($remote['priority'] ?? '', $VALID_TICKET_PRIORITY, true) ? $remote['priority'] : 'normal';
    $status = in_array($remote['status'] ?? '', $VALID_TICKET_STATUS, true) ? $remote['status'] : 'open';

    $ticket = [
        'id' => 'tkt_' . bin2hex(random_bytes(8)),
        'ticket_no' => nextTicketNo($store),
        'type' => $type,
        'category' => $cat,
        'priority' => $pri,
        'status' => $status,
        'subject' => $subject,
        'message' => $message,
        // The person is a user on the spoke, not a local account.
        'created_by' => '',
        'created_by_name' => trim($remote['created_by_name'] ?? '') ?: 'Client user',
        'created_by_email' => trim($remote['created_by_email'] ?? ''),
        'created_at' => trim($remote['created_at'] ?? '') ?: $now,
        'updated_at' => $now,
        'replies' => [],
        // Origin tag: which client, their ticket ids, and where to reply back to,
        // so a staff reply at the hub can be pushed home to the spoke (Phase 2).
        'source' => 'client',
        'client' => trim($client) ?: 'Client',
        'remote_id' => trim($remote['remote_id'] ?? ''),
        'remote_ticket_no' => trim($remote['remote_ticket_no'] ?? ''),
        'reply_url' => trim($replyUrl),
    ];
    $store['tickets'][] = $ticket;
    saveTicketsStore($store);
    // Notify the hub's support inbox too, reusing the existing email path.
    notifySupportEmail($ticket, 'new');
    // Light up the bell for every admin on the hub.
    notifyAdminsOfTicket($ticket);
    return $ticket;
}

/**
 * HUB side (Phase 2): push a staff reply back to the spoke the ticket came from.
 * Called after an admin replies to a client-originated ticket. Best-effort;
 * returns false quietly if the ticket has no callback URL or the spoke is
 * unreachable. Never throws.
 *
 * Authenticated with the hub's own ingest secret, which must match the shared
 * secret the spoke uses (spoke 'ticket_hub_secret' == hub 'ticket_ingest_secret').
 */
function sendReplyToSpoke($ticket, $reply) {
    if (($ticket['source'] ?? '') !== 'client') return false;   // not a forwarded ticket
    $replyUrl = trim($ticket['reply_url'] ?? '');
    $remoteId = trim($ticket['remote_id'] ?? '');
    if ($replyUrl === '' || $remoteId === '') return false;

    $admin = getAdmin();
    $secret = trim($admin['ticket_ingest_secret'] ?? '');
    if ($secret === '') return false;

    $payload = [
        'secret' => $secret,
        'remote_id' => $remoteId,   // the spoke's own ticket id
        'reply' => [
            'author_name' => $reply['author_name'] ?? 'Support',
            'is_staff' => true,       // it's a reply from the vendor/support side
            'message' => $reply['message'] ?? '',
            'created_at' => $reply['created_at'] ?? date('c'),
        ],
    ];

    $ch = curl_init($replyUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 10,
    ]);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code >= 200 && $code < 300;
}

/**
 * SPOKE side (Phase 2): store a reply pushed back from the hub.
 * Finds the local ticket by its own id (the hub sends it back as remote_id) and
 * appends the reply to the thread, so the user sees the vendor's answer in-app.
 * Returns true on success, false if the ticket isn't found or the reply is empty.
 */
function ingestReplyFromHub($localTicketId, $reply) {
    if (!is_array($reply)) return false;
    $message = trim($reply['message'] ?? '');
    if ($localTicketId === '' || $message === '') return false;

    $store = getTicketsStore();
    $found = false;
    $ownerTicket = null; $newReply = null;
    foreach ($store['tickets'] as &$ticket) {
        if (($ticket['id'] ?? '') === $localTicketId) {
            if (!isset($ticket['replies']) || !is_array($ticket['replies'])) $ticket['replies'] = [];
            $newReply = [
                'id' => 'rep_' . bin2hex(random_bytes(6)),
                'author_id' => '',
                'author_name' => trim($reply['author_name'] ?? '') ?: 'Support',
                'is_staff' => true,
                'message' => $message,
                'created_at' => trim($reply['created_at'] ?? '') ?: date('c'),
            ];
            $ticket['replies'][] = $newReply;
            $ticket['updated_at'] = date('c');
            $ownerTicket = $ticket;
            $found = true;
            break;
        }
    }
    unset($ticket);
    if (!$found) return false;
    saveTicketsStore($store);
    // Light up the bell for the user who raised this ticket.
    notifyOwnerOfReply($ownerTicket, $newReply);
    return true;
}
