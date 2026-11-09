<?php

namespace Tahadudhiya\AltMonitor\records;

use craft\db\ActiveRecord;

/**
 * A `altmonitor_usages` row: one place content references an image asset.
 *
 * @property int $id
 * @property string $usageKey
 * @property int $assetId
 * @property int $sourceElementId
 * @property int|null $sourceSiteId
 * @property int|null $fieldId
 * @property string $kind
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class UsageRecord extends ActiveRecord
{
    public const TABLE = '{{%altmonitor_usages}}';

    public static function tableName(): string
    {
        return self::TABLE;
    }
}
