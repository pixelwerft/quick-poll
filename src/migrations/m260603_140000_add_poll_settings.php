<?php

namespace pixelwerft\quickpoll\migrations;

use craft\db\Migration;

/**
 * Adds the per-poll settings introduced after the initial release:
 *   quickpoll_polls.showShare       — optional share button
 *   quickpoll_polls.hideAfterClose  — hide the widget once the poll closes
 *   quickpoll_polls_sites.resultText — per-site editorial text above the results
 *
 * Idempotent (columnExists guards) so it is safe on installs that already got
 * the columns from Install.php or a manual ALTER.
 */
class m260603_140000_add_poll_settings extends Migration
{
    public function safeUp(): bool
    {
        $polls = '{{%quickpoll_polls}}';
        if (!$this->db->columnExists($polls, 'showShare')) {
            $this->addColumn($polls, 'showShare', $this->boolean()->notNull()->defaultValue(false)->after('resultsVisibility'));
        }
        if (!$this->db->columnExists($polls, 'hideAfterClose')) {
            $this->addColumn($polls, 'hideAfterClose', $this->boolean()->notNull()->defaultValue(false)->after('showShare'));
        }

        $sites = '{{%quickpoll_polls_sites}}';
        if (!$this->db->columnExists($sites, 'resultText')) {
            $this->addColumn($sites, 'resultText', $this->text()->null()->after('options'));
        }

        return true;
    }

    public function safeDown(): bool
    {
        $polls = '{{%quickpoll_polls}}';
        foreach (['hideAfterClose', 'showShare'] as $col) {
            if ($this->db->columnExists($polls, $col)) {
                $this->dropColumn($polls, $col);
            }
        }

        $sites = '{{%quickpoll_polls_sites}}';
        if ($this->db->columnExists($sites, 'resultText')) {
            $this->dropColumn($sites, 'resultText');
        }

        return true;
    }
}
