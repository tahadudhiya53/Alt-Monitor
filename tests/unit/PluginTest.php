<?php

namespace Tahadudhiya\AltMonitor\Tests\unit;

use PHPUnit\Framework\TestCase;
use Tahadudhiya\AltMonitor\AltMonitor;

/**
 * What Craft reads from the plugin itself: its metadata, the files it looks for by convention,
 * and a source message for every translated string.
 */
class PluginTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    /**
     * @return array<string, mixed>
     */
    private static function composer(): array
    {
        return json_decode((string)file_get_contents(self::ROOT . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    public function testComposerMetadataMatchesThePluginClass(): void
    {
        $composer = self::composer();

        self::assertSame('craft-plugin', $composer['type']);
        self::assertSame(AltMonitor::class, $composer['extra']['class']);
        self::assertSame('alt-monitor', $composer['extra']['handle']);
        self::assertSame('^5.9.0', $composer['require']['craftcms/cms']);
        self::assertSame('>=8.2.0', $composer['require']['php']);
        // Nothing beyond Craft: Guzzle, used for Ollama, comes with it.
        self::assertSame(['php', 'craftcms/cms'], array_keys($composer['require']));
        // Free for everyone to use, change and share.
        self::assertSame('MIT', $composer['license']);
        self::assertStringStartsWith('# MIT License', (string)file_get_contents(self::ROOT . '/LICENSE.md'));
    }

    public function testTheBundledLibraryIsTheOneTheWorkerLoadsWithItsLicence(): void
    {
        $dist = self::ROOT . '/src/web/assets/cp/dist';

        self::assertStringContainsString('Florence2ForConditionalGeneration', (string)file_get_contents($dist . '/vendor/transformers.min.js'));
        self::assertStringContainsString('Apache License', (string)file_get_contents($dist . '/vendor/transformers.LICENSE.txt'));
        self::assertStringContainsString('Florence2ForConditionalGeneration', (string)file_get_contents($dist . '/describe-worker.js'));
    }

    public function testTheVersionsAreTheOnesDevelopmentStaysOn(): void
    {
        $defaults = (new \ReflectionClass(AltMonitor::class))->getDefaultProperties();

        self::assertSame('5.0.0', self::composer()['version']);
        self::assertSame('1.0.0', $defaults['schemaVersion']);
        self::assertTrue($defaults['hasCpSection']);
    }

    public function testThereIsOnlyTheInstallMigration(): void
    {
        self::assertSame(['Install.php'], array_map('basename', glob(self::ROOT . '/src/migrations/*.php') ?: []));
    }

    public function testBothIconsExistAndTheNavIconIsDrawnWithFills(): void
    {
        self::assertFileExists(self::ROOT . '/src/icon.svg');
        self::assertStringNotContainsString('stroke', (string)file_get_contents(self::ROOT . '/src/icon-mask.svg'));
    }

    public function testOnlyTheThreeDocumentsExist(): void
    {
        $markdown = array_map('basename', glob(self::ROOT . '/*.md') ?: []);
        sort($markdown);

        self::assertSame(['CHANGELOG.md', 'CLAUDE.md', 'LICENSE.md', 'README.md'], $markdown);
    }

    public function testEveryTranslatedMessageHasASourceMessage(): void
    {
        $messages = require self::ROOT . '/src/translations/en/alt-monitor.php';
        $literal = "'((?:[^'\\\\]|\\\\.)*)'";
        $patterns = [
            'php' => ["/Craft::t\\(\\s*'alt-monitor',\\s*$literal/", "/Translation::prep\\(\\s*'alt-monitor',\\s*$literal/"],
            'twig' => ["/$literal\\|t\\('alt-monitor'/"],
            'js' => ["/Craft\\.t\\('alt-monitor',\\s*$literal/"],
        ];
        $missing = [];
        $found = 0;
        $files = new \RegexIterator(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT . '/src')), '/\.(php|twig|js)$/');

        foreach ($files as $file) {
            $code = (string)file_get_contents((string)$file);

            foreach ($patterns[pathinfo((string)$file, PATHINFO_EXTENSION)] as $pattern) {
                preg_match_all($pattern, $code, $matches);

                foreach ($matches[1] as $message) {
                    $found++;

                    if (!array_key_exists(stripslashes($message), $messages)) {
                        $missing[] = stripslashes($message);
                    }
                }
            }
        }

        self::assertGreaterThan(150, $found, 'The scan found too few messages to be reading the source.');
        self::assertSame([], array_values(array_unique($missing)));
    }
}
