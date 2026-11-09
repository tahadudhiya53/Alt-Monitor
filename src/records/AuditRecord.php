<?php

namespace Tahadudhiya\AltMonitor\records;

use craft\db\ActiveRecord;

/**
 * A `altmonitor_audits` row: what the analyzer found about one image asset's alt text in one site.
 *
 * Derived data, apart from `decorative` and `dateAltSaved`, which record what people did. The alt
 * text itself is never copied here; it stays on the asset.
 *
 * @property int $id
 * @property int $assetId
 * @property int $siteId
 * @property int $volumeId
 * @property int|null $scanId
 * @property string $altStatus
 * @property string|null $issues
 * @property bool $decorative
 * @property string $usageStatus
 * @property string $altHash
 * @property string $dateScanned
 * @property string|null $dateAltSaved
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class AuditRecord extends ActiveRecord
{
    public const TABLE = '{{%altmonitor_audits}}';

    public static function tableName(): string
    {
        return self::TABLE;
    }
}
