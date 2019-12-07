<?php

/***********************************************************************************************************
*	
*	@author MPZinke
*	created on 08.14.19 
*	CC BY-NC-AS UTA FabLab 2016-2019
*	FabApp V 0.93.1
*		-Data Reports update
*
*	DESCRIPTION: AJAX POST call page to execute asyncronous JSON responses.  Used
*	 by 
*	FUTURE:	Have Database_Query handle Pie Chart data
*	BUGS: 
*
***********************************************************************************************************/


class Database_Table {
	public $table;  // STRING: SQL name of table
	public $name;  // STRING: colloqial of table name
	public $columns = array();  // ARRAY: columns for selected table
	public $column_names = array();  // columns generated by readable_headers(.)
	public $column_types = array();  // ASSOC ARRAY: column => column TYPE

	public function __construct($table_name) {
		$table_name = htmlspecialchars($table_name);
		$this->table = $table_name;
		$this->name = self::format_words(self::convert_header_to_words($table_name));

		// columns
		global $mysqli;
		if($result = $mysqli->query(	"SELECT `COLUMN_NAME` as 'column', `DATA_TYPE` as 'type'
										FROM `INFORMATION_SCHEMA`.`COLUMNS` 
										WHERE `TABLE_NAME`='$table_name';
		")) {
			while($row = $result->fetch_assoc()) {
				$this->columns[] = $row['column'];
				$this->column_types[$row['column']] = $row['type'];
			}

			foreach($this->columns as $column) {
				$words = self::convert_header_to_words($column);
				$tableless_words = self::remove_table_name_from_header_words_if_exists($words, $table_name);
				$this->column_names[$column] = self::format_words($tableless_words);
			}
		}
	}


	//
	public static function format_words($word_array) {
		$formatted_words = array();
		foreach($word_array as $word)
			$formatted_words[] = ucfirst($word);

		return implode($formatted_words, ' ');
	}


	// get list of all tables based on table_descriptions table in DB
	public static function get_tables() {
		global $mysqli;

		if($result = $mysqli->query("SHOW TABLES"))
		{
			$tables = array();
			while($row = $result->fetch_array(MYSQLI_NUM))
				$tables[] = new self($row[0]);
			return $tables;
		}
		return null;
	}


	public static function convert_header_to_words($header) {
		$header_words = array();
		// split words by snakecase and then split them by camelCase (if possible)
		if(strpos($header, '_')) {
			foreach(explode('_', $header) as $part)
				foreach(self::split_camel_case_and_ignore_consecutive_upper_case_chars($part) as $word)
					$header_words[] = $word;
		}
		else
			foreach(self::split_camel_case_and_ignore_consecutive_upper_case_chars($header) as $word)
				$header_words[] = $word;

		return $header_words;
	}


	public static function remove_table_name_from_header_words_if_exists($header_words, $parent) {
		// if first word is initials of table or first word is part of table name: remove list
		$parent_initials = self::table_names_as_intials(array($parent));
		$table_initials = self::table_names_as_intials();
		foreach($table_initials as $table => $initial)
			if(strpos(strtolower($table), strtolower($header_words[0])) !== false && 2 < strlen($header_words[0]) 
			|| strtolower($header_words[0]) ==strtolower($initial)) {
				$header_words[0] = self::format_words(self::convert_header_to_words($table));
				break;
			}

		return $header_words;
	}


	// parse camel case to individual words; strings of uppercase are sepparated at last upper char
	public static function split_camel_case_and_ignore_consecutive_upper_case_chars($word) {
		$words = array();

		while($word) {
			$end = 1;
			// while (the index is in bounds and (the current index is a continuing uppercase 
			// or current index is lower) or right before end
			while($end+1 < strlen($word) 
			&& ((ctype_upper($word[1]) && ctype_upper($word[$end+1])) || ctype_lower($word[$end]))
			|| $end+1 == strlen($word))
				$end++;

			$words[] = substr($word, 0, $end);
			$word = substr($word, $end);
		}
		return $words;
	}


	public static function table_names_as_intials($tables=null) {
		global $mysqli;

		if(!$tables) {
			if(!$result = $mysqli->query("SHOW TABLES"))
				return null;  // nothing to see here; move along

			$tables = array();
			while($row = $result->fetch_array(MYSQLI_NUM))
				$tables[] = $row[0];
		}
		$table_initials = array();
		foreach($tables as $table) {
			$words = self::convert_header_to_words($table);
			$table_initials[$table] = "";
			foreach(self::convert_header_to_words($table) as $word)
				$table_initials[$table] .= substr($word, 0, 1);
		}
		return $table_initials;
	}
}


class Database_Query {
	public $columns = array();  // columns returned from query
	public $names = array();  // columns generated by readable_headers(.)
	public $HTML_table;  // STRING: HTML rows for table of results
	public $results = array();  // fetch_assoc() response of query
	public $statement;  // STRING: MySQL code
	public $tsv;  // STRING
	// problem checking
	public $error;  // STRING: error with query/statement; stops object creation
	public $warning;  // STRING: issue with data set by user

