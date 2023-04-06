<?php

use GuzzleHttp\Client;
use SingerPhp\Singer;
use SingerPhp\SingerTap;

class SQLServerTap extends SingerTap
{
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * SQL Server Connection handler
     *
     * @var object
     */
    private $db = null;

    /**
     * SQL Server Credentials
     */
    private $host = '';
    private $database = '';
    private $port = 1433;
    private $user = '';
    private $password = '';
    private $schema = '';

    /**
     * Current table being processed
     *
     * @var string
     */
    private $table = '';

    /**
     * Column types
     * 
     * Note: Binary types not supported as of right now
     */
    private $types = [
        'bit'            => Singer::TYPE_BOOLEAN,
        'integer'        => Singer::TYPE_INTEGER,
        'null'           => Singer::TYPE_STRING,
        'object'         => Singer::TYPE_OBJECT,
        'timestamp'      => Singer::TYPE_TIMESTAMP,
        'datetime'       => Singer::TYPE_TIMESTAMP,
        'datetime2'      => Singer::TYPE_TIMESTAMP,
        'smalldatetime'  => Singer::TYPE_TIMESTAMP,
        'datetimeoffset' => Singer::TYPE_TIMESTAMPTZ,
        'float'          => Singer::TYPE_REALFLOAT,
        'real'           => Singer::TYPE_REAL,
        'tinyint'        => Singer::TYPE_TINYINT,
        'money'          => Singer::TYPE_MONEY,
        'smallmoney'     => Singer::TYPE_MONEY,
        'int'            => Singer::TYPE_INTEGER,
        'bigint'         => Singer::TYPE_INTEGER,
        'smallint'       => Singer::TYPE_INTEGER,
    ];

    /**
     * tests if the connector is working then writes the results to STDOUT
     */
    public function test()
    {
        try {
            $this->setAccessVars('input');
            $this->connect();
            $this->singer->writeMeta(['test_result' => true]);
        } catch (Exception $e) {
            $this->singer->writeMeta(['test_result' => false]);
        }
    }

    /**
     * gets all schemas/tables and writes the results to STDOUT
     */
    public function discover()
    {
        $this->singer->logger->debug('Starting discover for tap SQL Server');

        $this->setAccessVars('setting');
        $this->connect();

        foreach ($this->singer->config->catalog->streams as $stream) {
            $this->table = $stream->stream;

            $this->singer->logger->debug("Writing schema for {$this->table}");

            $this->singer->writeSchema(
                stream: $stream->stream,
                schema: $this->getTableColumns(),
                key_properties: $this->getTableIndexes()
            );
        }
    }

    /**
     * gets the record data and writes to STDOUT
     */
    public function tap()
    {
        $this->singer->logger->debug('Starting sync for tap SQL Server');

        $this->setAccessVars('setting');
        $this->connect();

        $last_started = date(DateTime::ATOM);

        foreach ($this->singer->config->catalog->streams as $stream) {
            $this->table = $stream->stream;
            $table_settings = $this->singer->config->setting('table_settings')[$this->table];

            $this->singer->logger->debug("Writing schema for {$this->table}");

            $this->singer->writeSchema(
                stream: $this->table,
                schema: $this->getTableColumns(),
                key_properties: $this->getTableIndexes()
            );

            $this->singer->logger->debug("Starting sync for {$this->table}");

            $full_replace = $table_settings['full_replace'] ?? FALSE == "true";

            if ($full_replace) {
                $id_column = array_keys($this->getTableColumns())[0];
                $where = "";
                $order_by = "";
            } else {
                $start = $this->singer->config->state->bookmarks->{$this->table}->last_started;
                $sort_column = $table_settings['sort_column'];
                $id_column = $table_settings['id_column'];

                $this->singer->writeMeta([ 'delete_keys' => [$id_column] ]);

                if ($start == "0001-01-01 00:00:00") {
                    $start = '1900-01-01 00:00:00'; // SQL Server flips out with 0001-01-01
                } else {
                    $day_offset = $table_settings['day_offset'];

                    $start = new DateTime($start);
                    $start = $start->sub(new DateInterval('PT3M'));

                    if (! empty($day_offset)) {
                        $day_offset = 'P' . $day_offset . 'D';
                        $start->sub(new DateInterval($day_offset));
                    }

                    $start = $start->format("Y-m-d H:i:s");
                }

                $where = "WHERE $sort_column > '$start'";
                $order_by = "ORDER BY $id_column";
            }

            $sql = <<<SQL
                SELECT
                    row_number() over(ORDER BY ?) as __bytespree_row_number,
                    t.*
                FROM $this->schema.$this->table as t
                $where
                $order_by
                SQL;

            $cursor = $this->db->prepare($sql, array(PDO::ATTR_CURSOR => PDO::CURSOR_SCROLL));
            $cursor->execute([$id_column, $id_column]);

            $records_this_run = $this->getTotalRecordCounts($cursor);

            if (empty($records_this_run)) {
                $this->singer->logger->debug("No records found this run for {$this->table}");
                $this->singer->writeMetric('counter', 'record_count', 0, [ 'table' => $this->table ]);
                return;
            }

            $total_records = 0;

            // Reset the cursor to the first record and insert it
            $record = $cursor->fetch(PDO::FETCH_ASSOC, PDO::FETCH_ORI_FIRST);

            unset($record['__bytespree_row_number']);
            $this->singer->writeRecord(
                stream: $this->table,
                record: (array) $record
            );
            $total_records++;

            while ($record = $cursor->fetch(PDO::FETCH_ASSOC, PDO::FETCH_ORI_NEXT)) {
                unset($record['__bytespree_row_number']);

                $this->singer->writeRecord(
                    stream: $this->table,
                    record: (array) $record
                );

                $total_records++;
            }

            if ($total_records < $records_this_run) {
                throw new Exception("Failed to get all records.");
            }

            $this->singer->logger->debug("Finished sync for {$this->table}");

            if (! $full_replace) {
                $this->singer->logger->debug("Writing state for {$this->table}");

                $this->singer->writeState([
                    $this->table => [
                        'last_started' => $last_started,
                    ]
                ]);
            }

            $this->singer->writeMetric(
                'counter',
                'record_count',
                $total_records,
                [
                    'table' => $this->table
                ]
            );
        }
    }

