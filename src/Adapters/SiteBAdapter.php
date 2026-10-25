<?php

declare(strict_types=1);

namespace SyncGuard\Adapters;

use DOMDocument;
use SyncGuard\Http\CurlClient;

/**
 * Class SiteBAdapter
 *
 * Channel adapter implementation for booking partner "Site B".
 * Translates updates into structured XML documents, computes HMAC-SHA256 signatures,
 * and safely parses XML responses with XXE injection protections.
 */
class SiteBAdapter implements ChannelAdapter
{
    private readonly CurlClient $httpClient;
    private readonly string $sharedSecret;

    public function __construct(
        ?CurlClient $httpClient = null,
        ?string $sharedSecret = null
    ) {
        if ($httpClient === null || $sharedSecret === null) {
            $config = require dirname(__DIR__, 2) . '/config/config.php';
            $this->httpClient = $httpClient ?? new CurlClient(
                connectTimeout: (int) ($config['adapter']['connect_timeout'] ?? 2),
                timeout: (int) ($config['adapter']['timeout'] ?? 5)
            );
            $this->sharedSecret = $sharedSecret ?? (string) ($config['mock']['shared_secret'] ?? 'dev_mock_shared_secret_7c9f8a2b4e6d1c3a');
        } else {
            $this->httpClient = $httpClient;
            $this->sharedSecret = $sharedSecret;
        }
    }

    /**
     * Dispatch an update to Site B via XML.
     *
     * @param array<string, mixed> $update
     * @param array<string, mixed> $channel
     * @return PushResult
     */
    public function push(array $update, array $channel): PushResult
    {
        $endpointUrl = (string) ($channel['endpoint_url'] ?? 'http://web/mock/site_b.php');

        /**
         * WHY DOMDOCUMENT INSTEAD OF STRING CONCATENATION:
         * Hand-crafted string concatenation (e.g. "<room_name>" . $roomName . "</room_name>")
         * breaks whenever strings contain reserved XML delimiters like '&', '<', '>', or quotes.
         * For example, a room named "Tom & Jerry <Suite> 和室" would corrupt the XML structure.
         * Using DOMDocument's createElement() and createTextNode() automatically escapes reserved
         * characters into valid XML entities (e.g. '&amp;') and preserves Unicode (Kanji/Kana).
         */
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = false;

        $root = $dom->createElement('inventory_update');
        $dom->appendChild($root);

        $appendElement = function (string $name, ?string $value) use ($dom, $root): void {
            $el = $dom->createElement($name);
            if ($value !== null) {
                $el->appendChild($dom->createTextNode($value));
            }
            $root->appendChild($el);
        };

        $idempotencyKey = (string) ($update['idempotency_key'] ?? '');
        $correlationId  = (string) ($update['correlation_id'] ?? '');

        $appendElement('idempotency_key', $idempotencyKey);
        $appendElement('correlation_id', $correlationId);
        $appendElement('room_id', isset($update['room_id']) ? (string) $update['room_id'] : null);
        $appendElement('room_name', (string) ($update['room_name'] ?? ''));
        $appendElement('date_from', (string) ($update['date_from'] ?? ''));
        $appendElement('date_to', (string) ($update['date_to'] ?? ''));
        $appendElement(
            'available_units',
            isset($update['available_units']) && $update['available_units'] !== null ? (string) $update['available_units'] : null
        );
        $appendElement(
            'price',
            isset($update['price']) && $update['price'] !== null ? (string) $update['price'] : null
        );

        $rawXml = $dom->saveXML();
        if ($rawXml === false) {
            return PushResult::httpError(0, null, 'Failed to serialize update to XML');
        }

        // Generate HMAC-SHA256 signature
        $signature = 'sha256=' . hash_hmac('sha256', $rawXml, $this->sharedSecret);

        $headers = [
            'Content-Type: application/xml',
            'Accept: application/xml',
            'X-Idempotency-Key: ' . $idempotencyKey,
            'X-Correlation-Id: ' . $correlationId,
            'X-Signature: ' . $signature,
        ];

        // Execute HTTP dispatch
        $httpResult = $this->httpClient->postJson($endpointUrl, $rawXml, $headers);

        // 1. Transport timeout check
        if ($httpResult->isTimeout()) {
            return PushResult::timeout(
                durationMs: $httpResult->durationMs,
                message: 'Site B timed out: ' . ($httpResult->curlErrorMessage ?: 'Operation timed out')
            );
        }

        // 2. Transport or HTTP error status (outside 2xx)
        if (!$httpResult->is2xx()) {
            $msg = $httpResult->curlErrorMessage !== ''
                ? $httpResult->curlErrorMessage
                : sprintf('HTTP error %d received from Site B', $httpResult->statusCode ?? 0);

            return PushResult::httpError(
                durationMs: $httpResult->durationMs,
                httpStatus: $httpResult->statusCode,
                message: $msg
            );
        }

        // 3. Inspect XML response body
        /**
         * WHY LIBXML_NONET WITHOUT LIBXML_NOENT (XXE PROTECTION):
         * XML External Entity (XXE) attacks occur when an XML parser resolves external URI entities
         * or DTDs defined inside untrusted incoming payloads.
         * Supplying `LIBXML_NONET` strictly forbids network socket operations during parsing,
         * while deliberately omitting `LIBXML_NOENT` prevents entity replacement, ensuring zero
         * risk of local file disclosure or server-side request forgery (SSRF).
         */
        $body = (string) $httpResult->body;
        $xml = @simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NONET);

        if ($xml === false) {
            return PushResult::rejected(
                durationMs: $httpResult->durationMs,
                httpStatus: $httpResult->statusCode,
                message: 'Site B returned malformed or unparseable XML response'
            );
        }

        $status = (string) ($xml->status ?? '');
        $statusLower = strtolower($status);

        if ($statusLower === 'accepted' || $statusLower === 'duplicate') {
            return PushResult::success(
                durationMs: $httpResult->durationMs,
                httpStatus: $httpResult->statusCode,
                message: 'Site B confirmed XML update: ' . $status
            );
        }

        // Status is not accepted or duplicate (business-level rejection inside 200 OK)
        $rejectMsg = (string) ($xml->message ?? 'Site B rejected update payload');
        return PushResult::rejected(
            durationMs: $httpResult->durationMs,
            httpStatus: $httpResult->statusCode,
            message: $rejectMsg !== '' ? $rejectMsg : 'Site B rejected update with status: ' . $status
        );
    }
}