	public function __construct($statement, $limit_results_to_500=true, $prohibit_potentially_harmful_words=true) {
		global $mysqli;

		// query
		$this->statement = $statement;
		// filter for harmful words
		if($prohibit_potentially_harmful_words && $prohibition = self::query_contains_prohibited_words($query))
			$this->set_error("'$prohibition' is a prohibited term as it may be used maliciously");
		if(!$results = $mysqli->query($statement))
			$this->set_error("Error in query: $results->error");
		$this->results = self::query_results($results);

		// create dict of more readable names for query
		foreach($this->columns as $column) {
			$words = Database_Table::convert_header_to_words($this->columns);
			$tableless_words = Database_Table::remove_table_name_from_header_words_if_exists($words);
			$this->names[$column] = Database_Table::format_words($tableless_words);
		}

		// limit results
		if($limit_results_to_500 && 500 < count($this->results)) {
			$this->warning = 	"The number of results for your query is greater than 500.  To prevent this ".
								"webpage from freezing, you will only receive 500 results.  To see all data, ".
								"you might want to break query up into different parts by conditions";
			$this->results = array_slice($this->results, 0, 500);
		}

		$this->HTML_table = self::create_HTML_table_rows($this->results);
		$this->tsv = self::tsv($this->results);
	}


	// for each row from query, create row for HTML table
	public static function create_HTML_table_rows($data) {
		// table head
		$HTML = 	"<table id='result_table' class='table col-md-12'>
						<thead>
							<tr>";
		$headers = array_keys($data[0]);
		foreach($headers as $head)
			$HTML .= "<th>$head</th>";
		$HTML .= "</tr>
				</thead>";

		// table data
		foreach($data as $row) {
			$HTML .= "<tr>";
			foreach($headers as $header) {
				$HTML .= 	"<td>
								$row[$header]
							</td>";
			}
			$HTML .= "</tr>";
		}
		return $HTML."</table>";
	}


