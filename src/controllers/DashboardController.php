<?php

namespace pixelwerft\quickpoll\controllers;

use Craft;
use craft\web\Controller;
use pixelwerft\quickpoll\QuickPoll;
use yii\web\Response;

/**
 * CP-only dashboard actions. The overview itself is a template (index.twig);
 * this controller handles the CSV export.
 */
class DashboardController extends Controller
{
    /**
     * GET actions/quick-poll/dashboard/export → CSV of aggregated results.
     */
    public function actionExport(): Response
    {
        $this->requireCpRequest();
        $this->requirePermission('accessPlugin-quick-poll');

        $results = QuickPoll::getInstance()->results;

        $rows = [['Poll', 'Type', 'Option', 'Votes', 'Percent', 'Yes', 'Maybe', 'No', 'Total']];
        foreach ($results->allPolls() as $poll) {
            $r = $results->forPoll($poll);
            foreach ($r['options'] as $o) {
                if (($r['type'] ?? '') === 'grid') {
                    $rows[] = [$poll->title, 'grid', $o['label'], '', '', $o['yes'], $o['maybe'], $o['no'], $r['total']];
                } else {
                    $rows[] = [$poll->title, $r['type'] ?? '', $o['label'], $o['count'], $o['pct'], '', '', '', $r['total']];
                }
            }
        }

        $csv = '';
        foreach ($rows as $row) {
            $csv .= implode(',', array_map([$this, 'csvCell'], $row)) . "\r\n";
        }

        $response = Craft::$app->getResponse();
        $response->format = Response::FORMAT_RAW;
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="quick-poll-results.csv"');
        $response->content = "\xEF\xBB\xBF" . $csv; // UTF-8 BOM so Excel reads umlauts
        return $response;
    }

    private function csvCell(mixed $v): string
    {
        $v = (string) $v;
        if (preg_match('/[",\r\n]/', $v)) {
            $v = '"' . str_replace('"', '""', $v) . '"';
        }
        return $v;
    }
}
