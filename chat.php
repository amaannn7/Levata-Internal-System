<?php
/**
 * Team Chat — shared, company-wide channels + direct messages.
 * Included by api.php (require_once). Holds ONLY helper functions; the request
 * actions (chat-channels, chat-messages, chat-react, chat-dm-threads,
 * chat-delete-message, chat-pin-message, chat-upload, chat-unread) live as
 * inline `case` blocks in api.php's switch, matching how jobs/tasks/clients wire in.
 *
 * Storage (dedicated Postgres tables, created by dbBootstrap in db.php):
 *   - chat_channels      one row per channel   (dbLoadAll/dbSaveAll 'chat_channels')
 *   - chat_messages      one row per message, keyed by thread_id (dbLoadMessages/dbSaveMessages)
 *   - chat_last_read     per-user/thread read marker (dbGetLastRead/dbSetLastRead)
 *
 * A "thread" is either a channel id (channel_xxxx) or a DM id (dm_<md5>). DMs are
 * derived deterministically from the two user ids, so there is no DM row to create.
 *
 * Notifications reuse the app's existing per-user bell: pushChatNotification writes
 * onto getUserData($id)['notifications'], surfaced by the existing `notifications` action.
 */

function isChatAdmin(array $user): bool {
    return !empty($user['is_admin']) || !empty($user['is_super_admin']);
}

/**
 * Pusher (Stage 2, real-time push). Config lives in the admin_config blob:
 *   pusher_app_id, pusher_key, pusher_secret, pusher_cluster
 * If any are missing, pusherEnabled() is false and the app falls back to the
 * polling that Stage 1 already ships — nothing breaks before keys are added.
 */
function pusherConfig(): array {
    $a = getAdmin();
    return [
        'app_id'  => trim($a['pusher_app_id'] ?? ''),
        'key'     => trim($a['pusher_key'] ?? ''),
        'secret'  => trim($a['pusher_secret'] ?? ''),
        'cluster' => trim($a['pusher_cluster'] ?? ''),
    ];
}

function pusherEnabled(): bool {
    $c = pusherConfig();
    return $c['app_id'] !== '' && $c['key'] !== '' && $c['secret'] !== '' && $c['cluster'] !== '';
}

/**
 * Fire one event to a Pusher channel via the REST API (raw signed cURL — no
 * library, cPanel-friendly). Best-effort: never throws, so a Pusher outage can
 * never break a chat send (the DB write already happened; polling still backs it up).
 *
 * Pusher channel names must be [A-Za-z0-9_\-=@,.;]; our thread ids (channel_xxx,
 * dm_<md5>) already satisfy that. We prefix with "chat-" for clarity.
 */
function pusherTrigger(string $threadId, string $event, array $payload): void {
    pusherTriggerRaw('chat-' . preg_replace('/[^A-Za-z0-9_\-]/', '', $threadId), $event, $payload);
}

// Notification bell — one channel per user, carries every notification type
// (chat mentions/DMs/messages, ticket_new, ticket_reply, lead-based ones...).
function pusherNotifyUser(string $userId, array $notif): void {
    pusherTriggerRaw('notif-' . preg_replace('/[^A-Za-z0-9_\-]/', '', $userId), 'notification', $notif);
}

// Help & Support ticket thread — live-appends replies while a ticket is open,
// same UX as chat, independent of the per-user bell channel above.
function pusherTriggerTicket(string $ticketId, string $event, array $payload): void {
    pusherTriggerRaw('ticket-' . preg_replace('/[^A-Za-z0-9_\-]/', '', $ticketId), $event, $payload);
}

// Raw signed Pusher REST call, shared by all the wrappers above. Best-effort:
// never throws, so a Pusher outage can never break a save (DB write already
// happened; polling still backs everything here up).
function pusherTriggerRaw(string $channel, string $event, array $payload): void {
    if (!pusherEnabled()) return;
    $c = pusherConfig();

    $body = json_encode([
        'name'     => $event,
        'channel'  => $channel,
        'data'     => json_encode($payload, JSON_UNESCAPED_UNICODE),
    ], JSON_UNESCAPED_UNICODE);

    $bodyMd5   = md5($body);
    $timestamp = time();
    $path      = "/apps/{$c['app_id']}/events";

    $params = [
        'auth_key'       => $c['key'],
        'auth_timestamp' => $timestamp,
        'auth_version'   => '1.0',
        'body_md5'       => $bodyMd5,
    ];
    ksort($params);
    $queryString = http_build_query($params);
    $stringToSign = "POST\n{$path}\n{$queryString}";
    $signature = hash_hmac('sha256', $stringToSign, $c['secret']);

    $url = "https://api-{$c['cluster']}.pusher.com{$path}?{$queryString}&auth_signature={$signature}";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 4,
        CURLOPT_CONNECTTIMEOUT => 3,
    ]);
    curl_exec($ch);
    curl_close($ch);
}

