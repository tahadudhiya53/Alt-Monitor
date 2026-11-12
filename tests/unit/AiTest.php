<?php

namespace Tahadudhiya\AltMonitor\Tests\unit;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tahadudhiya\AltMonitor\ai\EndpointPolicy;
use Tahadudhiya\AltMonitor\ai\OllamaProvider;
use Tahadudhiya\AltMonitor\ai\PromptBuilder;
use Tahadudhiya\AltMonitor\ai\Translations;
use Tahadudhiya\AltMonitor\errors\AiProviderException;
use Tahadudhiya\AltMonitor\models\ImageContext;
use Tahadudhiya\AltMonitor\services\AiProviders;

/**
 * The AI pieces that need no Craft app: where requests may go, the prompt, reading the model's
 * answer, and talking to Ollama, which is always mocked.
 */
class AiTest extends TestCase
{
    private const HOSTS = ['localhost', '127.0.0.1', '::1', 'host.docker.internal', 'ollama', 'gpu.internal'];

    private static function policy(array $resolved = ['127.0.0.1']): EndpointPolicy
    {
        return new EndpointPolicy(self::HOSTS, static fn(string $host): array => $resolved);
    }

    /**
     * @param list<Response|\Throwable> $responses
     * @param-out MockHandler $mock
     */
    private static function ollama(array $responses, string $model = 'qwen2.5vl:3b', ?MockHandler &$mock = null): OllamaProvider
    {
        $mock = new MockHandler($responses);

        return new OllamaProvider('http://localhost:11434', $model, 30, self::policy(), $mock);
    }

    private static function json(array $data, int $status = 200): Response
    {
        return new Response($status, [], (string)json_encode($data));
    }

    // Endpoint policy

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function endpoints(): iterable
    {
        yield 'local Ollama' => ['http://localhost:11434', true];
        yield 'trailing slash' => ['http://localhost:11434/', true];
        yield 'Docker host' => ['http://host.docker.internal:11434', true];
        yield 'IPv6 loopback' => ['http://[::1]:11434', true];
        yield 'https' => ['https://gpu.internal', true];
        yield 'host not allowed' => ['http://ollama.example.com:11434', false];
        yield 'credentials' => ['http://user:pass@localhost:11434', false];
        yield 'a path' => ['http://localhost:11434/api', false];
        yield 'a query' => ['http://localhost:11434?x=1', false];
        yield 'another scheme' => ['ftp://localhost', false];
        yield 'not a URL' => ['localhost:11434', false];
        yield 'metadata address' => ['http://169.254.169.254', false];
    }

    #[DataProvider('endpoints')]
    public function testOnlyAllowedBaseUrlsAreAccepted(string $url, bool $allowed): void
    {
        self::assertSame($allowed, self::policy()->problem($url) === null);
    }

