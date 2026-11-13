<?php

namespace Tahadudhiya\AltMonitor\Tests\unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tahadudhiya\AltMonitor\enums\AltIssue;
use Tahadudhiya\AltMonitor\enums\AltStatus;
use Tahadudhiya\AltMonitor\models\AltAnalysis;
use Tahadudhiya\AltMonitor\models\ImageContext;
use Tahadudhiya\AltMonitor\models\Settings;
use Tahadudhiya\AltMonitor\services\AltTextAnalyzer;
use Tahadudhiya\AltMonitor\services\AltTextEditor;
use Tahadudhiya\AltMonitor\services\ImageExport;
use Tahadudhiya\AltMonitor\services\SuggestionGenerator;

/**
 * The rule-based checks and suggestions: what counts as missing, weak, good or worth a review,
 * and what a suggestion can be built from.
 */
class RulesTest extends TestCase
{
    /**
     * @return iterable<string, array{?string, string, bool, AltStatus, list<AltIssue>}>
     */
    public static function classifications(): iterable
    {
        yield 'no alt text' => [null, 'team.jpg', false, AltStatus::MISSING, [AltIssue::EMPTY]];
        yield 'whitespace only' => ["  \n\t ", 'team.jpg', false, AltStatus::MISSING, [AltIssue::EMPTY]];
        yield 'empty and marked decorative' => ['', 'team.jpg', true, AltStatus::GOOD, [AltIssue::DECORATIVE]];
        yield 'empty, decorative-looking name' => ['', 'section_divider.png', false, AltStatus::REVIEW, [AltIssue::POSSIBLY_DECORATIVE]];
        yield 'empty, background image' => ['', 'hero-bg.jpg', false, AltStatus::REVIEW, [AltIssue::POSSIBLY_DECORATIVE]];
        yield 'the file name' => ['team-photo.jpg', 'team-photo.jpg', false, AltStatus::WEAK, [AltIssue::FILENAME, AltIssue::FILE_EXTENSION]];
        yield 'the file name without extension' => ['team_photo', 'team_photo.jpg', false, AltStatus::WEAK, [AltIssue::FILENAME]];
        yield 'a file extension' => ['Our team.png', 'x.jpg', false, AltStatus::WEAK, [AltIssue::FILE_EXTENSION]];
        yield 'a camera name' => ['IMG_1234', 'other.jpg', false, AltStatus::WEAK, [AltIssue::IDENTIFIER]];
        yield 'a screenshot name' => ['Screenshot 2024-01-05', 'x.png', false, AltStatus::WEAK, [AltIssue::IDENTIFIER]];
        yield 'a hash' => ['a3f9c2e1b7', 'x.png', false, AltStatus::WEAK, [AltIssue::IDENTIFIER]];
        yield 'a lone generic word' => ['Photo', 'x.jpg', false, AltStatus::WEAK, [AltIssue::GENERIC]];
        yield 'a generic phrase' => ['an image', 'x.jpg', false, AltStatus::WEAK, [AltIssue::GENERIC]];
        yield 'a numbered generic word' => ['Picture 3', 'x.jpg', false, AltStatus::WEAK, [AltIssue::GENERIC]];
        yield 'keyword stuffing' => ['cheap shoes cheap shoes buy cheap shoes', 'x.jpg', false, AltStatus::WEAK, [AltIssue::REPETITIVE]];
        yield 'a readable phrase matching the file name' => ['Harbour at night', 'harbour-at-night.jpg', false, AltStatus::GOOD, []];
        yield 'a short description' => ['Acme', 'logo.png', false, AltStatus::GOOD, []];
        yield 'a description' => ['Five people around a meeting table', 'x.jpg', false, AltStatus::GOOD, []];
        yield 'text that describes, with a redundant prefix' => ['Image of a dog on a beach', 'x.jpg', false, AltStatus::REVIEW, [AltIssue::REDUNDANT_PREFIX]];
        yield 'a single character' => ['A', 'x.jpg', false, AltStatus::REVIEW, [AltIssue::VERY_SHORT]];
        yield 'very long text' => [implode(' ', array_map(static fn(int $i): string => "word$i", range(1, 60))), 'x.jpg', false, AltStatus::REVIEW, [AltIssue::TOO_LONG]];
        yield 'decorative mark ignored once there is text' => ['IMG_1234', 'x.jpg', true, AltStatus::WEAK, [AltIssue::IDENTIFIER]];
    }

    /**
     * @param list<AltIssue> $issues
     */
    #[DataProvider('classifications')]
    public function testAltTextIsClassified(?string $alt, string $filename, bool $decorative, AltStatus $status, array $issues): void
    {
        $analysis = (new AltTextAnalyzer())->analyze($alt, $filename, $decorative);

        self::assertSame($status, $analysis->status);
        self::assertSame($issues, $analysis->issues);
        self::assertEquals($issues, AltAnalysis::issuesFromString($analysis->issuesString()));
    }