$_chatChannelsCache = null;
function getChatChannels(): array {
    global $_chatChannelsCache;
    if ($_chatChannelsCache !== null) return $_chatChannelsCache;
    $channels = dbLoadAll('chat_channels');
    if (empty($channels)) {
        $channels = [
            ['id'=>'channel_general','name'=>'general','description'=>'Company-wide chat','members'=>[],'created_by'=>'system','created_at'=>date('c')],
            ['id'=>'channel_deals','name'=>'deals','description'=>'Deal updates','members'=>[],'created_by'=>'system','created_at'=>date('c')],
        ];
        dbSaveAll('chat_channels', $channels);
    }
    $_chatChannelsCache = $channels;
    return $_chatChannelsCache;
}

function saveChatChannels(array $channels): void {
    global $_chatChannelsCache;
    dbSaveAll('chat_channels', array_values($channels));
    $_chatChannelsCache = array_values($channels);
}

function getChannelMessages(string $threadId): array { return dbLoadMessages($threadId); }
function saveChannelMessages(string $threadId, array $messages): void { dbSaveMessages($threadId, array_values($messages)); }

function getDmThreadId(string $a, string $b): string {
    $ids = [$a, $b];
    sort($ids);
    return 'dm_' . md5($ids[0] . '_' . $ids[1]);
}

// Verify the current user may read/post in a thread — a DM participant, a member
// of a private channel, or an admin (admins/public channels see everything).
function canAccessChatThread(array $user, string $threadId): bool {
    if (strpos($threadId, 'dm_') === 0) {
        foreach (getUsers() as $u) {
            if (($u['id'] ?? '') === $user['id']) continue;
            if (getDmThreadId($user['id'], $u['id']) === $threadId) return true;
        }
        return false;
    }
    foreach (getChatChannels() as $ch) {
        if ($ch['id'] !== $threadId) continue;
        $members = $ch['members'] ?? [];
        return empty($members) || isChatAdmin($user) || in_array($user['id'], $members, true);
    }
    return false; // unknown channel id
}

function getChatUnreadCounts(string $userId): array {
    $counts = [];
    foreach (getChatChannels() as $ch) {
        $members = $ch['members'] ?? [];
        // Only count channels this user can actually see.
        if (!empty($members) && !in_array($userId, $members, true)) {
            $u = null;
            foreach (getUsers() as $cand) { if (($cand['id'] ?? '') === $userId) { $u = $cand; break; } }
            if (!$u || !isChatAdmin($u)) continue;
        }
        $messages = getChannelMessages($ch['id']);
        $last = dbGetLastRead($userId, $ch['id']) ?: '1970-01-01T00:00:00+00:00';
        $counts[$ch['id']] = count(array_filter($messages, fn($m) => ($m['sent_at'] ?? '') > $last && ($m['user_id'] ?? '') !== $userId));
    }
    foreach (getUsers() as $u) {
        if (($u['id'] ?? '') === $userId) continue;
        $threadId = getDmThreadId($userId, $u['id']);
        $messages = getChannelMessages($threadId);
        $last = dbGetLastRead($userId, $threadId) ?: '1970-01-01T00:00:00+00:00';
        $counts[$threadId] = count(array_filter($messages, fn($m) => ($m['sent_at'] ?? '') > $last && ($m['user_id'] ?? '') !== $userId));
    }
    return $counts;
}

