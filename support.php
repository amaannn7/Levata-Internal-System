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

define('TICKETS_FILE', DATA_DIR . '/tickets.json');

/** Read the shared ticket store. Shape: ['tickets' => [...], 'seq' => ['ticket'=>n]]. */
function getTicketsStore() {
    if (!file_exists(TICKETS_FILE)) {
        return ['tickets' => [], 'seq' => ['ticket' => 0]];
    }
    $store = json_decode(file_get_contents(TICKETS_FILE), true);
    if (!is_array($store)) $store = [];
    if (!isset($store['tickets']) || !is_array($store['tickets'])) $store['tickets'] = [];
    if (!isset($store['seq']) || !is_array($store['seq'])) $store['seq'] = ['ticket' => 0];
    $store['seq']['ticket'] = (int) ($store['seq']['ticket'] ?? 0);
    return $store;
}

/** Write the shared ticket store with an exclusive lock (mirrors saveJobsStore). */
function saveTicketsStore($store) {
    $fp = fopen(TICKETS_FILE, 'c');
    if (flock($fp, LOCK_EX)) {
        ftruncate($fp, 0);
        fwrite($fp, json_encode($store, JSON_PRETTY_PRINT));
        fflush($fp);
        flock($fp, LOCK_UN);
    }
    fclose($fp);
}

function nextTicketNo(&$store) {
    $store['seq']['ticket']++;
    return sprintf('TKT-%04d', $store['seq']['ticket']);
}

$VALID_TICKET_TYPE     = ['feedback', 'support'];
$VALID_TICKET_CATEGORY = ['bug', 'feature', 'question', 'other'];
$VALID_TICKET_PRIORITY = ['low', 'normal', 'high'];
$VALID_TICKET_STATUS   = ['open', 'in_progress', 'resolved', 'closed'];

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

    $typeLabel = ($ticket['type'] ?? 'support') === 'feedback' ? 'Feedback' : 'Support';
    $no = $ticket['ticket_no'] ?? '';
    $subject = ($event === 'reply' ? 'Re: ' : '') . '[' . $typeLabel . '] ' . $no . ' - ' . ($ticket['subject'] ?? '(no subject)');

    $lines = [];
    $lines[] = $event === 'reply' ? 'A reply was added to a ticket.' : 'A new ' . strtolower($typeLabel) . ' ticket was submitted.';
    $lines[] = '';
    $lines[] = 'Ticket:    ' . $no;
    $lines[] = 'Type:      ' . ($ticket['type'] ?? '');
    $lines[] = 'Category:  ' . ($ticket['category'] ?? '');
    $lines[] = 'Priority:  ' . ($ticket['priority'] ?? '');
    $lines[] = 'Status:    ' . ($ticket['status'] ?? '');
    $lines[] = 'From:      ' . ($ticket['created_by_name'] ?? '') . ' <' . ($ticket['created_by_email'] ?? '') . '>';
    $lines[] = 'Subject:   ' . ($ticket['subject'] ?? '');
    $lines[] = '';
    if ($event === 'reply' && $reply) {
        $lines[] = 'Reply from ' . ($reply['author_name'] ?? '') . ':';
        $lines[] = $reply['message'] ?? '';
    } else {
        $lines[] = 'Message:';
        $lines[] = $ticket['message'] ?? '';
    }
    $body = implode("\n", $lines);

    // Sender must be on a Resend-verified domain, or Resend's shared test sender
    // 'onboarding@resend.dev' (which only delivers to your own Resend account email).
    $fromAddr = trim($admin['support_from'] ?? '') ?: 'onboarding@resend.dev';
    $payload = [
        'from' => 'Levata Support <' . $fromAddr . '>',
        'to' => [$to],
        'subject' => $subject,
        'text' => $body,
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
