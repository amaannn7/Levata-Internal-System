<?php
/**
 * Cost Proposal generation for Levata Document Studio.
 * Included by api.php (after sow.php). Depends on: callLLMForSow(), sowBulletList()
 * from sow.php — do not include this file standalone.
 */

/**
 * System prompt for Cost Proposals. Derived from Levata's 5 Buckingham v3 proposals
 * (Web Development, Booking Engine, Branding, Photography, Video Production). Unlike the
 * SOW (one rigid 12-section template), the cost proposal is MODULAR: which sections appear
 * depends on the service type. This prompt encodes that adaptivity.
 */
function costProposalSystemPrompt() {
    return <<<'PROMPT'
You are a senior proposal writer at Levata, a premium design and engineering studio (tagline: "Intelligence, Built."). You produce client-ready COST PROPOSALS (the document sent before an SOW; once approved it leads to a Statement of Work).

Voice and standards:
- Calm, refined, confident, warm. The "Understanding of Client Vision" section is evocative and emotive: it describes the client and what they are really about, building desire. Adapt this to the ACTUAL client and project, never copy hotel or Buckingham specifics unless that is the real client.
- British/international English. No emoji, no marketing cliches.
- Do NOT use em dashes or en dashes in prose. Use commas, parentheses, colons, or shorter sentences. (Hyphens in "Weeks 1-2" or "16:9" are fine.)
- Concrete and specific. Expand thin inputs with professional, industry-standard detail, but never invent fees, dates, or commitments the inputs do not support.
- Be internally consistent. If the brief names a technology stack (e.g. Node.js and Next.js), use that SAME stack everywhere it appears (Scope, Approach, anywhere else); never contradict it (e.g. do not say Next.js in one section and WordPress in another).

Output rules:
- Infer the service type from the brief (web development, booking engine / software, branding, photography, video production, or other). The set and order of sections ADAPTS to the type. Follow the section guidance in the template.
- Return ONLY the finished proposal as clean GitHub-flavoured Markdown. No preamble, no code fences, no commentary.
- DOCUMENT START: begin the output directly with "## 1. Project Overview". Do NOT add a title, a "COST PROPOSAL" line, the service type as a heading, a "Prepared for" line, a date line, or any cover/header block. The cover page is rendered separately and already shows all of that; repeating it here is wrong.
- Replace every {{placeholder}} with real content. Never leave a {{placeholder}} or a guidance line in the output.
- Keep the SIGN-OFF block exactly as templated: it MUST stay a two-column Markdown table (Levata on the left, the Client on the right) with the Name, Title, Signature and Date rows. Never flatten it into plain lines.
- Section numbering is sequential (1, 2, 3...). Only include the sections relevant to the service type, but ALWAYS include: Project Overview, Understanding of Client Vision, Project Objectives, Scope of Work, Levata Approach, Investment, Next Steps, and SIGN-OFF.
- Scope of Work uses bold sub-headings ("**4.1 Title**") with bullets or a short paragraph under each. For a WEBSITE, the FIRST sub-clause MUST be "Site Map and Architecture" and MUST list the actual pages as bullets (Home, Rooms/Suites or equivalent, Experiences, Dining, Location, Promotions/Offers, About, Contact, Gallery, Terms and Policies), each with a one-line purpose, derived from the brief; do not just state a page count. For software, list the modules; for an app, the screens. Expand thin scope input into named sub-clauses; never collapse the scope into a single paragraph.
- Project Objectives and Levata Approach are bulleted lists. Approach bullets use the form "- **Discovery:** description".
- Timeline: include ONLY if the service type has a schedule. Web/software use a Phase|Activity table whose Phase column is the actual TIME PERIOD in weeks or days (e.g. "Weeks 1 to 2", "Weeks 3 to 5", "Week 6"), NEVER generic stage names like "Phase 1". Branding usually has NO timeline section.
- Type-specific tables when relevant: Photography includes an "Estimated Shot Allocation" table (Content Area | Shots) and a Deliverables list; Video includes a Deliverables table (Deliverable | Duration | Primary Use); Booking engine includes Deliverables and Exclusions sections.
- Investment: a "Total Investment" line showing the amount (e.g. "LKR 450,000", or "LKR XXX,000" if no amount given), then a Payment Schedule (default 50% at kickoff, 50% on deployment/delivery; branding may be 50% before, 50% within 7 days of completion).
- Exclusions: ALWAYS include an Exclusions section for web, software, app, booking-engine, photography and video proposals (branding may omit it). List what is NOT covered and would be quoted separately. For a website always include, where applicable: domain registration and hosting fees (billed separately to the client); photography and visual content production (separate scope); payment gateway and booking engine integrations (separate proposal); premium or paid third-party plugins. Adapt the list to the actual service type and brief; never invent exclusions that contradict the stated scope.
- Keep all tables as valid GitHub-flavoured Markdown tables.
PROMPT;
}

