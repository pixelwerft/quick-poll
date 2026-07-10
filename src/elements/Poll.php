<?php

namespace pixelwerft\quickpoll\elements;

use Craft;
use craft\base\Element;
use craft\db\Query;
use craft\elements\Category;
use craft\elements\db\ElementQueryInterface;
use craft\elements\User;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use DateTime;
use pixelwerft\quickpoll\elements\db\PollQuery;
use pixelwerft\quickpoll\QuickPoll;

/**
 * Poll — a plugin-managed, localizable element.
 *
 * Storage:
 *   quickpoll_polls        non-localized settings (type, access, …)
 *   quickpoll_polls_sites  per-site options (the question is the element title)
 *
 * Editing happens through the plugin's own CP screens (PollsController), not a
 * field layout, so the host site needs no fields / sections / setup command.
 */
class Poll extends Element
{
    public string $pollType = 'choice';
    public string $pollAccess = 'public';
    public bool $multiSelect = false;
    public ?DateTime $openUntil = null;
    public string $resultsVisibility = 'afterVote';
    public bool $showShare = false;
    public bool $hideAfterClose = false;
    public bool $allowRevote = false;
    public ?string $resultText = null;

    private ?array $_options = null;
    private ?array $_categoryIds = null;

    public static function displayName(): string { return Craft::t('quick-poll', 'Poll'); }
    public static function lowerDisplayName(): string { return Craft::t('quick-poll', 'poll'); }
    public static function pluralDisplayName(): string { return Craft::t('quick-poll', 'Polls'); }
    public static function pluralLowerDisplayName(): string { return Craft::t('quick-poll', 'polls'); }
    public static function refHandle(): ?string { return 'poll'; }
    public static function hasTitles(): bool { return true; }
    public static function hasUris(): bool { return false; }
    public static function isLocalized(): bool { return true; }
    public static function hasStatuses(): bool { return true; }

    public static function find(): ElementQueryInterface
    {
        return Craft::createObject(PollQuery::class, [static::class]);
    }

    protected static function defineSources(?string $context = null): array
    {
        $sources = [
            [
                'key' => '*',
                'label' => Craft::t('quick-poll', 'All polls'),
                'criteria' => [],
            ],
        ];

        $group = static::categoryGroup();
        if ($group) {
            $cats = Category::find()->group($group)->all();
            if ($cats) {
                $sources[] = ['heading' => Craft::t('quick-poll', 'Categories')];
                foreach ($cats as $cat) {
                    $sources[] = [
                        'key' => 'category:' . $cat->id,
                        'label' => $cat->title,
                        'criteria' => ['category' => $cat->id],
                    ];
                }
            }
        }

        return $sources;
    }

    protected static function defineTableAttributes(): array
    {
        return [
            'title' => Craft::t('quick-poll', 'Question'),
            'pollType' => Craft::t('quick-poll', 'Type'),
            'pollStatus' => Craft::t('quick-poll', 'Status'),
            'participants' => Craft::t('quick-poll', 'Participants'),
            'dateCreated' => Craft::t('app', 'Date Created'),
        ];
    }

    protected static function defineSortOptions(): array
    {
        return [
            'title' => Craft::t('quick-poll', 'Question'),
            'dateCreated' => Craft::t('app', 'Date Created'),
        ];
    }

    /* --------------------------------------------------------------------- */

    public function getOptions(): array
    {
        return $this->_options ?? [];
    }

    public function setOptions(mixed $value): void
    {
        if (is_string($value)) {
            $value = Json::decodeIfJson($value) ?: [];
        }
        $this->_options = is_array($value) ? array_values($value) : [];
    }

    /** @return int[] */
    public function getCategoryIds(): array
    {
        if ($this->_categoryIds === null) {
            $this->_categoryIds = $this->id
                ? array_map('intval', (new Query())
                    ->select(['categoryId'])
                    ->from('{{%quickpoll_poll_categories}}')
                    ->where(['pollId' => $this->id])
                    ->column())
                : [];
        }
        return $this->_categoryIds;
    }

    public function setCategoryIds(mixed $value): void
    {
        $this->_categoryIds = is_array($value)
            ? array_values(array_filter(array_map('intval', $value)))
            : [];
    }

    /** @return Category[] */
    public function getCategories(): array
    {
        $ids = $this->getCategoryIds();
        return $ids ? Category::find()->id($ids)->status(null)->all() : [];
    }

