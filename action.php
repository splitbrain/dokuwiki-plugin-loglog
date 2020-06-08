<?php
/**
 * Login/Logout logging plugin
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Andreas Gohr <gohr@cosmocode.de>
 */

class action_plugin_loglog extends DokuWiki_Action_Plugin
{

    /** @inheritDoc */
    function register(Doku_Event_Handler $controller)
    {
        $controller->register_hook(
            'ACTION_ACT_PREPROCESS',
            'BEFORE',
            $this,
            'handle_before',
            array()
        );

        // allow other plugins to emit logging events
        $controller->register_hook(
            'PLUGIN_LOGLOG_LOG',
            'BEFORE',
            $this,
            'handle_custom',
            array()
        );

        // autologout plugin
        $controller->register_hook(
            'ACTION_AUTH_AUTOLOGOUT',
            'BEFORE',
            $this,
            'handle_autologout',
            array()
        );
    }

    /**
     * Log an action
     *
     * @param $msg
     * @param null|string $user
     */
    protected function logAccess($msg, $user = null)
    {
        global $conf;
        global $INPUT;

        if (is_null($user)) $user = $INPUT->server->str('REMOTE_USER');
        if (!$user) $user = $_REQUEST['u'];
        if (!$user) return;

        $t = time();
        $ip = clientIP(true);
        $log = $t . "\t" . strftime($conf['dformat'], $t) . "\t" . $ip . "\t" . $user . "\t" . $msg;
        io_saveFile($conf['cachedir'] . '/loglog.log', "$log\n", true);
    }

    /**
     * Handle custom logging events
     *
     * @param Doku_Event $event
     * @param mixed $param data passed to the event handler
     */
    public function handle_custom(Doku_Event $event, $param)
    {
        if (isset($event->data['message'])) {
            $log = $event->data['message'];
        } else {
            return;
        }
        if (isset($event->data['user'])) {
            $user = $event->data['user'];
        } else {
            $user = null;
        }

        $this->logAccess($log, $user);
    }

    /**
     * Handle autologoffs by the autologout plugin
     *
     * @param Doku_Event $event
     * @param mixed $param data passed to the event handler
     */
    public function handle_autologout(Doku_Event $event, $param)
    {
        $this->logAccess('has been automatically logged off');
    }

    /**
     * catch standard logins/logouts
     *
     * @param Doku_Event $event
     * @param mixed $param data passed to the event handler
     */
    public function handle_before(Doku_Event $event, $param)
    {
        $act = act_clean($event->data);
        if ($act == 'logout') {
            $this->logAccess('logged off');
        } elseif (!empty($_SERVER['REMOTE_USER']) && $act == 'login') {
            if (isset($_REQUEST['r'])) {
                $this->logAccess('logged in permanently');
            } else {
                $this->logAccess('logged in temporarily');
            }
        } elseif ($_REQUEST['u'] && empty($_REQUEST['http_credentials']) && empty($_SERVER['REMOTE_USER'])) {
            $this->logAccess('failed login attempt');
        }
    }
}

