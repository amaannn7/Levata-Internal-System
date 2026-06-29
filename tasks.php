<?php
/**
 * Task system — shared, company-wide task list.
 * Included by api.php after minutes.php, so it can reuse callLLMForSow()
 * for the extract-tasks LLM call.
 *
 * Tasks come from two sources:
 *   1. Extracted from meeting minutes (Next Steps / commitments → structured list)
 *   2. Created manually from the Tasks page
 *
 * Task ID format: task_0001 (sequential, stored in tasks.json seq).
 * Assignee is a plain name string (not a user ID) so meeting extraction works
 * without needing a name→user lookup.
 */

define('TASKS_FILE', DATA_DIR . '/tasks.json');

/** Read the shared task store. Shape: ['tasks' => [...], 'seq' => n]. */
function getTasksStore() {
    if (!file_exists(TASKS_FILE)) {
        return ['tasks' => [], 'seq' => 0];
    }
    $store = json_decode(file_get_contents(TASKS_FILE), true);
    if (!is_array($store)) $store = [];
    if (!isset($store['tasks']) || !is_array($store['tasks'])) $store['tasks'] = [];
    $store['seq'] = (int) ($store['seq'] ?? 0);
    return $store;
}

/** Write the shared task store with an exclusive lock. */
function saveTasksStore($store) {
    $fp = fopen(TASKS_FILE, 'c');
    if (flock($fp, LOCK_EX)) {
        ftruncate($fp, 0);
        fwrite($fp, json_encode($store, JSON_PRETTY_PRINT));
        fflush($fp);
        flock($fp, LOCK_UN);
    }
    fclose($fp);
}

/** Generate the next task ID: task_0001, task_0002, … */
function nextTaskId($store) {
    $store['seq'] = ($store['seq'] ?? 0) + 1;
    return [$store, 'task_' . str_pad($store['seq'], 4, '0', STR_PAD_LEFT)];
}

/**
 * Extract tasks from finished meeting minutes markdown via LLM.
 * Returns a plain array of task objects (not saved — caller reviews first).
 */
function extractTasksFromMinutes($provider, $apiKey, $minutesMarkdown, $client = '') {
    $system = <<<'PROMPT'
You extract action items from meeting minutes and return them as a JSON array. You are precise and faithful — only extract tasks that were genuinely committed to by someone in the meeting. Do not invent tasks, guess owners, or pad the list.

Return ONLY a valid JSON array with no preamble, no code fences, no commentary. Each element:
{
  "title": "short imperative description of the action (max 12 words)",
  "assignee": "full name of the person responsible, exactly as it appears in the minutes, or empty string if unclear",
  "due_date": "YYYY-MM-DD if a specific date was stated, otherwise empty string",
  "notes": "one short sentence of context if useful, otherwise empty string"
}

Rules:
- Draw from Next Steps and any concrete commitments in Key Points or Decisions.
- Do not duplicate: if the same action appears in multiple sections, include it once.
- If a Next Step says "Party A to coordinate with Party B to do X" — one task, assignee = Party A.
- If no tasks exist, return an empty array [].
PROMPT;

    $clientHint = $client ? "Client: $client\n\n" : '';
    $user = $clientHint . "MEETING MINUTES:\n\n" . $minutesMarkdown;

    $res = callLLMForSow($provider, $apiKey, $system, $user);
    if (!$res['success']) return $res;

    $raw = trim($res['content']);
    // Strip markdown code fences if the model wrapped the JSON anyway.
    $raw = preg_replace('/^```(?:json)?\s*/i', '', $raw);
    $raw = preg_replace('/\s*```$/', '', $raw);
    $raw = trim($raw);

    $tasks = json_decode($raw, true);
    if (!is_array($tasks)) {
        // Try to recover a JSON array from anywhere in the response.
        if (preg_match('/\[.*\]/s', $raw, $m)) {
            $tasks = json_decode($m[0], true);
        }
    }
    if (!is_array($tasks)) {
        return ['success' => false, 'error' => 'Could not parse task list from AI response.'];
    }

    // Sanitise each task.
    $clean = [];
    foreach ($tasks as $t) {
        if (!is_array($t)) continue;
        $title = trim($t['title'] ?? '');
        if ($title === '') continue;
        $clean[] = [
            'title'    => $title,
            'assignee' => trim($t['assignee'] ?? ''),
            'due_date' => trim($t['due_date'] ?? ''),
            'notes'    => trim($t['notes'] ?? ''),
        ];
    }

    return ['success' => true, 'tasks' => $clean];
}
