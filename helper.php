<?php

/**
 * Class helper_plugin_loglog
 */
class helper_plugin_loglog extends \dokuwiki\Extension\Plugin
{
    /**
     * @param array $msg
     * @param string $user
     */
    public function writeLine($msg, $user = null)
    {
        global $conf, $INPUT;

        if (is_null($user)) $user = $INPUT->server->str('REMOTE_USER');
        if (!$user) $user = $_REQUEST['u'];
        if (!$user) return;

        $t = time();
        $ip = clientIP(true);

        $line = join("\t", [$t, strftime($conf['dformat'], $t), $ip, $user, join("\t", $msg)]);

        io_saveFile($conf['cachedir'] . '/loglog.log', "$line\n", true);
    }
}
