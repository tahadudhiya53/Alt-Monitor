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
use Tahadudhiya\AltMonitor\services\ContentUsageScanner;
use Tahadudhiya\AltMonitor\services\ImageExport;
use Tahadudhiya\AltMonitor\services\SuggestionGenerator;
use Tahadudhiya\AltMonitor\services\TemplateCheck;

/**
 * The rule-based checks and suggestions: what counts as missing, weak, good or worth a review,
 * and what a suggestion can be built from. Also the template check's reading of rendered pages,
 * and the alt text images embedded in content are given there.
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

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function imgTags(): iterable
    {
        yield 'no alt' => ['<img src="/a.jpg">', true];
        yield 'self-closing, no alt' => ['<img src="/a.jpg" />', true];
        yield 'upper case, no alt' => ['<IMG SRC="/a.jpg">', true];
        yield 'over several lines, no alt' => ["<img\n  src=\"/a.jpg\"\n  width=\"10\">", true];
        yield 'alt only inside another value' => ['<img src="/a.jpg" title="has alt=no">', true];
        yield 'data-alt is not alt' => ['<img src="/a.jpg" data-alt="Text">', true];
        yield 'a > inside a value' => ['<img title="a > b" src="/a.jpg">', true];
        yield 'alt text' => ['<img src="/a.jpg" alt="A dog">', false];
        yield 'empty alt' => ['<img src="/a.jpg" alt="">', false];
        yield 'alt with no value' => ['<img src="/a.jpg" alt>', false];
        yield 'alt last, self-closing' => ['<img src="/a.jpg" alt/>', false];
        yield 'single-quoted alt' => ["<img src='/a.jpg' alt='A dog'>", false];
        yield 'unquoted alt' => ['<img src=/a.jpg alt=dog>', false];
        yield 'upper-case ALT' => ['<img src="/a.jpg" ALT="A dog">', false];
        yield 'alt with spaces around =' => ['<img src="/a.jpg" alt = "A dog">', false];
    }

    #[DataProvider('imgTags')]
    public function testTheTemplateCheckFindsImgTagsWithoutAnAltAttribute(string $tag, bool $missing): void
    {
        $found = TemplateCheck::findProblems("<p>Before</p>$tag<p>After</p>");

        self::assertSame($missing ? [(string)preg_replace('/\s+/', ' ', $tag)] : [], $found['noAttribute']);
        self::assertSame([], $found['noText']);
    }

    public function testTheTemplateCheckIgnoresCommentsScriptsStylesAndOtherTags(): void
    {
        $html = <<<'HTML'
            <!-- <img src="/commented.jpg"> -->
            <script>document.body.innerHTML += '<img src="/script.jpg">';</script>
            <script type="text/template"><img src="/template.jpg"></script>
            <style>/* <img src="/style.jpg"> */</style>
            <picture><source srcset="/a.webp"><img src="/a.jpg" alt="A dog"></picture>
            <image href="/svg.png"></image><imgx src="/x.jpg">
            <p>Text with &lt;img src="/escaped.jpg"&gt;</p>
            HTML;

        self::assertSame(['noAttribute' => [], 'noText' => []], TemplateCheck::findProblems($html));
    }

    public function testTheTemplateCheckNamesImagesTheHelperPrintedWithoutAltText(): void
    {
        $found = TemplateCheck::findProblems('<img src="/a.jpg" alt="" ' . TemplateCheck::NO_TEXT_MARKER . '="12"><img src="/b.jpg" alt="" title="' . TemplateCheck::NO_TEXT_MARKER . '">');

        self::assertSame(['<img src="/a.jpg" alt="" ' . TemplateCheck::NO_TEXT_MARKER . '="12">'], $found['noText']);
        self::assertSame([], $found['noAttribute']);
    }

    public function testTheTemplateCheckWarnsInTheConsoleBeforeTheEndOfTheBody(): void
    {
        $page = "<html><body><img src=\"/a.jpg?x=1&y=2\"><img src=\"/b.jpg\" alt=\"\" data-altmonitor-no-alt-text=\"7\"><img src=\"/c.jpg\" alt=\"ok\">\n</body></html>";
        $checked = TemplateCheck::withWarnings($page);

        // The page is the same, with one script added just before </body>.
        self::assertSame(1, preg_match('/^(.*)<script>(.*)<\/script>(<\/body><\/html>)$/s', $checked, $parts));
        self::assertSame($page, $parts[1] . $parts[3]);

        // Two warnings, as JSON strings that can't close the script or break out of it.
        self::assertSame(2, preg_match_all('/console\.warn\(("(?:[^"\\\\]|\\\\.)*")\);/', $parts[2], $calls));
        self::assertStringNotContainsString('<', $parts[2]);
        self::assertStringNotContainsString('&', $parts[2]);
        $first = json_decode($calls[1][0], flags: JSON_THROW_ON_ERROR);
        $second = json_decode($calls[1][1], flags: JSON_THROW_ON_ERROR);

        self::assertStringStartsWith('AltMonitor (dev mode): Images on this page with no alt attribute: 1.', $first);
        self::assertStringContainsString('{{ altText(asset) }}', $first);
        self::assertStringEndsWith("\n• <img src=\"/a.jpg?x=1&y=2\">", $first);
        self::assertStringStartsWith('AltMonitor (dev mode): Images on this page with no alt text in Craft: 1.', $second);
        self::assertStringContainsString('/b.jpg', $second);
        self::assertStringNotContainsString('/c.jpg', $first . $second);
    }

    public function testTheTemplateCheckLeavesGoodPagesAndPartsOfPagesAlone(): void
    {
        $good = '<html><body><img src="/a.jpg" alt="A dog"></body></html>';
        $fragment = '<div><img src="/a.jpg"></div>';

        self::assertSame($good, TemplateCheck::withWarnings($good));
        // Not a full page (an Ajax fragment, a JSON or XML template): never changed.
        self::assertSame($fragment, TemplateCheck::withWarnings($fragment));
    }

    public function testTheTemplateCheckListsAtMostFiftyTagsAndShortensLongOnes(): void
    {
        $tags = str_repeat('<img src="/a.jpg">', 53) . '<img src="/' . str_repeat('x', 400) . '.jpg">';
        $checked = TemplateCheck::withWarnings("<body>$tags</body>");
        preg_match('/console\.warn\(("(?:[^"\\\\]|\\\\.)*")\);/', $checked, $call);
        $warning = (string)json_decode($call[1], flags: JSON_THROW_ON_ERROR);
        $lines = explode("\n", $warning);

        self::assertStringContainsString('no alt attribute: 54.', $lines[0]);
        self::assertCount(1 + 50 + 1, $lines);
        self::assertSame('…and 4 more.', end($lines));
        self::assertCount(54, TemplateCheck::findProblems($tags)['noAttribute']);
        self::assertSame(300, mb_strlen(TemplateCheck::findProblems($tags)['noAttribute'][53]));
    }

    public function testTheTemplateCheckIsOnByDefault(): void
    {
        self::assertTrue((new Settings())->templateCheck);
    }

    /**
     * @return iterable<string, array{string, array<int, list<string>>}>
     */
    public static function embeddedImages(): iterable
    {
        yield 'no alt attribute' => ['<img src="{asset:5:url}">', [5 => ['']]];
        yield 'empty alt' => ['<img src="{asset:5:url}" alt="">', [5 => ['']]];
        yield 'only spaces' => ['<img src="{asset:5:url}" alt=" &nbsp; ">', [5 => ['']]];
        yield 'alt text, entities decoded, spaces collapsed' => ['<img alt="Fish &amp;  chips" src="{asset:5:url||https://x.test/a.jpg}">', [5 => ['Fish & chips']]];
        yield 'single quotes and a transform' => ["<img src='{asset:6:transform:hero||https://x.test/b.jpg}' alt='A bridge'>", [6 => ['A bridge']]];
        yield 'bare values, upper case' => ['<IMG SRC={asset:7} ALT=Gulls>', [7 => ['Gulls']]];
        yield 'a site in the tag' => ['<img src="{asset:8@2:url}" alt="Dunes">', [8 => ['Dunes']]];
        yield 'self-closing' => ['<img src="{asset:9:url}" alt="Pier"/>', [9 => ['Pier']]];
        yield 'alt only inside another value' => ['<img title="has alt=yes" src="{asset:5:url}">', [5 => ['']]];
        yield 'the same image twice' => ['<img src="{asset:5}" alt="One"><img src="{asset:5}">', [5 => ['One', '']]];
        yield 'a link to an image is not an image' => ['<a href="{asset:5:url}">Download</a>', []];
        yield 'an image that is not an asset' => ['<img src="https://x.test/c.jpg">', []];
        yield 'a reference tag elsewhere in the tag' => ['<img src="/c.jpg" data-x="{asset:5}">', []];
    }

    /**
     * @param array<int, list<string>> $expected
     */
    #[DataProvider('embeddedImages')]
    public function testTheAltTextEmbeddedImagesAreGivenInContentIsRead(string $html, array $expected): void
    {
        self::assertSame($expected, ContentUsageScanner::embeddedAlts("<p>$html</p>"));
        // As Craft stores it: JSON whose text values hold the HTML, at any depth.
        self::assertSame($expected, ContentUsageScanner::embeddedAlts((string)json_encode(['f1' => [1, 2], 'f2' => ['nested' => "<p>$html</p>"]])));
    }
}
