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
     * catch logouts
     */
    function handle_before(&$event, $param){
        $act = $this->_act_clean($event->data);
        if($act == 'logout'){
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



    /**
     * Pre-Sanitize the action command
     *
     * Similar to act_clean in action.php but simplified and without
     * error messages
     */
    function _act_clean($act){
         // check if the action was given as array key
         if(is_array($act)){
           list($act) = array_keys($act);
         }

         //remove all bad chars
         $act = strtolower($act);
         $act = preg_replace('/[^a-z_]+/','',$act);

         return $act;
     }
}

