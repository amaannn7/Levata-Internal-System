<?php
/**
 * Document Studio — SOW generation + shared document infrastructure.
 * Included by api.php. Provides:
 *   - sowSystemPrompt(), sowTemplate()
 *   - generateSow($provider, $apiKey, $input)
 *   - refineSow($provider, $apiKey, $markdown, $instruction)
 *   - callLLMForSow() — like callLLM but with a larger token budget, since a
 *     12-section SOW exceeds the default 2048 cap used elsewhere.
 *   - Fireflies integration: firefliesListMeetings(), firefliesFetchTranscript()
 *   - extractSowInput() — transcript -> SOW form fields
 *   - nextDocumentNumber() — sequential CP-0001 / SOW-0001 numbering
 *
 * Cost Proposal functions live in cp.php (included separately by api.php).
 */

/**
 * Default model per provider (current, newest IDs as of 2026).
 * Single source of truth so SOW, Cost Proposal, research, and email all agree.
 */
function defaultModelFor($provider) {
    switch ($provider) {
        case 'groq':      return 'llama-3.3-70b-versatile';
        case 'anthropic': return 'claude-opus-4-8';
        case 'gemini':    return 'gemini-3.5-flash';
        default:          return '';
    }
}

/**
 * The model to actually use for a provider: the admin-chosen model from settings
 * (data/admin.json -> "{provider}_model"), or the current default if none chosen.
 */
function chosenModelFor($provider) {
    $admin = function_exists('getAdmin') ? getAdmin() : [];
    $picked = trim($admin[$provider . '_model'] ?? '');
    return $picked !== '' ? $picked : defaultModelFor($provider);
}

/* ================= SOW generation ================= */

function sowSystemPrompt() {
    return <<<'PROMPT'
You are a senior proposal writer at Levata, a premium design and engineering studio (tagline: "Intelligence, Built."). You produce client-ready Statements of Work (SOWs).

Voice and standards:
- Calm, refined, confident, and conversion-focused. Clear and unhurried, never salesy or padded.
- British/international English spelling. No emoji. No marketing cliches.
- Do NOT use em dashes or en dashes in ordinary prose sentences. Rewrite such sentences using commas, parentheses, a colon, or two shorter sentences. (Numeric/date ranges in tables, e.g. "Weeks 1-2", may use a hyphen.) EXCEPTION: the fixed document patterns below DO use an em dash and you must keep it exactly: Section 5 step bullets ("**Discovery —** aligning on...") and the Section 10 Fees milestone labels ("**Advance — 50%**", "**Balance — 50%**").
- Concrete and specific: turn the client's inputs into precise scope, deliverables, and assumptions. Where the inputs are thin, expand sensibly with professional, industry-standard detail, but never invent fees, dates, or commitments the inputs do not support.

Output rules:
- PROJECT TYPE FIRST: before writing, infer what kind of engagement this is from the brief (website, web app or software system, mobile app, branding/identity, design, content, or other). This template was derived from a website project but must fit ANY project type. Adapt the wording of every section to the actual project: do not mention website-only concepts (pages, wireframes, site maps, domains, hosting, plugins) unless the project really is a website. For a software system talk about modules, integrations, data, APIs, and releases; for branding talk about concepts, design systems, and assets. Keep the legal sections (Fees structure, MSA relationship, sign-off) unchanged.
- Return ONLY the finished SOW as clean GitHub-flavoured Markdown. No preamble, no code fences, no commentary before or after.
- Include EVERY section of the template, in order, with its heading and number. Never drop a section.
- Replace every {{placeholder}} with real content. Never leave a {{placeholder}} or a guidance/placeholder line in the output.
- Section 1 (Engagement Overview): two short paragraphs. The first says what Levata will build and the outcome it drives; the second adds context about the client or their audience. Plain prose, no bullets.
- Section 2 (Objectives): a bulleted list of 4 to 6 concrete single-sentence bullets, each starting with "- ".
- Section 3 (Scope of Work): expand into numbered sub-clauses. Each sub-clause MUST be its own paragraph on its own line, separated by a blank line, and MUST begin with a bold lead-in in the exact form "**3.1 Title.**", followed by one or two plain sentences. Never run them together; never omit the bold markers.
- Section 5 (Levata Approach): an intro line, then a bulleted list. Each bullet is a bold step name followed by an em dash and a short description, in the EXACT form "- **Discovery —** aligning on vision, audience, goals, and competitive context." Keep the em dash. Use 4 to 7 stages fitting the project type (e.g. Discovery, Architecture, Wireframing/Design, Development, QA & Testing, Deployment).
- Sections 2, 6, 7, 8 are bulleted lists (lines starting with "- "), never paragraphs or tables.
- Section 4 (Architecture): NOT website-only. Infer the project type, then adapt the heading and the table's column labels: Website -> "Site Architecture", Page | Purpose; Software/system -> "System Architecture", Module | Function; Mobile app -> "App Architecture", Screen | Purpose; Branding/other -> "Deliverable Breakdown", Item | Description. Replace {{architectureHeading}}, {{architectureColLabel}}, {{architectureColPurpose}} accordingly.
- Section 9 (Timeline): the table has EXACTLY two columns, Phase and Activity. The Phase column is the TIME PERIOD in weeks (e.g. "Weeks 1-2", "Week 6"), NOT a stage name. Bold the Phase cell. Never add a third column.
- Engagement field (top table): a SHORT one-line description of the work (e.g. "Design and development of a new website", "Brand identity and design system"), NOT the project name and NOT a sentence with a full stop.
- Section 10 (Fees): the intro line must state the total investment with the numeric amount AND the amount written out in words in parentheses, e.g. "LKR 450,000 (four hundred and fifty thousand Sri Lankan Rupees)". The table's third column header MUST be "Amount (CUR)" where CUR is the currency from the investment (e.g. "Amount (LKR)"). Compute the 50% advance and 50% balance as actual numbers from the total if a numeric amount is given (e.g. 225,000); otherwise write "50% of total". Bold the first column of each row. Use "Due upon delivery" (or "Due upon deployment" for a website) as the balance trigger.
- In the Section 4 and Section 9 tables, bold the first column of each data row with ** **.
- Keep all tables as valid GitHub-flavoured Markdown tables (pipe-delimited, with a header divider row).
PROMPT;
}