// Push a chat notification onto a user's per-user bell. De-dupes on notif_key,
// respects the user's read marker and dismiss-all timestamp.
function pushChatNotification(string $userId, array $notif): void {
    $data = getUserData($userId);
    $data['notifications'] = $data['notifications'] ?? [];

    $threadId = $notif['thread_id'] ?? '';
    if ($threadId !== '') {
        $lastRead = dbGetLastRead($userId, $threadId);
        $msgTime  = $notif['created_at'] ?? '';
        if ($lastRead && $msgTime && $msgTime <= $lastRead) return;
    }

    $dismissedAt = $data['notifications_dismissed_at'] ?? null;
    if ($dismissedAt && ($notif['created_at'] ?? '') < $dismissedAt) return;

    $key = $notif['notif_key'] ?? '';
    if ($key !== '') {
        foreach ($data['notifications'] as $n) {
            if (($n['notif_key'] ?? '') === $key) return;
        }
    }

    $data['notifications'][] = $notif;
    usort($data['notifications'], fn($a,$b) => strtotime($b['created_at']??'0') - strtotime($a['created_at']??'0'));
    $data['notifications'] = array_slice($data['notifications'], 0, 50);
    saveUserData($userId, $data);
    pusherNotifyUser($userId, $notif);
}

// Notify @mentioned users (matches full name or first name, case-insensitive).
function notifyChatMentions(array $sender, string $text, string $threadId, string $threadLabel): void {
    if (strpos($text, '@') === false) return;
    $senderName = $sender['name'] ?? $sender['email'] ?? 'Someone';
    foreach (getUsers() as $u) {
        if (($u['id'] ?? '') === ($sender['id'] ?? '')) continue;
        $fullName  = trim($u['name'] ?? '');
        $firstName = $fullName !== '' ? strtok($fullName, ' ') : '';
        $matched = false;
        foreach (array_filter([$fullName, $firstName]) as $cand) {
            if (preg_match('/@' . preg_quote($cand, '/') . '(?![a-zA-Z0-9_])/i', $text)) { $matched = true; break; }
        }
        if (!$matched) continue;
        pushChatNotification($u['id'], [
            'id'         => 'notif_' . bin2hex(random_bytes(6)),
            'notif_key'  => "mention_{$threadId}_{$u['id']}_" . substr(md5($text), 0, 8),
            'type'       => 'mention',
            'title'      => "{$senderName} mentioned you",
            'body'       => "In {$threadLabel}: " . substr($text, 0, 80),
            'thread_id'  => $threadId,
            'created_at' => date('c'),
            'read'       => false,
        ]);
    }
}

// Fan out new-message notifications to everyone in a thread (except the sender).
// Shared by both chat-messages POST and chat-upload so the two stay identical.
function notifyChatThread(array $sender, string $threadId, string $safeThreadId, array $msg, string $notifBody): void {
    $senderName = $sender['name'] ?? $sender['email'];
    $isDm = strpos($threadId, 'dm_') === 0;
    if ($isDm) {
        foreach (getUsers() as $u) {
            if (($u['id'] ?? '') === $sender['id']) continue;
            if (getDmThreadId($sender['id'], $u['id']) === $threadId) {
                pushChatNotification($u['id'], [
                    'id'         => 'notif_' . bin2hex(random_bytes(6)),
                    'notif_key'  => 'dm_' . $threadId . '_' . $msg['id'],
                    'type'       => 'chat_dm',
                    'title'      => "DM from {$senderName}",
                    'body'       => $notifBody,
                    'thread_id'  => $threadId,
                    'created_at' => date('c'),
                    'read'       => false,
                ]);
                break;
            }
        }
        return;
    }
    $threadLabel = '#' . $safeThreadId;
    $channelMembers = [];
    foreach (getChatChannels() as $ch) {
        if ($ch['id'] === $threadId) { $threadLabel = '#' . $ch['name']; $channelMembers = $ch['members'] ?? []; break; }
    }
    foreach (getUsers() as $u) {
        if (($u['id'] ?? '') === $sender['id']) continue;
        $isPublic = empty($channelMembers);
        if (!$isPublic && !in_array($u['id'], $channelMembers, true)) continue;
        pushChatNotification($u['id'], [
            'id'         => 'notif_' . bin2hex(random_bytes(6)),
            'notif_key'  => 'ch_' . $threadId . '_' . $msg['id'] . '_' . $u['id'],
            'type'       => 'chat_message',
            'title'      => "{$senderName} in {$threadLabel}",
            'body'       => $notifBody,
            'thread_id'  => $threadId,
            'created_at' => date('c'),
            'read'       => false,
        ]);
    }
}
