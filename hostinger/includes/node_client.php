<?php
// ============================================================
// WhatsApp CRM OS — Node.js API Client
// HTTP wrapper for all Hostinger → HF Node communication
// Handles: auth headers, timeouts, retries, error parsing
// ============================================================

defined('CRM_APP') or die('Direct access not permitted');

class NodeClient
{
    private static ?NodeClient $instance = null;

    private string $baseUrl;
    private string $apiKey;
    private int    $timeout;

    private function __construct()
    {
        $this->baseUrl = rtrim(setting('node_api_url', NODE_API_URL), '/');
        $this->apiKey  = setting('node_api_key',  NODE_API_KEY);
        $this->timeout = NODE_REQUEST_TIMEOUT;
    }

    private function __clone() {}

    public static function getInstance(): NodeClient
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // ── Refresh config from DB settings ──────────────────────
    public function refreshConfig(): void
    {
        $this->baseUrl = rtrim(setting('node_api_url', NODE_API_URL), '/');
        $this->apiKey  = setting('node_api_key',  NODE_API_KEY);
    }

    // ── Core request method ───────────────────────────────────
    /**
     * @param string $method   GET|POST
     * @param string $endpoint e.g. /send-message
     * @param array  $payload  POST body data
     * @param int    $retries  retry attempts on failure
     * @return array ['success' => bool, 'data' => array|null, 'error' => string|null, 'http_code' => int]
     */
    private function request(
        string $method,
        string $endpoint,
        array  $payload  = [],
        int    $retries  = 1
    ): array {
        if (empty($this->baseUrl) || $this->baseUrl === 'https://your-space.hf.space') {
            return [
                'success'   => false,
                'data'      => null,
                'error'     => 'Node API URL not configured. Please update settings.',
                'http_code' => 0,
            ];
        }

        $url     = $this->baseUrl . $endpoint;
        $attempt = 0;

        while ($attempt <= $retries) {
            $result = $this->_curlRequest($method, $url, $payload);

            if ($result['success'] || $attempt >= $retries) {
                return $result;
            }

            // Only retry on network-level failures or 5xx errors
            if ($result['http_code'] >= 400 && $result['http_code'] < 500) {
                return $result; // Don't retry 4xx (client errors)
            }

            $attempt++;
            usleep(500000 * $attempt); // 0.5s, 1s backoff
        }

        return $result ?? ['success' => false, 'data' => null, 'error' => 'Unknown error', 'http_code' => 0];
    }

    // ── cURL execution ────────────────────────────────────────
    private function _curlRequest(string $method, string $url, array $payload): array
    {
        $ch = curl_init();

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-API-Key: ' . $this->apiKey,
            'X-Request-Source: hostinger-crm',
            'X-Request-Time: ' . time(),
        ];

