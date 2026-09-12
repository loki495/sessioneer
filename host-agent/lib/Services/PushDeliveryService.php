<?php

declare(strict_types=1);

namespace HostAgent\Services;

use HostAgent\Stores\GlobalStateStore;
use HostAgent\Stores\PushQuotaStateStore;
use HostAgent\Stores\PushSessionStateStore;
use HostAgent\Stores\PushSubscriptionStore;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * The actual VAPID-signed send (via minishlink/web-push) and the main
 * push-trigger pass that decides WHEN a session's state transition is
 * worth sending for - called on every sessioneer-push-check systemd timer tick
 * (see host-agent/push_trigger.php). iOS Safari has no working
 * client-side background-sync mechanism, so detecting a session
 * transitioning into a blocked state has to happen here, server/host-
 * triggered, not in the browser.
 */
class PushDeliveryService
{
    public static function vapid_public_key(): string
    {
        return Config::sessioneer_config('VAPID_PUBLIC_KEY', '');
    }

    public static function vapid_private_key(): string
    {
        return Config::sessioneer_config('VAPID_PRIVATE_KEY', '');
    }

    /**
     * Required by the Web Push spec itself (a mailto: address or an HTTPS
     * URL push services can use to contact you about your VAPID usage) -
     * no default here, set it explicitly in .env alongside the VAPID
     * keypair when enabling push notifications (see README).
     */
    public static function vapid_subject(): string
    {
        return Config::sessioneer_config('VAPID_SUBJECT', '');
    }

    /**
     * How long a session must have been continuously 'working' before its
     * transition to 'idle' (finished, nothing left needing input) is worth a
     * push notification for - avoids notifying for every trivial quick reply,
     * only for something that actually took a while.
     */
    public static function push_min_working_seconds_for_finish_notify(): int
    {
        return (int)Config::sessioneer_config('PUSH_MIN_WORKING_SECONDS_FOR_FINISH_NOTIFY', '60');
    }

    /**
     * A quota bucket's pct at or above this counts as "close to over" for
     * check_and_send_quota_pushes() - deliberately below 100 (that's its own
     * separate "over" notification), configurable since what counts as
     * "close enough to want a warning" is a judgment call, not a fact.
     */
    public static function push_quota_near_threshold_pct(): int
    {
        return (int)Config::sessioneer_config('PUSH_QUOTA_NEAR_THRESHOLD_PCT', '90');
    }

    /**
     * False (and every push-related action a harmless no-op) until VAPID
     * keys are actually generated and set - see README for the one-time
     * generation step.
     */
    public static function push_configured(): bool
    {
        return self::vapid_public_key() !== '' && self::vapid_private_key() !== '';
    }

    /**
     * Last-tick outcome of check_and_send_pushes() - written on EVERY tick
     * (even ones with nothing to send), so its timestamp doubles as a
     * heartbeat proving the sessioneer-push-check timer is actually running, not
     * just that sends succeed when attempted. Read back by
     * PushHealthService::push_delivery_check() for the dashboard.
     * GlobalStateStore key, not a file, since 2026-08-24 - see that
     * class's own docblock.
     */
    public static function push_check_status_key(): string
    {
        return 'push_check_status';
    }

    /**
     * Same heartbeat idea as push_check_status_key(), its own separate key
     * - check_and_send_quota_pushes() runs as its own pass alongside
     * check_and_send_pushes() every tick (see push_trigger.php), and each
     * writing to the SAME key would have the second call's counts silently
     * clobber the first's, breaking push_delivery_check()'s existing "the
     * session-transition pass ran and its own sends succeeded" reading of
     * that key.
     */
    public static function push_quota_check_status_key(): string
    {
        return 'push_quota_check_status';
    }

