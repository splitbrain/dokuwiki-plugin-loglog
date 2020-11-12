<?php

/**
 * Class helper_plugin_loglog_report
 */
class helper_plugin_loglog_report extends DokuWiki_Plugin
{
    /**
     * @var \helper_plugin_loglog_main
     */
    protected $mainHelper;

    /**
     * @var \helper_plugin_loglog_logging
     */
    protected $logHelper;

    public function __construct()
    {
        $this->mainHelper = $this->loadHelper('loglog_main');
        $this->logHelper = $this->loadHelper('loglog_logging');
    }

    /**
     * Checks if the report has already been sent this month. If not, creates and
     * sends the report, and records this action in the log.
     */
    public function handleReport()
    {
        $email = $this->getConf('report_email');
        if (!$email) return;

        // calculate cutoff dates
        $lastMonthStart = mktime(0, 0, 0, date('n', strtotime('last month')), 1);
        $currentMonthStart = mktime(0, 0, 0, date('n'), 1);

        // check if the report is due
        global $conf;
        $statfile = $conf['cachedir'] . '/loglog.stat';
        if (is_file($statfile) && filemtime($statfile) >= $currentMonthStart) {
            return;
        }

        // calculate stat
        $monthLines = $this->logHelper->readLines($lastMonthStart, $currentMonthStart);
        $stats = $this->getStats($monthLines);

        // email the report
        $template = $this->localFN('report');
        $text = file_get_contents($template);
        // format access to admin pages
        $adminPages = implode(
            "\n",
            array_map(
                function ($page, $cnt) {
                    return "  - $page: $cnt";
                },
                array_keys($stats['admin']),
                $stats['admin']
            )
        );

        $text = str_replace(
            ['@@auth_ok@@', '@@auth_fail@@', '@@users@@', '@@admin_pages@@'],
            [$stats['auth_success'], $stats['auth_failed'], $stats['users'], $adminPages],
            $text
        );

        if (
            $this->mainHelper->sendEmail(
                $email,
                $this->getLang('email_report_subject'),
                $text
            )
        ) {
            // log itself
            $this->logHelper->writeLine('loglog - report', 'cron');
            // touch statfile
            touch($statfile);
        }
    }

    /**
     * Go through supplied log lines and aggregate basic activity statistics
     *
     * @param array $lines
     * @return array
     */
    public function getStats(array $lines)
    {
        $authOk = 0;
        $authFail = 0;
        $users = [];
        $pages = ['start' => 0];

        foreach ($lines as $line) {
            if (
                strpos(
                    $line['msg'],
                    $this->mainHelper->getNotificationString(\helper_plugin_loglog_main::LOGTYPE_AUTH_OK, 'msgNeedle')
                ) !== false
            ) {
                $authOk++;
                if ($line['user']) $users[] = $line['user'];
            } elseif (
                strpos(
                    $line['msg'],
                    $this->mainHelper->getNotificationString(\helper_plugin_loglog_main::LOGTYPE_AUTH_FAIL, 'msgNeedle')
                ) !== false
            ) {
                $authFail++;
            } elseif (strpos($line['msg'], 'admin') !== false) {
                list($action, $page) = explode(' - ', $line['msg']);
                if ($page) {
                    $pages[$page] = !isset($pages[$page]) ? 1 : $pages[$page] + 1;
                } else {
                    $pages['start']++;
                }
            }
        }

        return [
            \helper_plugin_loglog_main::LOGTYPE_AUTH_OK => $authOk,
            \helper_plugin_loglog_main::LOGTYPE_AUTH_FAIL => $authFail,
            'users' => count(array_unique($users)),
            'admin' => $pages
        ];
    }
}
