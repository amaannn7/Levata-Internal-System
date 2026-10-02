<?php
/**
 * Document Studio — SOW generation + shared document infrastructure.
 * Included by api.php. Provides:
 *   - sowSystemPrompt(), sowTemplate()
 *   - generateSow($provider, $apiKey, $input)
 *   - refineSow($provider, $apiKey, $markdown, $instruction)
 *   - callLLMForSow() — like callLLM but with a larger token budget, since a
 *     12-section SOW exceeds the default 2048 cap used elsewhere.
 *   - ClickUp integration: clickupListMeetingDocs(), clickupFetchDocText(), clickupCreateTask()
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
        case 'groq':      return 'openai/gpt-oss-120b';
        case 'cerebras':  return 'gpt-oss-120b';
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

/**
 * Which provider to try FIRST for document generation (SOW / Cost Proposal),
 * independent of the general default_provider setting. Documents are large:
 * Groq's free-tier TPM cap truncates or 429s them (worse since switching off
 * Llama to GPT-OSS-120B, which has a tighter cap), so Gemini goes first here
 * whenever a Gemini key exists — falling back to the general default_provider
 * only when Gemini isn't configured. llmWithFallback() still retries every
 * other configured provider after this one if it fails, so this only changes
 * which provider is tried FIRST, not the safety net.
 */
function documentProviderAndKey($admin) {
    $geminiKey = trim($admin['gemini_key'] ?? '');
    if ($geminiKey !== '') return ['gemini', $geminiKey];
    $provider = trim($admin['default_provider'] ?? 'groq');
    return [$provider, trim($admin[$provider . '_key'] ?? '')];
}

/* ================= SOW generation ================= */

