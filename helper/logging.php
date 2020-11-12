<?php

/**
 * Class helper_plugin_loglog_logging
 */
class helper_plugin_loglog_logging extends DokuWiki_Plugin
{
    protected $file = '';

    public function __construct()
    {
        global $conf;
        if(defined('DOKU_UNITTEST')) {
            $this->file = DOKU_PLUGIN . 'loglog/_test/loglog.log';
        } else {
            $this->file = $conf['cachedir'] . '/loglog.log';
        }
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
     * Return logfile lines limited to specified $min - $max range
     *
     * @param int $min
     * @param int $max
     * @return array
     */
    public function readLines($min, $max)
    {
        $lines = [];
        $candidateLines = $this->readChunks($min, $max);
        foreach ($candidateLines as $line) {
            if (empty($line)) continue; // Filter empty lines
            $parsedLine = $this->loglineToArray($line);
            if ($parsedLine['dt'] >= $min && $parsedLine['dt'] <= $max) {
                $lines[] = $parsedLine;
            }
        }
        return $lines;
    }

    /**
     * Read log lines backwards. Start and end timestamps are used to evaluate
     * only the chunks being read, NOT single lines. This method will return
     * too many lines, the dates have to be checked by the caller again.
     *
     * @param int $min start time (in seconds)
     * @param int $max end time (in seconds)
     * @return array
     */
    protected function readChunks($min, $max)
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
     * Convert log line to array
     *
     * @param string $line
     * @return array
     */
    protected function loglineToArray($line)
    {
        list($dt, $junk, $ip, $user, $msg, $data) = explode("\t", $line, 6);
        return [
            'dt' => $dt, // timestamp
            'ip' => $ip,
            'user' => $user,
            'msg' => $msg,
            'data' => $data, // JSON encoded additional data
        ];
    }

    /**
     * Returns the number of lines where the given needle has been found in message
     *
     * @param array $lines
     * @param string $msgNeedle
     * @return mixed
     */
    public function countMatchingLines(array $lines, string $msgNeedle)
    {
        return array_reduce(
            $lines,
            function ($carry, $line) use ($msgNeedle) {
                $carry = $carry + (int)(strpos($line['msg'], $msgNeedle) !== false);
                return $carry;
            },
            0
        );
    }

}
