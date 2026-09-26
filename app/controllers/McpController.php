<?php
declare(strict_types=1);

namespace BlaCloud\Controllers;

use BlaCloud\AiTokens;
use BlaCloud\Mcp\Tools;
use BlaCloud\StorageException;

/**
 * Entry point for /mcp — a Model Context Protocol server (JSON-RPC 2.0 over HTTP, "Streamable HTTP"
 * transport, single-JSON-response mode: every request gets one application/json reply, no SSE stream
 * — simpler to run on ordinary PHP hosting, and sufficient for request/response tool calls).
 *
 * Stateless like DavController: every request authenticates itself with an AI access token
 * (Settings > Sync > AI access) via "Authorization: Bearer <token>", never a session cookie. A token
 * grants full read/write access to that one person's own files, calendar, contacts and projects —
 * see app/lib/Mcp/Tools.php for exactly what that means, and app/lib/AiTokens.php for the token model.
 */
final class McpController
{
    private const PROTOCOL_VERSION = '2025-06-18';

    public function handle(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');

        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            http_response_code(405);
            echo json_encode(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32600, 'message' => 'Only POST is supported.']]);
            return;
        }

        $user = $this->authenticate();
        if (!$user) {
            http_response_code(401);
            header('WWW-Authenticate: Bearer realm="BLA-Cloud MCP"');
            echo json_encode(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32000, 'message' => 'Missing or invalid AI access token.']]);
            return;
        }

        $raw = file_get_contents('php://input') ?: '';
        $body = json_decode($raw, true);
        if (!is_array($body)) {
            http_response_code(400);
            echo json_encode(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'Invalid JSON: expected an object or array of requests.']]);
            return;
        }

        $messages = array_is_list($body) ? $body : [$body];
        $responses = [];
        foreach ($messages as $msg) {
            $resp = $this->handleMessage(is_array($msg) ? $msg : [], $user);
            if ($resp !== null) {
                $responses[] = $resp;
            }
        }

        if (!$responses) {
            http_response_code(202); // notifications only — nothing to reply with
            return;
        }
        echo json_encode(array_is_list($body) ? $responses : $responses[0], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** Returns the JSON-RPC response array for a request, or null for a notification (no "id"). */
    private function handleMessage(array $msg, array $user): ?array
    {
        $id = $msg['id'] ?? null;
        $method = $msg['method'] ?? '';
        $params = (array) ($msg['params'] ?? []);
        $isNotification = !array_key_exists('id', $msg);

        try {
            $result = match ($method) {
                'initialize' => [
                    'protocolVersion' => self::PROTOCOL_VERSION,
                    'capabilities' => ['tools' => new \stdClass()],
                    'serverInfo' => ['name' => 'BLA-Cloud', 'title' => 'BLA-Cloud (' . $user['username'] . ')', 'version' => BLA_VERSION],
                ],
                'ping' => new \stdClass(),
                'tools/list' => ['tools' => (static function () {
                    $out = [];
                    foreach (Tools::definitions() as $n => $d) {
                        $out[] = ['name' => $n, 'description' => $d[0], 'inputSchema' => $d[1]];
                    }
                    return $out;
                })()],
                'tools/call' => $this->callTool($params, $user),
                'notifications/initialized' => null,
                default => throw new \DomainException('method_not_found'),
            };
        } catch (\DomainException) {
            if ($isNotification) {
                return null;
            }
            return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32601, 'message' => 'Method not found: ' . $method]];
        }

        if ($isNotification) {
            return null;
        }
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    /** tools/call never surfaces a tool-level failure as a JSON-RPC error — MCP wants isError content instead. */
    private function callTool(array $params, array $user): array
    {
        $name = (string) ($params['name'] ?? '');
        $args = (array) ($params['arguments'] ?? []);
        try {
            $data = Tools::call($name, $args, $user);
            return ['content' => [['type' => 'text', 'text' => json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)]]];
        } catch (StorageException $e) {
            return ['content' => [['type' => 'text', 'text' => $e->getMessage()]], 'isError' => true];
        }
    }

    private function authenticate(): ?array
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (!str_starts_with($header, 'Bearer ')) {
            return null;
        }
        return AiTokens::verify(trim(substr($header, 7)));
    }
}