/**
 * The cost proposal template scaffold. Sections marked OPTIONAL are included by the AI
 * only when they fit the service type (per the system prompt).
 */
function costProposalTemplate() {
    return <<<'TPL'
## 1. Project Overview
{{overview}}

## 2. Understanding of Client Vision
Write two or three warm, evocative paragraphs that show genuine understanding of THIS client and what they are really about, and connect that to how the work will serve it. Adapt entirely to the real client; do not reuse hotel or Buckingham wording unless that is the client.

## 3. Project Objectives
Write four to six concrete single-sentence bullets capturing what this engagement must achieve for the Client.

## 4. Scope of Work
Break the scope into numbered sub-clauses ("**4.1 Title**") derived from the scope details below, each with bullets or a short paragraph. Adapt the clauses to the service type. For a website, make 4.1 "Site Map and Architecture" and list the actual pages as bullets (each with a one-line purpose); for software list modules, for an app list screens. Do not reduce the scope to a single page count.

Scope details provided:
{{scopeDetails}}

## 5. Levata Approach
Our process is built around partnership. We keep clients informed and in control at every stage while our team manages the complexity of execution.

Write the stages as bullets in the form "- **Discovery:** description", four to seven stages fitting the service type.

<!-- OPTIONAL: Deliverables. Include for booking engine, photography, video, or when deliverables are listed. Photography/video may use a table. -->
## Deliverables
{{deliverables}}

<!-- OPTIONAL: type-specific table. Photography -> Estimated Shot Allocation (Content Area | Shots). Video -> Deliverables table (Deliverable | Duration | Primary Use). Omit otherwise. -->

<!-- OPTIONAL: Timeline. Include for web/software/shoots; omit for branding. The Phase column is the actual time period (weeks or days), not a stage name. -->
## Timeline
Estimated duration: {{timeline}}.

| Phase | Activity |
| --- | --- |
| **Weeks 1 to 2** | _the activities in this period_ |

## Investment
{{investmentIntro}}

| | |
| --- | --- |
| **Total Investment** | {{investment}} |

**Payment Schedule**
- 50% due at project kickoff
- 50% due upon deployment/delivery

## Exclusions
List what is not included and would be quoted separately. For a website include, where applicable: domain registration and hosting fees (billed separately to the Client); photography and visual content production (separate scope); payment gateway and booking engine integrations (separate proposal); premium or paid third-party plugins. Adapt to the actual service type. (Branding proposals may omit this section.)

## Next Steps
- Review and sign this proposal
- Submit the 50% advance payment to initiate the project

This proposal is valid until {{validUntil}}. Payments are made by bank transfer to the details provided in the invoice upon sign-off.

## SIGN-OFF

| For Levata | For {{clientShort}} |
| --- | --- |
| **Name:** Shameer Refai | **Name:** {{contactPerson}} |
| **Title:** Co-Founder, Levata | **Title:** ___________________ |
| **Signature:** ___________________ | **Signature:** ___________________ |
| **Date:** ______ / ______ / {{year}} | **Date:** ______ / ______ / {{year}} |
TPL;
}

/** Fill the cost proposal template placeholders from the form input. */
function cpFillTemplate($template, $input) {
    $loc = !empty($input['location']) ? ', ' . $input['location'] : '';
    $clientName = ($input['clientName'] ?? '') ?: 'Client';
    $year = date('Y');
    $validUntil = date('jS F Y', strtotime('+14 days'));
    $map = [
        'serviceType'     => ($input['serviceType'] ?? '') ?: 'Service',
        'clientName'      => $clientName,
        'clientShort'     => $clientName,
        'location'        => $loc,
        'projectId'       => ($input['projectId'] ?? '') ?: 'PID: TBC',
        'contactPerson'   => ($input['contactPerson'] ?? '') ?: 'Client Representative',
        'overview'        => ($input['overview'] ?? '') ?: 'Provide a project overview describing what this proposal covers.',
        'scopeDetails'    => sowBulletList($input['scopeDetails'] ?? []),
        'deliverables'    => sowBulletList($input['deliverables'] ?? []),
        'timeline'        => ($input['timeline'] ?? '') ?: 'to be confirmed at kickoff',
        'investmentIntro' => ($input['investmentIntro'] ?? '') ?: 'This cost covers the full scope as outlined.',
        'investment'      => ($input['investment'] ?? '') ?: 'LKR XXX,000',
        'validUntil'      => $validUntil,
        'date'            => date('jS F Y'),
        'year'            => $year,
    ];
    return preg_replace_callback('/\{\{(\w+)\}\}/', function ($m) use ($map) {
        return array_key_exists($m[1], $map) ? $map[$m[1]] : $m[0];
    }, $template);
}

