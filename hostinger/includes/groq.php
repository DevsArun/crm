<?php
// ============================================================
// WhatsApp CRM OS — Groq AI Personalization Engine
// Handles: prompt building, language adaptation,
//          service selection, website/no-website branching,
//          anti-spam messaging, fallback generation
// ============================================================

defined('CRM_APP') or die('Direct access not permitted');

// ============================================================
// MAIN ENTRY POINT
// ============================================================

/**
 * Generate a personalized first outreach WhatsApp message
 *
 * @param array $lead  Full lead row from DB
 * @return array ['success' => bool, 'message' => string, 'reasoning' => string, 'error' => string]
 */
function generateOutreachMessage(array $lead): array
{
    if (!FEATURE_AI_GENERATION) {
        return _fallbackMessage($lead);
    }

    $apiKey = setting('groq_api_key', GROQ_API_KEY);
    if (empty($apiKey)) {
        crmLog('warning', 'groq', 'Groq API key not configured — using fallback');
        return _fallbackMessage($lead);
    }

    try {
        $prompt    = _buildPrompt($lead);
        $response  = _callGroqApi($prompt, $apiKey);
        $message   = _extractMessage($response);

        if (empty($message)) {
            crmLog('warning', 'groq', 'Empty response from Groq — using fallback', ['lead_id' => $lead['id']]);
            return _fallbackMessage($lead);
        }

        // Clean and validate the message
        $message = _cleanMessage($message);

        crmLog('info', 'groq', 'Message generated successfully', [
            'lead_id'  => $lead['id'],
            'business' => $lead['business_name'],
            'length'   => strlen($message),
        ]);

        return [
            'success'   => true,
            'message'   => $message,
            'reasoning' => _buildReasoning($lead),
            'error'     => null,
        ];

    } catch (Exception $e) {
        crmLog('error', 'groq', 'Groq API error: ' . $e->getMessage(), [
            'lead_id' => $lead['id'] ?? null,
        ]);
        return _fallbackMessage($lead);
    }
}

// ============================================================
// PROMPT BUILDER
// ============================================================

function _buildPrompt(array $lead): array
{
    $businessName   = sanitizeString($lead['business_name'] ?? 'your business');
    $locality       = sanitizeString($lead['locality']      ?? '');
    $city           = sanitizeString($lead['city']          ?? '');
    $state          = sanitizeString($lead['state']         ?? '');
    $rating         = !empty($lead['rating'])       ? (float)$lead['rating']    : null;
    $reviewCount    = !empty($lead['review_count']) ? (int)$lead['review_count'] : 0;
    $websiteStatus  = $lead['website_status']  ?? 'no_website';
    $websiteUrl     = $lead['website_url']     ?? '';
    $pitchType      = $lead['pitch_type']      ?? ($websiteStatus === 'has_website' ? 'type_a' : 'type_b');
    $language       = $lead['language_preference'] ?? 'english';

    // Select 2-3 most relevant services
    $services = _selectServices($pitchType, $websiteStatus);

    // Build location string
    $location = _buildLocation($locality, $city, $state);

    // Build rating context
    $ratingContext = _buildRatingContext($rating, $reviewCount);

    // Language instructions
    $langInstructions = _getLanguageInstructions($language);

    // Build the pitch angle based on type
    $pitchAngle = $pitchType === 'type_a'
        ? _buildTypeAPitchAngle($websiteUrl)
        : _buildTypeBPitchAngle();

    $systemPrompt = <<<SYSTEM
You are an expert business development writer who crafts highly personalized WhatsApp outreach messages for local Indian businesses.

Your job is to write a single first-contact WhatsApp message that feels genuinely human, warm, locally relevant, and professionally helpful — NOT salesy, NOT spammy, NOT generic.

STRICT RULES:
- Write EXACTLY 4-5 short paragraphs (each 1-3 sentences)
- Sound like a real person reaching out, not a marketing bot
- NEVER mention pricing, packages, or costs
- NEVER use fake urgency ("limited time", "act now", "don't miss")
- NEVER list multiple services in bullet points — weave them naturally into conversation
- NEVER mention all services — pick only 2-3 most relevant ones
- NEVER start with "Hi" or "Hello" followed immediately by a pitch
- DO mention the business name, locality/city naturally in the message
- If rating/reviews exist, reference them as a genuine compliment
- End with a SOFT human CTA — a simple question or gentle offer to help
- Message should feel like it was written specifically for THIS business
- Total message length: 120-180 words
- NO emojis in excess — at most 1-2 subtle ones maximum
- Output ONLY the message text — no subject line, no greeting header, no explanation
SYSTEM;

    $userPrompt = <<<USER
Write a personalized WhatsApp outreach message for the following local business:

BUSINESS DETAILS:
- Business Name: {$businessName}
- Location: {$location}
- {$ratingContext}
- Website Status: {$websiteStatus} {$websiteUrl}
- Pitch Type: {$pitchType}

PITCH ANGLE FOR THIS BUSINESS:
{$pitchAngle}

SERVICES TO MENTION (choose 2-3 most relevant only):
{$services}

LANGUAGE TONE INSTRUCTIONS:
{$langInstructions}

MESSAGE STRUCTURE TO FOLLOW:
Para 1: Local trust signal — acknowledge something specific about their business/location/reputation
Para 2: Digital observation — what you noticed (website opportunity OR existing digital presence gap)
Para 3: Specific opportunity — what they are missing that others in their city are leveraging
Para 4: Relevant services — naturally mention 2-3 relevant services I can offer (no bullet points, conversational)
Para 5: Soft CTA — a genuine, non-pushy closing question or offer

Write the message now:
USER;

    return [
        'system' => $systemPrompt,
        'user'   => $userPrompt,
    ];
}