    public function testAnAllowedHostResolvingToAForbiddenAddressIsRefused(): void
    {
        foreach ([['169.254.169.254'], ['127.0.0.1', '169.254.1.1'], ['::ffff:169.254.169.254'], ['fe80::1'], ['0.0.0.0'], ['224.0.0.1'], []] as $resolved) {
            try {
                self::policy($resolved)->resolve('http://ollama:11434');
                self::fail('Accepted ' . implode(', ', $resolved));
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }

        // Local and private networks are where Ollama lives.
        self::assertSame(['10.0.0.5'], self::policy(['10.0.0.5'])->resolve('http://ollama:11434'));
    }

    public function testTheMetadataAddressIsRefusedEvenWhenAllowedByName(): void
    {
        $policy = new EndpointPolicy(['169.254.169.254']);

        self::assertNotNull($policy->problem('http://169.254.169.254'));
    }

    // Prompt

    public function testThePromptAsksForVisibleContentOnlyAndOffersContextAsAHint(): void
    {
        $prompt = (new PromptBuilder())->build(new ImageContext('x.jpg', 'Spring "fair"', captions: ["Stalls\nin the square"], usedOn: ['Events', 'News', 'Home', 'Ignored']));

        foreach (['Describe only what is clearly visible', 'Do not invent people, objects, locations or activities', 'Do not start with "image of"', 'No keywords', 'Reply with the alt text only', 'must not be described unless it is visible'] as $rule) {
            self::assertStringContainsString($rule, $prompt);
        }

        self::assertStringContainsString('titled "Spring fair"', $prompt);
        self::assertStringContainsString('"Stalls in the square"', $prompt);
        self::assertStringNotContainsString('Ignored', $prompt);
        self::assertStringNotContainsString('Context', (new PromptBuilder())->build(new ImageContext('x.jpg')));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function answers(): iterable
    {
        yield 'plain' => ['A dog running on a beach.', 'A dog running on a beach.'];
        yield 'quoted with a label' => ['Alt text: "A dog running on a beach."', 'A dog running on a beach.'];
        yield 'markdown and commentary' => ["**Alt:** a dog running on a beach\n\nI focused on the main subject.", 'A dog running on a beach'];
        yield 'redundant prefix' => ['An image of a dog running on a beach', 'A dog running on a beach'];
        yield 'this image shows' => ['This image shows two cups of coffee', 'Two cups of coffee'];
        yield 'thinking first' => ["<think>It looks like a dog.</think>\nA dog on a beach", 'A dog on a beach'];
        yield 'bullet' => ['- A red bicycle', 'A red bicycle'];
    }

    #[DataProvider('answers')]
    public function testTheModelsAnswerBecomesCleanAltText(string $answer, string $expected): void
    {
        self::assertSame($expected, (new PromptBuilder())->parse($answer));
    }

    public function testLongAnswersAreCutAtAWord(): void
    {
        $parsed = (new PromptBuilder())->parse(str_repeat('word ', 80));

        self::assertLessThanOrEqual(PromptBuilder::MAX_LENGTH, mb_strlen($parsed));
        self::assertStringEndsWith('word', $parsed);
    }

    public function testAnAnswerWithNothingUsableIsRefused(): void
    {
        foreach (['', "  \n ", '"" ', '12345', '<think>hmm</think>'] as $answer) {
            try {
                (new PromptBuilder())->parse($answer);
                self::fail("Accepted “{$answer}”.");
            } catch (AiProviderException) {
                self::addToAssertionCount(1);
            }
        }
    }

    // Ollama

    public function testStatusReportsAReadyVisionModel(): void
    {
        $status = self::ollama([self::json(['models' => [['name' => 'qwen2.5vl:3b']]]), self::json(['capabilities' => ['completion', 'vision']])])->status();

        self::assertTrue($status->isReady());
        self::assertSame(['qwen2.5vl:3b'], $status->models);
    }

    public function testStatusFindsAnUntaggedModelAsLatest(): void
    {
        self::assertTrue(self::ollama([self::json(['models' => [['name' => 'llava:latest']]]), self::json([])], 'llava')->status()->isReady());
    }

    public function testStatusReportsAMissingModelOrOneWithoutVision(): void
    {
        $missing = self::ollama([self::json(['models' => [['name' => 'llama3:8b']]])])->status();
        self::assertTrue($missing->reachable);
        self::assertFalse($missing->modelAvailable);
        self::assertStringContainsString('ollama pull qwen2.5vl:3b', $missing->message);

        $textOnly = self::ollama([self::json(['models' => [['name' => 'qwen2.5vl:3b']]]), self::json(['capabilities' => ['completion']])])->status();
        self::assertFalse($textOnly->modelAvailable);
        self::assertStringContainsString('can’t read images', $textOnly->message);
    }

    public function testStatusReportsAnUnreachableServer(): void
    {
        $status = self::ollama([new ConnectException('Connection refused', new Request('GET', 'http://localhost:11434/api/tags'))])->status();

        self::assertFalse($status->reachable);
        self::assertStringContainsString('Can’t reach Ollama', $status->message);
    }

    public function testDescribeReturnsTheModelsAnswer(): void
    {
        $provider = self::ollama([self::json(['response' => 'A dog on a beach', 'done' => true])], mock: $mock);

        self::assertSame('A dog on a beach', $provider->describe(base64_encode('jpeg'), 'Describe it'));
        $request = $mock->getLastRequest();
        self::assertNotNull($request);
        self::assertSame('http://localhost:11434/api/generate', (string)$request->getUri());
        self::assertSame(['model', 'prompt', 'images', 'stream', 'options'], array_keys(json_decode((string)$request->getBody(), true)));
    }

    /**
     * @return iterable<string, array{Response|\Throwable, bool}>
     */
    public static function failures(): iterable
    {
        yield 'model missing' => [self::json(['error' => 'model not found'], 404), false];
        yield 'server error' => [self::json(['error' => 'out of memory'], 500), true];
        yield 'busy' => [new Response(429), true];
        yield 'bad request' => [self::json(['error' => 'invalid image'], 400), false];
        yield 'not JSON' => [new Response(200, [], '<html>proxy error</html>'), false];
        yield 'no answer' => [self::json(['done' => true]), false];
        yield 'connection refused' => [new ConnectException('refused', new Request('POST', 'http://localhost:11434/api/generate')), true];
    }

    #[DataProvider('failures')]
    public function testDescribeFailuresSayWhetherRetryingCanHelp(Response|\Throwable $response, bool $retryable): void
    {
        try {
            self::ollama([$response])->describe('aW1hZ2U=', 'Describe it');
            self::fail('No exception.');
        } catch (AiProviderException $exception) {
            self::assertSame($retryable, $exception->retryable);
            self::assertStringNotContainsString('aW1hZ2U=', $exception->getMessage());
        }
    }

    public function testNothingIsSentToAForbiddenEndpoint(): void
    {
        $mock = new MockHandler([self::json(['response' => 'x'])]);
        $provider = new OllamaProvider('http://ollama.example.com', 'm', 30, self::policy(), $mock);

        try {
            $provider->describe('aW1hZ2U=', 'Describe it');
            self::fail('No exception.');
        } catch (AiProviderException) {
            self::assertCount(1, $mock);
        }
    }

    // Translating the browser model's English descriptions

    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function siteLanguages(): iterable
    {
        yield 'German' => ['de', 'Xenova/opus-mt-en-de'];
        yield 'Swiss German, as Craft writes it' => ['de-CH', 'Xenova/opus-mt-en-de'];
        yield 'with an underscore and capitals' => ['FR_fr', 'Xenova/opus-mt-en-fr'];
        yield 'Simplified Chinese' => ['zh-CN', 'Xenova/opus-mt-en-zh'];
        yield 'Traditional Chinese' => ['zh-TW', null];
        yield 'Traditional Chinese by script' => ['zh-Hant', null];
        yield 'English needs none' => ['en-GB', null];
        yield 'Japanese (its model is left out)' => ['ja', null];
        yield 'Portuguese (no single-language model)' => ['pt-BR', null];
        yield 'nonsense' => ['', null];
    }

    #[DataProvider('siteLanguages')]
    public function testEachSiteLanguageGetsItsTranslationModelOrNone(string $language, ?string $model): void
    {
        self::assertSame($model, Translations::forLanguage($language)['model'] ?? null);
    }

    public function testOnlyEnglishCountsAsEnglish(): void
    {
        self::assertTrue(Translations::isEnglish('en'));
        self::assertTrue(Translations::isEnglish('en-US'));
        self::assertFalse(Translations::isEnglish('de'));
        self::assertFalse(Translations::isEnglish('eu'));
    }

    public function testTheBasisSaysWhetherADescriptionWasTranslated(): void
    {
        $plain = AiProviders::browserModelLabel('onnx-community/Florence-2-base-ft');
        $translated = AiProviders::browserModelLabel('onnx-community/Florence-2-base-ft', 'Xenova/opus-mt-en-de');

        self::assertSame('Florence-2-base-ft (in the browser)', $plain);
        self::assertSame('Florence-2-base-ft + opus-mt-en-de (in the browser)', $translated);
        self::assertFalse(AiProviders::isTranslated($plain));
        self::assertTrue(AiProviders::isTranslated($translated));
        self::assertFalse(AiProviders::isTranslated('qwen2.5vl:3b'));
        self::assertFalse(AiProviders::isTranslated(null));
    }

    public function testTranslatingAnEnglishSuggestionAddsTheTranslationModelToItsBasis(): void
    {
        $model = 'Xenova/opus-mt-en-de';

        self::assertSame('Florence-2-base-ft + opus-mt-en-de (in the browser)', AiProviders::translatedBasis('Florence-2-base-ft (in the browser)', $model));
        self::assertSame('qwen2.5vl:3b + opus-mt-en-de (in the browser)', AiProviders::translatedBasis('qwen2.5vl:3b', $model));
        self::assertTrue(AiProviders::isTranslated(AiProviders::translatedBasis(null, $model)));
    }
}