function sowTemplate() {
    return <<<'TPL'
# STATEMENT OF WORK
## {{sowNumber}} · {{projectName}}
*Intelligence, Built.*

| Field | Detail |
| --- | --- |
| **Client** | {{clientName}} |
| **Service Provider** | Levata, a brand of Unknwn Global (Pvt) Ltd, 21A, 17th Lane, Colombo 03, Sri Lanka (levatahq.com) |
| **Project ID** | {{projectId}} |
| **Engagement** | {{engagement}} |
| **Investment** | {{investment}} (excluding applicable taxes and third-party costs) |
| **Estimated duration** | {{timeline}} |
| **Effective date** | {{effectiveDate}} |

> **Governed by the MSA.** This Statement of Work is issued under, incorporates, and is subject to the Master Services Agreement between Levata and {{clientName}}. Capitalised terms not defined here have the meaning given in that Agreement. Where this SOW and the Agreement conflict, the Agreement prevails unless this SOW expressly states otherwise.

## 1. Engagement Overview
{{description}}

## 2. Objectives
Write four to six concrete, single-sentence bullets capturing what the work must achieve for the Client, framed around the outcomes that matter for this project type.

## 3. Scope of Work
Break the scope into numbered sub-clauses derived from the scope highlights below, each with a bold lead-in.

Scope highlights provided by the Client:
{{scopeHighlights}}

## 4. {{architectureHeading}}
Present the planned structure of the solution as a table, adapted to what is being built. Confirm the final structure is set during the architecture phase.

| {{architectureColLabel}} | {{architectureColPurpose}} |
| --- | --- |
| _…_ | _List each part of the solution and a one-line purpose, derived from the scope._ |

## 5. Levata Approach
Our process is built around partnership: the Client stays informed and in control at every stage while Levata manages the complexity of execution.

Write the stages as a bulleted list. Each bullet is a bold step name, then an em dash, then a short description, in the exact form "- **Discovery —** aligning on vision, audience, goals, and competitive context." Use four to seven stages fitting the project type.

## 6. Deliverables
{{deliverables}}

## 7. Out of Scope
The following are not included in this SOW and will be costed separately if required:

List the exclusions relevant to THIS project type. Always include, where applicable: third-party costs and licences billed directly to the Client, and ongoing maintenance or support beyond the warranty period in the MSA.

## 8. Assumptions & Client Dependencies
{{assumptions}}

## 9. Timeline
Estimated duration is {{timeline}}, subject to timely Client feedback and content supply.

| Phase | Activity |
| --- | --- |
| **Weeks 1-2** | _The activities in this period._ |

## 10. Fees & Payment
Total investment: {{investment}} (write the amount in words in parentheses here), covering the full scope as outlined. This amount excludes applicable taxes and the third-party costs listed under Out of Scope.

| Milestone | Trigger | Amount |
| --- | --- | --- |
| **Advance — 50%** | Due at project kickoff | _50% of the total_ |
| **Balance — 50%** | Due upon final delivery | _50% of the total_ |
| **Total** | | _the total investment_ |

Work commences once the advance payment is received in cleared funds. Payments are made by bank transfer to the details provided on the invoice, on the terms set out in the MSA.

## 11. Acceptance & Revisions
**11.1** Deliverables are submitted for Client review at the key milestones of this engagement. The Client will provide consolidated feedback or written approval within the agreed review window for each stage.

**11.2** A deliverable is deemed accepted on the Client's written approval, or if the Client does not provide consolidated feedback within the agreed review window.

**11.3** Revisions within the agreed scope are included. Changes that expand scope (additional features, deliverables, or revision rounds beyond those agreed) are handled through change control under the MSA and may affect Fees and timeline.

**11.4** Post-delivery defect correction is provided as set out in the warranty clause of the MSA.

## 12. Relationship to the Master Services Agreement
This SOW forms part of, and is governed by, the Master Services Agreement between the Parties, including its terms on intellectual property and usage rights, confidentiality, warranties, limitation of liability, and governing law. On full payment of the Fees, ownership of the final Deliverables passes to the Client as set out in the Agreement, excluding Levata Background IP and third-party materials, for which the Client receives the licence described in the Agreement.

---

### Acceptance & Sign-Off

By signing below, the Parties agree to this Statement of Work and to its incorporation under the Master Services Agreement.

| For Levata | For {{clientName}} |
| --- | --- |
| **Service Provider** | **Client** |
| **Unknwn Global (Pvt) Ltd, Levata** | **{{clientName}}** |
| **Signature:** ___________________ | **Signature:** ___________________ |
| **Name:** Shameer Refai | **Name:** {{contactPerson}} |
| **Title:** Chief Executive Officer | **Title:** ___________________ |
| **Date:** {{date}} | **Date:** ___________________ |

*Levata · levatahq.com · hello@levatahq.com · Confidential*
TPL;
}