// ============================================================
// SERVICE SELECTION
// ============================================================

function _selectServices(string $pitchType, string $websiteStatus): string
{
    $allServices = setting('seller_services', SELLER_SERVICES);

    if ($pitchType === 'type_b' || $websiteStatus === 'no_website') {
        // No website — focus on getting online
        $relevant = [
            'Landing Pages',
            'Business Websites',
            'eCommerce Websites',
            'Digital Marketing',
        ];
    } else {
        // Has website — focus on growth and automation
        $relevant = [
            'Custom Web Apps',
            'AI Agents',
            'Automation Systems',
            'Digital Marketing',
            'Chrome Extensions',
        ];
    }

    // Filter to only services the seller actually offers
    $selected = array_intersect($relevant, (array)$allServices);

    // Ensure at least 2, max 3
    $selected = array_slice($selected, 0, 3);

    if (empty($selected)) {
        $selected = array_slice((array)$allServices, 0, 3);
    }

    return implode(', ', $selected);
}

// ============================================================
// PITCH ANGLES
// ============================================================

function _buildTypeAPitchAngle(string $websiteUrl): string
{
    return <<<PITCH
This business HAS a website ({$websiteUrl}).
Focus on: how most local businesses with websites are losing potential customers because of poor conversion, no automation, or outdated user experience.
Talk about: AI-powered tools, automation systems, smarter web apps, digital marketing that converts, or systems that save them time.
Opportunity: They have digital presence but aren't maximizing it.
PITCH;
}

function _buildTypeBPitchAngle(): string
{
    return <<<PITCH
This business has NO website.
Focus on: how their competitors are getting discovered online while they are relying only on word-of-mouth and foot traffic.
Talk about: having a professional online presence that works 24/7, brings in enquiries, builds trust with new customers who search online before visiting.
Opportunity: They are completely invisible online — a simple landing page or business website could transform their discoverability.
PITCH;
}

// ============================================================
// LANGUAGE INSTRUCTIONS
// ============================================================

