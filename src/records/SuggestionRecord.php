<?php

namespace Tahadudhiya\AltMonitor\records;

use craft\db\ActiveRecord;

/**
 * A `altmonitor_suggestions` row: the current suggestion for one image asset in one site.
 *
 * @property int $id
 * @property int $assetId
 * @property int $siteId
 * @property string|null $suggestedAlt
 * @property string $source
 * @property string $status
 * @property bool $needsReview
 * @property string|null $basis
 * @property string|null $error
 * @property string|null $dateGenerated
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class SuggestionRecord extends ActiveRecord
{
    public const TABLE = '{{%altmonitor_suggestions}}';

    public static function tableName(): string
    {
        return self::TABLE;
    }
}
