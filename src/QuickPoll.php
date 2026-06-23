<?php

namespace pixelwerft\quickpoll;

use Craft;
use craft\base\Model;
use craft\base\Plugin;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\services\Elements;
use craft\services\Fields;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use pixelwerft\quickpoll\elements\Poll;
use pixelwerft\quickpoll\fields\PollField;
use pixelwerft\quickpoll\models\Settings;
use pixelwerft\quickpoll\services\ResultsService;
use pixelwerft\quickpoll\services\VoteService;
use yii\base\Event;

/**
 * Quick Poll plugin — lightweight, reusable polls & votings.
 *
 * Domain boundary: this plugin owns only the *runtime* of voting (the votes
 * table, the vote/results endpoints, the dedup logic and the CP result view).
 * The content model (the `poll` section, its entry types and fields) lives in
 * the host site's project config — scaffold it once with:
 *
 *     php craft quick-poll/setup
 *
 * Nothing here is site-specific; the poll section is resolved via the
 * `pollSectionHandle` setting so the plugin travels between sites unchanged.
 *
 * @method static QuickPoll getInstance()
 * @method Settings getSettings()
 * @property-read VoteService $votes
 * @property-read ResultsService $results
 */
class QuickPoll extends Plugin
{
    public string $schemaVersion = '1.3.0';
    public bool $hasCpSettings = true;

    // CP section "Polls" — overview dashboard (all polls + vote totals + CSV
    // export). The per-poll result tab still lives on the entry itself.
    public bool $hasCpSection = true;

    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        if ($item === null) {
            return null;
        }
        $item['label'] = Craft::t('quick-poll', 'Polls');
        return $item;
    }

    public static function config(): array
    {
        return [
            'components' => [
                'votes'   => VoteService::class,
                'results' => ResultsService::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        // Console command lives under quick-poll/* (e.g. quick-poll/setup).
        if (Craft::$app instanceof \craft\console\Application) {
            $this->controllerNamespace = 'pixelwerft\quickpoll\console\controllers';
        }

        // Register the plugin-managed Poll element type.
        Event::on(
            Elements::class,
            Elements::EVENT_REGISTER_ELEMENT_TYPES,
            function (RegisterComponentTypesEvent $event) {
                $event->types[] = Poll::class;
            }
        );

        // Register the "Quick Poll" field type.
        Event::on(
            Fields::class,
            Fields::EVENT_REGISTER_FIELD_TYPES,
            function (RegisterComponentTypesEvent $event) {
                $event->types[] = PollField::class;
            }
        );

        // CP edit routes for poll elements.
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function (RegisterUrlRulesEvent $event) {
                $event->rules['quick-poll/polls/new'] = 'quick-poll/polls/edit';
                $event->rules['quick-poll/polls/<pollId:\d+>'] = 'quick-poll/polls/edit';
            }
        );

        // Expose read-only results to templates:
        //   {% set r = craft.quickPoll.results(pollEntry) %}
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            function (Event $event) {
                /** @var CraftVariable $variable */
                $variable = $event->sender;
                $variable->set('quickPoll', $this->get('results'));
            }
        );
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('quick-poll/_settings', [
            'settings' => $this->getSettings(),
        ]);
    }
}
