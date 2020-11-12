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
    protected $multiplier;

    /** @var string */
    protected $statfile;

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
        $this->resetMultiplier();
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

        global $conf;
        $this->statfile = $conf['cachedir'] . '/loglog.' . $logType . '.stat';

        if ($this->actNow()) {
            io_saveFile($this->statfile, $this->multiplier);
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
                'next_alert' => date('Y-m-d H:i', $this->getNextAlert()),
            ]
        );
    }

    /**
     * Check if it is time to act or wait this interval out
     *
     * @return bool
     */
    protected function actNow()
    {
        $act = true;

        if (!is_file($this->statfile)) {
            return $act;
        }

        $lastAlert = filemtime($this->statfile);
        $this->multiplier = (int)file_get_contents($this->statfile);

        $intervalsAfterLastAlert = (int)floor(($this->now - $lastAlert) / $this->interval);

        if ($intervalsAfterLastAlert === $this->multiplier) {
            $this->increaseMultiplier();
        } elseif ($intervalsAfterLastAlert < $this->multiplier) {
            $act = false;
        } elseif ($intervalsAfterLastAlert > $this->multiplier) {
            $this->resetMultiplier(); // no longer part of series, reset multiplier
        }

        return $act;
    }

    /**
     * Calculate which phase of sequential events we are in (possible attacks),
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

    /**
     * Reset multiplier. Called when the triggering event is not part of a sequence.
     */
    protected function resetMultiplier()
    {
        $this->multiplier = 1;
    }

    /**
     * Increase multiplier. Called when the triggering event belongs to a sequence.
     */
    protected function increaseMultiplier()
    {
        $this->multiplier *= 2;
    }
}