function sowSystemPrompt() {
    return <<<'PROMPT'
You are a senior proposal writer at Levata, a premium design and engineering studio (tagline: "Intelligence, Built."). You produce client-ready Statements of Work (SOWs).

Voice and standards:
- Calm, refined, confident, and conversion-focused. Clear and unhurried, never salesy or padded.
- British/international English spelling. No emoji. No marketing cliches.
- Do NOT use em dashes or en dashes anywhere in the document, including Section 5 step bullets and the Section 10 Fees milestone labels. Rewrite such sentences using commas, parentheses, a colon, or two shorter sentences. (Numeric/date ranges in tables, e.g. "Weeks 1-2", may use a hyphen.) Section 5 step bullets use a colon instead, in the exact form "- **Discovery:** aligning on...". Section 10 Fees milestone labels use a colon too, in the exact form "**Advance: 50%**", "**Balance: 50%**".
- Concrete and specific: turn the client's inputs into precise scope, deliverables, and assumptions. Where the inputs are thin, expand sensibly with professional, industry-standard detail, but never invent fees, dates, or commitments the inputs do not support.

Output rules:
- PROJECT TYPE FIRST: before writing, infer what kind of engagement this is from the brief (website, web app or software system, mobile app, branding/identity, design, content, or other). This template was derived from a website project but must fit ANY project type. Adapt the wording of every section to the actual project: do not mention website-only concepts (pages, wireframes, site maps, domains, hosting, plugins) unless the project really is a website. For a software system talk about modules, integrations, data, APIs, and releases; for branding talk about concepts, design systems, and assets. Keep the legal sections (Fees structure, MSA relationship, sign-off) unchanged.
- Return ONLY the finished SOW as clean GitHub-flavoured Markdown. No preamble, no code fences, no commentary before or after.
- Include EVERY section of the template, in order, with its heading and number. Never drop a section.
- Replace every {{placeholder}} with real content. Never leave a {{placeholder}} or a guidance/placeholder line in the output.
- Section 1 (Engagement Overview): two short paragraphs. The first says what Levata will build and the outcome it drives; the second adds context about the client or their audience. Plain prose, no bullets.
- Section 2 (Objectives): a bulleted list of 4 to 6 concrete single-sentence bullets, each starting with "- ".
- Section 3 (Scope of Work): expand into numbered sub-clauses. Each sub-clause MUST be its own paragraph on its own line, separated by a blank line, and MUST begin with a bold lead-in in the exact form "**3.1 Title.**", followed by one or two plain sentences. Never run them together; never omit the bold markers.
- Section 5 (Levata Approach): an intro line, then a bulleted list. Each bullet is a bold step name followed by a colon and a short description, in the EXACT form "- **Discovery:** aligning on vision, audience, goals, and competitive context." Do NOT use an em dash. Use 4 to 7 stages fitting the project type (e.g. Discovery, Architecture, Wireframing/Design, Development, QA & Testing, Deployment).
- Sections 2, 6, 7, 8 are bulleted lists (lines starting with "- "), never paragraphs or tables.
- Section 4 (Architecture): NOT website-only. Infer the project type, then adapt the heading and the table's column labels: Website -> "Site Architecture", Page | Purpose; Software/system -> "System Architecture", Module | Function; Mobile app -> "App Architecture", Screen | Purpose; Branding/other -> "Deliverable Breakdown", Item | Description. Replace {{architectureHeading}}, {{architectureColLabel}}, {{architectureColPurpose}} accordingly.
- Section 9 (Timeline): the table has EXACTLY two columns, Phase and Activity. The Phase column is the TIME PERIOD in weeks (e.g. "Weeks 1-2", "Week 6"), NOT a stage name. Bold the Phase cell. Never add a third column.
- Engagement field (top table): a SHORT one-line description of the work (e.g. "Design and development of a new website", "Brand identity and design system"), NOT the project name and NOT a sentence with a full stop.
- Section 10 (Fees) for a ONE-OFF engagement: the intro line must state the total investment with the numeric amount AND the amount written out in words in parentheses, e.g. "LKR 450,000 (four hundred and fifty thousand Sri Lankan Rupees)". The table's third column header MUST be "Amount (CUR)" where CUR is the currency from the investment (e.g. "Amount (LKR)"). Compute the 50% advance and 50% balance as actual numbers from the total if a numeric amount is given (e.g. 225,000); otherwise write "50% of total". Bold the first column of each row. Use "Due upon delivery" (or "Due upon deployment" for a website) as the balance trigger.
- Section 10 (Fees) for a RETAINER engagement: do NOT use the advance/balance milestone structure at all. The FEES SUMMARY block given to you in the project details is authoritative. Write a short intro line stating the pricing structure in plain words (setup fee if any, the recurring retainer amount and its billing basis, minimum term if stated), then a table with columns "Component | Basis | Amount": one row for the one-time setup/onboarding fee (omit this row entirely if no setup fee was given), one row for the recurring retainer (its Basis cell states the billing basis, e.g. "per licence / month" or "flat / month"), and one row per third-party pass-through cost if any were given (Basis cell says "billed separately" or similar; these are NOT included in the Levata total). Bold the first column of each row. After the table, state the minimum term if one was given, and note that pass-through costs (if any) are billed directly and are not part of the Levata fee. Never invent an advance/balance split for a retainer.
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
| **Investment** | {{investmentSummary}} (excluding applicable taxes and third-party costs) |
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

Write the stages as a bulleted list. Each bullet is a bold step name, then a colon, then a short description, in the exact form "- **Discovery:** aligning on vision, audience, goals, and competitive context." Use four to seven stages fitting the project type.

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
| **Advance: 50%** | Due at project kickoff | _50% of the total_ |
| **Balance: 50%** | Due upon final delivery | _50% of the total_ |
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

/**
 * A short one-line investment summary for the top Field|Detail table, valid for
 * either a one-off figure or a retainer (setup fee + recurring billing).
 */
function sowInvestmentSummary($input) {
    if (($input['engagementType'] ?? 'one_off') === 'retainer') {
        $parts = [];
        $setup = trim($input['setupFee'] ?? '');
        if ($setup !== '') $parts[] = $setup . ' setup';
        $retainer = trim($input['retainerAmount'] ?? '');
        if ($retainer !== '') {
            $basis = trim($input['retainerBasis'] ?? '');
            $parts[] = $retainer . ($basis !== '' ? ' (' . $basis . ')' : '') . ' recurring';
        }
        return $parts ? implode(' + ', $parts) : 'To be confirmed';
    }
    return ($input['investment'] ?? '') ?: 'to be confirmed';
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
        'investmentSummary' => sowInvestmentSummary($input),
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
    $engagementType = ($input['engagementType'] ?? 'one_off') === 'retainer' ? 'retainer' : 'one_off';
    $feesSummary = "Engagement type: " . ($engagementType === 'retainer' ? 'RETAINER (recurring billing, no advance/balance split)' : 'ONE-OFF (single total fee, advance/balance split)') . "\n";
    if ($engagementType === 'retainer') {
        $feesSummary .= "Setup / onboarding fee: " . (($input['setupFee'] ?? '') ?: 'none') . "\n"
            . "Recurring retainer amount: " . ($input['retainerAmount'] ?? '') . "\n"
            . "Billing basis: " . (($input['retainerBasis'] ?? '') ?: 'not specified') . "\n"
            . "Minimum term: " . (($input['retainerTerm'] ?? '') ?: 'not specified') . "\n"
            . "Third-party pass-through costs: " . (implode('; ', $input['passthroughCosts'] ?? []) ?: 'none') . "\n";
    } else {
        $feesSummary .= "Total investment: " . ($input['investment'] ?? '') . "\n";
    }
    $details = "PROJECT DETAILS:\n"
        . "Project name: " . ($input['projectName'] ?? '') . "\n"
        . "Client: " . ($input['clientName'] ?? '') . "\n"
        . "Description: " . ($input['description'] ?? '') . "\n"
        . "Scope highlights: " . implode('; ', $input['scopeHighlights'] ?? []) . "\n"
        . "Deliverables: " . implode('; ', $input['deliverables'] ?? []) . "\n"
        . "Timeline: " . ($input['timeline'] ?? '') . "\n"
        . "Team: " . ($input['team'] ?? '') . "\n"
        . "Assumptions: " . ($input['assumptions'] ?? '') . "\n"
        . "FEES SUMMARY (authoritative for Section 10, do not deviate from these figures):\n" . $feesSummary;
    return "Fill in and complete the following SOW template using the project details. Follow every output rule.\n\n"
        . "=== TEMPLATE ===\n" . $filled . "\n\n" . $details;
}

/* =====================================================================
 * Provider fallback (system-wide)
 *
 * Every AI feature used to call a single provider and fail hard when that
 * provider was rate-limited. These helpers resolve an ordered chain of the
 * providers that actually have a key, and retry the next one when a call fails
 * TRANSIENTLY (429 rate limit, 5xx, network). A fatal error (bad key, malformed
 * request) is returned immediately, since retrying elsewhere would fail the same
 * way and would hide a real misconfiguration.
 *
 * Used by callLLM() (short prompts) and callLLMForSow() (documents), which
 * between them back every AI feature: research, emails, call pitches, SOW/CP
 * generation and refinement, minutes, extraction, briefings.
 * ===================================================================== */

/**
 * Is this HTTP status worth retrying on a DIFFERENT provider?
 *
 *   429 rate limit, 5xx outage, 0 network error  -> yes, transient
 *   402 payment required / quota exhausted       -> yes: this provider is
 *       unusable but the others may work, so fall through rather than dead-end
 *   401/403 bad key, 400 bad request             -> no: a real misconfiguration
 *       that would fail identically elsewhere and must be surfaced
 */
function llmIsRetryableStatus($status) {
    $status = (int) $status;
    return $status === 0 || $status === 429 || $status === 402 || $status >= 500;
}

/**
 * Ordered list of ['provider'=>..,'key'=>..] to try, preferred first.
 * Providers without a configured key are skipped entirely.
 */
function llmProviderChain($preferred = '') {
    $admin = function_exists('getAdmin') ? getAdmin() : [];
    $keys = [
        'groq'      => trim($admin['groq_key'] ?? ''),
        'cerebras'  => trim($admin['cerebras_key'] ?? ''),
        'gemini'    => trim($admin['gemini_key'] ?? ''),
        'anthropic' => trim($admin['anthropic_key'] ?? ''),
    ];
    $preferred = trim($preferred) !== '' ? trim($preferred) : trim($admin['default_provider'] ?? 'groq');
    // Cerebras before Groq as the general fallback: far more generous free tier.
    $order = ['cerebras', 'groq', 'gemini', 'anthropic'];
    if (isset($keys[$preferred])) {
        $order = array_merge([$preferred], array_values(array_diff($order, [$preferred])));
    }
    $chain = [];
    foreach ($order as $p) {
        if (($keys[$p] ?? '') !== '') $chain[] = ['provider' => $p, 'key' => $keys[$p]];
    }
    return $chain;
}

/**
 * Run $fn(provider, apiKey) across the chain until one succeeds.
 * $fn must return the app's usual ['success'=>bool, 'content'|'error'=>..] shape,
 * optionally with 'retryable'=>bool. Adds 'provider' (who answered) and
 * 'fell_back_from' (the preferred provider, when a fallback was used).
 */
function llmWithFallback(callable $fn, $preferred = '') {
    $chain = llmProviderChain($preferred);
    if (empty($chain)) {
        return ['success' => false, 'error' => 'No AI provider is configured. Add an API key in Admin > Settings.'];
    }
    $last = null;
    foreach ($chain as $i => $hop) {
        $res = $fn($hop['provider'], $hop['key']);
        if (!empty($res['success'])) {
            $res['provider'] = $hop['provider'];
            if ($i > 0) $res['fell_back_from'] = $chain[0]['provider'];
            return $res;
        }
        $last = $res;
        // Stop on a fatal error; only transient failures move to the next provider.
        if (empty($res['retryable'])) return $res;
    }
    return $last ?: ['success' => false, 'error' => 'All AI providers failed.'];
}

/**
 * LLM call for documents: like callLLM but with a larger token budget so the full
 * document is not truncated. Used by both SOW and CP generation (cp.php calls this).
 */
function callLLMForSow($provider, $apiKey, $system, $user) {
    // $provider/$apiKey are the preference; fail over to other configured
    // providers on a rate limit or outage. Document generation is long and
    // expensive to retry by hand, so this matters most here.
    return llmWithFallback(function ($p, $key) use ($system, $user) {
        return callLLMForSowOnce($p, $key, $system, $user);
    }, $provider);
}

/** One document-sized attempt against one provider. */
function callLLMForSowOnce($provider, $apiKey, $system, $user) {
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
        case 'cerebras':
            return sowProviderCall('https://api.cerebras.ai/v1/chat/completions', $apiKey, [
                'model' => chosenModelFor('cerebras'),
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
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    // 'retryable' tells llmWithFallback() whether trying another provider is
    // worth it: rate limits/outages yes, a bad key or bad request no.
    if ($error) return ['success' => false, 'error' => $error, 'retryable' => true];
    $r = json_decode($response, true);

    if ($shape === 'openai') {
        $text = $r['choices'][0]['message']['content'] ?? null;
    } elseif ($shape === 'anthropic') {
        $text = $r['content'][0]['text'] ?? null;
    } else {
        $text = $r['candidates'][0]['content']['parts'][0]['text'] ?? null;
    }
    if (!$text) {
        // Error shapes differ per provider: OpenAI/Groq nest under "error",
        // Cerebras puts "message" at the top level.
        $detail = $r['error']['message'] ?? ($r['message'] ?? null);
        return [
            'success' => false,
            'error' => $detail ?: 'AI returned an empty response',
            'retryable' => llmIsRetryableStatus($status),
        ];
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
    $system = "You are a senior proposal writer at Levata. You revise an existing Statement of Work according to an instruction. Apply the change faithfully while keeping the document's structure, headings, tables, tone, formatting (bold lead-ins, bullet lists, bold first-column table cells), and confidential footer intact. Keep everything the instruction does not ask you to change. Do NOT use em dashes anywhere in the document. If the existing document contains em dashes (e.g. in Section 5 step bullets or the Section 10 Fees labels), replace them with a colon as part of this revision (e.g. '**Discovery —** ...' becomes '**Discovery:** ...', '**Advance — 50%**' becomes '**Advance: 50%**'). Return ONLY the full revised SOW as clean GitHub-flavoured Markdown, no preamble or commentary.";
    $user = "CURRENT SOW:\n\n$markdown\n\n=== INSTRUCTION ===\n$instruction";
    return callLLMForSow($provider, $apiKey, $system, $user);
}

/* ================= ClickUp + transcript extraction ================= */

/** Core ClickUp REST call (raw personal-token auth, no Bearer prefix). */
function clickupRequest($apiToken, $method, $path, $body = null) {
    $ch = curl_init('https://api.clickup.com/api' . $path);
    $headers = ['Content-Type: application/json', 'Authorization: ' . $apiToken];
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_CUSTOMREQUEST => $method,
    ];
    if ($body !== null) $opts[CURLOPT_POSTFIELDS] = json_encode($body);
    curl_setopt_array($ch, $opts);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($error) return ['success' => false, 'error' => 'Could not reach ClickUp. ' . $error];
    if ($httpCode === 401 || $httpCode === 403) {
        return ['success' => false, 'error' => 'ClickUp rejected the API token. Check it in Settings.'];
    }
    if ($httpCode === 429) {
        return ['success' => false, 'error' => 'ClickUp rate limit reached. Wait a moment and try again.'];
    }
    $r = json_decode($response, true);
    if ($httpCode >= 400) {
        return ['success' => false, 'error' => ($r['err'] ?? null) ?: 'ClickUp returned an error.'];
    }
    if (!is_array($r)) return ['success' => false, 'error' => 'ClickUp returned no data.'];
    return ['success' => true, 'data' => $r];
}

/** List the most recently updated Docs for the meeting picker (AI Notetaker writes meeting notes into a Doc). */
function clickupListMeetingDocs($apiToken, $workspaceId, $limit = 15) {
    // The ClickUp Docs endpoint has no sort/order param, so fetch a larger raw
    // page and sort by recency ourselves before trimming to the requested limit.
    $qs = http_build_query(['limit' => max($limit, 100)]);
    $res = clickupRequest($apiToken, 'GET', "/v3/workspaces/{$workspaceId}/docs?{$qs}");
    if (!$res['success']) return $res;
    $list = $res['data']['docs'] ?? [];
    $meetings = [];
    foreach ($list as $d) {
        if (empty($d['id'])) continue;
        $date = $d['date_updated'] ?? ($d['date_created'] ?? 0);
        $meetings[] = ['id' => $d['id'], 'title' => trim($d['name'] ?? '') ?: 'Untitled doc', 'date' => (int)$date];
    }
    usort($meetings, fn($a, $b) => $b['date'] <=> $a['date']);
    return ['success' => true, 'meetings' => array_slice($meetings, 0, $limit)];
}

/** Fetch one Doc's page content and flatten it to plain text (used as the "transcript"). */
function clickupFetchDocText($apiToken, $workspaceId, $docId) {
    $qs = http_build_query(['content_format' => 'text/plain']);
    $res = clickupRequest($apiToken, 'GET', "/v3/workspaces/{$workspaceId}/docs/{$docId}/pages?{$qs}");
    if (!$res['success']) return $res;
    $pages = $res['data'] ?? [];
    if (!is_array($pages) || !$pages) return ['success' => false, 'error' => 'That doc has no readable content yet.'];

    $title = trim($pages[0]['name'] ?? '') ?: 'Meeting';
    $parts = [];
    foreach ($pages as $p) {
        $content = trim($p['content'] ?? '');
        if ($content !== '') $parts[] = $content;
    }
    $joined = implode("\n\n", $parts);
    if (strlen($joined) > 6000) $joined = substr($joined, 0, 6000) . "\n[... notes truncated ...]";
    if (!$joined) return ['success' => false, 'error' => 'That doc has no readable content yet.'];
    return ['success' => true, 'title' => $title, 'text' => "MEETING: $title\n\n$joined"];
}

/** Create a task in the configured ClickUp List (best-effort push after a local task save). */
function clickupCreateTask($apiToken, $listId, $title, $opts = []) {
    $body = ['name' => $title];
    if (!empty($opts['notes'])) $body['description'] = $opts['notes'];
    if (!empty($opts['due_date'])) {
        $ts = strtotime($opts['due_date']);
        if ($ts) $body['due_date'] = $ts * 1000;
    }
    $res = clickupRequest($apiToken, 'POST', "/v2/list/{$listId}/task", $body);
    if (!$res['success']) return $res;
    $t = $res['data'];
    if (empty($t['id'])) return ['success' => false, 'error' => 'ClickUp did not return a task id.'];
    return ['success' => true, 'id' => $t['id'], 'url' => $t['url'] ?? ''];
}

/** System prompt for extracting SOW form fields from meeting notes. */
function extractSystemPrompt() {
    return <<<'P'
You extract structured project details from a client meeting transcript or notes so they can pre-fill a Statement of Work form.

Return ONLY a single JSON object (no prose, no code fences) with EXACTLY these keys:
{ "projectName": string, "projectId": string, "clientName": string, "contactPerson": string, "email": string, "engagement": string, "description": string, "scopeHighlights": string[], "timeline": string, "deliverables": string[], "team": string, "assumptions": string, "engagementType": string, "investment": string, "setupFee": string, "retainerAmount": string, "retainerBasis": string, "retainerTerm": string, "passthroughCosts": string[] }

Rules:
- Extract only what the transcript actually supports. If something is not mentioned, use an empty string "" (or [] for list fields). NEVER invent a client name, fee, date, contact, or commitment not in the notes.
- projectName: a SHORT label for the type of engagement (e.g. "Website Redesign", "Marketing Plan"), not a sentence.
- projectId: a short internal reference CODE only (e.g. "PID-097"), never a title or description. If the transcript does not state an explicit ID/reference code, leave this "" — do NOT invent one and do NOT reuse the project name or meeting title here.
- engagement: a SHORT one-line label for the work (e.g. "Design and development of a new website"), not a full sentence.
- description: 2 to 4 sentences summarising what is being built and why. This is the only field you may lightly paraphrase.
- scopeHighlights / deliverables: one short string each.
- engagementType: "retainer" if the pricing discussed is a recurring/ongoing arrangement (a monthly or periodic fee, a subscription, a per-licence or per-seat charge, an ongoing service relationship), otherwise "one_off" for a single fixed-scope project with one total fee. Default to "one_off" if unclear.
- If engagementType is "one_off": use ONLY the investment field (see its rule below) and leave setupFee, retainerAmount, retainerBasis, retainerTerm, passthroughCosts all "" / [].
- If engagementType is "retainer": leave investment "" and instead use:
  - setupFee: a one-time setup, onboarding, customization, or implementation fee, as a currency amount, if one was explicitly discussed. Otherwise "".
  - retainerAmount: the recurring fee amount, as a currency amount (e.g. "LKR 75,000"). Otherwise "".
  - retainerBasis: how the recurring fee is charged, in a few words (e.g. "per licence, per month", "flat, monthly", "per seat"). Otherwise "".
  - retainerTerm: any stated minimum commitment period (e.g. "3 months minimum, then month-to-month"). Otherwise "".
  - passthroughCosts: separate third-party costs the client pays that are NOT part of Levata's fee (e.g. a named database/subscription/tool cost mentioned as billed separately, with its billing terms if stated). One short string per cost. Otherwise [].
- investment (one-off only): the total project fee ONLY, as a currency amount (e.g. "LKR 450,000"). Use this field only if the transcript states a specific figure that both sides clearly treat as the agreed or proposed total price for this project. Do NOT use a budget range, a client's stated ceiling ("we can spend up to X"), a competitor's quote, a cost for one deliverable within a larger scope, or any other number that is not the total fee being proposed here — leave "" in all of those cases rather than guess.
- Never invent a specific number for any fee field. If a fee was discussed only vaguely (e.g. "pricing to be sent later", "proposal to follow"), leave the relevant field(s) "".
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
        'engagementType' => ($obj['engagementType'] ?? '') === 'retainer' ? 'retainer' : 'one_off',
        'investment'     => $str($obj['investment'] ?? ''),
        'setupFee'       => $str($obj['setupFee'] ?? ''),
        'retainerAmount' => $str($obj['retainerAmount'] ?? ''),
        'retainerBasis'  => $str($obj['retainerBasis'] ?? ''),
        'retainerTerm'   => $str($obj['retainerTerm'] ?? ''),
        'passthroughCosts' => $arr($obj['passthroughCosts'] ?? []),
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
        case 'nda':
            return 'NDA';
        case 'google_ads_report':
        case 'google_ads':
        case 'gar':
            return 'GAR';
        case 'meta_report':
        case 'meta':
        case 'mar':
            return 'MAR';
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

/** Read the shared documents store. Shape: ['documents' => [...]]. */
function getDocsStore() {
    $store = memoGetBlob('documents');
    if ($store === null) {
        // One-time migration: gather any documents that still live in legacy user_*.json files, if any exist on disk.
        $store = migrateDocsFromUsers();
        saveDocsStore($store);
        return $store;
    }
    if (!isset($store['documents']) || !is_array($store['documents'])) $store['documents'] = [];
    return $store;
}

/** Write the shared documents store (mirrors saveJobsStore), plus refresh the reporting projection. */
function saveDocsStore($store) {
    memoSaveBlob('documents', $store);
    dbSyncReportingTable('documents', $store['documents'] ?? [], [
        'doc_no' => 'doc_no',
        'type' => 'type',
        'client' => 'client',
        'owner_id' => 'owner_id',
        'linked_cost_proposal' => 'linked_cost_proposal',
        'created_at' => fn($d) => $d['created_at'] ?? null,
        'updated_at' => fn($d) => $d['updated_at'] ?? null,
    ]);
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