	// return query statement, headers and filename based on request, start, end & device
	public static function prebuilt_query($end, $function, $start, $device_id) {
		global $status;

		$device_condition = $device_id != "*" ? "AND `d_id` = '$device_id'" : "";

		// number of tickets for a period divided into hours of day (~24 results)
		if($function === "byHour") {
			$file_name = "FabLab_TicketsByHour";
			$head = array("Hour", "Count");
			$statement = "SELECT HOUR(`t_start`) as Hour, COUNT(*) as Count
		  					FROM `transactions`
		  					WHERE '$start' <= `t_start`
		  					AND `t_start` < '$end'
		  					$device_condition
		  					GROUP BY HOUR(`t_start`);";
		}
		// number of tickets for a period divided into each day of the week
		elseif($function === "byDay") {
			$file_name = "FabLab_TicketByDay";
			$head = array("Day", "Count");
			$statement = "SELECT DAYNAME(`t_start`) as Day, COUNT(*) as Count
							FROM `transactions`
							WHERE '$start' <= `t_start` 
		  					AND `t_start` < '$end'
		  					$device_condition
							GROUP BY WEEKDAY(`t_start`);";				
		}
		// number of tickets for a period divided into the hours of each day (~168 results)
		elseif($function === "byHourDay") {
			$head = array("Day", "Hour", "Count");
			$file_name = "FabLab_TicketsByHourForEachDay";
			$statement = "SELECT DAYNAME(`t_start`) as Day, HOUR(`t_start`) as Hour, COUNT(*) as Count
		  					FROM `transactions`
		  					WHERE '$start' <= `t_start` 
		  					AND `t_start` < '$end'
		  					$device_condition
		  					GROUP BY HOUR(`t_start`), WEEKDAY(`t_start`)
		  					ORDER BY WEEKDAY(`t_start`), HOUR(`t_start`);";
		}
		// number of tickets divided into 
		elseif($function === "byStation") {
			$file_name = "FabLab_TicketsByStation";
			$head = array("Name", "Count");
			$statement = "SELECT `device_group`.`dg_desc` as Name, COUNT(*) as Count
							FROM `transactions`
							JOIN `devices` ON `transactions`.`d_id` = `devices`.`d_id`
							JOIN `device_group` ON `devices`.`dg_id` = `device_group`.`dg_id`
							WHERE '$start' <= `transactions`.`t_start`
							AND `transactions`.`t_start` < '$end'
		  					$device_condition
							GROUP BY `device_group`.`dg_desc`;";
		}
		// number of tickets divided into different accounts
		elseif($function === "byAccount") {
			$file_name = "FabLab_TicketsByAccount";
			$head = array("Name", "Count");
			$statement = "SELECT `accounts`.`name` as Name, COUNT(*) as Count
							FROM `transactions`
							JOIN `acct_charge` ON `transactions`.`trans_id` = `acct_charge`.`trans_id`
							JOIN `accounts` ON `acct_charge`.`a_id` = `accounts`.`a_id`
							WHERE '$start' <= `transactions`.`t_start`
							AND `transactions`.`t_start` < '$end'
		  					$device_condition
							GROUP BY `accounts`.`name`;";
		}
		elseif($function === "failedTickets") {
			$file_name = "FabLab_FailedTickets";
			$head = array("Count");
			$statement = "SELECT COUNT(*) as Count
							FROM `transactions`
							WHERE `status_id` = $status[total_fail]
							AND '$start' <= `t_start` 
		  					AND `t_start` < '$end'
		  					$device_condition;";
		}
		// account charges charged to IDT account
		elseif($function === "IDTs") {
			$file_name = "FabLab_IDTs";
			$head = array("Charge", "Account", "Transaction", "Date", "User", "Staff", "Amount", "Notes");
			$statement = "SELECT `ac_id` as Charge, `a_id` as Account, `trans_id` as Transaction,
							`ac_date` as Date, `operator` as User, `staff_id` as Staff,
							`amount` as Amount, `ac_notes` as Notes
							FROM `acct_charge`
							WHERE `a_id` = 5
							AND '$start' <= `ac_date` 
		  					AND `ac_date` <= '$end'
		  					$device_condition;";
		}
		// tickets by individual tool for ALL devices in the lab
		elseif($function === "by_device_all") {
			$file_name = "FabLab_Device_Complete";
			$head = array("Device", "Count");
			$statement = 	"SELECT `devices`.`device_desc` AS Device, COUNT(*) AS Count
							FROM `transactions`
							JOIN `devices` ON `transactions`.`d_id` = `devices`.`d_id`
							WHERE '$start' <=`transactions`.`t_start`
							AND `transactions`.`t_end` < '$end'
		  					$device_condition
							GROUP BY `devices`.`device_desc`
							ORDER BY `devices`.`device_desc`;";
		}
		// Tickets by individual tool for ONLY non-shop-room devices
		elseif($function === "by_device_floor") {
			$file_name = "FabLab_Device_Floor";
			$head = array("Device", "Count");
			$statement = 	"SELECT `devices`.`device_desc` AS Device, COUNT(*) AS Count
							FROM `transactions`
							JOIN `devices` ON `transactions`.`d_id` = `devices`.`d_id`
							WHERE '$start' <=`transactions`.`t_start`
							AND `transactions`.`t_end` < '$end'
							AND `devices`.`dg_id` != '3'
		  					$device_condition
							GROUP BY `devices`.`device_desc`
							ORDER BY `devices`.`device_desc`;";
		}
		// Tickets by individual tool for ONLY shop room devices
		elseif($function === "by_device_shop") {
			$file_name = "FabLab_Device_Shop";
			$head = array("Device", "Count");
			$statement = 	"SELECT `devices`.`device_desc` AS Device, COUNT(*) AS Count
							FROM `transactions`
							JOIN `devices` ON `transactions`.`d_id` = `devices`.`d_id`
							WHERE '$start' <=`transactions`.`t_start`
							AND `transactions`.`t_end` < '$end'
							AND `devices`.`dg_id` = '3'
		  					$device_condition
							GROUP BY `devices`.`device_desc`
							ORDER BY `devices`.`device_desc`;";
		}

		return array("file_name" => $file_name, "head" => $head, "statement" => $statement, "error" => $function);
	}


