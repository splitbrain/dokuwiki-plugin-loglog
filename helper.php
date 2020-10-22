<?php

/**
 * Class helper_plugin_loglog
 */
class helper_plugin_loglog extends \dokuwiki\Extension\Plugin
{
    const CONTEXT_AUTH_OK = 'auth_success';
    const CONTEXT_AUTH_FAIL = 'auth_failed';

    /**
     * Build a log entry from passed data and write a single line to log file
     *
     * @param string $msg
     * @param null $user
     * @param array $data
     */
    public function writeLine($msg, $user = null, $data = [])
    {
        global $conf, $INPUT;

        if (is_null($user)) $user = $INPUT->server->str('REMOTE_USER');
        if (!$user) $user = $_REQUEST['u'];
        if (!$user) return;

        $t = time();
        $ip = clientIP(true);
        $data = !empty($data) ? serialize($data) : '';

        $line = join("\t", [$t, strftime($conf['dformat'], $t), $ip, $user, $msg, $data]);

        io_saveFile($conf['cachedir'] . '/loglog.log', "$line\n", true);
    }

    /**
     * Deduce filter from message field. Filters are used in a dropdown in admin listing of activities,
     * as well as when generating reports to send per email.
     *
     * @param string $msg
     * @return string
     */
    public function getFilterFromMsg($msg)
    {
        $filter = 'other';
        if (in_array(
            $msg,
            [
                'logged in temporarily',
                'logged in permanently',
                'logged off',
                'has been automatically logged off'
            ]
        )) {
            $filter = 'auth_ok';
        } elseif (in_array(
            $msg,
            [
                'failed login attempt',
            ]
        )) {
            $filter = 'auth_error';
        } elseif (strpos($msg, 'admin') === 0) {
            $filter = 'admin';
        }

        return $filter;
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
            if (strpos($line, $this->getNotificationString(self::CONTEXT_AUTH_OK, 'msgNeedle')) !== false) {
                $authOk++;
                list($dt, $junk, $ip, $user, $msg, $data) = explode("\t", $line, 6);
                if ($user) $users[] = $user;
            } elseif (strpos($line, $this->getNotificationString(self::CONTEXT_AUTH_FAIL, 'msgNeedle')) !== false) {
                $authFail++;
            } elseif (strpos($line, 'admin') !== false) {
                list($dt, $junk, $ip, $user, $msg, $data) = explode("\t", $line, 6);
                list($action, $page) = explode(' - ', $msg);
                if ($page) {
                    $pages[$page] = !isset($pages[$page]) ? 1 : $pages[$page] + 1;
                } else {
                    $pages['start']++;
                }
            }
        }

        return [
            self::CONTEXT_AUTH_OK => $authOk,
            self::CONTEXT_AUTH_FAIL => $authFail,
            'users' => count(array_unique($users)),
            'admin' => $pages
        ];
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
            self::CONTEXT_AUTH_FAIL,
            $this->getConf('login_failed_max'),
            $this->getConf('login_failed_interval'),
            $this->getConf('login_failed_email')
        );

        $this->handleThreshold(
            self::CONTEXT_AUTH_OK,
            $this->getConf('login_success_max'),
            $this->getConf('login_success_interval'),
            $this->getConf('login_success_email')
        );
    }

    /**
     * Evaluates threshold configuration for given context and triggers email alerts.
     *
     * @param string $context
     * @param int $threshold
     * @param int $interval
     * @param string $email
     */
    protected function handleThreshold($context, $threshold, $interval, $email)
    {
        // proceed only if context has sufficient configuration
        if ($email && $threshold && $interval) {
            $now = time();

            $max = $now;
            $min = $now - ($interval / 60);

            $msgNeedle = $this->getNotificationString($context, 'msgNeedle');
            $lines = $this->readLines($min, $max);
            $cnt = $this->countMatchingLines($lines, $msgNeedle);
            if ($cnt >= $threshold) {
                $text = $this->locale_xhtml($context);
                $this->sendEmail(
                    $email,
                    $this->getLang($this->getNotificationString($context, 'emailSubjectLang')) . ' ' . DOKU_URL,
                    $text
                );
            }
        }
    }

    /**
     * Sends emails
     *
     * @param string $email
     * @param string $subject
     * @param string $text
     * @return bool
     */
    public function sendEmail($email, $subject, $text)
    {
        $mail = new Mailer();
        $mail->to($email);
        $mail->subject($subject);
        $mail->setBody($text);
        return $mail->send();
    }

    /**
     * Returns a string corresponding to $key in a given $context,
     * empty string if nothing has been found in the string repository.
     *
     * @param string $context
     * @param string $key
     * @return string
     */
    protected function getNotificationString($context, $key)
    {
        $stringRepo = [
            self::CONTEXT_AUTH_FAIL => [
                'msgNeedle' => 'failed login attempt',
                'emailSubjectLang' => 'email_max_failed_logins_subject'
            ],
            self::CONTEXT_AUTH_OK => [
                'msgNeedle' => 'logged in',
                'emailSubjectLang' => 'email_max_success_logins_subject'
            ],
        ];

        return isset($stringRepo[$context][$key]) ? $stringRepo[$context][$key] : '';
    }

    /**
     * Read loglines backwards
     *
     * @param int $min start time (in seconds)
     * @param int $max end time (in seconds)
     * @return array
     */
    public function readLines($min, $max)
    {
        global $conf;
        $file = $conf['cachedir'] . '/loglog.log';

        $data = array();
        $lines = array();
        $chunk_size = 8192;

        if (!@file_exists($file)) return $data;
        $fp = fopen($file, 'rb');
        if ($fp === false) return $data;

        //seek to end
        fseek($fp, 0, SEEK_END);
        $pos = ftell($fp);
        $chunk = '';

        while ($pos) {

            // how much to read? Set pointer
            if ($pos > $chunk_size) {
                $pos -= $chunk_size;
                $read = $chunk_size;
            } else {
                $read = $pos;
                $pos = 0;
            }
            fseek($fp, $pos);

            $tmp = fread($fp, $read);
            if ($tmp === false) break;
            $chunk = $tmp . $chunk;

            // now split the chunk
            $cparts = explode("\n", $chunk);

            // keep the first part in chunk (may be incomplete)
            if ($pos) $chunk = array_shift($cparts);

            // no more parts available, read on
            if (!count($cparts)) continue;

            // get date of first line:
            list($cdate) = explode("\t", $cparts[0]);

            if ($cdate > $max) continue; // haven't reached wanted area, yet

            // put the new lines on the stack
            $lines = array_merge($cparts, $lines);

            if ($cdate < $min) break; // we have enough
        }
        fclose($fp);

        return $lines;
    }

    /**
     * Returns the number of lines where the given needle has been found
     *
     * @param array $lines
     * @param string $msgNeedle
     * @return mixed
     */
    protected function countMatchingLines(array $lines, string $msgNeedle)
    {
        return array_reduce(
            $lines,
            function ($carry, $line) use ($msgNeedle) {
                $carry = $carry + (int)(strpos($line, $msgNeedle) !== false);
                return $carry;
            },
            0
        );
    }

}
