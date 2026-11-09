<?php

namespace Tahadudhiya\AltMonitor\records;

use craft\db\ActiveRecord;

/**
 * A `altmonitor_scans` row: one run of a scan, with its progress and the totals it ended with.
 *
 * @property int $id
 * @property string $type
 * @property string $status
 * @property int $totalAssets
 * @property int $scannedAssets
 * @property int $missingAlt
 * @property int $weakAlt
 * @property int $goodAlt
 * @property int $reviewCount
 * @property string|null $error
 * @property string|null $startedAt
 * @property string|null $completedAt
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class ScanRecord extends ActiveRecord
{
    public const TABLE = '{{%altmonitor_scans}}';

    public static function tableName(): string
    {
        return self::TABLE;
    }
}
