<?php

namespace dokuwiki\plugin\loglog\test;

/**
 * Tests for the loglog plugin
 *
 * @group plugin_loglog
 * @group plugins
 *
 */
class Logging_loglog_test extends \DokuWikiTest
{
    /**
     * @var \helper_plugin_loglog_logging
     */
    protected $logHelper;

    public function setUp()
    {
        $this->pluginsEnabled[] = 'loglog';
        parent::setUp();

        $this->logHelper = plugin_load('helper', 'loglog_logging');
    }

    public function test_readLinesInRange()
    {
        $min = strtotime('2020-10-01');
        $max = strtotime('2020-10-31');

        $expected = 4;
        $actual = count($this->logHelper->readLines($min, $max));

        $this->assertEquals($expected, $actual);
    }

    public function test_reportStats()
    {
        $min = strtotime('2020-11-01');
        $max = strtotime('2020-11-30');
        $lines = $this->logHelper->readLines($min, $max);

        /** @var \helper_plugin_loglog_report $reportHelper */
        $reportHelper = plugin_load('helper', 'loglog_report');
        $actual = $reportHelper->getStats($lines);

        $expected = [
            \helper_plugin_loglog_main::LOGTYPE_AUTH_OK => 1,
            \helper_plugin_loglog_main::LOGTYPE_AUTH_FAIL => 2,
            'users' => 1,
            'admin' => [
                'start' => 1,
                'usermanager' => 1,
            ]
        ];

        $this->assertEquals($expected, $actual);
    }
}