    /**
     * Sends one push notification to one subscription. Returns whether the
     * subscription is still good - a 404/410 means the browser/OS has
     * permanently discarded it, the caller should prune it rather than retry
     * it forever (iOS's own subscription lifecycle is especially prone to
     * this - see the README).
     *
     * $actions (only ever set for a "blocked" notification - see
     * check_and_send_pushes() below) adds session/option data sw.js's
     * showNotification() turns into one-tap Approve/Deny action buttons,
     * letting notificationclick answer the prompt directly via fetch() with
     * no window ever opening. Platform support for `actions` varies (full
     * on Android Chrome and desktop Chrome/Edge on Windows/Linux, degraded
     * to hover+"More" on macOS, absent entirely on iOS Safari and Firefox -
     * researched 2026-08-22) - omitted (null) here always falls back
     * cleanly to a plain notification, exactly today's behavior, on every
     * platform/state where it doesn't apply.
     *
     * @param array{endpoint:string, keys:array{p256dh:string, auth:string}} $subscription
     * @param array{session:string, approve_option:int, deny_option:int}|null $actions
     * @return array{ok:bool, expired:bool, message?:string}
     */
    public static function send_push_notification(array $subscription, string $title, string $body, ?string $url = null, ?array $actions = null): array
    {
        if (!self::push_configured()) {
            return ['ok' => false, 'expired' => false, 'message' => 'VAPID keys not configured'];
        }

        // minishlink/web-push validates VAPID key format (and can throw for
        // other reasons too) - caught rather than left to propagate, since
        // this runs unattended via the sessioneer-push-check systemd timer: an
        // uncaught exception here would silently kill that whole tick (every
        // other session's transition check included) rather than just this
        // one send failing. Found live while testing against a deliberately
        // malformed key: it's a hard ErrorException, not a normal return.
        try {
            $webPush = new WebPush([
                'VAPID' => [
                    'subject' => self::vapid_subject(),
                    'publicKey' => self::vapid_public_key(),
                    'privateKey' => self::vapid_private_key(),
                ],
            ], [], 30, [
                // Found live: IPv6 to web.push.apple.com can silently black-hole
                // on this network (times out after the full 30s) while IPv4 to
                // the exact same endpoint responds instantly - forcing IPv4
                // avoids paying that timeout on every send.
                'curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4],
            ]);

            $payload = json_encode([
                'title' => $title,
                'body' => $body,
                'url' => $url,
                'session' => $actions['session'] ?? null,
                'approve_option' => $actions['approve_option'] ?? null,
                'deny_option' => $actions['deny_option'] ?? null,
            ]);

            $report = $webPush->sendOneNotification(
                Subscription::create($subscription),
                $payload !== false ? $payload : null
            );
        } catch (\Throwable $e) {
            return ['ok' => false, 'expired' => false, 'message' => $e->getMessage()];
        }

        if ($report->isSuccess()) {
            return ['ok' => true, 'expired' => false];
        }

        return [
            'ok' => false,
            'expired' => $report->isSubscriptionExpired(),
            'message' => $report->getReason(),
        ];
    }

    /**
     * Persists the outcome of one check_and_send_pushes() tick and logs any
     * non-expiry failure to the journal (via error_log(), which sessioneer-push-
     * check.service's default StandardError=journal already routes there) -
     * previously the ONLY trace of a failed send was silently pruning an
     * expired subscription; anything else (malformed payload, the push
     * service unreachable, a bad VAPID key) left no record anywhere. Called
     * every tick regardless of whether there was anything to send, so
     * "checked_at" also works as a heartbeat - see push_check_status_key().
     *
     * $statusKey defaults to push_check_status_key() - check_and_send_quota_pushes()
     * passes push_quota_check_status_key() instead, its own separate
     * GlobalStateStore key, so the two passes' heartbeats never clobber
     * each other (see that method's own doc comment).
     *
     * @param array<int, array{ok:bool, expired:bool, message?:string}> $sendResults
     */
    public static function record_push_check_result(array $sendResults, ?string $statusKey = null): void
    {
        $statusKey ??= self::push_check_status_key();
        $failures = array_values(array_filter(
            $sendResults,
            fn(array $r): bool => !$r['ok'] && !$r['expired']
        ));

        foreach ($failures as $failure) {
            error_log('sessioneer-push-check: send failed - ' . ($failure['message'] ?? 'unknown reason'));
        }

        $status = [
            'checked_at' => time(),
            'sent' => count($sendResults),
            'failed' => count($failures),
            'last_failure_message' => $failures !== [] ? ($failures[count($failures) - 1]['message'] ?? 'unknown reason') : null,
        ];

        GlobalStateStore::write($statusKey, $status);
    }

