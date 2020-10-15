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
     * @var helper_plugin_loglog
     */
    protected $helper;

    public function __construct()
    {
        $this->helper = $this->loadHelper('loglog');
    }

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

        // log other admin actions: acl
        $controller->register_hook(
            'AJAX_CALL_UNKNOWN',
            'AFTER',
            $this,
            'handleAjax'
        );

        // log other admin actions: config
        $controller->register_hook(
            'DOKUWIKI_STARTED',
            'AFTER',
            $this,
            'handleConfig'
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
        $this->helper->writeLine([$msg], $user);
    }

    /**
     * Log access to admin tools
     *
     * @param array $data
     */
    protected function logAdmin($data)
    {
        // FIXME AJAX etc.
        global $INPUT;
        $page = $INPUT->str('page');
        array_unshift($data, $page);
        $this->helper->writeLine($data);
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

    /**
     * Log access to admin pages
     *
     * @param Doku_Event $event
     */
    public function handleAdminAccess(Doku_Event $event)
    {
        global $ACT;
        if ($ACT === 'admin') {
            $this->logAdmin(['access']);
        }
    }

    /**
     * Log actions in user manager
     *
     * @param Doku_Event $event
     */
    public function handleUsermod(Doku_Event $event)
    {
        $modType = $event->data['type'];
        $modUser = $event->data['params'][0];
        if (is_array($modUser)) $modUser = implode(', ', $modUser);

        $this->logAdmin([$modType . ' user', $modUser]);
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
            $this->logAdmin([$INPUT->str('act') . ' ' . $INPUT->str('ext')]);
        }
    }

    /**
     * @param \Doku_Event $event
     */
    public function handleConfig(\Doku_Event $event)
    {
        global $INPUT;

        // configuration manager
        if ($INPUT->str('page') === 'config'
            && $INPUT->bool('save') === true
            && !empty($INPUT->arr('config'))
        ) {
            // TODO can we get a diff?
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

            $this->logAdmin([
                $cmd, serialize($rule)
            ]);
        }
    }
}