    public function testAdditionalGenericTermsAreConfigurable(): void
    {
        $analyzer = new AltTextAnalyzer(['additionalGenericTerms' => ['Product shot']]);

        self::assertSame(AltStatus::WEAK, $analyzer->analyze('product shot', 'x.jpg')->status);
        self::assertSame(AltStatus::GOOD, (new AltTextAnalyzer())->analyze('product shot', 'x.jpg')->status);
    }

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function filenames(): iterable
    {
        yield 'separators and case' => ['red-kite_over-the.Hills.jpg', 'Red kite over the hills'];
        yield 'camel case' => ['annualReportCover.png', 'Annual report cover'];
        yield 'sizes, retina and versions' => ['beach-sunset-1920x1080@2x-v2-final.webp', 'Beach sunset'];
        yield 'repeated words' => ['dog-dog-park-dog.jpg', 'Dog park'];
        yield 'stock prefixes and IDs' => ['shutterstock_123456789-mountain-lake.jpg', 'Mountain lake'];
        yield 'acronyms keep their capitals' => ['NHS-hospital-entrance.jpg', 'NHS hospital entrance'];
        yield 'a camera name' => ['IMG_20240101_123456.jpg', null];
        yield 'a hash' => ['3f2a9c1d7e8b.png', null];
        yield 'only noise' => ['image-final-copy.png', null];
    }

    #[DataProvider('filenames')]
    public function testFileNamesBecomeReadableText(string $filename, ?string $expected): void
    {
        self::assertSame($expected, SuggestionGenerator::humanizeFilename($filename));
    }

    public function testSuggestionsPreferACaptionThenATitleThenTheFileNameThenUsage(): void
    {
        $generator = new SuggestionGenerator(new AltTextAnalyzer());

        $caption = $generator->suggest(new ImageContext('IMG_1.jpg', 'Img 1', captions: ['<p>A heron <b>fishing</b> at dawn. Taken by Jo.</p>']));
        self::assertSame(['A heron fishing at dawn', SuggestionGenerator::BASIS_CAPTION, false], [$caption?->text, $caption->basis, $caption->needsReview]);

        $title = $generator->suggest(new ImageContext('IMG_2.jpg', 'Grey heron by the river'));
        self::assertSame(['Grey heron by the river', SuggestionGenerator::BASIS_TITLE, true], [$title?->text, $title->basis, $title->needsReview]);

        // A title Craft made from the file name says nothing more than the name.
        $filename = $generator->suggest(new ImageContext('grey-heron.jpg', 'Grey heron'));
        self::assertSame(['Grey heron', SuggestionGenerator::BASIS_FILENAME], [$filename?->text, $filename->basis]);

        $usage = $generator->suggest(new ImageContext('DSC_0042.jpg', 'DSC 0042', usedOn: ['Summer open day']));
        self::assertSame(['Summer open day', SuggestionGenerator::BASIS_USAGE, true], [$usage?->text, $usage->basis, $usage->needsReview]);

        self::assertNull($generator->suggest(new ImageContext('DSC_0042.jpg', 'DSC 0042')));
        // A generic caption is no better than none.
        self::assertNull($generator->suggest(new ImageContext('DSC_0042.jpg', captions: ['Photo'])));
    }

    public function testAltTextIsSavedAsPlainSingleLineText(): void
    {
        self::assertSame('A wooden jetty at dawn', AltTextEditor::sanitize("  A <b>wooden</b>\n jetty&nbsp;at dawn\u{200B} "));
        self::assertSame('Fish & chips', AltTextEditor::sanitize('Fish &amp; chips'));
    }

    public function testExportedCellsNeverRunAsSpreadsheetFormulas(): void
    {
        foreach (['=HYPERLINK("https://example.com")', '+1', '-2+3', '@SUM(A1)', "\tA tab", "\rA return"] as $text) {
            self::assertSame("'" . $text, ImageExport::cell($text), $text);
        }

        foreach (['A heron', '', 'Price: =5', "Owl's nest", '2024 harbour'] as $text) {
            self::assertSame($text, ImageExport::cell($text), $text);
        }
    }

    public function testByDefaultSuggestionsDescribeTheImageNotItsFileName(): void
    {
        $settings = new Settings();

        // No AI switch to turn on: the browser model is the default, Ollama is opt-in by config.
        self::assertFalse($settings->aiEnabled);
        self::assertFalse($settings->fallbackToRules);
        self::assertSame('onnx-community/Florence-2-base-ft', $settings->browserModel);
    }

    public function testSettingsListsAreTrimmedAndDeduplicated(): void
    {
        self::assertSame(['caption', 'credit'], Settings::normalizeList("caption\n\n credit \ncaption"));
        self::assertSame(['a', 'b'], Settings::normalizeList([' a', 'b', '']));
    }
}