    /**
     * Maps a blocked prompt's options onto Approve/Deny for
     * send_push_notification()'s own $actions -
     * PromptParser::build_options_from_permission_suggestions() always
     * builds "1. Yes" ... "N. No" (an optional middle suggestion-derived
     * option in between doesn't change either end), and the one other real
     * source of $prompt_options here, the folder-trust dialog, uses "No,
     * exit" / "Yes, I trust this folder" - checking label text rather than
     * position is what makes this a real correctness check, not just
     * paranoia, so it never assumed a fixed position in the first place:
     * null for anything that doesn't actually look like a plain Yes/No
     * choice (a multi-question AskUserQuestion never populates
     * $prompt_options at all, so this never runs for that shape) rather
     * than guessing and risking Approve secretly meaning something else.
     *
     * Found live 2026-09-11: Claude Code v2.1.269 reversed the folder-trust
     * dialog's default option order ("No, exit" now comes first, not
     * "Yes, I trust this folder" - see PromptParser's own docblock), which
     * would have silently broken a first-vs-last check - scans every
     * option for its label instead of assuming either end.
     *
     * @param array<int, mixed> $promptOptions
     * @return array{session:string, approve_option:int, deny_option:int}|null
     */
    public static function approve_deny_actions(string $sessionName, array $promptOptions): ?array
    {
        if (count($promptOptions) < 2) {
            return null;
        }

        $approveOption = null;
        $denyOption = null;

        foreach ($promptOptions as $opt) {
            if (!is_array($opt) || !is_int($opt['number'] ?? null) || !is_string($opt['label'] ?? null)) {
                return null;
            }

            // First "yes"-prefixed option wins (e.g. a permission prompt's
            // real menu, "1. Yes" / "2. Yes, and always allow this" / "3. No" -
            // the SECOND option there is also "yes"-prefixed but is an
            // "always" variant, not what Approve should mean); only one
            // "no"-prefixed option is ever expected, so plain overwrite is fine.
            if ($approveOption === null && stripos($opt['label'], 'yes') === 0) {
                $approveOption = $opt['number'];
            } elseif (stripos($opt['label'], 'no') === 0) {
                $denyOption = $opt['number'];
            }
        }

        if ($approveOption === null || $denyOption === null) {
            return null;
        }

        return ['session' => $sessionName, 'approve_option' => $approveOption, 'deny_option' => $denyOption];
    }

