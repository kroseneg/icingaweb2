<?php

namespace Icinga\Module\Monitoring\Clicommands;

use Icinga\Module\Monitoring\Backend;
use Icinga\Module\Monitoring\Cli\CliUtils;
use Icinga\Util\Format;
use Icinga\Cli\Command;
use Icinga\File\Csv;

/**
 * List and filter monitored objects
 *
 * This command allows you to search and visualize your monitored objects in
 * different ways.
 *
 * USAGE
 *
 * icingaweb monitoring list [<type>] [options]
 *
 * OPTIONS
 *
 *   --verbose  Show detailled output
 *   --showsql  Dump generated SQL query (DB backend only)
 *
 *   --format <csv|json|<custom>>
 *     Dump columns in the given format. <custom> format allows $column$
 *     placeholders, e.g. --format '$host$: $service$'
 *
 *   --<column> [filter]
 *     Filter given column by optional filter. Boolean (1/0) columns are true
 *     if no filter value is given.
 *
 * EXAMPLES
 *
 * icingaweb monitoring list --unhandled
 * icingaweb monitoring list --host local* --service *disk*
 * icingaweb monitoring list --format '$host_name$: $service_description$'
 */
class ListCommand extends Command
{
    protected $backend;
    protected $dumpSql;
    protected $defaultActionName = 'status';

    public function init()
    {
        $this->backend = Backend::createBackend($this->params->shift('backend'));
        $this->dumpSql = $this->params->shift('showsql');
    }

    protected function getQuery($table, $columns)
    {
        $limit = $this->params->shift('limit');
        $format = $this->params->shift('format');
        if ($format !== null) {
            if ($this->params->has('columns')) {
                $columnParams = preg_split(
                    '/,/',
                    $this->params->shift('columns')
                );
                $columns = array();
                foreach ($columnParams as $col) {
                    if (false !== ($pos = strpos($col, '='))) {
                        $columns[substr($col, 0, $pos)] = substr($col, $pos + 1);
                    } else {
                        $columns[] = $col;
                    }
                }
            }
        }

        $query = $this->backend->select()->from($table, $columns);
        if ($limit) {
            $query->limit($limit, $this->params->shift('offset'));
        }
        foreach ($this->params->getParams() as $col => $filter) {
            $query->where($col, $filter);
        }
        // $query->applyFilters($this->params->getParams());
        if ($this->dumpSql) {
            echo wordwrap($query->dump(), 72);
            exit;
        }

        if ($format !== null) {
            $this->showFormatted($query, $format, $columns);
        }

        return $query;
    }

    protected function showFormatted($query, $format, $columns)
    {
        switch($format) {
            case 'json':
                echo json_encode($query->fetchAll());
                break;
            case 'csv':
                Csv::fromQuery($query)->dump();
                break;
            default:
                preg_match_all('~\$([a-z0-9_-]+)\$~', $format, $m);
                $words = array();
                foreach ($columns as $key => $col) {
                    if (is_numeric($key)) {
                        if (in_array($col, $m[1])) {
                            $words[] = $col;
                        }
                    } else {
                        if (in_array($key, $m[1])) {
                            $words[] = $key;
                        }
                    }
                }
                foreach ($query->fetchAll() as $row) {
                    $output = $format;
                    foreach ($words as $word) {
                        $output = preg_replace(
                            '~\$' . $word . '\$~',
                            $row->{$word},
                            $output
                        );
                    }
                    echo $output . "\n";
                }
        }
        exit;
    }

    public function statusAction()
    {
        $columns = array(
            'host_name',
            'host_state',
            'host_output',
            'host_handled',
            'host_acknowledged',
            'host_in_downtime',
            'service_description',
            'service_state',
            'service_acknowledged',
            'service_in_downtime',
            'service_handled',
            'service_output',
            'service_last_state_change'
        );
        $query = $this->getQuery('status', $columns)
            ->order('host_name');
        echo $this->renderQuery($query);
    }
    
    protected function renderQuery($query)
    {
        $out = '';
        $last_host = null;
        $screen = $this->screen;
        $utils = new CliUtils($screen);
        $maxCols = $screen->getColumns();
        $rows = $query->fetchAll();
        $count = $query->count();
        $count = count($rows);

        for ($i = 0; $i < $count; $i++) {
            $row = & $rows[$i];

            $utils->setHostState($row->host_state);
            if (! array_key_exists($i + 1, $rows)
              || $row->host_name !== $rows[$i + 1]->host_name
            ) {
                $lastService = true;
            } else {
                $lastService = false;
            }

            $hostUnhandled = ! ($row->host_state == 0 || $row->host_handled);

            if ($row->host_name !== $last_host) {
                if (isset($row->service_description)) {
                    $out .= "\n";
                }

                $hostTxt = $utils->shortHostState();
                if ($hostUnhandled) {
                    $out .= $utils->hostStateBackground(
                        sprintf('   %s ', $utils->shortHostState())
                    );
                } else {
                    $out .= sprintf(
                        '%s  %s ',
                        $utils->hostStateBackground(' '),
                        $utils->shortHostState()
                    );
                }
                $out .= sprintf(
                    " %s%s: %s\n",
                    $screen->underline($row->host_name),
                    $screen->colorize($utils->objectStateFlags('host', $row), 'lightblue'),
                    $row->host_output
                );

                if (isset($row->services_ok)) {
                    $out .= sprintf(
                        "%d services, %d problems (%d unhandled), %d OK\n",
                        $row->services_cnt,
                        $row->services_problem,
                        $row->services_problem_unhandled,
                        $row->services_ok
                    );
                }
            }
            
            $last_host = $row->host_name;
            if (! isset($row->service_description)) {
                continue;
            }

            $utils->setServiceState($row->service_state);
            $serviceUnhandled = ! (
                $row->service_state == 0 || $row->service_handled
            );

            if ($lastService) {
                $straight = ' ';
                $leaf     = '└';
            } else {
                $straight = '│';
                $leaf     = '├';
            }
            $out .= $utils->hostStateBackground(' ');

            if ($serviceUnhandled) {
                $out .= $utils->serviceStateBackground(
                    sprintf('  %s ', $utils->shortServiceState())
                );
                $emptyBg = '       ';
                $emptySpace = '';
            } else {
                $out .= sprintf(
                    '%s %s ',
                    $utils->serviceStateBackground(' '),
                    $utils->shortServiceState()
                );
                $emptyBg = ' ';
                $emptySpace = '      ';
            }

            $emptyLine = "\n"
                       . $utils->hostStateBackground(' ')
                       . $utils->serviceStateBackground($emptyBg)
                       . $emptySpace
                       . ' ' . $straight . '  ';

            $wrappedOutput = wordwrap(
                    preg_replace('~\@{3,}~', '@@@', $row->service_output),
                    $maxCols - 13
                ) . "\n";
            $out .= sprintf(
                " %1s─ %s%s (since %s)",
                $leaf,
                $screen->underline($row->service_description),
                $screen->colorize($utils->objectStateFlags('service', $row), 'lightblue'),
                Format::timeSince($row->service_last_state_change)
            );
            if ($this->isVerbose) {
                $out .= $emptyLine . preg_replace(
                    '/\n/',
                    $emptyLine,
                    $wrappedOutput
                ) . "\n";
            } else {
                $out .= "\n";
            }
        }

        $out .= "\n";
        return $out;
    }
}

