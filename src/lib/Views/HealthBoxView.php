<?php

declare(strict_types=1);

namespace App\Views;

/**
 * Dashboard setup-status box - one place to see whether everything this
 * app needs (hooks, claude-quota, tmux socket dir, VAPID keys, Composer
 * vendor/) is actually installed, instead of discovering each piece is
 * missing separately (see HostAgent\Services\PushHealthService::health_check()
 * for what's actually checked), plus the push-check interval control folded
 * into the same panel.
 */
class HealthBoxView extends View
{
    /**
     * The push-check interval control - a small preset dropdown + Save button
     * to adjust how often sessioneer-push-check.timer polls without
     * editing/reinstalling the unit file by hand on the host (see
     * HostAgent\Services\PushTimerService::set_push_timer_interval() for the
     * actual mechanics). $currentSeconds is always included as an option even
     * when it isn't one of the presets (a value set by hand outside this
     * control, or a future default change), so the dropdown never silently
     * misrepresents what's actually running.
     *
     * Renders nothing when $currentSeconds is null - the timer unit isn't
     * installed, or the agent is unreachable, and there's nothing to adjust
     * in either case.
     */
    public static function push_timer_interval_control_html(?int $currentSeconds, string $csrfToken): string
    {
        if ($currentSeconds === null) {
            return '';
        }

        $presets = [5, 10, 15, 30, 60, 120];

        if (!in_array($currentSeconds, $presets, true)) {
            $presets[] = $currentSeconds;
            sort($presets);
        }

        return self::render('health-box/push-timer-interval-control', [
            'presets' => $presets,
            'currentSeconds' => $currentSeconds,
            'csrfToken' => $csrfToken,
        ]);
    }

    /**
     * Collapsed <details> rather than an always-open block since most of the
     * time there's nothing to look at - the summary row alone (colored dot +
     * "All systems OK"/"Some setup checks failed") is enough for the common
     * case.
     *
     * Renders nothing when $checks is empty - either the host agent is
     * unreachable (already covered by index.php's own red banner for that) or
     * the health_check call itself failed, neither of which this box can add
     * anything useful to.
     *
     * $pushTimerIntervalSeconds/$csrfToken: folds the push-check interval
     * control (see push_timer_interval_control_html()) into this same panel,
     * right below the "Push delivery" check it's directly related to, rather
     * than a separate floating control elsewhere on the page.
     *
     * @param array<int, array{key?:string, label?:string, ok?:bool, detail?:?string}> $checks
     */
    public static function health_box_html(array $checks, ?int $pushTimerIntervalSeconds = null, string $csrfToken = ''): string
    {
        if ($checks === []) {
            return '';
        }

        $allOk = true;

        foreach ($checks as $check) {
            if (!($check['ok'] ?? false)) {
                $allOk = false;
                break;
            }
        }

        $dotColor = $allOk ? 'bg-emerald-400' : 'bg-amber-400';
        $summaryColor = $allOk ? 'text-emerald-400' : 'text-amber-400';
        $summaryText = $allOk ? 'All systems OK' : 'Some setup checks failed';

        $intervalControl = self::push_timer_interval_control_html($pushTimerIntervalSeconds, $csrfToken);

        // Group checks by section, preserving first-seen order with
        // Global first (it contains the foundational infrastructure).
        $grouped = [];

        foreach ($checks as $check) {
            $section = $check['section'] ?? 'General';
            $grouped[$section][] = $check;
        }

        if (isset($grouped['Global'])) {
            $global = $grouped['Global'];
            unset($grouped['Global']);
            $grouped = ['Global' => $global] + $grouped;
        }

        return self::render('health-box/box', [
            'grouped' => $grouped,
            'dotColor' => $dotColor,
            'summaryColor' => $summaryColor,
            'summaryText' => $summaryText,
            'intervalControl' => $intervalControl,
        ]);
    }
}