    /**
     * The main push-trigger pass, called on every sessioneer-push-check timer tick
     * (see host-agent/push_trigger.php): for every currently-live session,
     * compares its current NotificationContentBuilder::push_session_state()
     * against what was last recorded (including how long it's been in that
     * state, tracked via "since" - see
     * PushSessionStateStore::read_push_session_state()), and sends a push to
     * every stored subscription for either of two transitions:
     *
     * - INTO 'blocked' (a new prompt appeared) - the transition matters, not
     *   the state itself, so a prompt that's been sitting unanswered for an
     *   hour doesn't re-notify on every tick.
     * - FROM 'working' INTO 'idle', but only once it's been working
     *   continuously for at least push_min_working_seconds_for_finish_notify()
     *   - a session finishing a genuinely long task without needing any
     *   input at all previously had NO notification coverage whatsoever (only
     *   the "needs input" case did); the duration gate avoids notifying for
     *   every trivial quick reply.
     *
     * Also prunes any subscription a send reports as permanently expired.
     *
     * @param array<int, array{name:string, blocked_reason?:?string, working?:bool, title?:?string, workdir?:?string, last_message?:?array}> $sessions
     * @param int|null $now defaults to time() - overridable so tests can
     *   exercise the duration gate without real sleeps
     * @return array{ok:bool, notified:array<int, string>, pruned:int}
     */
    public static function check_and_send_pushes(array $sessions, ?int $now = null): array
    {
        if (!self::push_configured()) {
            return ['ok' => false, 'notified' => [], 'pruned' => 0];
        }

        $now ??= time();
        $previousState = PushSessionStateStore::read_push_session_state();
        $currentState = [];
        $notified = [];
        $subscriptions = PushSubscriptionStore::read_push_subscriptions();
        $expiredEndpoints = [];
        $sendResults = [];
        $minWorkingSeconds = self::push_min_working_seconds_for_finish_notify();

        foreach ($sessions as $session) {
            $name = (string)$session['name'];
            $state = NotificationContentBuilder::push_session_state($session);

            $previousEntry = is_array($previousState[$name] ?? null) ? $previousState[$name] : null;
            $previousStateName = is_string($previousEntry['state'] ?? null) ? $previousEntry['state'] : null;
            $previousSince = is_int($previousEntry['since'] ?? null) ? $previousEntry['since'] : null;

            // Carries the "since" timestamp forward as long as the state
            // itself hasn't changed - this is what lets a later tick compute
            // "how long has it actually been in this state" rather than just
            // "not the same as last tick".
            $since = ($previousStateName === $state && $previousSince !== null) ? $previousSince : $now;
            $currentState[$name] = ['state' => $state, 'since' => $since];

            $notification = null;

            if ($state === 'blocked' && $previousStateName !== 'blocked') {
                $notification = [
                    'title' => NotificationContentBuilder::push_blocked_title($session),
                    'body' => NotificationContentBuilder::push_blocked_body($session),
                    'actions' => self::approve_deny_actions($name, is_array($session['prompt_options'] ?? null) ? $session['prompt_options'] : []),
                ];
            } elseif (
                $state === 'idle'
                && $previousStateName === 'working'
                && $previousSince !== null
                && ($now - $previousSince) >= $minWorkingSeconds
            ) {
                $notification = [
                    'title' => NotificationContentBuilder::push_finished_title($session),
                    'body' => NotificationContentBuilder::push_finished_body(is_array($session['last_message'] ?? null) ? $session['last_message'] : null),
                ];
            }

            if ($notification === null) {
                continue;
            }

            // $notified reflects the transition itself, independent of
            // whether there's actually anyone subscribed to receive it -
            // keeps "was a transition detected" and "did a send happen"
            // separately observable/testable, and means a fresh install with
            // zero subscriptions yet still correctly tracks state instead of
            // silently skipping the bookkeeping too.
            $notified[] = $name;

            if ($subscriptions !== []) {
                foreach ($subscriptions as $subscription) {
                    $result = self::send_push_notification($subscription, $notification['title'], $notification['body'], '/session.php?session=' . urlencode($name), $notification['actions'] ?? null);
                    $sendResults[] = $result;

                    if ($result['expired']) {
                        $expiredEndpoints[] = $subscription['endpoint'];
                    }
                }
            }
        }

        if ($expiredEndpoints !== []) {
            $subscriptions = array_values(array_filter($subscriptions, fn(array $s): bool => !in_array($s['endpoint'], $expiredEndpoints, true)));
            PushSubscriptionStore::write_push_subscriptions($subscriptions);
        }

        PushSessionStateStore::write_push_session_state($currentState);
        self::record_push_check_result($sendResults);

        return ['ok' => true, 'notified' => $notified, 'pruned' => count($expiredEndpoints)];
    }