        $curlOpts = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
        ];

        if ($method === 'POST') {
            $curlOpts[CURLOPT_POST]       = true;
            $curlOpts[CURLOPT_POSTFIELDS] = json_encode($payload);
        } else {
            $curlOpts[CURLOPT_HTTPGET] = true;
            if (!empty($payload)) {
                $url .= '?' . http_build_query($payload);
                $curlOpts[CURLOPT_URL] = $url;
            }
        }

        curl_setopt_array($ch, $curlOpts);

        $raw      = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        // Network-level failure
        if ($curlErr) {
            crmLog('error', 'node_client', "cURL error: {$curlErr}", [
                'url' => $url, 'method' => $method,
            ]);
            return [
                'success'   => false,
                'data'      => null,
                'error'     => 'Connection failed: ' . $curlErr,
                'http_code' => 0,
            ];
        }

        // Timeout
        if ($raw === false) {
            return [
                'success'   => false,
                'data'      => null,
                'error'     => 'Request timed out',
                'http_code' => $httpCode,
            ];
        }

        // Parse JSON response
        $decoded = json_decode($raw, true);

        if ($httpCode >= 200 && $httpCode < 300) {
            return [
                'success'   => true,
                'data'      => is_array($decoded) ? $decoded : ['raw' => $raw],
                'error'     => null,
                'http_code' => $httpCode,
            ];
        }

        // Error response
        $errorMsg = is_array($decoded)
            ? ($decoded['error'] ?? "HTTP {$httpCode}")
            : "HTTP {$httpCode}";

        crmLog('warning', 'node_client', "API error {$httpCode}: {$errorMsg}", [
            'url' => $url, 'method' => $method,
        ]);

        return [
            'success'   => false,
            'data'      => $decoded,
            'error'     => $errorMsg,
            'http_code' => $httpCode,
        ];
    }

    // ============================================================
    // PUBLIC API METHODS
    // ============================================================

    // ── Health check ──────────────────────────────────────────
    public function health(): array
    {
        return $this->request('GET', '/health');
    }

    // ── Ping ──────────────────────────────────────────────────
    public function ping(): bool
    {
        $result = $this->request('GET', '/ping');
        return $result['success'] && isset($result['data']['pong']);
    }

    // ── Send a single message (queued) ────────────────────────
    /**
     * @param string $phone         Normalized phone (e.g. 919876543210)
     * @param string $message       Message text
     * @param int    $leadId        DB lead ID
     * @param string $businessName  For logging
     * @param int    $delayMin      Seconds min delay
     * @param int    $delayMax      Seconds max delay
     */
    public function sendMessage(
        string $phone,
        string $message,
        int    $leadId,
        string $businessName = '',
        int    $delayMin     = CAMPAIGN_DELAY_MIN,
        int    $delayMax     = CAMPAIGN_DELAY_MAX
    ): array {
        return $this->request('POST', '/send-message', [
            'phone'        => $phone,
            'message'      => $message,
            'leadId'       => $leadId,
            'businessName' => $businessName,
            'delayMin'     => $delayMin,
            'delayMax'     => $delayMax,
            'immediate'    => false,
        ], 1);
    }

    // ── Send a message immediately (manual, bypass queue) ─────
    public function sendMessageImmediate(string $phone, string $message, int $leadId): array
    {
        return $this->request('POST', '/send-message', [
            'phone'     => $phone,
            'message'   => $message,
            'leadId'    => $leadId,
            'immediate' => true,
        ], 1);
    }

    // ── Check if single number is on WhatsApp ─────────────────
    public function checkNumber(string $phone): array
    {
        return $this->request('POST', '/check-number', [
            'phone' => $phone,
        ], 1);
    }

    // ── Batch check multiple numbers ──────────────────────────
    public function checkNumberBatch(array $phones): array
    {
        return $this->request('POST', '/check-number', [
            'phones' => $phones,
        ], 1);
    }

    // ── Get WhatsApp engine status ────────────────────────────
    public function waStatus(): array
    {
        return $this->request('GET', '/whatsapp/status');
    }

    // ── Get current QR code ───────────────────────────────────
    public function getQR(): array
    {
        return $this->request('GET', '/whatsapp/qr');
    }

    // ── Restart WhatsApp client ───────────────────────────────
    public function restartWA(): array
    {
        return $this->request('POST', '/whatsapp/restart', []);
    }

    // ── Logout WhatsApp ───────────────────────────────────────
    public function logoutWA(): array
    {
        return $this->request('POST', '/whatsapp/logout', []);
    }

    // ── Pause outreach queue ──────────────────────────────────
    public function pauseQueue(): array
    {
        return $this->request('POST', '/queue/pause', []);
    }

    // ── Resume outreach queue ─────────────────────────────────
    public function resumeQueue(): array
    {
        return $this->request('POST', '/queue/resume', []);
    }

    // ── Stop and clear outreach queue ────────────────────────
    public function stopQueue(): array
    {
        return $this->request('POST', '/queue/stop', []);
    }

    // ── Get queue state ───────────────────────────────────────
    public function queueState(): array
    {
        return $this->request('GET', '/queue/state');
    }

    // ── Remove a specific lead from queue ────────────────────
    public function removeFromQueue(int $leadId): array
    {
        return $this->request('POST', '/queue/remove', ['leadId' => $leadId]);
    }

    // ── Get socket server stats ───────────────────────────────
    public function socketStats(): array
    {
        return $this->request('GET', '/socket/stats');
    }

    // ── Get full system health ────────────────────────────────
    public function fullHealth(): array
    {
        return $this->request('GET', '/health');
    }
}

// ── Global shortcut ───────────────────────────────────────────
/**
 * Get NodeClient singleton
 * Usage: node()->sendMessage($phone, $message, $leadId)
 */
function node(): NodeClient
{
    return NodeClient::getInstance();
}
