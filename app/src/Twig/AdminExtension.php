<?php

namespace App\Twig;

use App\Service\ReportPresenter;
use DateTimeInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Small admin-UI-only Twig helpers: German relative timestamps ("vor 2 Std.") for the dashboard
 * queue / Meldungen table, matching design-prototype/dashboard.html's copy, and the "m-1234"
 * report id formatting shared with the API (see App\Service\ReportPresenter).
 */
class AdminExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('relative_de', $this->relativeDe(...)),
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('report_id', ReportPresenter::formatId(...)),
        ];
    }

    public function relativeDe(?DateTimeInterface $dt): string
    {
        if ($dt === null) {
            return '—';
        }

        $diff = (new \DateTimeImmutable())->getTimestamp() - $dt->getTimestamp();
        if ($diff < 60) {
            return 'gerade eben';
        }
        if ($diff < 3600) {
            $m = (int) floor($diff / 60);
            return 'vor ' . $m . ' Min.';
        }
        if ($diff < 86400) {
            $h = (int) floor($diff / 3600);
            return 'vor ' . $h . ' Std.';
        }
        $days = (int) floor($diff / 86400);
        if ($days === 1) {
            return 'gestern';
        }
        if ($days < 7) {
            return 'vor ' . $days . ' Tagen';
        }
        $weeks = (int) floor($days / 7);

        return $weeks === 1 ? 'vor 1 Woche' : 'vor ' . $weeks . ' Wochen';
    }
}