/** Build the cost-proposal user prompt: filled template + raw details. */
function buildCostProposalUserPrompt($template, $input) {
    $filled = cpFillTemplate($template, $input);
    $details = "PROPOSAL DETAILS:\n"
        . "Service type: " . ($input['serviceType'] ?? '') . "\n"
        . "Client: " . ($input['clientName'] ?? '') . "\n"
        . "Location: " . ($input['location'] ?? '') . "\n"
        . "Overview: " . ($input['overview'] ?? '') . "\n"
        . "Scope details: " . implode('; ', $input['scopeDetails'] ?? []) . "\n"
        . "Deliverables: " . implode('; ', $input['deliverables'] ?? []) . "\n"
        . "Timeline: " . ($input['timeline'] ?? '') . "\n"
        . "Investment: " . ($input['investment'] ?? '') . "\n";
    return "Fill in and complete the following COST PROPOSAL template using the details. Adapt the optional sections to the service type. Follow every output rule.\n\n"
        . "=== TEMPLATE ===\n" . $filled . "\n\n" . $details;
}

/** Generate a Cost Proposal from the intake form input. */
function generateCostProposal($provider, $apiKey, $input) {
    $user = buildCostProposalUserPrompt(costProposalTemplate(), $input);
    return callLLMForSow($provider, $apiKey, costProposalSystemPrompt(), $user);
}

/** Refine an existing Cost Proposal per an instruction. */
function refineCostProposal($provider, $apiKey, $markdown, $instruction) {
    $system = "You are a senior proposal writer at Levata. You revise an existing Cost Proposal according to an instruction. Apply the change faithfully while keeping the document's structure, headings, tables, tone, and formatting intact. Keep everything the instruction does not ask you to change. Do NOT introduce em dashes into prose. Return ONLY the full revised Cost Proposal as clean GitHub-flavoured Markdown, no preamble or commentary.";
    $user = "CURRENT COST PROPOSAL:\n\n$markdown\n\n=== INSTRUCTION ===\n$instruction";
    return callLLMForSow($provider, $apiKey, $system, $user);
}

/** Extract Cost Proposal form fields from a transcript (maps to the CP form, not the SOW form). */
function extractCostProposalInput($provider, $apiKey, $transcript) {
    $cap = $provider === 'groq' ? 4500 : 40000;
    if (strlen($transcript) > $cap) $transcript = substr($transcript, 0, $cap) . "\n[... notes truncated to fit the model ...]";
    $system = <<<'P'
You extract structured project details from a client meeting transcript or notes so they can pre-fill a Cost Proposal form.

Return ONLY a single JSON object (no prose, no code fences) with EXACTLY these keys:
{ "serviceType": string, "clientName": string, "location": string, "projectId": string, "contactPerson": string, "overview": string, "scopeDetails": string[], "deliverables": string[], "timeline": string, "investment": string }

Rules:
- Extract only what the transcript supports. If something is not mentioned, use an empty string "" (or [] for list fields). NEVER invent a client name, fee, date, contact, or commitment not in the notes.
- serviceType: a short label (e.g. "Web Development", "Branding", "Video Production").
- overview: 1 to 3 sentences on what the work covers. This is the only field you may lightly paraphrase.
- scopeDetails / deliverables: one short string each.
- Do NOT use em dashes. Output must be valid JSON. Use straight double quotes. No trailing commas.
P;
    $user = "Extract the Cost Proposal form fields from the meeting notes below. Return only the JSON object.\n\n--- MEETING NOTES ---\n$transcript\n--- END ---";
    $res = callLLMForSow($provider, $apiKey, $system, $user);
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
        'serviceType'  => $str($obj['serviceType'] ?? ''),
        'clientName'   => $str($obj['clientName'] ?? ''),
        'location'     => $str($obj['location'] ?? ''),
        'projectId'    => $str($obj['projectId'] ?? ''),
        'contactPerson'=> $str($obj['contactPerson'] ?? ''),
        'overview'     => $str($obj['overview'] ?? ''),
        'scopeDetails' => $arr($obj['scopeDetails'] ?? []),
        'deliverables' => $arr($obj['deliverables'] ?? []),
        'timeline'     => $str($obj['timeline'] ?? ''),
        'investment'   => $str($obj['investment'] ?? ''),
    ];
    return ['success' => true, 'input' => $input];
}
