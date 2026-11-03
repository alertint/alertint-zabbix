<?php

namespace Modules\AlertINT\Includes;

use Exception;

/**
 * Minimal MCP (Streamable HTTP) client: initialize once per request, then call tools.
 * The AlertINT agent answers plain JSON with structuredContent, so no SSE parsing is needed.
 */
class McpClient {

	private string $url;
	private string $token;
	private ?string $session = null;
	private int $id = 0;
	private array $server_info = [];

	public function __construct(string $url, string $token) {
		if ($url === '') {
			throw new Exception(_('AlertINT is not set up yet: a Super admin adds the agent address in AlertINT → Settings.'));
		}

		$this->url = $url;
		$this->token = $token;
	}

	/**
	 * serverInfo from the MCP handshake (the MCP server's name/version, not the agent release).
	 */
	public function serverInfo(): array {
		if ($this->session === null) {
			$this->initialize();
		}

		return $this->server_info;
	}

	public function callTool(string $name, array $arguments = []): array {
		if ($this->session === null) {
			$this->initialize();
		}

		$result = $this->rpc('tools/call', ['name' => $name, 'arguments' => (object) $arguments]);

		if (!empty($result['isError'])) {
			$text = $result['content'][0]['text'] ?? 'tool error';
			throw new Exception($name.': '.$text);
		}

		if (isset($result['structuredContent'])) {
			return $result['structuredContent'];
		}

		$decoded = json_decode($result['content'][0]['text'] ?? '', true);

		return is_array($decoded) ? $decoded : [];
	}

	private function initialize(): void {
		$result = $this->rpc('initialize', [
			'protocolVersion' => '2025-03-26',
			'capabilities' => (object) [],
			'clientInfo' => ['name' => 'zabbix-alertint-module', 'version' => '0.1.0']
		]);
		$this->server_info = $result['serverInfo'] ?? [];
		$this->post(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);
	}

	private function rpc(string $method, array $params): array {
		$response = $this->post(['jsonrpc' => '2.0', 'id' => ++$this->id, 'method' => $method, 'params' => $params]);

		if (isset($response['error'])) {
			throw new Exception($method.': '.($response['error']['message'] ?? 'rpc error'));
		}

		return $response['result'] ?? [];
	}

	private function post(array $body): array {
		$headers = [
			'Content-Type: application/json',
			'Accept: application/json, text/event-stream',
			'Authorization: Bearer '.$this->token
		];

		if ($this->session !== null) {
			$headers[] = 'Mcp-Session-Id: '.$this->session;
		}

		$context = stream_context_create(['http' => [
			'method' => 'POST',
			'header' => implode("\r\n", $headers),
			'content' => json_encode($body),
			'timeout' => 8,
			'ignore_errors' => true
		]]);

		$raw = @file_get_contents($this->url, false, $context);

		if ($raw === false) {
			throw new Exception('AlertINT agent unreachable at '.$this->url);
		}

		$status = 0;

		foreach ($http_response_header ?? [] as $line) {
			if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
				$status = (int) $m[1];
			}
			elseif (stripos($line, 'Mcp-Session-Id:') === 0) {
				$this->session = trim(substr($line, strlen('Mcp-Session-Id:')));
			}
		}

		if ($status === 401 || $status === 403) {
			throw new Exception('AlertINT agent refused the MCP token (HTTP '.$status.')');
		}

		if ($status >= 300) {
			throw new Exception('AlertINT agent replied HTTP '.$status.' at '.$this->url);
		}

		if ($raw === '') {
			return [];
		}

		// Tolerate an SSE-framed reply: take the last "data:" line.
		if (strncmp(ltrim($raw), 'event:', 6) === 0 || strncmp(ltrim($raw), 'data:', 5) === 0) {
			preg_match_all('/^data:\s*(.+)$/m', $raw, $m);
			$raw = end($m[1]) ?: '';
		}

		$decoded = json_decode($raw, true);

		return is_array($decoded) ? $decoded : [];
	}
}
