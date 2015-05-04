<?php
/**
 * Login/Logout logging plugin
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Andreas Gohr <gohr@cosmocode.de>
 */

// must be run within Dokuwiki
if(!defined('DOKU_INC')) die();

if(!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN',DOKU_INC.'lib/plugins/');
require_once(DOKU_PLUGIN.'action.php');

class action_plugin_loglog extends DokuWiki_Action_Plugin {

    var $islogin = false;

    /**
     * register the eventhandlers
     */
    function register(&$controller){
        $controller->register_hook('ACTION_ACT_PREPROCESS',
                                   'BEFORE',
                                   $this,
                                   'handle_before',
                                   array());
        $controller->register_hook('ACTION_AUTH_AUTOLOGOUT','BEFORE',$this,'handle_autologout',array());
    }

    function _log($msg){
        global $conf;

        $user = $_SERVER['REMOTE_USER'];
        if(!$user) $user = $_REQUEST['u'];

        $t   = time();
        $log = $t."\t".strftime($conf['dformat'],$t)."\t".$_SERVER['REMOTE_ADDR']."\t".$user."\t".$msg;
        io_saveFile($conf['cachedir'].'/loglog.log',"$log\n",true);
    }

    /**
     * @param Doku_Event $event
     * @param mixed      $param   data passed to the event handler
     */
    function handle_autologout (&$event, $param){
        $this->_log('has been automatically logged off');
    }


    /**
     * catch logouts
     */
    public function handle_before(Doku_Event $event, $param) {
        $act = act_clean($event->data);
        if($act == 'logout') {
            $this->_log('logged off');
        }elseif($_SERVER['REMOTE_USER'] && $act=='login'){
            if($_REQUEST['r']){
                $this->_log('logged in permanently');
            }else{
                $this->_log('logged in temporarily');
            }
        }elseif($_REQUEST['u'] && !$_REQUEST['http_credentials'] && !$_SERVER['REMOTE_USER']){
            $this->_log('failed login attempt');
        }
    }
}