function _getLanguageInstructions(string $language): string
{
    $instructions = [
        'hinglish' => 'Write in a warm, conversational Hinglish tone (mix of Hindi and English, Roman script). Use natural phrases like "aapka", "kafi", "bahut", "kyunki" sparingly and naturally — do NOT force Hindi. The tone should feel like a friendly colleague from the same region reaching out.',

        'gujarati_english' => 'Write in warm Indian business English with a Gujarati sensibility — respectful, relationship-first, value-conscious. Gujarati business people appreciate directness with respect. Use "ji" once if natural. Keep it professional yet warm.',

        'marathi_english' => 'Write in conversational Indian English with Maharashtrian warmth. The tone should be respectful, grounded, and genuine. Maharashtra businesses appreciate straightforward communication that respects their time.',

        'bengali_english' => 'Write in polished Indian English with warmth. Bengali business owners appreciate intellectual engagement and quality. Tone should be cultured, thoughtful, and genuine.',

        'tamil_english' => 'Write in professional South Indian English — formal yet warm. Tamil Nadu businesses appreciate respect, quality, and long-term relationship language. Be dignified and sincere.',

        'telugu_english' => 'Write in professional Indian English. Telangana and Andhra business owners appreciate clear, direct communication. Be warm but professional.',

        'kannada_english' => 'Write in warm professional Indian English. Karnataka/Bengaluru businesses understand tech well — you can reference digital growth naturally without over-explaining.',

        'malayalam_english' => 'Write in warm, respectful professional English. Kerala businesses are highly educated — be sophisticated, genuine, and avoid anything that sounds like a sales pitch.',

        'punjabi_english' => 'Write in energetic, warm Indian English with Punjabi enthusiasm. Punjab businesses appreciate confidence, warmth, and straight talk. Be friendly and bold.',

        'english' => 'Write in clear, warm, professional Indian business English. Avoid British/American idioms. Keep it simple, genuine, and locally relevant. The tone should feel like a professional Indian entrepreneur reaching out to another.',
    ];

    return $instructions[$language] ?? $instructions['english'];
}

// ============================================================
// HELPER BUILDERS
// ============================================================

function _buildLocation(string $locality, string $city, string $state): string
{
    $parts = array_filter([$locality, $city, $state], fn($p) => !empty(trim($p)));
    return implode(', ', $parts) ?: 'India';
}

function _buildRatingContext(?float $rating, int $reviewCount): string
{
    if ($rating === null || $rating <= 0) {
        return 'Rating: Not available';
    }

    $context = "Rating: {$rating}/5";

    if ($reviewCount > 0) {
        $context .= " with {$reviewCount} reviews";
    }

    if ($rating >= 4.5) {
        $context .= " (Excellent — highly regarded local business)";
    } elseif ($rating >= 4.0) {
        $context .= " (Well-rated — trusted by customers)";
    } elseif ($rating >= 3.5) {
        $context .= " (Good standing)";
    }

    return $context;
}

function _buildReasoning(array $lead): string
{
    $type     = ($lead['pitch_type'] ?? 'type_b') === 'type_a' ? 'Website upgrade' : 'Digital presence';
    $lang     = $lead['language_preference'] ?? 'english';
    $services = _selectServices($lead['pitch_type'] ?? 'type_b', $lead['website_status'] ?? 'no_website');

    return "Pitch type: {$type} | Language: {$lang} | Services selected: {$services}";
}

// ============================================================
// GROQ API CALL
// ============================================================

