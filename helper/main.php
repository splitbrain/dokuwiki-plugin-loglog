<?php

/**
 * Class helper_plugin_loglog_main
 */
class helper_plugin_loglog_main extends \dokuwiki\Extension\Plugin
{
    const LOGTYPE_AUTH_OK = 'auth_success';
    const LOGTYPE_AUTH_FAIL = 'auth_failed';

    /**
     * @var helper_plugin_loglog_logging
     */
    protected $logHelper;

    public function __construct()
    {
        $this->logHelper = $this->loadHelper('loglog_logging');
    }

    /**
     * Deduce the type of logged event from message field. Those types are used in a dropdown filter
     * in admin listing of activities, as well as when generating reports to send per email.
     *
     * @param string $msg
     * @return string
     */
    public function getLogTypeFromMsg($msg)
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
     * Check if any configured thresholds have been exceeded and trigger
     * alert notifications accordingly.
     *
     * @return void
     */
    public function checkAlertThresholds()
    {
        $this->handleThreshold(
            self::LOGTYPE_AUTH_FAIL,
            $this->getConf('login_failed_max'),
            $this->getConf('login_failed_interval'),
            $this->getConf('login_failed_email')
        );

        $this->handleThreshold(
            self::LOGTYPE_AUTH_OK,
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
     * @param int $interval
     * @param string $email
     */
    protected function handleThreshold($logType, $threshold, $interval, $email)
    {
        // proceed only if we have sufficient configuration
        if (! $email || ! $threshold || ! $interval) {
            return;
        }
        $now = time();

        $max = $now;
        $min = $now - ($interval * 60);

        $msgNeedle = $this->getNotificationString($logType, 'msgNeedle');
        $lines = $this->logHelper->readLines($min, $max);
        $cnt = $this->logHelper->countMatchingLines($lines, $msgNeedle, $min, $max);
        if ($cnt >= $threshold) {
            $template = $this->localFN($logType);
            $text = file_get_contents($template);
            $this->sendEmail(
                $email,
                $this->getLang($this->getNotificationString($logType, 'emailSubjectLang')),
                $text
            );
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
        $html = p_render('xhtml', p_get_instructions($text), $info);

        $mail = new Mailer();
        $mail->to($email);
        $mail->subject($subject);
        $mail->setBody($text, null, null, $html);
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
    public function getNotificationString($context, $key)
    {
        $stringRepo = [
            self::LOGTYPE_AUTH_FAIL => [
                'msgNeedle' => 'failed login attempt',
                'emailSubjectLang' => 'email_max_failed_logins_subject'
            ],
            self::LOGTYPE_AUTH_OK => [
                'msgNeedle' => 'logged in',
                'emailSubjectLang' => 'email_max_success_logins_subject'
            ],
        ];

        return isset($stringRepo[$context][$key]) ? $stringRepo[$context][$key] : '';
    }
}
