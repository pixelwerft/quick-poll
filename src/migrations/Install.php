<?php

namespace pixelwerft\quickpoll\migrations;

use craft\db\Migration;

/**
 * Creates the quickpoll_votes table — the single store for every vote across
 * all poll types. Deliberately NOT a section/entry-type:
 *   - votes are high-volume runtime data, not editorial content;
 *   - keeping them out of project config means votes never travel through CI;
 *   - one flat table with a UNIQUE guard is the whole dedup story.
 *
 * Column semantics by poll type:
 *   rating  → optionKey "1".."5",       value NULL
 *   choice  → optionKey = option index, value NULL  (multiple rows if multi-select)
 *   mood    → optionKey = option index, value NULL
 *   grid    → optionKey = option index, value "yes"|"maybe"|"no"  (one row per option)
 *
 * targetId (Phase 3 — entity-attached polls):
 *   0       → poll-level vote (the poll embedded standalone)
 *   <id>    → vote scoped to a target element, so ONE poll definition can be
 *             attached to many entries (e.g. "rate this entry") and each target
 *             aggregates its own votes.
 *
 * voterHash:
 *   members → "u:<userId>"
 *   public  → sha256(ip + cookieId + pepper)   (engagement-grade, not ballot-grade)
 */
class Install extends Migration
{
    public function safeUp(): bool
    {
        $this->createPollsTables();
        $this->createVotesTable();
        $this->createCategoriesTable();
        return true;
    }

    /** Join table for the optional poll ↔ category relation (many-to-many). */
    private function createCategoriesTable(): void
    {
        $table = '{{%quickpoll_poll_categories}}';
        if ($this->db->tableExists($table)) {
            return;
        }
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

    /**
     * Poll elements are plugin-managed:
     *   quickpoll_polls        → one row per poll, non-localised settings
     *   quickpoll_polls_sites  → one row per (poll, site), localised content
     *                            (question is the element title; options here)
     */
    private function createPollsTables(): void
    {
        $polls = '{{%quickpoll_polls}}';
        if (!$this->db->tableExists($polls)) {
            $this->createTable($polls, [
                'id'                => $this->integer()->notNull(), // = elements.id
                'pollType'          => $this->string(16)->notNull()->defaultValue('choice'),
                'pollAccess'        => $this->string(16)->notNull()->defaultValue('public'),
                'multiSelect'       => $this->boolean()->notNull()->defaultValue(false),
                'openUntil'         => $this->dateTime()->null(),
                'resultsVisibility' => $this->string(16)->notNull()->defaultValue('afterVote'),
                'showShare'         => $this->boolean()->notNull()->defaultValue(false),
                'hideAfterClose'    => $this->boolean()->notNull()->defaultValue(false),
                'allowRevote'       => $this->boolean()->notNull()->defaultValue(false),
                'dateCreated'       => $this->dateTime()->notNull(),
                'dateUpdated'       => $this->dateTime()->notNull(),
                'uid'               => $this->uid(),
                'PRIMARY KEY([[id]])',
            ]);
            $this->addForeignKey(null, $polls, ['id'], '{{%elements}}', ['id'], 'CASCADE', null);
        }

        $sites = '{{%quickpoll_polls_sites}}';
        if (!$this->db->tableExists($sites)) {
            $this->createTable($sites, [
                'id'          => $this->primaryKey(),
                'pollId'      => $this->integer()->notNull(),
                'siteId'      => $this->integer()->notNull(),
                'options'     => $this->text()->null(),   // JSON array of option labels
                'resultText'  => $this->text()->null(),   // editorial text shown above the results (per-site)
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid'         => $this->uid(),
            ]);
            $this->createIndex(null, $sites, ['pollId', 'siteId'], true);
            $this->addForeignKey(null, $sites, ['pollId'], $polls, ['id'], 'CASCADE', null);
            $this->addForeignKey(null, $sites, ['siteId'], '{{%sites}}', ['id'], 'CASCADE', null);
        }
    }

    private function createVotesTable(): void
    {
        $table = '{{%quickpoll_votes}}';

        if ($this->db->tableExists($table)) {
            return;
        }

        $this->createTable($table, [
            'id'          => $this->primaryKey(),
            'pollId'      => $this->integer()->notNull(),       // FK → elements.id
            'targetId'    => $this->integer()->notNull()->defaultValue(0), // 0 = poll-level; else target element
            'siteId'      => $this->integer()->notNull(),       // which language the vote came from
            'optionKey'   => $this->string(32)->notNull(),
            'value'       => $this->string(16)->null(),         // only used by grid polls
            'voterHash'   => $this->string(64)->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
            'uid'         => $this->uid(),
        ]);

        // One-vote guarantee: a voter may hold at most one row per
        // (poll, target, option). targetId defaults to 0 (NOT NULL) so the
        // unique index works for poll-level votes too — a NULL would let MySQL
        // treat every poll-level row as distinct and break dedup.
        $this->createIndex(null, $table, ['pollId', 'targetId', 'voterHash', 'optionKey'], true);

        // Main aggregation path:
        //   COUNT(*) … WHERE pollId = ? AND targetId = ? [AND siteId = ?] GROUP BY optionKey[, value].
        $this->createIndex(null, $table, ['pollId', 'targetId', 'siteId'], false);

        // Note: targetId has NO foreign key by design — 0 is a sentinel ("no
        // target"), not a valid element id. Target-scoped votes are therefore
        // cleaned up lazily (a deleted target leaves orphan rows that simply
        // never aggregate again). A future console GC can prune them if needed.

        // FK on craft_elements: votes are cleaned up with the poll entry.
        $this->addForeignKey(
            null, $table, ['pollId'],
            '{{%elements}}', ['id'],
            'CASCADE', 'CASCADE',
        );

        $this->addForeignKey(
            null, $table, ['siteId'],
            '{{%sites}}', ['id'],
            'CASCADE', 'CASCADE',
        );
    }

    public function safeDown(): bool
    {
        // Drop polls first; FKs from votes/sites reference elements, not polls.
        $this->dropTableIfExists('{{%quickpoll_poll_categories}}');
        $this->dropTableIfExists('{{%quickpoll_votes}}');
        $this->dropTableIfExists('{{%quickpoll_polls_sites}}');
        $this->dropTableIfExists('{{%quickpoll_polls}}');
        return true;
    }
}
