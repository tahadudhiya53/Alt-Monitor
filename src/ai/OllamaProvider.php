<?php

namespace Tahadudhiya\AltMonitor\ai;

use Craft;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\HandlerStack;
use Psr\Http\Message\ResponseInterface;
use Tahadudhiya\AltMonitor\errors\AiProviderException;
use Tahadudhiya\AltMonitor\models\AiStatus;

/**
 * Describes images with a vision model served by a local Ollama.
 *
 * Every request goes through {@see EndpointPolicy}: the host must be allowed, the addresses it
 * resolves to are checked, and the connection is pinned to them. Redirects are not followed. The
 * request does not use Craft's HTTP proxy setting, since a local service should not be reached
 * through one. Errors are reported without the image data or the request body.
 */
class OllamaProvider implements AiProviderInterface
{
    /** Seconds to wait for Ollama to accept a connection, and for anything but a description. */
    private const CONNECT_TIMEOUT = 5;

    /**
     * @param callable|null $handler A Guzzle handler, for tests; the default sends real requests.
     */
    public function __construct(
        private readonly string $endpoint,
        private readonly string $model,
        private readonly int $timeout,
        private readonly EndpointPolicy $policy,
        private $handler = null,
    ) {
    }

    public static function handle(): string
    {
        return 'ollama';
    }

    public static function displayName(): string
    {
        return 'Ollama';
    }

    public function modelName(): string
    {
        return $this->model;
    }

    public function status(): AiStatus
    {
        try {
            $response = $this->request('GET', '/api/tags', [], self::CONNECT_TIMEOUT);
        } catch (AiProviderException $exception) {
            return new AiStatus(false, false, $exception->getMessage());
        }

        if ($response->getStatusCode() !== 200) {
            return new AiStatus(false, false, Craft::t('alt-monitor', 'Ollama answered with HTTP {code}.', ['code' => $response->getStatusCode()]));
        }

        $models = array_values(array_filter(array_map(
            static fn(mixed $model): string => is_array($model) ? (string)($model['name'] ?? $model['model'] ?? '') : '',
            (array)(self::json($response)['models'] ?? []),
        )));

        if (!in_array($this->model, $models, true) && !in_array($this->model . ':latest', $models, true)) {
            return new AiStatus(true, false, Craft::t('alt-monitor', 'Ollama is running, but the model “{model}” is not installed. Install it with: ollama pull {model}', ['model' => $this->model]), $models);
        }

        // Newer Ollama versions say what a model can do; an older one that doesn't is trusted.
        try {
            $show = $this->request('POST', '/api/show', ['json' => ['model' => $this->model]], self::CONNECT_TIMEOUT);
            $capabilities = $show->getStatusCode() === 200 ? (self::json($show)['capabilities'] ?? null) : null;
        } catch (AiProviderException) {
            $capabilities = null;
        }

        if (is_array($capabilities) && !in_array('vision', $capabilities, true)) {
            return new AiStatus(true, false, Craft::t('alt-monitor', '“{model}” is installed but can’t read images. Choose a vision model.', ['model' => $this->model]), $models);
        }

        return new AiStatus(true, true, Craft::t('alt-monitor', 'Ollama is running and “{model}” is installed.', ['model' => $this->model]), $models);
    }

    public function describe(string $imageBase64, string $prompt): string
    {
        $response = $this->request('POST', '/api/generate', ['json' => [
            'model' => $this->model,
            'prompt' => $prompt,
            'images' => [$imageBase64],
            'stream' => false,
            'options' => ['temperature' => 0.2, 'num_predict' => 120],
        ]], $this->timeout);
        $code = $response->getStatusCode();

        if ($code === 404) {
            throw new AiProviderException(Craft::t('alt-monitor', 'The model “{model}” is not installed in Ollama.', ['model' => $this->model]));
        }

        if ($code >= 500 || $code === 429) {
            throw new AiProviderException(Craft::t('alt-monitor', 'Ollama answered with HTTP {code}.', ['code' => $code]), true);
        }

        if ($code !== 200) {
            throw new AiProviderException(Craft::t('alt-monitor', 'Ollama answered with HTTP {code}.', ['code' => $code]));
        }

        $answer = self::json($response)['response'] ?? null;

        if (!is_string($answer)) {
            throw new AiProviderException(Craft::t('alt-monitor', 'Ollama’s answer wasn’t in the expected format.'));
        }

        return $answer;
    }

    /**
     * @param array<string, mixed> $options
     * @throws AiProviderException
     */
    private function request(string $method, string $path, array $options, int $timeout): ResponseInterface
    {
        try {
            $addresses = $this->policy->resolve($this->endpoint);
        } catch (\InvalidArgumentException $exception) {
            throw new AiProviderException($exception->getMessage(), false, $exception);
        }

        $client = new Client([
            'handler' => $this->handler !== null ? HandlerStack::create($this->handler) : HandlerStack::create(),
            'base_uri' => $this->endpoint,
            'allow_redirects' => false,
            'http_errors' => false,
            'connect_timeout' => self::CONNECT_TIMEOUT,
            'timeout' => $timeout,
            'proxy' => '',
            'curl' => [CURLOPT_RESOLVE => $this->pins($addresses)],
            'headers' => ['Accept' => 'application/json', 'User-Agent' => 'AltMonitor'],
        ]);

        try {
            return $client->request($method, $path, $options);
        } catch (ConnectException $exception) {
            Craft::warning(sprintf('Ollama could not be reached: %s', $exception->getHandlerContext()['error'] ?? 'connection failed'), __METHOD__);

            throw new AiProviderException(Craft::t('alt-monitor', 'Can’t reach Ollama at {endpoint}. Check that it’s running.', ['endpoint' => $this->endpoint]), true, $exception);
        } catch (GuzzleException $exception) {
            Craft::warning(sprintf('The request to Ollama failed: %s', get_class($exception)), __METHOD__);

            throw new AiProviderException(Craft::t('alt-monitor', 'The request to Ollama failed or timed out.'), true, $exception);
        }
    }

    /**
     * cURL's resolve entries pinning the endpoint's host to the addresses that were checked.
     *
     * @param list<string> $addresses
     * @return list<string>
     */
    private function pins(array $addresses): array
    {
        $parts = (array)parse_url($this->endpoint);
        $host = trim((string)($parts['host'] ?? ''), '[]');
        $port = $parts['port'] ?? (($parts['scheme'] ?? 'http') === 'https' ? 443 : 80);

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [];
        }

        return ["$host:$port:" . implode(',', array_map(static fn(string $address): string => str_contains($address, ':') ? "[$address]" : $address, $addresses))];
    }

    /**
     * @return array<string, mixed>
     * @throws AiProviderException
     */
    private static function json(ResponseInterface $response): array
    {
        try {
            $data = json_decode((string)$response->getBody(), true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new AiProviderException(Craft::t('alt-monitor', 'Ollama’s answer wasn’t in the expected format.'), false, $exception);
        }

        return is_array($data) ? $data : [];
    }
}
