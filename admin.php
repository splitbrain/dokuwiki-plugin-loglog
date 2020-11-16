<?php

class admin_plugin_loglog extends DokuWiki_Admin_Plugin
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
     * @var string
     */
    protected $filter = '';

    /** @inheritDoc */
    public function forAdminOnly()
    {
        return false;
    }

    /** @inheritDoc */
    public function getMenuSort()
    {
        return 141;
    }

    public function __construct()
    {
        $this->logHelper = $this->loadHelper('loglog_logging');
        $this->mainHelper = $this->loadHelper('loglog_main');

        global $INPUT;
        $this->filter = $INPUT->str('filter');
    }


    /** @inheritDoc */
    public function html()
    {
        global $ID, $INPUT, $conf, $lang;
        $now = time();
        $go = isset($_REQUEST['time']) ? intval($_REQUEST['time']) : $now;
        $min = $go - (7 * 24 * 60 * 60);
        $max = $go;

        $past = $now - $go > 60 * 60 * 5;
        if ($past) {
            $next = $max + (7 * 24 * 60 * 60);
            if ($now - $next < 60 * 60 * 5) {
                $next = $now;
            }
        }

        $time = $INPUT->str('time') ?: $now;

        // alternative date format?
        $dateFormat = $this->getConf('admin_date_format') ?: $conf['dformat'];

        echo $this->locale_xhtml('intro');

        $form = new dokuwiki\Form\Form(['method'=>'GET']);
        $form->setHiddenField('do', 'admin');
        $form->setHiddenField('page', 'loglog');
        $form->setHiddenField('time', $time);
        $form->addDropdown(
            'filter',
            [
                '' => '',
                'auth_ok' => $this->getLang('filter_auth_ok'),
                'auth_error' => $this->getLang('filter_auth_error'),
                'admin' => $this->getLang('filter_admin'),
                'other' => $this->getLang('filter_other')
            ]
        );
        $form->addButton('submit', $this->getLang('submit'))->attr('type','submit');
        echo $form->toHTML();

        echo '<p>' . $this->getLang('range') . ' ' . strftime($dateFormat, $min) .
            ' - ' . strftime($dateFormat, $max) . '</p>';

        echo '<table class="inline loglog">';
        echo '<tr>';
        echo '<th>' . $this->getLang('date') . '</th>';
        echo '<th>' . $this->getLang('ip') . '</th>';
        echo '<th>' . $lang['user'] . '</th>';
        echo '<th>' . $this->getLang('action') . '</th>';
        echo '<th>'. $this->getLang('data') . '</th>';
        echo '</tr>';

        $lines = $this->logHelper->readLines($min, $max);
        $lines = array_reverse($lines);

        foreach ($lines as $line) {
            if (!$line['user']) continue;

            $logType = $this->mainHelper->getLogTypeFromMsg($line['msg']);

            if ($this->filter && $this->filter !== '' && $this->filter!== $logType) {
                continue;
            }

            if ($line['msg'] == 'logged off') {
                $line['msg'] = $this->getLang('off');
                $class = 'off';
            } elseif ($line['msg'] == 'logged in permanently') {
                $line['msg'] = $this->getLang('in');
                $class = 'perm';
            } elseif ($line['msg'] == 'logged in temporarily') {
                $line['msg'] = $this->getLang('tin');
                $class = 'temp';
            } elseif ($line['msg'] == 'failed login attempt') {
                $line['msg'] = $this->getLang('fail');
                $class = 'fail';
            } elseif ($line['msg'] == 'has been automatically logged off') {
                $line['msg'] = $this->getLang('autologoff');
                $class = 'off';
            } else {
                $line['msg'] = hsc($line['msg']);
                if (strpos($line['msg'], 'logged off') !== false) {
                    $class = 'off';
                } elseif (strpos($line['msg'], 'logged in permanently') !== false) {
                    $class = 'perm';
                } elseif (strpos($line['msg'], 'logged in') !== false) {
                    $class = 'temp';
                } elseif (strpos($line['msg'], 'failed') !== false) {
                    $class = 'fail';
                } else {
                    $class = 'unknown';
                }
            }

            echo '<tr>';
            echo '<td>' . strftime($dateFormat, $line['dt']) . '</td>';
            echo '<td>' . hsc($line['ip']) . '</td>';
            echo '<td>' . hsc($line['user']) . '</td>';
            echo '<td><span class="loglog_' . $class . '">' . $line['msg'] . '</span></td>';
            echo '<td>';
            if ($line['data']) {
                // logs contain single-line JSON data, so we have to decode and encode it again for pretty print
                echo '<pre>' . json_encode(json_decode($line['data']), JSON_PRETTY_PRINT) . '</pre>';
            }
            echo '</td>';
            echo '</tr>';
        }

        echo '</table>';

        echo '<div class="pagenav">';
        if ($past) {
            echo '<div class="pagenav-prev">';
            echo html_btn('newer',
                $ID,
                "p",
                ['do' => 'admin', 'page' => 'loglog', 'time' => $next, 'filter' => $this->filter]
            );
            echo '</div>';
        }

        echo '<div class="pagenav-next">';
        echo html_btn('older',
            $ID,
            "n",
            ['do' => 'admin', 'page' => 'loglog', 'time' => $min, 'filter' => $this->filter]
        );
        echo '</div>';
        echo '</div>';

    }
}