/** Turn an array of strings into a markdown bullet list. */
function sowBulletList($items) {
    if (!is_array($items)) return '';
    $clean = array_filter(array_map('trim', $items));
    if (empty($clean)) return '_To be defined during the discovery phase._';
    return implode("\n", array_map(function ($i) { return "- $i"; }, $clean));
}

/** Fill the template placeholders from the form input. */
function sowFillTemplate($template, $input) {
    $effectiveDate = '______ / ______ / ' . date('Y');
    $map = [
        'sowNumber'     => ($input['sowNumber'] ?? '') ?: 'SOW',
        'projectName'   => ($input['projectName'] ?? '') ?: 'Untitled Project',
        'projectId'     => ($input['projectId'] ?? '') ?: 'To be confirmed',
        'clientName'    => ($input['clientName'] ?? '') ?: 'Client',
        'contactPerson' => ($input['contactPerson'] ?? '') ?: 'To be confirmed',
        'engagement'    => ($input['engagement'] ?? '') ?: (($input['projectName'] ?? '') ?: 'The engagement described in this SOW'),
        'description'   => ($input['description'] ?? '') ?: 'Provide an engagement overview describing the project and its purpose.',
        'scopeHighlights' => sowBulletList($input['scopeHighlights'] ?? []),
        'deliverables'  => sowBulletList($input['deliverables'] ?? []),
        'timeline'      => ($input['timeline'] ?? '') ?: 'to be confirmed at kickoff',
        'assumptions'   => ($input['assumptions'] ?? '') ?: 'The Client will provide content, branding assets, and timely feedback.',
        'investment'    => ($input['investment'] ?? '') ?: 'to be confirmed',
        'date'          => $effectiveDate,
        'effectiveDate' => $effectiveDate,
        'architectureHeading'    => 'Solution Architecture',
        'architectureColLabel'   => 'Component',
        'architectureColPurpose' => 'Purpose',
    ];
    return preg_replace_callback('/\{\{(\w+)\}\}/', function ($m) use ($map) {
        return array_key_exists($m[1], $map) ? $map[$m[1]] : $m[0];
    }, $template);
}

