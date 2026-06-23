<?php

namespace pixelwerft\quickpoll\migrations;

use craft\db\Migration;

/**
 * Adds the per-poll re-vote toggle:
 *   quickpoll_polls.allowRevote — let voters change their answer while open.
 *
 * Idempotent (columnExists guard) so it is safe on installs that already got
 * the column from Install.php.
 */
class m260614_120000_add_poll_revote extends Migration
{
    public function safeUp(): bool
    {
        $polls = '{{%quickpoll_polls}}';
        if (!$this->db->columnExists($polls, 'allowRevote')) {
            $this->addColumn($polls, 'allowRevote', $this->boolean()->notNull()->defaultValue(false)->after('hideAfterClose'));
        }

        return true;
    }

    public function safeDown(): bool
    {
        $polls = '{{%quickpoll_polls}}';
        if ($this->db->columnExists($polls, 'allowRevote')) {
            $this->dropColumn($polls, 'allowRevote');
        }

        return true;
    }
}