	// get data as a dictionary for pie chart usage; $data_column is the data points used in 
	// calculating sum, values & percentages. $label_column is the column of the query that
	// contains label/word/value for query
	public function pie_chart_data($data_column, $label_column) {
		$DEFINED_NUMBER_OF_COLORS = 24;  // set this to match front end availability of colors
		if($DEFINED_NUMBER_OF_COLORS < count($this->results)) return null;
		elseif(!$data_column || !$label_column) return null;

		$sum = 0;
		foreach($this->results as $row)
			$sum += $row[$data_column];

		$percentages = $values = array();
		foreach($this->results as $row) {
			$percentages[$row[$label_column]] = $row[$data_column] / $sum;
			$values[$row[$label_column]] = $row[$data_column];
		}

		return array("percentages" => $percentages, "sum" => $sum, "values" => $values);
	}


	// compiled list of MySQL key words that have been deemed inappropriate in submitted queries
	public static function query_contains_prohibited_words($statement) {
		$prohibited_words = array(	" ACCESSIBLE ", " ACTION ", " ADD ", " AFTER ", " AGAINST ", " AGGREGATE ", " ALGORITHM ", " ALTER ", " ANALYZE ", " ASCII ", 
										" ASENSITIVE ", " AT ", " AUTHORS ", " AUTOEXTEND_SIZE ", " AUTO_INCREMENT ", " AVG_ROW_LENGTH ", " BACKUP ", " BEFORE ", " BEGIN ", " BETWEEN ", 
										" BIGINT ", " BINARY ", " BINLOG ", " BIT ", " BLOB ", " BLOCK ", " BOOL ", " BOOLEAN ", " BTREE ", " BYTE ", 
										" CACHE ", " CALL ", " CASCADE ", " CASCADED ", " CATALOG_NAME ", " CHAIN ", " CHANGE ", " CHANGED ", " CHAR ", " CHARACTER ", 
										" CHARSET ", " CHECK ", " CHECKSUM ", " CIPHER ", " CLASS_ORIGIN ", " CLIENT ", " CLOSE ", " COALESCE ", " CODE ", " COLLATE ", 
										" COLLATION ", " COLUMN ", " COLUMNS ", " COLUMN_NAME ", " COMMENT ", " COMMIT ", " COMMITTED ", " COMPACT ", " COMPLETION ", " COMPRESSED ", 
										" CONCURRENT ", " CONDITION ", " CONNECTION ", " CONSISTENT ", " CONSTRAINT ", " CONSTRAINT_CATALOG ", " CONSTRAINT_NAME ", " CONSTRAINT_SCHEMA ", " CONTEXT ", " CONTINUE ", 
										" CONTRIBUTORS ", " CONVERT ", " CPU ", " CREATE ", " CROSS ", " CUBE ", " CURRENT_DATE ", " CURRENT_TIME ", " CURRENT_TIMESTAMP ", " CURRENT_USER ", 
										" CURSOR ", " CURSOR_NAME ", " DATA ", " DATABASE ", " DATABASES ", " DATAFILE ", " DATE ", " DATETIME ", " DAY_HOUR ", " DAY_MICROSECOND ", 
										" DAY_MINUTE ", " DAY_SECOND ", " DEALLOCATE ", " DEC ", " DECIMAL ", " DECLARE ", " DEFAULT ", " DEFINER ", " DELAYED ", " DELAY_KEY_WRITE ", 
										" DELETE ", " DESCRIBE ", " DES_KEY_FILE ", " DETERMINISTIC ", " DIRECTORY ", " DISABLE ", " DISCARD ", " DISK ", " DISTINCT ", " DISTINCTROW ", 
										" DIV ", " DO ", " DOUBLE ", " DROP ", " DUAL ", " DUMPFILE ", " DUPLICATE ", " DYNAMIC ", " EACH ", " ELSE ", 
										" ELSEIF ", " ENABLE ", " ENCLOSED ", " END ", " ENDS ", " ENGINE ", " ENGINES ", " ENUM ", " ERROR ", " ERRORS ", 
										" ESCAPE ", " ESCAPED ", " EVENT ", " EVENTS ", " EVERY ", " EXECUTE ", " EXISTS ", " EXIT ", " EXPANSION ", " EXPLAIN ", 
										" EXTENDED ", " EXTENT_SIZE ", " FALSE ", " FAST ", " FAULTS ", " FETCH ", " FIELDS ", " FILE ", " FIRST ", " FIXED ", 
										" FLOAT ", " FLOAT4 ", " FLOAT8 ", " FLUSH ", " FOR ", " FORCE ", " FOREIGN ", " FOUND ", " FRAC_SECOND ", " FULL ", 
										" FULLTEXT ", " FUNCTION ", " GENERAL ", " GEOMETRY ", " GEOMETRYCOLLECTION ", " GET_FORMAT ", " GLOBAL ", " GRANT ", " GRANTS ", " HANDLER ", 
										" HASH ", " HAVING ", " HELP ", " HIGH_PRIORITY ", " HOST ", " HOSTS ", " HOUR_MICROSECOND ", " HOUR_MINUTE ", " HOUR_SECOND ", " IDENTIFIED ", 
										" IF ", " IGNORE ", " IGNORE_SERVER_IDS ", " IMPORT ", " IN ", " INDEX ", " INDEXES ", " INFILE ", " INITIAL_SIZE ", " INNER ", 
										" INNOBASE ", " INNODB ", " INOUT ", " INSENSITIVE ", " INSERT ", " INSERT_METHOD ", " INSTALL ", " INT ", " INT1 ", " INT2 ", 
										" INT3 ", " INT4 ", " INT8 ", " INTEGER ", " INTERVAL ", " INTO ", " INVOKER ", " IO ", " IO_THREAD ", " IPC ", 
										" IS ", " ISOLATION ", " ISSUER ", " ITERATE ", " KEY ", " KEYS ", " KEY_BLOCK_SIZE ", " KILL ", " LANGUAGE ", " LAST ", 
										" LEADING ", " LEAVE ", " LEAVES ", " LESS ", " LEVEL ", " LIKE ", " LINEAR ", " LINES ", " LINESTRING ", " LIST ", 
										" LOAD ", " LOCAL ", " LOCALTIME ", " LOCALTIMESTAMP ", " LOCK ", " LOCKS ", " LOGFILE ", " LOGS ", " LONG ", " LONGBLOB ", 
										" LONGTEXT ", " LOOP ", " LOW_PRIORITY ", " MASTER ", " MASTER_CONNECT_RETRY ", " MASTER_HEARTBEAT_PERIOD ", " MASTER_HOST ", " MASTER_LOG_FILE ", " MASTER_LOG_POS ", " MASTER_PASSWORD ", 
										" MASTER_PORT ", " MASTER_SERVER_ID ", " MASTER_SSL ", " MASTER_SSL_CA ", " MASTER_SSL_CAPATH ", " MASTER_SSL_CERT ", " MASTER_SSL_CIPHER ", " MASTER_SSL_KEY ", " MASTER_SSL_VERIFY_SERVER_CERT ", " MASTER_USER ", 
										" MATCH ", " MAXVALUE ", " MAX_CONNECTIONS_PER_HOUR ", " MAX_QUERIES_PER_HOUR ", " MAX_ROWS ", " MAX_SIZE ", " MAX_UPDATES_PER_HOUR ", " MAX_USER_CONNECTIONS ", " MEDIUM ", " MEDIUMBLOB ", 
										" MEDIUMINT ", " MEDIUMTEXT ", " MEMORY ", " MERGE ", " MESSAGE_TEXT ", " MICROSECOND ", " MIDDLEINT ", " MIGRATE ", " MINUTE ", " MINUTE_MICROSECOND ", 
										" MINUTE_SECOND ", " MIN_ROWS ", " MOD ", " MODE ", " MODIFIES ", " MODIFY ", " MONTH ", " MULTILINESTRING ", " MULTIPOINT ", " MULTIPOLYGON ", 
										" MUTEX ", " MYSQL_ERRNO ", " NAME ", " NAMES ", " NATIONAL ", " NATURAL ", " NCHAR ", " NDB ", " NDBCLUSTER ", " NEW ", 
										" NEXT ", " NO ", " NODEGROUP ", " NONE ", " NO_WAIT ", " NO_WRITE_TO_BINLOG ", " NULL ", " NUMERIC ", " NVARCHAR ", " OFFSET ", 
										" OLD_PASSWORD ", " ONE ", " ONE_SHOT ", " OPEN ", " OPTIMIZE ", " OPTION ", " OPTIONALLY ", " OPTIONS ", " ORDER ", " OUT ", 
										" OUTER ", " OUTFILE ", " OWNER ", " PACK_KEYS ", " PAGE ", " PARSER ", " PARTIAL ", " PARTITION ", " PARTITIONING ", " PARTITIONS ", 
										" PASSWORD ", " PHASE ", " PLUGIN ", " PLUGINS ", " POINT ", " POLYGON ", " PORT ", " PRECISION ", " PREPARE ", " PRESERVE ", 
										" PREV ", " PRIMARY ", " PRIVILEGES ", " PROCEDURE ", " PROCESSLIST ", " PROFILE ", " PROFILES ", " PROXY ", " PURGE ", " QUARTER ", 
										" QUERY ", " QUICK ", " RANGE ", " READ ", " READS ", " READ_ONLY ", " READ_WRITE ", " REAL ", " REBUILD ", " RECOVER ", 
										" REDOFILE ", " REDO_BUFFER_SIZE ", " REDUNDANT ", " REFERENCES ", " REGEXP ", " RELAY; added in 5.5.3 (nonreserved) ", " RELAYLOG ", " RELAY_LOG_FILE ", " RELAY_LOG_POS ", " RELAY_THREAD ", 
										" RELEASE ", " RELOAD ", " REMOVE ", " RENAME ", " REORGANIZE ", " REPAIR ", " REPEAT ", " REPEATABLE ", " REPLACE ", " REPLICATION ", 
										" REQUIRE ", " RESET ", " RESIGNAL ", " RESTORE ", " RESTRICT ", " RESUME ", " RETURN ", " RETURNS ", " REVOKE ", " RIGHT ", 
										" RLIKE ", " ROLLBACK ", " ROLLUP ", " ROUTINE ", " ROW ", " ROWS ", " ROW_FORMAT ", " RTREE ", " SAVEPOINT ", " SCHEDULE ", 
										" SCHEMA ", " SCHEMAS ", " SCHEMA_NAME ", " SECOND ", " SECOND_MICROSECOND ", " SECURITY ", " SENSITIVE ", " SEPARATOR ", " SERIAL ", 
										" SERIALIZABLE ", " SERVER ", " SESSION ", " SET ", " SHARE ", " SHOW ", " SHUTDOWN ", " SIGNAL ", " SIGNED ", " SIMPLE ", 
										" SLAVE ", " SLOW; added in 5.5.3 (reserved) ", " SMALLINT ", " SNAPSHOT ", " SOCKET ", " SOME ", " SONAME ", " SOUNDS ", " SOURCE ", " SPATIAL ", 
										" SPECIFIC ", " SQL ", " SQLEXCEPTION ", " SQLSTATE ", " SQLWARNING ", " SQL_BIG_RESULT ", " SQL_BUFFER_RESULT ", " SQL_CACHE ", " SQL_CALC_FOUND_ROWS ", " SQL_NO_CACHE ", 
										" SQL_SMALL_RESULT ", " SQL_THREAD ", " SQL_TSI_DAY ", " SQL_TSI_FRAC_SECOND ", " SQL_TSI_HOUR ", " SQL_TSI_MINUTE ", " SQL_TSI_MONTH ", " SQL_TSI_QUARTER ", " SQL_TSI_SECOND ", " SQL_TSI_WEEK ", 
										" SQL_TSI_YEAR ", " SSL ", " START ", " STARTING ", " STARTS ", " STATUS ", " STOP ", " STORAGE ", " STRAIGHT_JOIN ", " STRING ", 
										" SUBCLASS_ORIGIN ", " SUBJECT ", " SUBPARTITION ", " SUBPARTITIONS ", " SUPER ", " SUSPEND ", " SWAPS ", " SWITCHES ", " TABLE ", " TABLES ", 
										" TABLESPACE ", " TABLE_CHECKSUM ", " TABLE_NAME ", " TEMPORARY ", " TEMPTABLE ", " TERMINATED ", " TEXT ", " THAN ", " THEN ", " TIME ", 
										" TIMESTAMP ", " TIMESTAMPADD ", " TIMESTAMPDIFF ", " TINYBLOB ", " TINYINT ", " TINYTEXT ", " TO ", " TRAILING ", " TRANSACTION ", " TRIGGER ", 
										" TRIGGERS ", " TRUE ", " TRUNCATE ", " TYPE ", " TYPES ", " UNCOMMITTED ", " UNDEFINED ", " UNDO ", " UNDOFILE ", " UNDO_BUFFER_SIZE ", 
										" UNICODE ", " UNINSTALL ", " UNION ", " UNIQUE ", " UNKNOWN ", " UNLOCK ", " UNSIGNED ", " UNTIL ", " UPDATE ", " UPGRADE ", 
										" USAGE ", " USE ", " USER ", " USER_RESOURCES ", " USE_FRM ", " USING ", " UTC_DATE ", " UTC_TIME ", " UTC_TIMESTAMP ", " VALUE ", 
										" VALUES ", " VARBINARY ", " VARCHAR ", " VARCHARACTER ", " VARIABLES ", " VARYING ", " VIEW ", " WAIT ", " WARNINGS ", " WHEN ", 
										" WHILE ", " WITH ", " WORK ", " WRAPPER ", " WRITE ", " X509 ", " XA ", " XML ", " XOR ", " YEAR_MONTH ", 
										" ZEROFILL ");

		foreach($prohibited_words as $word)
			if(strpos(strtolower($statement), strtolower($word)) !== false && self::word_is_not_in_column_name($statement, $word))
				return $word;	
		return null;
	}


	// return an array of the results of a query
	public static function query_results($results) {
		$values = array();
		while($row = $results->fetch_assoc())
			$values[] = $row;
		return $values;
	}


	// return tsv string to print into excel sheet
	public static function tsv($data) {
		$tsv = implode("\t", array_keys($data[0]));

		foreach($data as $row)
			$tsv .= "\n".implode("\t", $row);

		return $tsv;
	}


	private function set_error($error_message) {
		$this->error = $error_message;
		exit();
	}


	public static function word_is_not_in_column_name($statement, $word) {
		$grave_accent_count = 0;
		for($x = 0; $x < strpos($statement, $word); $x++)
			if(substr($statement, $x, $x+1) == "`") $grave_accent_count++;
		return $grave_accent_count % 2 == 0;
	}
}


?>