/** Build the full user prompt: filled template + raw project details. */
function buildSowUserPrompt($template, $input) {
    $filled = sowFillTemplate($template, $input);
    $details = "PROJECT DETAILS:\n"
        . "Project name: " . ($input['projectName'] ?? '') . "\n"
        . "Client: " . ($input['clientName'] ?? '') . "\n"
        . "Description: " . ($input['description'] ?? '') . "\n"
        . "Scope highlights: " . implode('; ', $input['scopeHighlights'] ?? []) . "\n"
        . "Deliverables: " . implode('; ', $input['deliverables'] ?? []) . "\n"
        . "Timeline: " . ($input['timeline'] ?? '') . "\n"
        . "Team: " . ($input['team'] ?? '') . "\n"
        . "Assumptions: " . ($input['assumptions'] ?? '') . "\n"
        . "Investment: " . ($input['investment'] ?? '') . "\n";
    return "Fill in and complete the following SOW template using the project details. Follow every output rule.\n\n"
        . "=== TEMPLATE ===\n" . $filled . "\n\n" . $details;
}

/**
 * LLM call for documents: like callLLM but with a larger token budget so the full
 * document is not truncated. Used by both SOW and CP generation (cp.php calls this).
 */
function callLLMForSow($provider, $apiKey, $system, $user) {
    $prompt = $system . "\n\n" . $user;
    $maxTokens = 8000;
    switch ($provider) {
        case 'groq':
            return sowProviderCall('https://api.groq.com/openai/v1/chat/completions', $apiKey, [
                'model' => chosenModelFor('groq'),
                'messages' => [['role' => 'user', 'content' => $prompt]],
                'max_tokens' => $maxTokens,
                'temperature' => 0.4,
            ], 'openai');
        case 'anthropic':
            return sowProviderCall('https://api.anthropic.com/v1/messages', $apiKey, [
                'model' => chosenModelFor('anthropic'),
                'max_tokens' => $maxTokens,
                'system' => $system,
                'messages' => [['role' => 'user', 'content' => $user]],
            ], 'anthropic');
        case 'gemini':
            $model = chosenModelFor('gemini');
            $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent?key=' . urlencode($apiKey);
            return sowProviderCall($url, null, [
                'system_instruction' => ['parts' => [['text' => $system]]],
                'contents' => [['role' => 'user', 'parts' => [['text' => $user]]]],
                'generationConfig' => ['maxOutputTokens' => 32000, 'temperature' => 0.4],
            ], 'gemini');
        default:
            return ['success' => false, 'error' => 'Unknown provider'];
    }
}

/** Shared curl call + response extraction for the three provider shapes. */
function sowProviderCall($url, $apiKey, $payload, $shape) {
    $headers = ['Content-Type: application/json'];
    if ($shape === 'openai')    $headers[] = 'Authorization: Bearer ' . $apiKey;
    if ($shape === 'anthropic') {
        $headers[] = 'x-api-key: ' . $apiKey;
        $headers[] = 'anthropic-version: 2023-06-01';
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 120,
    ]);
    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);
    if ($error) return ['success' => false, 'error' => $error];
    $r = json_decode($response, true);

    if ($shape === 'openai') {
        $text = $r['choices'][0]['message']['content'] ?? null;
    } elseif ($shape === 'anthropic') {
        $text = $r['content'][0]['text'] ?? null;
    } else {
        $text = $r['candidates'][0]['content']['parts'][0]['text'] ?? null;
    }
    if (!$text) {
        return ['success' => false, 'error' => $r['error']['message'] ?? 'AI returned an empty response'];
    }
    return ['success' => true, 'content' => trim($text)];
}

