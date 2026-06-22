<?php
/**
 * Meeting Minutes generator — turns a client-meeting transcript (pulled from
 * Fireflies, or pasted) into clean, client-ready minutes.
 *
 * Included by api.php AFTER sow.php, so it reuses sow.php's plumbing:
 *   - callLLMForSow()  — large-token LLM call with system+user separation
 *   - chosenModelFor() — provider/model resolution
 *   - firefliesListMeetings() / firefliesFetchTranscript() — meeting pull
 *
 * Provides:
 *   - meetingMinutesSystemPrompt()
 *   - generateMeetingMinutes($provider, $apiKey, $transcript, $context)
 *   - refineMeetingMinutes($provider, $apiKey, $markdown, $instruction)
 *
 * Scope (first slice): minutes only. No task extraction, no client emailing yet.
 */

function meetingMinutesSystemPrompt() {
    return <<<'PROMPT'
You are a senior account lead at Levata, a premium design and engineering studio. After a client meeting you write clear, professional Meeting Minutes that are sent to the client as a record of what was discussed and agreed.

Voice and standards:
- Calm, precise, and professional. British/international English spelling. No emoji. No marketing language.
- Do NOT use em dashes or en dashes in ordinary prose. Use commas, parentheses, a colon, or shorter sentences instead. (A hyphen in a date or numeric range is fine.)
- Be faithful to the transcript. Summarise what was actually said and agreed. Do NOT invent decisions, figures, dates, names, or commitments that the notes do not support. If something important was clearly left open or undecided, say so plainly rather than guessing.
- These minutes go to a client, so they must read cleanly and make the client feel the meeting was well run. Tidy up rambling discussion into clear points, but never change the meaning.

Output rules:
- Return ONLY the finished minutes as clean GitHub-flavoured Markdown. No preamble, no code fences, no commentary before or after.
- Follow this exact structure and headings:

# Meeting Minutes

**Meeting:** {{a short descriptive title of the meeting}}
**Date:** {{the meeting date if stated, otherwise omit this line}}
**Attendees:** {{names/roles of who was present if identifiable, otherwise "Not specified"}}

## Summary
{{Two to four short paragraphs (or a tight bulleted list) summarising what was discussed. Plain prose preferred. Cover the substance of the conversation, not pleasantries.}}

## Key Points Discussed
{{A bulleted list of the main topics and points raised. Each bullet one clear sentence, starting with "- ".}}

## Decisions and Agreements
{{A bulleted list of what was actually decided or agreed. Each bullet starts with "- ". If nothing concrete was agreed, write a single bullet: "- No formal decisions were made in this meeting."}}

## Open Items
{{A bulleted list of questions or points left unresolved / needing follow-up. Each bullet starts with "- ". If there are none, write "- None."}}

Notes:
- Do NOT add a tasks/action-items owner table. Open Items captures only what is unresolved; assigning and tracking tasks is out of scope for this document.
- If the transcript is thin or unclear, produce the best honest summary you can and keep sections short rather than padding them.
PROMPT;
}

/**
 * Generate meeting minutes from a transcript.
 * $context is optional caller-supplied hints (client name, meeting title, date)
 * that get prepended so the model has cleaner anchors than the raw transcript.
 */
function generateMeetingMinutes($provider, $apiKey, $transcript, $context = []) {
    // Keep within model limits (mirror extractSowInput's caps).
    $cap = $provider === 'groq' ? 4500 : 40000;
    if (strlen($transcript) > $cap) {
        $transcript = substr($transcript, 0, $cap) . "\n[... transcript truncated to fit the model ...]";
    }

    $hints = [];
    if (!empty($context['clientName']))   $hints[] = 'Client: ' . trim($context['clientName']);
    if (!empty($context['meetingTitle'])) $hints[] = 'Meeting title: ' . trim($context['meetingTitle']);
    if (!empty($context['meetingDate']))  $hints[] = 'Meeting date: ' . trim($context['meetingDate']);
    $hintBlock = $hints ? ("KNOWN CONTEXT:\n" . implode("\n", $hints) . "\n\n") : '';

    $user = $hintBlock
        . "Write the Meeting Minutes for the client from the transcript below. Follow the required structure exactly.\n\n"
        . "--- TRANSCRIPT ---\n" . $transcript . "\n--- END TRANSCRIPT ---";

    return callLLMForSow($provider, $apiKey, meetingMinutesSystemPrompt(), $user);
}

/** Refine existing meeting minutes per an instruction, keeping structure intact. */
function refineMeetingMinutes($provider, $apiKey, $markdown, $instruction) {
    $system = "You revise an existing set of client Meeting Minutes according to an instruction. Apply the change faithfully while keeping the document's structure, headings, and tone intact, and keeping everything the instruction does not ask you to change. British/international English, no emoji, no em dashes in ordinary prose. Do not invent decisions or facts not supported by the existing minutes. Return ONLY the full revised minutes as clean GitHub-flavoured Markdown, with no preamble or commentary.";
    $user = "CURRENT MINUTES:\n\n$markdown\n\n=== INSTRUCTION ===\n$instruction";
    return callLLMForSow($provider, $apiKey, $system, $user);
}