    /**
     * writes a metadata response with the tables to STDOUT
     */
    public function getTables()
    {
        $this->setAccessVars('input');
        $this->connect();

        $sql = <<<SQL
            SELECT table_name FROM (
                SELECT table_name FROM information_schema.tables WHERE table_schema = ? UNION SELECT table_name FROM information_schema.views WHERE table_schema = ?
            ) AS results ORDER BY table_name
            SQL;

        $cursor = $this->db->prepare($sql);
        $cursor->execute([$this->schema, $this->schema]);

        $tables = [];
        if ( $cursor ) {
            $results = $cursor->fetchAll(PDO::FETCH_ASSOC);
            foreach ($results as $result)
            {
                $tables[] = $result["table_name"];
            }
        }

        $this->singer->writeMeta(compact('tables'));
    }

    /**
     * Get Connection Credentials from singer config
     */
    public function setAccessVars(string $type)
    {
        $this->host     = $this->singer->config->$type('sqlserver_host');
        $this->database = $this->singer->config->$type('sqlserver_database');
        $this->user     = $this->singer->config->$type('sqlserver_user');
        $this->password = $this->singer->config->$type('sqlserver_password');
        $this->port     = $this->singer->config->$type('sqlserver_port');
        $this->schema   = $this->singer->config->$type('sqlserver_schema') ?? 'dbo';

        /**
         * To-do: Fix setting values are saved with double quotes. ex: host "147.182.131.211". expected: 147.182.131.211
         */
        $this->host     = str_replace('"', "", $this->host);
        $this->database = str_replace('"', "", $this->database);
        $this->user     = str_replace('"', "", $this->user);
        $this->password = str_replace('"', "", $this->password);
        $this->port     = str_replace('"', "", $this->port);
        $this->schema   = str_replace('"', "", $this->schema);
    }

    /**
     * Connects to the SQLServer instance
     */
    public function connect()
    {
        $conn_string = "sqlsrv:Server=$this->host,$this->port;Database=$this->database;";

        try {
            $this->db = new PDO($conn_string, $this->user, $this->password);
        } catch (Exception $e) {
            throw new Exception("Failed to connect to the sql server. {$e->getMessage()}");
        }
    }

    /**
     * Get Table Columns
     */
    public function getTableColumns()
    {
        $sql = <<<SQL
            SELECT column_name, data_type
            FROM information_schema.columns 
            WHERE table_name = ? AND table_schema = ?
            ORDER BY ordinal_position
            SQL;

        $cursor = $this->db->prepare($sql);
        $cursor->execute([$this->table, $this->schema]);

        $columns = [];
        while ($column = $cursor->fetch(PDO::FETCH_ASSOC)) {
            if (str_contains($column['column_name'], 'dmi_')) {
                continue;
            }

            $type = $this->types[$column["data_type"]] ?? Singer::TYPE_STRING;
            $columns[$column['column_name']] = [ "type" => $type ];
        }

        return $columns;
    }

    /**
     * Get Table Indexes
     */
    public function getTableIndexes()
    {
        $sql = <<<SQL
            SELECT 
                COL_NAME(b.object_id,b.column_id) AS column_name
            FROM
                sys.indexes as a
            INNER JOIN
                sys.index_columns as b ON a.object_id = b.object_id AND a.index_id = b.index_id
            WHERE
                a.object_id = OBJECT_ID('?.?')
            SQL;

        $cursor = $this->db->prepare($sql);
        $cursor->execute([$this->schema, $this->table]);

        $indexes = [];
        while ($index = $cursor->fetchColumn()) {
            $indexes[] = $index;
        }

        return $indexes;
    }

    /**
     * Get Total Record Count
     */
    public function getTotalRecordCounts($cursor)
    {
        $record = $cursor->fetch(PDO::FETCH_ASSOC, PDO::FETCH_ORI_LAST);

        if (empty($record)) {
            return 0;
        }

        return $record["__bytespree_row_number"];
    }

    /**
     * Options - Get Table Columns
     */
    public function optionsGetTableColumns()
    {
        $this->table = $this->singer->config->input('table');

        $this->setAccessVars('input');
        $this->connect();

        $columns = $this->getTableColumns();

        $options = [];
        foreach ($columns as $column_name => $column_type) {
            $options[$column_name] = $column_name;
        }

        $this->singer->writeMeta(compact('options'));
    }
}
