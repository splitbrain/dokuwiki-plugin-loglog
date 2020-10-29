<?php

/**
 * Class helper_plugin_loglog_logging
 */
class helper_plugin_loglog_logging extends \dokuwiki\Extension\Plugin
{
    protected $file = '';

    public function __construct()
    {
        global $conf;
        $this->file = $conf['cachedir'] . '/loglog.log';
    }

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
        $data = !empty($data) ? json_encode($data) : '';

        $line = join("\t", [$t, strftime($conf['dformat'], $t), $ip, $user, $msg, $data]);

        io_saveFile($this->file, "$line\n", true);
    }

    /**
     * Read log lines backwards. Start and end timestamps are used to evaluate
     * only the chunks being read, NOT single lines. This method will return
     * too many lines, the dates have to be checked be the caller again.
     *
     * @param int $min start time (in seconds)
     * @param int $max end time (in seconds)
     * @return array
     */
    public function readLines($min, $max)
    {
        $data = array();
        $lines = array();
        $chunk_size = 8192;

        if (!@file_exists($this->file)) return $data;
        $fp = fopen($this->file, 'rb');
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

            // put all the lines from the chunk on the stack
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
     * @param int $min
     * @param int $max
     * @return mixed
     */
    public function countMatchingLines(array $lines, string $msgNeedle, int $min, int $max)
    {
        return array_reduce(
            $lines,
            function ($carry, $line) use ($msgNeedle, $min, $max) {
                list($dt, $junk, $ip, $user, $msg, $data) = explode("\t", $line, 6);
                if ($dt >= $min && $dt <= $max) {
                    $carry = $carry + (int)(strpos($line, $msgNeedle) !== false);
                }
                return $carry;
            },
            0
        );
    }

}