/** Generate a SOW from the intake form input. */
function generateSow($provider, $apiKey, $input) {
    $user = buildSowUserPrompt(sowTemplate(), $input);
    return callLLMForSow($provider, $apiKey, sowSystemPrompt(), $user);
}

/** Refine an existing SOW per an instruction. */
function refineSow($provider, $apiKey, $markdown, $instruction) {
    $system = "You are a senior proposal writer at Levata. You revise an existing Statement of Work according to an instruction. Apply the change faithfully while keeping the document's structure, headings, tables, tone, formatting (bold lead-ins, bullet lists, bold first-column table cells), and confidential footer intact. Keep everything the instruction does not ask you to change. Do NOT introduce em dashes into ordinary prose, but KEEP the existing em dashes in the Section 5 step bullets ('**Discovery —** ...') and the Section 10 Fees labels ('**Advance — 50%**'). Return ONLY the full revised SOW as clean GitHub-flavoured Markdown, no preamble or commentary.";
    $user = "CURRENT SOW:\n\n$markdown\n\n=== INSTRUCTION ===\n$instruction";
    return callLLMForSow($provider, $apiKey, $system, $user);
}

/* ================= Fireflies + transcript extraction ================= */

/** Core Fireflies GraphQL call (Bearer auth). */
function firefliesQuery($apiKey, $query, $variables = null) {
    $payload = ['query' => $query];
    if ($variables !== null) $payload['variables'] = $variables;
    $ch = curl_init('https://api.fireflies.ai/graphql');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
        CURLOPT_TIMEOUT => 60,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($error) return ['success' => false, 'error' => 'Could not reach Fireflies. ' . $error];
    if ($httpCode === 401 || $httpCode === 403) {
        return ['success' => false, 'error' => 'Fireflies rejected the API key. Check it in Settings.'];
    }
    if ($httpCode === 429) {
        return ['success' => false, 'error' => 'Fireflies rate limit reached. Wait a moment and try again.'];
    }
    $r = json_decode($response, true);
    if (!empty($r['errors'])) {
        return ['success' => false, 'error' => $r['errors'][0]['message'] ?? 'Fireflies returned an error.'];
    }
    if (!isset($r['data'])) return ['success' => false, 'error' => 'Fireflies returned no data.'];
    return ['success' => true, 'data' => $r['data']];
}

/** List the most recent meetings for the picker. */
function firefliesListMeetings($apiKey, $limit = 15) {
    $query = 'query Recent($limit: Int) { transcripts(limit: $limit) { id title date } }';
    $res = firefliesQuery($apiKey, $query, ['limit' => $limit]);
    if (!$res['success']) return $res;
    $list = $res['data']['transcripts'] ?? [];
    $meetings = [];
    foreach ($list as $t) {
        if (empty($t['id'])) continue;
        $date = $t['date'] ?? 0;
        if (is_string($date)) { $ts = strtotime($date); $date = $ts ? $ts * 1000 : 0; }
        $meetings[] = ['id' => $t['id'], 'title' => trim($t['title'] ?? '') ?: 'Untitled meeting', 'date' => $date];
    }
    return ['success' => true, 'meetings' => $meetings];
}

/** Fetch one transcript and flatten it (summary + spoken lines) to plain text. */
function firefliesFetchTranscript($apiKey, $id) {
    $query = 'query One($id: String!) { transcript(id: $id) { title summary { overview action_items keywords } sentences { speaker_name text } } }';
    $res = firefliesQuery($apiKey, $query, ['id' => $id]);
    if (!$res['success']) return $res;
    $t = $res['data']['transcript'] ?? null;
    if (!$t) return ['success' => false, 'error' => 'That meeting could not be found.'];

    $title = trim($t['title'] ?? '') ?: 'Meeting';
    $summaryParts = ["MEETING: $title"];
    $hasSummary = false;
    if (!empty($t['summary']['overview']))      { $summaryParts[] = "\nSUMMARY:\n" . trim($t['summary']['overview']); $hasSummary = true; }
    if (!empty($t['summary']['action_items']))  { $summaryParts[] = "\nACTION ITEMS:\n" . trim($t['summary']['action_items']); $hasSummary = true; }
    if (!empty($t['summary']['keywords'])) {
        $kw = $t['summary']['keywords'];
        $kwText = is_array($kw) ? implode(', ', $kw) : (string)$kw;
        if (trim($kwText)) { $summaryParts[] = "\nKEYWORDS: " . trim($kwText); $hasSummary = true; }
    }

    if ($hasSummary) {
        return ['success' => true, 'title' => $title, 'text' => trim(implode("\n", $summaryParts))];
    }

    $sentences = [];
    foreach (($t['sentences'] ?? []) as $s) {
        $said = trim($s['text'] ?? '');
        if (!$said) continue;
        $who = trim($s['speaker_name'] ?? '');
        $sentences[] = $who ? "$who: $said" : $said;
    }
    $joined = implode("\n", $sentences);
    if (strlen($joined) > 6000) $joined = substr($joined, 0, 6000) . "\n[... transcript truncated ...]";
    if ($joined) $summaryParts[] = "\nTRANSCRIPT:\n" . $joined;

    $text = trim(implode("\n", $summaryParts));
    if (!$text || $text === "MEETING: $title") return ['success' => false, 'error' => 'That meeting has no readable transcript yet.'];
    return ['success' => true, 'title' => $title, 'text' => $text];
}

