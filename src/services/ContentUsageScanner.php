<?php

namespace Tahadudhiya\AltMonitor\services;

use Craft;
use craft\base\ElementInterface;
use craft\base\NestedElementInterface;
use craft\db\Query;
use craft\db\Table;
use craft\elements\Asset;
use craft\helpers\Db;
use Tahadudhiya\AltMonitor\enums\UsageKind;
use Tahadudhiya\AltMonitor\models\UsageReference;
use Tahadudhiya\AltMonitor\records\UsageRecord;
use yii\base\Component;
use yii\db\Expression;

/**
 * Finds where content references image assets: the only writer of `altmonitor_usages`.
 *
 * Two kinds of reference are found, both recorded by Craft itself:
 *
 * - relations, which every relational field (Assets fields, at any depth of Matrix or other nested
 *   entries, in entries, global sets, categories, users, products…) stores in Craft's `relations`
 *   table;
 * - reference tags such as `{asset:123:url}` in field content, which is how rich text editors
 *   store embedded images.
 *
 * For images embedded with an `<img>` tag, it also records whether the content gives the image alt
 * text of its own: rich text keeps the alt text it was inserted with, which may be empty or no
 * longer match the asset's. Only yes or no is stored, never the text.
 *
 * Both are read-only queries of Craft's tables, walked in ID order a batch at a time. Drafts,
 * revisions and trashed content are left out. Anything else that shows an image (a template,
 * custom code, an external system using its URL) is invisible here, so finding no reference
 * never proves an image is unused.
 */
class ContentUsageScanner extends Component
{
    /** Matches the asset ID in a reference tag: `{asset:123}`, `{asset:123:url}`, `{asset:123@1:url||…}`. */
    public const REFERENCE_TAG = '/\{asset:(\d+)(?=[:@}|])/';

    /** An `<img>` tag, with `>` allowed inside quoted values. */
    private const IMG_TAG = '/<img\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>/i';

    /** One attribute of a tag: its name, then a double-quoted, single-quoted or bare value, if any. */
    private const ATTRIBUTE = '/([^\s"\'>\/=]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+)))?/';

    /**
     * Records one batch of relations to image assets.
     *
     * @return array{0: int|null, 1: int} The last relation ID read, or null at the end; how many were read.
     */
    public function scanRelations(int $afterId, int $limit): array
    {
        $rows = $this->relationQuery()
            ->select(['relations.id', 'relations.sourceId', 'relations.sourceSiteId', 'relations.targetId', 'relations.fieldId'])
            ->andWhere(['>', 'relations.id', $afterId])
            ->orderBy(['relations.id' => SORT_ASC])
            ->limit($limit)
            ->all();

        foreach ($rows as $row) {
            $this->record((int)$row['targetId'], (int)$row['sourceId'], $row['sourceSiteId'] !== null ? (int)$row['sourceSiteId'] : null, (int)$row['fieldId'], UsageKind::RELATION);
        }

        return [$rows === [] ? null : (int)end($rows)['id'], count($rows)];
    }

    /**
     * Records the image reference tags in one batch of element content.
     *
     * @return array{0: int|null, 1: int} The last content row ID read, or null at the end; how many were read.
     */
    public function scanReferenceTags(int $afterId, int $limit): array
    {
        $rows = $this->contentQuery()
            ->select(['elements_sites.id', 'elements_sites.elementId', 'elements_sites.siteId', 'content' => $this->contentText()])
            ->andWhere(['>', 'elements_sites.id', $afterId])
            ->orderBy(['elements_sites.id' => SORT_ASC])
            ->limit($limit)
            ->all();
        $found = [];

        foreach ($rows as $row) {
            preg_match_all(self::REFERENCE_TAG, (string)$row['content'], $matches);
            $embedded = self::embeddedAlts((string)$row['content']);

            foreach (array_unique($matches[1]) as $assetId) {
                // Only <img> tags have alt text; a link to the image ({asset:1:url} in href) doesn't.
                $alts = $embedded[(int)$assetId] ?? null;
                $found[] = [(int)$assetId, (int)$row['elementId'], (int)$row['siteId'], $alts === null ? null : !in_array('', $alts, true)];
            }
        }

        if ($found !== []) {
            $images = array_flip(Asset::find()->id(array_unique(array_column($found, 0)))->kind(Asset::KIND_IMAGE)->status(null)->site('*')->unique()->ids());

            foreach ($found as [$assetId, $elementId, $siteId, $altInContent]) {
                if (isset($images[$assetId])) {
                    $this->record($assetId, $elementId, $siteId, null, UsageKind::REFERENCE_TAG, $altInContent);
                }
            }
        }

        return [$rows === [] ? null : (int)end($rows)['id'], count($rows)];
    }

