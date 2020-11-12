<?php

/**
 * Class helper_plugin_loglog_main
 */
class helper_plugin_loglog_main extends DokuWiki_Plugin
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
     * Sends emails
     *
     * @param string $email
     * @param string $subject
     * @param string $text
     * @return bool
     */
    public function sendEmail($email, $subject, $text, $textrep = [])
    {
        $html = p_render('xhtml', p_get_instructions($text), $info);

        $mail = new Mailer();
        $mail->to($email);
        $mail->subject($subject);
        $mail->setBody($text, $textrep, null, $html);
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