    private static function categoryGroup(): ?\craft\models\CategoryGroup
    {
        $uid = QuickPoll::getInstance()->getSettings()->categoryGroupUid;
        return $uid ? Craft::$app->getCategories()->getGroupByUid($uid) : null;
    }

    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['pollType'], 'in', 'range' => ['rating', 'choice', 'mood', 'grid']];
        $rules[] = [['pollAccess'], 'in', 'range' => ['public', 'members']];
        $rules[] = [['resultsVisibility'], 'in', 'range' => ['afterVote', 'always', 'afterClose']];
        $rules[] = [['title'], 'required'];
        return $rules;
    }

    public function getSupportedSites(): array
    {
        // Localizable across every site, so the question + options can be
        // translated per language.
        return Craft::$app->getSites()->getAllSiteIds();
    }

    protected function cpEditUrl(): ?string
    {
        return UrlHelper::cpUrl('quick-poll/polls/' . $this->id);
    }

    public function getUriFormat(): ?string
    {
        return null;
    }

    public function canView(User $user): bool { return $this->canManage($user); }
    public function canSave(User $user): bool { return $this->canManage($user); }
    public function canDelete(User $user): bool { return $this->canManage($user); }
    public function canDuplicate(User $user): bool { return $this->canManage($user); }

    private function canManage(User $user): bool
    {
        return $user->admin || $user->can('accessPlugin-quick-poll');
    }

    protected function attributeHtml(string $attribute): string
    {
        return match ($attribute) {
            'pollType' => '<code>' . $this->pollType . '</code>',
            'pollStatus' => QuickPoll::getInstance()->votes->isOpen($this)
                ? Craft::t('quick-poll', 'Open')
                : Craft::t('quick-poll', 'Closed'),
            'participants' => (string) (QuickPoll::getInstance()->results->votersByPoll()[$this->id] ?? 0),
            default => parent::attributeHtml($attribute),
        };
    }

    /* --------------------------------------------------------------------- */

    public function afterSave(bool $isNew): void
    {
        if (!$this->propagating) {
            $now = Db::prepareDateForDb(new DateTime());
            $settings = [
                'pollType' => $this->pollType,
                'pollAccess' => $this->pollAccess,
                'multiSelect' => $this->multiSelect,
                'openUntil' => $this->openUntil ? Db::prepareDateForDb($this->openUntil) : null,
                'resultsVisibility' => $this->resultsVisibility,
                'showShare' => $this->showShare,
                'hideAfterClose' => $this->hideAfterClose,
                'allowRevote' => $this->allowRevote,
                'dateUpdated' => $now,
            ];

            if ($isNew) {
                Db::insert('{{%quickpoll_polls}}', $settings + [
                    'id' => $this->id,
                    'dateCreated' => $now,
                    'uid' => StringHelper::UUID(),
                ]);
            } else {
                Db::update('{{%quickpoll_polls}}', $settings, ['id' => $this->id]);
            }

            // Per-site content (options + result text) for the edited site.
            $this->saveSiteContent($this->siteId, $this->getOptions(), $this->resultText, $now);

            // On creation, seed the other sites with the same content as a
            // starting point. Propagation saves deliberately skip this so a
            // later per-site edit never overwrites another language.
            if ($isNew) {
                foreach ($this->getSupportedSites() as $siteId) {
                    if ((int) $siteId !== (int) $this->siteId) {
                        $this->saveSiteContent((int) $siteId, $this->getOptions(), $this->resultText, $now);
                    }
                }
            }

            // Categories — non-localized many-to-many. Only rewritten when set
            // (the editor posts them); a programmatic save without them is a no-op.
            if ($this->_categoryIds !== null) {
                Db::delete('{{%quickpoll_poll_categories}}', ['pollId' => $this->id]);
                foreach ($this->_categoryIds as $catId) {
                    Db::insert('{{%quickpoll_poll_categories}}', [
                        'pollId' => $this->id,
                        'categoryId' => $catId,
                        'dateCreated' => $now,
                        'uid' => StringHelper::UUID(),
                    ]);
                }
            }
        }

        parent::afterSave($isNew);
    }

    private function saveSiteContent(int $siteId, array $options, ?string $resultText, string $now): void
    {
        $json = Json::encode(array_values($options));
        $resultText = ($resultText !== null && trim($resultText) !== '') ? $resultText : null;

        $exists = (new Query())
            ->from('{{%quickpoll_polls_sites}}')
            ->where(['pollId' => $this->id, 'siteId' => $siteId])
            ->exists();

        if ($exists) {
            Db::update('{{%quickpoll_polls_sites}}', ['options' => $json, 'resultText' => $resultText, 'dateUpdated' => $now], ['pollId' => $this->id, 'siteId' => $siteId]);
        } else {
            Db::insert('{{%quickpoll_polls_sites}}', [
                'pollId' => $this->id,
                'siteId' => $siteId,
                'options' => $json,
                'resultText' => $resultText,
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => StringHelper::UUID(),
            ]);
        }
    }
}
