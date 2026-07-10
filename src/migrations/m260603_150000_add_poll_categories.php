<?php

namespace pixelwerft\quickpoll\migrations;

use craft\db\Migration;

/**
 * Join table for the optional poll ↔ category relation (many-to-many).
 * Categories live in the group chosen via the plugin settings.
 *
 * Idempotent (tableExists guard) so it is safe on installs that already got the
 * table from Install.php.
 */
class m260603_150000_add_poll_categories extends Migration
{
    public function safeUp(): bool
    {
        $table = '{{%quickpoll_poll_categories}}';
        if (!$this->db->tableExists($table)) {
            $this->createTable($table, [
                'pollId' => $this->integer()->notNull(),
                'categoryId' => $this->integer()->notNull(),
                'dateCreated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
                'PRIMARY KEY([[pollId]], [[categoryId]])',
            ]);
            $this->createIndex(null, $table, ['categoryId'], false);
            $this->addForeignKey(null, $table, ['pollId'], '{{%elements}}', ['id'], 'CASCADE', null);
            $this->addForeignKey(null, $table, ['categoryId'], '{{%elements}}', ['id'], 'CASCADE', null);
        }
        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%quickpoll_poll_categories}}');
        return true;
    }
}
