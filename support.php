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
 * Ticket sync (Phase 1: one-way, spoke -> hub)
 *
 * The SAME codebase runs on every deployment. A deployment's role is set
 * purely by its admin_config:
 *
 *  - SPOKE (e.g. a client like Macktiles): has 'ticket_hub_url' + 'ticket_hub_secret'
 *    set. When a user files a ticket, the spoke forwards a copy to the hub.
 *
 *  - HUB (our central Levata system): has 'ticket_ingest_secret' set. It accepts
 *    forwarded tickets on the 'ingest-ticket' endpoint (secret-checked) and stores
 *    them tagged with the sending client's name, so we see every client's tickets
 *    in one Help & Support list.
 *
 * A deployment can be neither (standalone, current behaviour), a spoke, or a hub.
 * Forwarding is best-effort: it never blocks or fails the user's submission.
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
function ingestForwardedTicket($client, $remote) {
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
        // Origin tag: which client, and their ticket ids, so we can reply home later.
        'source' => 'client',
        'client' => trim($client) ?: 'Client',
        'remote_id' => trim($remote['remote_id'] ?? ''),
        'remote_ticket_no' => trim($remote['remote_ticket_no'] ?? ''),
    ];
    $store['tickets'][] = $ticket;
    saveTicketsStore($store);
    // Notify the hub's support inbox too, reusing the existing email path.
    notifySupportEmail($ticket, 'new');
    return $ticket;
}