    /**
     * The quota counterpart to check_and_send_pushes() above, called
     * alongside it on every sessioneer-push-check timer tick (see
     * host-agent/push_trigger.php) with QuotaService::get_quota(null)'s
     * 'quota' sub-array - account-wide only (no session name is ever
     * passed there), so the per-session 'context' bucket never appears
     * here and needs no special exclusion.
     *
     * Three distinct notification kinds per bucket, all independent of each
     * other within the same tick:
     * - "near" once pct first reaches push_quota_near_threshold_pct()
     * - "over" once pct first reaches 100
     * - "reset" once wall-clock time passes the bucket's own resets_at,
     *   tracked via a one-shot notified_reset flag in PushQuotaStateStore
     *   (Andres's own proposal, 2026-08-23, after a real reset notification
     *   arrived ~29 minutes late in production). Replaces an EARLIER
     *   version that inferred a reset from pct DROPPING by a threshold
     *   amount since the last tick, which - although itself a deliberate
     *   fix for an even older resets_at-jitter bug (2026-08-05: resets_at
     *   used to be re-parsed from the live pane's own duration text, e.g.
     *   "1h 53m", only minute-precision, jittering ~60s between ticks) -
     *   had a structural problem the pct-jitter fix never addressed:
     *   QuotaService::quota_from_statusline_state() is event-driven, only
     *   ever updated by a LIVE session's own statusLine render (see that
     *   method's docblock) - with no session open, pct/resets_at both sit
     *   frozen at whatever was last observed, so a pct-drop comparison
     *   literally cannot fire until SOME session becomes active again and
     *   renders at least once, however long after the real reset that
     *   happens to be. resets_at, by contrast, is (as of the 2026-08-05
     *   fix) a real Unix epoch straight from Claude Code's own statusLine
     *   JSON (rate_limits.*.resets_at) - no longer reconstructed from lossy
     *   pane text, so comparing it against time() needs no fresh render at
     *   all: even a STALE bucket (last written hours ago, before the
     *   reset) still carries an accurate resets_at, and the sessioneer-push-check
     *   timer's own 10s ticks (independent of any session) can detect
     *   "we're past it" within one tick of the real reset, active session
     *   or not.
     *
     * notified_reset (per bucket, in PushQuotaStateStore) starts true for a
     * bucket never seen before (nothing to announce on a first-ever
     * observation), and is re-armed to false whenever a FRESH, LATER
     * resets_at is observed (a session has revealed a new window boundary
     * to eventually announce) - the write happens the moment fresher data
     * arrives, not the moment it's actually due, so the flag can sit
     * "armed" for as long as the current window lasts before the
     * time()-based check above actually fires it.
     *
     * Both the near and over flags are one-shot per window: once fired,
     * they don't fire again until either a real reset (above) or the pct
     * drops back under the near-threshold on its own (e.g. a plan change)
     * - re-arming both for the next climb. $quota being null/empty (the
     * quota fetch itself failed or hasn't completed yet this tick) is a
     * harmless no-op, same as check_and_send_pushes() with zero live
     * sessions.
     *
     * @param array<string, mixed>|null $quota
     * @return array{ok:bool, notified:array<int, string>}
     */
    public static function check_and_send_quota_pushes(?array $quota): array
    {
        if (!self::push_configured() || $quota === null || $quota === []) {
            return ['ok' => false, 'notified' => []];
        }

        $threshold = self::push_quota_near_threshold_pct();
        $previousState = PushQuotaStateStore::read_push_quota_state();
        $currentState = [];
        $notified = [];
        $subscriptions = PushSubscriptionStore::read_push_subscriptions();
        $expiredEndpoints = [];
        $sendResults = [];
        $now = time();

        foreach ($quota as $key => $bucket) {
            if (!is_array($bucket) || !isset($bucket['pct'])) {
                continue; // not a real bucket (e.g. captured_at) - nothing to track
            }

            $pct = (int)$bucket['pct'];
            $resetsAt = is_int($bucket['resets_at'] ?? null) ? $bucket['resets_at'] : null;

            $previousEntry = is_array($previousState[$key] ?? null) ? $previousState[$key] : null;
            $previousResetsAt = is_int($previousEntry['resets_at'] ?? null) ? $previousEntry['resets_at'] : null;
            $notifiedNear = !empty($previousEntry['notified_near']);
            $notifiedOver = !empty($previousEntry['notified_over']);
            $notifiedReset = $previousEntry !== null ? !empty($previousEntry['notified_reset']) : true;

            // A session revealed a DIFFERENT window boundary since the last
            // tick - a fresh cycle to track, not yet notified for THIS one.
            // Any change counts, not just an increase: the fresh value
            // might itself still be in the future (a real rollover just
            // observed, its NEXT resets_at now further out - re-arming here
            // is harmless, $justReset below still correctly waits for time
            // to actually reach it). A missing/unchanged resets_at this
            // tick (a stale read, or nothing new to report) leaves
            // notifiedReset exactly as it was. Gated on $previousEntry !==
            // null - a bucket's very FIRST-ever observation has no prior
            // window to have reset FROM, so it must never re-arm (and thus
            // never immediately fire) no matter what its resets_at happens
            // to be.
            if ($previousEntry !== null && $resetsAt !== null && $resetsAt !== $previousResetsAt) {
                $notifiedReset = false;
            }

            // The EFFECTIVE resets_at to check against wall-clock time -
            // this tick's fresh value if we have one, else whatever was
            // last known (a stale read must still be able to fire once
            // enough real time has passed, that's the whole point).
            $effectiveResetsAt = $resetsAt ?? $previousResetsAt;
            $justReset = $effectiveResetsAt !== null && $now >= $effectiveResetsAt && !$notifiedReset;

            if ($justReset) {
                $notifiedReset = true;
                $notifiedNear = false;
                $notifiedOver = false;
            }

            $notifications = [];

            if ($justReset) {
                // Deliberately NOT also evaluated for near/over below (see
                // the elseif) - $pct on THIS tick may still be the stale,
                // pre-reset reading (resets_at and pct come from the same
                // statusline read, but a reset detected from a STALE read,
                // per this method's whole reason for existing, means we
                // can't trust that pct reflects the NEW window at all).
                // Firing "reset" and "over" together off a known-stale pct
                // would be self-contradictory. notifiedNear/notifiedOver
                // are already re-armed to false just above, so the next
                // FRESH read decides near/over cleanly on its own terms.
                $notifications[] = ['title' => NotificationContentBuilder::push_quota_reset_title((string)$key), 'body' => NotificationContentBuilder::push_quota_reset_body((string)$key)];
            } elseif ($pct >= 100 && !$notifiedOver) {
                $notifications[] = ['title' => NotificationContentBuilder::push_quota_over_title((string)$key), 'body' => NotificationContentBuilder::push_quota_over_body((string)$key, $pct)];
                $notifiedOver = true;
                $notifiedNear = true; // over implies near - no separate near notification right after
            } elseif ($pct >= $threshold && !$notifiedNear) {
                $notifications[] = ['title' => NotificationContentBuilder::push_quota_near_title((string)$key), 'body' => NotificationContentBuilder::push_quota_near_body((string)$key, $pct)];
                $notifiedNear = true;
            } elseif ($pct < $threshold) {
                // Dropped back under the threshold on its own (a plan
                // change, or a reset already handled above) - re-arm both
                // so a future climb notifies again instead of staying
                // permanently silenced from one earlier crossing.
                $notifiedNear = false;
                $notifiedOver = false;
            }

            $currentState[$key] = [
                'pct' => $pct,
                'resets_at' => $effectiveResetsAt,
                'notified_near' => $notifiedNear,
                'notified_over' => $notifiedOver,
                'notified_reset' => $notifiedReset,
            ];

            foreach ($notifications as $notification) {
                $notified[] = "{$key}:{$notification['title']}";

                foreach ($subscriptions as $subscription) {
                    $result = self::send_push_notification($subscription, $notification['title'], $notification['body']);
                    $sendResults[] = $result;

                    if ($result['expired']) {
                        $expiredEndpoints[] = $subscription['endpoint'];
                    }
                }
            }
        }

        if ($expiredEndpoints !== []) {
            $subscriptions = array_values(array_filter($subscriptions, fn(array $s): bool => !in_array($s['endpoint'], $expiredEndpoints, true)));
            PushSubscriptionStore::write_push_subscriptions($subscriptions);
        }

        PushQuotaStateStore::write_push_quota_state($currentState);
        self::record_push_check_result($sendResults, self::push_quota_check_status_key());

        return ['ok' => true, 'notified' => $notified];
    }
}