function _callGroqApi(array $prompt, string $apiKey): array
{
    $model       = setting('groq_model', GROQ_MODEL);
    $maxTokens   = (int) GROQ_MAX_TOKENS;
    $temperature = (float) GROQ_TEMPERATURE;
    $timeout     = (int) GROQ_TIMEOUT;

    $payload = json_encode([
        'model'       => $model,
        'messages'    => [
            ['role' => 'system', 'content' => $prompt['system']],
            ['role' => 'user',   'content' => $prompt['user']],
        ],
        'max_tokens'  => $maxTokens,
        'temperature' => $temperature,
        'top_p'       => 0.9,
        'stream'      => false,
    ]);

    $ch = curl_init();

    curl_setopt_array($ch, [
        CURLOPT_URL            => GROQ_API_URL,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $raw      = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        throw new RuntimeException('Groq cURL error: ' . $curlErr);
    }

    if ($httpCode !== 200) {
        $errorBody = json_decode($raw, true);
        $errorMsg  = $errorBody['error']['message'] ?? "HTTP {$httpCode}";
        throw new RuntimeException('Groq API error: ' . $errorMsg);
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Invalid JSON response from Groq');
    }

    return $decoded;
}

// ============================================================
// RESPONSE EXTRACTION
// ============================================================

function _extractMessage(array $response): string
{
    return trim($response['choices'][0]['message']['content'] ?? '');
}

// ============================================================
// MESSAGE CLEANING
// ============================================================

function _cleanMessage(string $message): string
{
    // Remove any markdown formatting that AI might add
    $message = preg_replace('/\*\*(.*?)\*\*/s', '$1', $message);
    $message = preg_replace('/\*(.*?)\*/s', '$1', $message);
    $message = preg_replace('/#{1,6}\s+/', '', $message);

    // Remove leading/trailing quotes if AI added them
    $message = trim($message, '"\'');

    // Normalize multiple blank lines to max 1
    $message = preg_replace('/\n{3,}/', "\n\n", $message);

    // Remove any "Message:" or "WhatsApp Message:" prefix AI might add
    $message = preg_replace('/^(WhatsApp\s+)?Message:\s*/i', '', $message);

    return trim($message);
}

// ============================================================
// FALLBACK MESSAGE GENERATOR
// ============================================================

/**
 * Generate a simple personalized fallback message when Groq is unavailable
 */
function _fallbackMessage(array $lead): array
{
    $name     = sanitizeString($lead['business_name'] ?? 'your business');
    $city     = sanitizeString($lead['city']          ?? 'your city');
    $locality = sanitizeString($lead['locality']      ?? '');

    $location = $locality ? "{$locality}, {$city}" : $city;

    $hasWebsite = ($lead['website_status'] ?? 'no_website') === 'has_website';

    if ($hasWebsite) {
        $message = "Hi, I came across {$name} in {$location} and was genuinely impressed by your presence.\n\n"
            . "I work with local businesses to help them grow their digital reach — from smarter web systems "
            . "to AI-powered tools and automation that saves time and brings in more customers.\n\n"
            . "I noticed a few opportunities where we could potentially strengthen your online presence and "
            . "convert more of your website visitors into actual customers.\n\n"
            . "Would love to share a quick idea with you if you're open to it. No obligations at all!";
    } else {
        $message = "Hi, I noticed {$name} in {$location} while researching local businesses in your area — "
            . "you have a strong local presence!\n\n"
            . "Many businesses like yours are now getting discovered by new customers online through professional "
            . "websites and digital marketing — even a simple landing page can make a huge difference in bringing "
            . "in new inquiries.\n\n"
            . "I help local businesses build affordable, mobile-friendly websites and digital marketing systems "
            . "that work around the clock for them.\n\n"
            . "Would you be open to a quick conversation about how this could work for {$name}?";
    }

    return [
        'success'   => true,
        'message'   => $message,
        'reasoning' => 'Fallback message used (Groq unavailable)',
        'error'     => null,
    ];
}

// ============================================================
// BATCH GENERATION
// ============================================================

/**
 * Generate messages for multiple leads with rate limiting
 *
 * @param array    $leads
 * @param callable $onProgress  fn(leadId, result) — called after each generation
 * @param int      $delayMs     milliseconds between API calls (rate limit safety)
 */
function generateBatchMessages(array $leads, callable $onProgress = null, int $delayMs = 500): array
{
    $results = [];

    foreach ($leads as $lead) {
        $result = generateOutreachMessage($lead);
        $results[$lead['id']] = $result;

        if ($onProgress !== null) {
            $onProgress($lead['id'], $result);
        }

        // Save generated message to DB immediately
        if ($result['success'] && !empty($result['message'])) {
            try {
                db()->execute(
                    "UPDATE leads SET generated_message = ?, ai_reasoning = ?, updated_at = NOW() WHERE id = ?",
                    [$result['message'], $result['reasoning'], $lead['id']]
                );
            } catch (Exception $e) {
                crmLog('error', 'groq', 'Failed to save generated message', [
                    'lead_id' => $lead['id'],
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        // Respect rate limit between calls
        if ($delayMs > 0 && next($leads) !== false) {
            usleep($delayMs * 1000);
        }
    }

    return $results;
}
