<?php

namespace Tahadudhiya\AltMonitor\migrations;

use craft\db\Migration;
use craft\db\Table;
use Tahadudhiya\AltMonitor\enums\UsageStatus;
use Tahadudhiya\AltMonitor\records\AuditRecord;
use Tahadudhiya\AltMonitor\records\ScanRecord;
use Tahadudhiya\AltMonitor\records\SuggestionRecord;
use Tahadudhiya\AltMonitor\records\UsageRecord;

/**
 * Creates the tables AltMonitor owns.
 *
 * None of them holds alt text: that stays on Craft's assets, and approving a suggestion saves it
 * there. Rows are keyed to Craft's elements and sites with cascading foreign keys, so deleting
 * an asset or a site removes what AltMonitor knew about it.
 */
class Install extends Migration
{
    public function safeUp(): bool
    {
        $this->createScansTable();
        $this->createAuditsTable();
        $this->createSuggestionsTable();
        $this->createUsagesTable();

        return true;
    }

    public function safeDown(): bool
    {
        // Tables holding a foreign key go before the table it points at.
        $this->dropTableIfExists(UsageRecord::TABLE);
        $this->dropTableIfExists(SuggestionRecord::TABLE);
        $this->dropTableIfExists(AuditRecord::TABLE);
        $this->dropTableIfExists(ScanRecord::TABLE);

        return true;
    }

    private function createScansTable(): void
    {
        $this->createTable(ScanRecord::TABLE, [
            'id' => $this->primaryKey(),
            'type' => $this->string(16)->notNull(),
            'status' => $this->string(16)->notNull(),
            'totalAssets' => $this->integer()->unsigned()->notNull()->defaultValue(0),
            'scannedAssets' => $this->integer()->unsigned()->notNull()->defaultValue(0),
            // Totals across every audited image when the scan completed.
            'missingAlt' => $this->integer()->unsigned()->notNull()->defaultValue(0),
            'weakAlt' => $this->integer()->unsigned()->notNull()->defaultValue(0),
            'goodAlt' => $this->integer()->unsigned()->notNull()->defaultValue(0),
            'reviewCount' => $this->integer()->unsigned()->notNull()->defaultValue(0),
            'error' => $this->text(),
            'startedAt' => $this->dateTime(),
            'completedAt' => $this->dateTime(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, ScanRecord::TABLE, ['status']);
        $this->createIndex(null, ScanRecord::TABLE, ['type', 'status', 'completedAt']);
    }

    private function createAuditsTable(): void
    {
        $this->createTable(AuditRecord::TABLE, [
            'id' => $this->primaryKey(),
            'assetId' => $this->integer()->notNull(),
            'siteId' => $this->integer()->notNull(),
            'volumeId' => $this->integer()->notNull(),
            'scanId' => $this->integer(),
            'altStatus' => $this->string(16)->notNull(),
            // The AltIssue values behind the status, comma-separated.
            'issues' => $this->string(255),
            // Set by a person: empty alt text is intended.
            'decorative' => $this->boolean()->notNull()->defaultValue(false),
            'usageStatus' => $this->string(16)->notNull()->defaultValue(UsageStatus::UNKNOWN->value),
            // A hash of what the analysis read (alt text, file name, title), so an unchanged asset
            // is not analysed again and a suggestion can tell it is out of date.
            'altHash' => $this->char(40)->notNull(),
            'dateScanned' => $this->dateTime()->notNull(),
            // When AltMonitor last saved the asset's alt text.
            'dateAltSaved' => $this->dateTime(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, AuditRecord::TABLE, ['assetId', 'siteId'], true);
        $this->createIndex(null, AuditRecord::TABLE, ['altStatus', 'siteId']);
        $this->createIndex(null, AuditRecord::TABLE, ['usageStatus']);
        $this->createIndex(null, AuditRecord::TABLE, ['volumeId']);
        $this->createIndex(null, AuditRecord::TABLE, ['dateScanned']);
        $this->addForeignKey(null, AuditRecord::TABLE, ['assetId'], Table::ELEMENTS, ['id'], 'CASCADE');
        $this->addForeignKey(null, AuditRecord::TABLE, ['siteId'], Table::SITES, ['id'], 'CASCADE', 'CASCADE');
        $this->addForeignKey(null, AuditRecord::TABLE, ['volumeId'], Table::VOLUMES, ['id'], 'CASCADE');
        $this->addForeignKey(null, AuditRecord::TABLE, ['scanId'], ScanRecord::TABLE, ['id'], 'SET NULL');
    }

    private function createSuggestionsTable(): void
    {
        $this->createTable(SuggestionRecord::TABLE, [
            'id' => $this->primaryKey(),
            'assetId' => $this->integer()->notNull(),
            'siteId' => $this->integer()->notNull(),
            'suggestedAlt' => $this->text(),
            'source' => $this->string(16)->notNull(),
            'status' => $this->string(16)->notNull(),
            // The generator was unsure, so the suggestion should be read before it is approved.
            'needsReview' => $this->boolean()->notNull()->defaultValue(true),
            // What it was built from: the model's name, or the asset detail a rule used.
            'basis' => $this->string(255),
            'error' => $this->text(),
            'dateGenerated' => $this->dateTime(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        // One current suggestion per image and site: regenerating replaces it.
        $this->createIndex(null, SuggestionRecord::TABLE, ['assetId', 'siteId'], true);
        $this->createIndex(null, SuggestionRecord::TABLE, ['status', 'source']);
        $this->addForeignKey(null, SuggestionRecord::TABLE, ['assetId'], Table::ELEMENTS, ['id'], 'CASCADE');
        $this->addForeignKey(null, SuggestionRecord::TABLE, ['siteId'], Table::SITES, ['id'], 'CASCADE', 'CASCADE');
    }

    private function createUsagesTable(): void
    {
        $this->createTable(UsageRecord::TABLE, [
            'id' => $this->primaryKey(),
            // A hash of the columns below, unique, so a rescan updates rather than duplicates.
            'usageKey' => $this->char(40)->notNull(),
            'assetId' => $this->integer()->notNull(),
            'sourceElementId' => $this->integer()->notNull(),
            // Empty when the relation applies in every site.
            'sourceSiteId' => $this->integer(),
            // No foreign key: a deleted field only means the next usage scan finds nothing there.
            'fieldId' => $this->integer(),
            'kind' => $this->string(16)->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, UsageRecord::TABLE, ['usageKey'], true);
        $this->createIndex(null, UsageRecord::TABLE, ['assetId']);
        $this->createIndex(null, UsageRecord::TABLE, ['dateUpdated']);
        $this->addForeignKey(null, UsageRecord::TABLE, ['assetId'], Table::ELEMENTS, ['id'], 'CASCADE');
        $this->addForeignKey(null, UsageRecord::TABLE, ['sourceElementId'], Table::ELEMENTS, ['id'], 'CASCADE');
        $this->addForeignKey(null, UsageRecord::TABLE, ['sourceSiteId'], Table::SITES, ['id'], 'CASCADE', 'CASCADE');
    }
}
