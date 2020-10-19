<?php

/**
 * Class helper_plugin_loglog
 */
class helper_plugin_loglog extends \dokuwiki\Extension\Plugin
{
    /**
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
}
