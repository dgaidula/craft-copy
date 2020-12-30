<?php

declare(strict_types=1);

namespace fortrabbit\Copy\EventHandlers;

use craft\db\Table;
use craft\events\BackupEvent;

/**
 * Handler to adjust tables to exclude from dump
 */
class IgnoredBackupTablesHandler
{
    /**
     * Modify the ignored BackupTables from
     * from craft\db\Connection::getIgnoredBackupTables()
     */
    public function __invoke(BackupEvent $event): void
    {
        // Since we sync assets, we keep assettransformindex
        if (property_exists($event, 'ignoreTables')) {
            $event->ignoreTables = array_diff($event->ignoreTables, [Table::ASSETTRANSFORMINDEX]);
        }
    }
}
