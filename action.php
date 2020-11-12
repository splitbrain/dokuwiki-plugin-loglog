<?php
/**
 * Login/Logout logging plugin
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Andreas Gohr <gohr@cosmocode.de>
 */

class action_plugin_loglog extends DokuWiki_Action_Plugin
{
    /**
     * @var \helper_plugin_loglog_logging
     */
    protected $logHelper;

    /**
     * @var \helper_plugin_loglog_main
     */
    protected $mainHelper;

    /**
     * @var \helper_plugin_loglog_alert
     */
    protected $alertHelper;

    public function __construct()
    {
        $this->mainHelper = $this->loadHelper('loglog_main');
        $this->logHelper = $this->loadHelper('loglog_logging');
        $this->alertHelper = $this->loadHelper('loglog_alert');
    }

    /** @inheritDoc */
    function register(Doku_Event_Handler $controller)
    {
        // tasks to perform on login/logoff
        $controller->register_hook(
            'ACTION_ACT_PREPROCESS',
            'BEFORE',
            $this,
            'handleAuth'
        );

        // allow other plugins to emit logging events
        $controller->register_hook(
            'PLUGIN_LOGLOG_LOG',
            'BEFORE',
            $this,
            'handleCustom'
        );

        // autologout plugin
        $controller->register_hook(
            'ACTION_AUTH_AUTOLOGOUT',
            'BEFORE',
            $this,
            'handleAutologout'
        );

        // log admin access
        $controller->register_hook(
            'ACTION_ACT_PREPROCESS',
            'BEFORE',
            $this,
            'handleAdminAccess'
        );

        // log user modifications
        $controller->register_hook(
            'AUTH_USER_CHANGE',
            'BEFORE',
            $this,
            'handleUsermod'
        );

        // log admin actions triggered via Ajax
        $controller->register_hook(
            'AJAX_CALL_UNKNOWN',
            'AFTER',
            $this,
            'handleAjax'
        );

        // log other admin actions
        $controller->register_hook(
            'DOKUWIKI_STARTED',
            'AFTER',
            $this,
            'handleOther'
        );

        // log other admin actions
        $controller->register_hook(
            'INDEXER_TASKS_RUN',
            'AFTER',
            $this,
            'handleReport'
        );
    }

    /**
     * Log login/logoff actions and optionally trigger alerts
     * if configured thresholds have just been exceeded
     *
     * @param $msg
     * @param null|string $user
     */
    protected function logAuth($msg, $user = null)
    {
        $this->logHelper->writeLine($msg, $user);

        // trigger alert notifications if necessary
        $this->alertHelper->checkAlertThresholds();
    }

    /**
     * Log usage of admin tools
     *
     * @param array $data
     * @param string $more
     */
    protected function logAdmin(array $data = [], $more = '')
    {
        global $INPUT;
        $msg = 'admin';
        $page = $INPUT->str('page');
        if ($page) $msg .= " - $page";
        if ($more && $more !== $page) $msg .= " - $more";
        $this->logHelper->writeLine($msg,null, $data);
    }

    /**
     * Handle custom logging events
     *
     * @param Doku_Event $event
     * @param mixed $param data passed to the event handler
     */
    public function handleCustom(Doku_Event $event, $param)
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

        $this->logHelper->writeLine($log, $user);
    }

    /**
     * Handle autologoffs by the autologout plugin
     *
     * @param Doku_Event $event
     * @param mixed $param data passed to the event handler
     */
    public function handleAutologout(Doku_Event $event, $param)
    {
        $this->logAuth('has been automatically logged off');
    }

    /**
     * catch standard logins/logouts, check if any alert notifications should be sent
     *
     * @param Doku_Event $event
     * @param mixed $param data passed to the event handler
     */
    public function handleAuth(Doku_Event $event, $param)
    {
        // log authentication events
        $act = act_clean($event->data);
        if ($act == 'logout') {
            $this->logAuth('logged off');
        } elseif (!empty($_SERVER['REMOTE_USER']) && $act == 'login') {
            if (isset($_REQUEST['r'])) {
                $this->logAuth('logged in permanently');
            } else {
                $this->logAuth('logged in temporarily');
            }
        } elseif ($_REQUEST['u'] && empty($_REQUEST['http_credentials']) && empty($_SERVER['REMOTE_USER'])) {
            $this->logAuth('failed login attempt');
        }
    }

    /**
     * Log access to admin pages
     *
     * @param Doku_Event $event
     */
    public function handleAdminAccess(Doku_Event $event)
    {
        global $ACT;
        if ($ACT === 'admin') {
            $this->logAdmin();
        }
    }

    /**
     * Log user modifications
     *
     * @param Doku_Event $event
     */
    public function handleUsermod(Doku_Event $event)
    {
        $modType = $event->data['type'];
        $modUser = $event->data['params'][0];
        if (is_array($modUser)) $modUser = implode(', ', $modUser);

        // check if admin or user are modifying the data
        global $ACT;
        if ($ACT === 'profile') {
            $this->logHelper->writeLine('user profile',null, [$modType . ' user', $modUser]);
        } else {
            $this->logAdmin([$modType . ' user', $modUser]);
        }
    }

    /**
     * Catch admin actions performed via Ajax
     *
     * @param Doku_Event $event
     */
    public function handleAjax(Doku_Event $event)
    {
        global $INPUT;

        // extension manager
        if ($event->data === 'plugin_extension') {
            $this->logAdmin([$INPUT->str('act') . ' ' . $INPUT->str('ext')], 'extension');
        }
    }

    /**
     * Log activity in select core admin modules
     *
     * @param \Doku_Event $event
     */
    public function handleOther(\Doku_Event $event)
    {
        global $INPUT;

        // configuration manager
        if ($INPUT->str('page') === 'config'
            && $INPUT->bool('save') === true
            && !empty($INPUT->arr('config'))
        ) {
            $this->logAdmin(['save config']);
        }

        // extension manager
        if ($INPUT->str('page') === 'extension') {
            if ($INPUT->post->has('fn')) {
                $actions = $INPUT->post->arr('fn');
                foreach ($actions as $action => $extensions) {
                    foreach ($extensions as $extname => $label) {
                        $this->logAdmin([$action, $extname]);
                    }
                }
            } elseif ($INPUT->post->str('installurl')) {
                $this->logAdmin(['installurl', $INPUT->post->str('installurl')]);
            } elseif (isset($_FILES['installfile'])) {
                $this->logAdmin(['installfile', $_FILES['installfile']['name']]);
            }
        }

        // ACL manager
        if ($INPUT->str('page') === 'acl' && $INPUT->has('cmd')) {
            $cmd = $INPUT->extract('cmd')->str('cmd');
            $del = $INPUT->arr('del');
            if ($cmd === 'update' && !empty($del)) {
                $cmd = 'delete';
                $rule = $del;
            } else {
                $rule = [
                    'ns' => $INPUT->str('ns'),
                    'acl_t' => $INPUT->str('acl_t'),
                    'acl_w' => $INPUT->str('acl_w'),
                    'acl' => $INPUT->str('acl')
                ];
            }

            $this->logAdmin([$cmd, $rule]);
        }
    }

    /**
     * Handle monthly usage reports
     *
     * @param Doku_Event $event
     */
    public function handleReport(Doku_Event $event)
    {
        $reportHelper = new helper_plugin_loglog_report();
        $reportHelper->handleReport();
    }
}