    /**
     * The alt text each `<img>` in some content gives the image asset its `src` references, by
     * asset ID: one entry per tag, '' when the tag has no alt text. Content is read as Craft
     * stores it (JSON, whose text values hold the HTML) or as plain HTML.
     *
     * @return array<int, list<string>>
     */
    public static function embeddedAlts(string $content): array
    {
        $alts = [];

        foreach (self::texts($content) as $text) {
            if (!str_contains($text, '{asset:')) {
                continue;
            }

            preg_match_all(self::IMG_TAG, $text, $tags);

            foreach ($tags[0] as $tag) {
                $attributes = self::attributes($tag);

                if (!preg_match(self::REFERENCE_TAG, $attributes['src'] ?? '', $match)) {
                    continue;
                }

                $alt = html_entity_decode($attributes['alt'] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $alts[(int)$match[1]][] = trim((string)preg_replace('/[\s\x{00A0}]+/u', ' ', $alt));
            }
        }

        return $alts;
    }

    /** How many relations and content rows a usage scan will read, for progress. */
    public function workCount(): int
    {
        return (int)$this->relationQuery()->select(['relations.id'])->count() + (int)$this->contentQuery()->select(['elements_sites.id'])->count();
    }

    /**
     * Removes usage a scan that started at the given time no longer found.
     */
    public function pruneNotFoundSince(string $since): int
    {
        return Db::delete(UsageRecord::TABLE, ['<', 'dateUpdated', $since]);
    }

    /**
     * How many references each image has.
     *
     * @param list<int> $assetIds
     * @return array<int, int>
     */
    public function counts(array $assetIds): array
    {
        if ($assetIds === []) {
            return [];
        }

        // One per piece of content, however many sites it references the image in.
        return array_map('intval', (new Query())
            ->select(['COUNT(DISTINCT [[sourceElementId]])'])
            ->from(UsageRecord::TABLE)
            ->where(['assetId' => array_unique($assetIds)])
            ->groupBy(['assetId'])
            ->indexBy('assetId')
            ->column());
    }

    /**
     * Where one image is referenced, as last scanned: one entry per piece of content, field and
     * kind of reference, with the sites it is referenced in.
     *
     * @return list<UsageReference>
     */
    public function references(int $assetId, int $limit = 100, ?\craft\elements\User $user = null, ?int $siteId = null): array
    {
        $query = (new Query())
            ->select(['sourceElementId', 'sourceSiteId', 'fieldId', 'kind'])
            ->from(UsageRecord::TABLE)
            ->where(['assetId' => $assetId]);

        // With a site: only content in it, and relations that apply in every site. (Not
        // andFilterWhere(), which would drop the `sourceSiteId IS NULL` half.)
        if ($siteId !== null) {
            $query->andWhere(['or', ['sourceSiteId' => null], ['sourceSiteId' => $siteId]]);
        }

        $rows = $query
            ->orderBy(['id' => SORT_ASC])
            ->limit($limit * 10)
            ->all();
        $groups = [];

        foreach ($rows as $row) {
            $key = $row['sourceElementId'] . ':' . $row['fieldId'] . ':' . $row['kind'];
            $groups[$key] ??= $row + ['siteIds' => []];

            if ($row['sourceSiteId'] === null) {
                $groups[$key]['allSites'] = true;
            } else {
                $groups[$key]['siteIds'][] = (int)$row['sourceSiteId'];
            }
        }

        $references = [];
        $sites = Craft::$app->getSites();

        $assetAlts = $this->embeddedAltsFor($assetId, array_filter($groups, static fn(array $row): bool => $row['kind'] === UsageKind::REFERENCE_TAG->value));

        foreach (array_slice($groups, 0, $limit) as $row) {
            $siteIds = $row['siteIds'];
            $element = $this->loadElement((int)$row['sourceElementId'], $siteIds[0] ?? null);

            if ($element === null) {
                continue;
            }

            $root = $element instanceof NestedElementInterface ? $element->getRootOwner() : $element;
            $field = $row['fieldId'] !== null ? Craft::$app->getFields()->getFieldById((int)$row['fieldId']) : null;

            // The reference is reported, but not what the user may not see.
            if ($user !== null && !Craft::$app->getElements()->canView($root, $user)) {
                $references[] = new UsageReference(UsageKind::from($row['kind']), Craft::t('alt-monitor', 'Content you can’t see'), $root::displayName(), null, null, null, null);
                continue;
            }

            $references[] = new UsageReference(
                kind: UsageKind::from($row['kind']),
                label: $root->getUiLabel(),
                elementType: $root::displayName(),
                cpEditUrl: $root->getCpEditUrl(),
                fieldName: $field?->name,
                siteName: isset($row['allSites']) || $siteIds === [] ? null : implode(', ', array_filter(array_map(static fn(int $id): ?string => $sites->getSiteById($id)?->getName(), $siteIds))),
                nestedIn: $root !== $element ? $element->getUiLabel() : null,
                contentAlts: $row['kind'] === UsageKind::REFERENCE_TAG->value ? ($assetAlts[$row['sourceElementId'] . ':' . implode(',', $siteIds)] ?? null) : null,
            );
        }

        return $references;
    }

    /**
     * Titles of content using an image, as context for a suggestion.
     *
     * @return list<string>
     */
    public function titles(int $assetId, int $limit = 3): array
    {
        $titles = array_map(static fn(UsageReference $reference): string => $reference->label, $this->references($assetId, $limit * 2));

        return array_slice(array_values(array_unique(array_filter($titles, static fn(string $title): bool => trim($title) !== ''))), 0, $limit);
    }

    /**
     * What the content holding each reference tag says about the image now, read from Craft's
     * content (not from the last scan), so the image page shows the current text.
     *
     * @param array<string, array<string, mixed>> $groups
     * @return array<string, list<string>> By source element ID and its site IDs.
     */
    private function embeddedAltsFor(int $assetId, array $groups): array
    {
        $alts = [];

        foreach (array_slice($groups, 0, 100) as $row) {
            $siteIds = $row['siteIds'];
            $contents = (new Query())
                ->select(['content' => $this->contentText()])
                ->from(['elements_sites' => Table::ELEMENTS_SITES])
                ->where(['elements_sites.elementId' => (int)$row['sourceElementId']])
                ->andFilterWhere(['elements_sites.siteId' => $siteIds === [] ? null : $siteIds])
                ->column();
            $found = [];

            foreach ($contents as $content) {
                array_push($found, ...(self::embeddedAlts((string)$content)[$assetId] ?? []));
            }

            if ($found !== []) {
                $alts[$row['sourceElementId'] . ':' . implode(',', $siteIds)] = array_values(array_unique($found));
            }
        }

        return $alts;
    }

    /**
     * The text values of content as Craft stores it, or the content itself when it isn't JSON.
     *
     * @return list<string>
     */
    private static function texts(string $content): array
    {
        $data = json_decode($content, true);

        if (!is_array($data)) {
            return [$content];
        }

        $texts = [];
        array_walk_recursive($data, static function(mixed $value) use (&$texts): void {
            if (is_string($value)) {
                $texts[] = $value;
            }
        });

        return $texts;
    }

    /**
     * A tag's attributes by lower-case name ('' for one without a value); the first of a name wins,
     * as in browsers.
     *
     * @return array<string, string>
     */
    private static function attributes(string $tag): array
    {
        // Without “<img” and the closing “>” (or “/>”).
        preg_match_all(self::ATTRIBUTE, (string)preg_replace('/\/?>$/', '', substr($tag, 4)), $matches, PREG_SET_ORDER);
        $attributes = [];

        foreach ($matches as $match) {
            $attributes[strtolower($match[1])] ??= ($match[2] ?? '') . ($match[3] ?? '') . ($match[4] ?? '');
        }

        return $attributes;
    }

    private function record(int $assetId, int $sourceElementId, ?int $sourceSiteId, ?int $fieldId, UsageKind $kind, ?bool $altInContent = null): void
    {
        Db::upsert(UsageRecord::TABLE, [
            'usageKey' => sha1(implode(':', [$assetId, $sourceElementId, $sourceSiteId ?? '*', $fieldId ?? '-', $kind->value])),
            'assetId' => $assetId,
            'sourceElementId' => $sourceElementId,
            'sourceSiteId' => $sourceSiteId,
            'fieldId' => $fieldId,
            'kind' => $kind->value,
            'altInContent' => $altInContent,
        ], ['assetId' => $assetId, 'altInContent' => $altInContent]);
    }

    /**
     * Relations from live content to image assets that are not in the trash.
     */
    private function relationQuery(): Query
    {
        return $this->liveSources((new Query())
            ->from(['relations' => Table::RELATIONS])
            ->innerJoin(['assets' => Table::ASSETS], '[[assets.id]] = [[relations.targetId]]')
            ->innerJoin(['targets' => Table::ELEMENTS], '[[targets.id]] = [[relations.targetId]]')
            ->innerJoin(['elements' => Table::ELEMENTS], '[[elements.id]] = [[relations.sourceId]]')
            ->where(['assets.kind' => Asset::KIND_IMAGE, 'targets.dateDeleted' => null]));
    }

    /**
     * Content of live elements that holds an asset reference tag.
     */
    private function contentQuery(): Query
    {
        return $this->liveSources((new Query())
            ->from(['elements_sites' => Table::ELEMENTS_SITES])
            ->innerJoin(['elements' => Table::ELEMENTS], '[[elements.id]] = [[elements_sites.elementId]]')
            ->where(['like', $this->contentText(), '{asset:']));
    }

    /**
     * Leaves out drafts, revisions, trashed elements, and nested elements whose owner is one.
     */
    private function liveSources(Query $query): Query
    {
        return $query
            ->leftJoin(['owners' => Table::ELEMENTS_OWNERS], '[[owners.elementId]] = [[elements.id]]')
            ->leftJoin(['ownerElements' => Table::ELEMENTS], '[[ownerElements.id]] = [[owners.ownerId]]')
            ->andWhere(['elements.draftId' => null, 'elements.revisionId' => null, 'elements.dateDeleted' => null])
            ->andWhere(['or', ['owners.ownerId' => null], ['and', ['ownerElements.draftId' => null], ['ownerElements.revisionId' => null], ['ownerElements.dateDeleted' => null]]])
            ->distinct();
    }

    /** The content column as text: PostgreSQL won't match a pattern against JSON directly. */
    private function contentText(): Expression
    {
        return new Expression(Craft::$app->getDb()->getIsPgsql() ? 'CAST([[elements_sites.content]] AS TEXT)' : '[[elements_sites.content]]');
    }

    private function loadElement(int $elementId, ?int $siteId): ?ElementInterface
    {
        $elements = Craft::$app->getElements();
        $type = $elements->getElementTypeById($elementId);

        if ($type === null) {
            return null;
        }

        /** @var class-string<ElementInterface> $type */
        return $type::find()->id($elementId)->siteId($siteId ?? '*')->status(null)->one();
    }
}