/** System prompt for extracting SOW form fields from meeting notes. */
function extractSystemPrompt() {
    return <<<'P'
You extract structured project details from a client meeting transcript or notes so they can pre-fill a Statement of Work form.

Return ONLY a single JSON object (no prose, no code fences) with EXACTLY these keys:
{ "projectName": string, "projectId": string, "clientName": string, "contactPerson": string, "email": string, "engagement": string, "description": string, "scopeHighlights": string[], "timeline": string, "deliverables": string[], "team": string, "assumptions": string, "investment": string }

Rules:
- Extract only what the transcript actually supports. If something is not mentioned, use an empty string "" (or [] for list fields). NEVER invent a client name, fee, date, contact, or commitment not in the notes.
- engagement: a SHORT one-line label for the work (e.g. "Design and development of a new website"), not a full sentence.
- description: 2 to 4 sentences summarising what is being built and why. This is the only field you may lightly paraphrase.
- scopeHighlights / deliverables: one short string each.
- Do NOT use em dashes.
- Output must be valid JSON that parses. Use straight double quotes. No trailing commas.
P;
}

/** Extract SOW form fields from a transcript. */
function extractSowInput($provider, $apiKey, $transcript) {
    $cap = $provider === 'groq' ? 4500 : 40000;
    if (strlen($transcript) > $cap) $transcript = substr($transcript, 0, $cap) . "\n[... notes truncated to fit the model ...]";
    $user = "Extract the SOW form fields from the meeting notes below. Return only the JSON object.\n\n--- MEETING NOTES ---\n$transcript\n--- END ---";
    $res = callLLMForSow($provider, $apiKey, extractSystemPrompt(), $user);
    if (!$res['success']) return $res;

    $raw = $res['content'];
    $start = strpos($raw, '{');
    $end = strrpos($raw, '}');
    if ($start === false || $end === false || $end <= $start) {
        return ['success' => false, 'error' => 'The model did not return valid JSON for the notes.'];
    }
    $obj = json_decode(substr($raw, $start, $end - $start + 1), true);
    if (!is_array($obj)) return ['success' => false, 'error' => 'The model did not return valid JSON.'];

    $str = function ($v) { return is_string($v) ? trim($v) : ''; };
    $arr = function ($v) { return is_array($v) ? array_values(array_filter(array_map(function ($x) { return is_string($x) ? trim($x) : ''; }, $v))) : []; };
    $input = [
        'projectName'    => $str($obj['projectName'] ?? ''),
        'projectId'      => $str($obj['projectId'] ?? ''),
        'clientName'     => $str($obj['clientName'] ?? ''),
        'contactPerson'  => $str($obj['contactPerson'] ?? ''),
        'email'          => $str($obj['email'] ?? ''),
        'engagement'     => $str($obj['engagement'] ?? ''),
        'description'    => $str($obj['description'] ?? ''),
        'scopeHighlights'=> $arr($obj['scopeHighlights'] ?? []),
        'timeline'       => $str($obj['timeline'] ?? ''),
        'deliverables'   => $arr($obj['deliverables'] ?? []),
        'team'           => $str($obj['team'] ?? ''),
        'assumptions'    => $str($obj['assumptions'] ?? ''),
        'investment'     => $str($obj['investment'] ?? ''),
    ];
    return ['success' => true, 'input' => $input];
}

