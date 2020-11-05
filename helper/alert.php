<?php

/**
 * Class helper_plugin_loglog_alert
 */
class helper_plugin_loglog_alert extends DokuWiki_Plugin
{
    /**
     * @var \helper_plugin_loglog_main
     */
    protected $mainHelper;

    /**
     * @var \helper_plugin_loglog_logging
     */
    protected $logHelper;

    /** @var int */
    protected $interval;

    /** @var int */
    protected $threshold;

    /** @var int */
    protected $now;

    /** @var int */
    protected $lastAlert;

    /** @var int */
    protected $multiplier;

    public function __construct()
    {
        $this->mainHelper = $this->loadHelper('loglog_main');
        $this->logHelper = $this->loadHelper('loglog_logging');
    }

    /**
     * Check if any configured thresholds have been exceeded and trigger
     * alert notifications accordingly.
     *
     * @return void
     */
    public function checkAlertThresholds()
    {
        $this->handleThreshold(
            \helper_plugin_loglog_main::LOGTYPE_AUTH_FAIL,
            $this->getConf('login_failed_max'),
            $this->getConf('login_failed_interval'),
            $this->getConf('login_failed_email')
        );

        $this->handleThreshold(
            \helper_plugin_loglog_main::LOGTYPE_AUTH_OK,
            $this->getConf('login_success_max'),
            $this->getConf('login_success_interval'),
            $this->getConf('login_success_email')
        );
    }

    /**
     * Evaluates threshold configuration for given type of logged event
     * and triggers email alerts.
     *
     * @param string $logType
     * @param int $threshold
     * @param int $minuteInterval
     * @param string $email
     */
    protected function handleThreshold($logType, $threshold, $minuteInterval, $email)
    {
        // proceed only if we have sufficient configuration
        if (! $email || ! $threshold || ! $minuteInterval) {
            return;
        }
        $this->multiplier = 1;
        $this->threshold = $threshold;
        $this->interval = $minuteInterval * 60;
        $this->now = time();
        $max = $this->now;
        $min = $this->now - ($this->interval);

        $msgNeedle = $this->mainHelper->getNotificationString($logType, 'msgNeedle');
        $lines = $this->logHelper->readLines($min, $max);
        $cnt = $this->logHelper->countMatchingLines($lines, $msgNeedle);
        if ($cnt < $threshold) {
            return;
        }

        // first alert, or part of a series?
        $wait = false;

        global $conf;
        $statfile = $conf['cachedir'] . '/loglog.'. $logType.'.stat';

        if (is_file($statfile)) {
            $lastAlert = filemtime($statfile);
            $this->multiplier = (int) file_get_contents($statfile);

            $intervalsAfterLastAlert = (int) floor(($this->now - $lastAlert) / $this->interval);

            // time to act or wait this interval out?
            if ($intervalsAfterLastAlert === $this->multiplier) {
                $this->multiplier *= 2;
            } elseif ($intervalsAfterLastAlert < $this->multiplier) {
                $wait = true;
            } elseif ($intervalsAfterLastAlert > $this->multiplier) {
                $this->multiplier = 1; // no longer part of series, reset multiplier
            }
        }

        if (!$wait) {
            io_saveFile($statfile, $this->multiplier);
            $this->sendAlert($logType, $email);
        }
    }

    /**
     * Send alert email
     *
     * @param string $logType
     * @param string $email
     */
    protected function sendAlert($logType, $email)
    {
        $template = $this->localFN($logType);
        $text = file_get_contents($template);
        $this->mainHelper->sendEmail(
            $email,
            $this->getLang($this->mainHelper->getNotificationString($logType, 'emailSubjectLang')),
            $text,
            [
                'threshold' => $this->threshold,
                'interval' => $this->interval / 60, // falling back to minutes for the view
                'now' => date('Y-m-d H:i', $this->now),
                'sequence' => $this->getSequencePhase(),
                'next_interval' => $this->interval / 60 * $this->multiplier * 2, // falling back to minutes for the view
                'next_alert' => date('Y-m-d H:i', $this->getNextAlert()),
            ]
        );
    }

    /**
     * Calculate in which phase of sequential events we are in (possible attacks),
     * based on the interval multiplier. 1 indicates the first incident,
     * otherwise evaluate the exponent (because we multiply the interval by 2 on each alert).
     *
     * @return int
     */
    protected function getSequencePhase()
    {
        return $this->multiplier === 1 ? $this->multiplier : log($this->multiplier, 2) + 1;
    }

    /**
     * Calculate when the next alert is due based on the current multiplier
     *
     * @return int
     */
    protected function getNextAlert()
    {
        return $this->now + $this->interval * $this->multiplier * 2;
    }
}