/* ================= Shared document numbering ================= */

/**
 * Returns the prefix for a given document type (SOW, CP, INV).
 * Used by nextDocumentNumber() and api.php to generate human-readable doc IDs.
 */
function documentNumberPrefix($type) {
    switch ($type) {
        case 'cost-proposal':
        case 'cost_proposal':
        case 'cp':
            return 'CP';
        case 'invoice':
            return 'INV';
        case 'sow':
        default:
            return 'SOW';
    }
}

function nextDocumentNumber($documents, $type) {
    $prefix = documentNumberPrefix($type);
    $max = 0;
    foreach (($documents ?? []) as $d) {
        $no = $d['doc_no'] ?? '';
        if (preg_match('/^' . preg_quote($prefix, '/') . '-(\d+)$/', $no, $m)) {
            $n = (int) $m[1];
            if ($n > $max) $max = $n;
        }
    }
    return sprintf('%s-%04d', $prefix, $max + 1);
}

/* ================= Shared document store (company-wide) =================
 * Documents (Cost Proposals + SOWs) used to live per-user in user_*.json.
 * They are now stored in a single shared file so the whole team can see every
 * document, the same way the Job Registry (jobs.json) is shared. Doc numbering
 * (CP-0001 / SOW-0001) is global across the company so numbers never collide.
 */

define('DOCS_FILE', DATA_DIR . '/documents.json');

/** Read the shared documents store. Shape: ['documents' => [...]]. */
function getDocsStore() {
    if (!file_exists(DOCS_FILE)) {
        // One-time migration: gather any documents that still live in user_*.json.
        $store = migrateDocsFromUsers();
        saveDocsStore($store);
        return $store;
    }
    $store = json_decode(file_get_contents(DOCS_FILE), true);
    if (!is_array($store)) $store = [];
    if (!isset($store['documents']) || !is_array($store['documents'])) $store['documents'] = [];
    return $store;
}

/** Write the shared documents store with an exclusive lock (mirrors saveJobsStore). */
function saveDocsStore($store) {
    $fp = fopen(DOCS_FILE, 'c');
    if (flock($fp, LOCK_EX)) {
        ftruncate($fp, 0);
        fwrite($fp, json_encode($store, JSON_PRETTY_PRINT));
        fflush($fp);
        flock($fp, LOCK_UN);
    }
    fclose($fp);
}

/** Convenience: just the documents array from the shared store. */
function getAllDocuments() {
    $store = getDocsStore();
    return $store['documents'];
}

/**
 * Build the initial shared store from the per-user files (run once, when
 * documents.json does not yet exist). Stamps owner info from users.json and
 * keeps each document's existing id and doc_no so all existing links survive.
 */
function migrateDocsFromUsers() {
    $usersById = [];
    if (function_exists('getUsers')) {
        foreach (getUsers() as $usr) {
            $usersById[$usr['id'] ?? ''] = $usr['name'] ?? ($usr['email'] ?? '');
        }
    }
    $documents = [];
    foreach (glob(DATA_DIR . '/user_*.json') as $file) {
        // The user id is the whole filename between "user_" and ".json" (ids are not
        // always hex, e.g. "user_levata_dev_amaan"), so match greedily.
        $ownerId = preg_match('/user_(.+)\.json$/', basename($file), $m) ? $m[1] : '';
        $d = json_decode(@file_get_contents($file), true);
        if (!is_array($d) || empty($d['documents']) || !is_array($d['documents'])) continue;
        foreach ($d['documents'] as $doc) {
            if (empty($doc['owner_id'])) $doc['owner_id'] = $ownerId;
            if (empty($doc['owner'])) $doc['owner'] = $usersById[$ownerId] ?? '';
            $documents[] = $doc;
        }
    }
    return ['documents' => $documents];
}

/** Resolve a user's display name from users.json (for stamping document owners). */
function docOwnerName($userId) {
    if (!function_exists('getUsers')) return '';
    foreach (getUsers() as $usr) {
        if (($usr['id'] ?? '') === $userId) return $usr['name'] ?? ($usr['email'] ?? '');
    }
    return '';
}
