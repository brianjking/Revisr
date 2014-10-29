<?php
/**
 * class-revisr-db.php
 *
 * Performs database backup and restore operations.
 *
 * @package   Revisr
 * @license   GPLv3
 * @link      https://revisr.io
 * @copyright 2014 Expanded Fronts, LLC
 */

// Disallow direct access.
if ( ! defined( 'ABSPATH' ) ) exit;

class Revisr_DB {

	/**
	 * The connection to use for the commands passed to MySQL.
	 * @var string
	 */
	protected $conn;

	/**
	 * Stores the current working directory.
	 * @var string
	 */
	protected $current_dir;

	/**
	 * Stores the upload directory.
	 * @var string
	 */
	protected $upload_dir;

	/**
	 * Stores user options and preferences.
	 * @var array
	 */
	protected $options;

	/**
	 * Stores the path to MySQL.
	 * @var string
	 */
	protected $path;

	/**
	 * The main Git class.
	 * @var Revisr_Git
	 */
	protected $git;

	/**
	 * The WordPress database class.
	 * @var WPDB
	 */
	private $wpdb;

	/**
	 * Initiate the class.
	 * @access public
	 * @param  string $path Optional, overrides the saved setting (for testing).
	 */
	public function __construct( $path = '' ) {
		global $wpdb;
		$this->wpdb 		= $wpdb;
		$this->git 			= new Revisr_Git();
		$this->current_dir 	= getcwd();
		$this->upload_dir 	= wp_upload_dir();
		$this->options 		= Revisr::get_options();

		$get_path = $this->git->config_revisr_path( 'mysql' );
		if ( is_array( $get_path ) ) {
			$this->path = $get_path[0];
		} else {
			$this->path = '';
		}

		$this->setup_env();
	}

	/**
	 * Close any pending connections and switch back to the previous directory.
	 * @access public
	 */
	public function __destruct() {
		$this->wpdb->flush();
		chdir( $this->current_dir );
	}

	/**
	 * Builds the connection string to use with MySQL.
	 * @access public
	 * @param  string $table Optionally pass the table to use.
	 * @return string
	 */
	public function build_conn( $table = '' ) {
		if ( $this->check_port( DB_HOST ) != false ) {
			$port 		= $this->check_port( DB_HOST );
			$add_port 	= " --port=$port";
			$temp 		= strlen($port) * -1 - 1;
			$db_host 	= substr( DB_HOST, 0, $temp );
		} else {
			$add_port 	= '';
			$db_host 	= DB_HOST;
		}

		if ( $table != '' ) {
			$table = " $table";
		}
		// Workaround for Windows/Mac compatibility.
		if ( DB_PASSWORD != '' ) {
			$conn = "-u '" . DB_USER . "' -p'" . DB_PASSWORD . "' " . DB_NAME . $table . " --host " . $db_host . $add_port;
		} else {
			$conn = "-u '" . DB_USER . "' " . DB_NAME . $table . " --host " . $db_host . $add_port;
		}
		return $conn;
	}

	/**
	 * Creates the backup folder and adds the .htaccess if necessary.
	 * @access private
	 */
	public function setup_env() {
		// Create the backups directory if it doesn't exist.
		$backup_dir = $this->upload_dir['basedir'] . '/revisr-backups/';
		if ( is_dir( $backup_dir ) ) {
			chdir( $backup_dir );
		} else {
			mkdir( $backup_dir );
			chdir( $backup_dir );
		}

		// Prevent '.sql' files from public access.
		if ( ! file_exists( '.htaccess' ) ) {
			$htaccess_content = '<FilesMatch "\.sql">' .
			PHP_EOL . 'Order allow,deny' .
			PHP_EOL . 'Deny from all' .
			PHP_EOL . 'Satisfy All' .
			PHP_EOL . '</FilesMatch>';
			file_put_contents( '.htaccess', $htaccess_content );
		}

		// Prevent directory listing.
		if ( ! file_exists( 'index.php' ) ) {
			$index_content = '<?php // Silence is golden' . PHP_EOL;
			file_put_contents( 'index.php', $index_content );
		}
	}

	/**
	 * Returns an array of tables in the database.
	 * @access public
	 * @return array
	 */
	public function get_tables() {
		$tables = $this->wpdb->get_col( 'SHOW TABLES' );
		return $tables;
	}

	/**
	 * Returns the array of tables that are to be tracked.
	 * @access public
	 * @return array
	 */
	public function get_tracked_tables() {
		if ( isset( $this->options['tracked_tables'] ) && is_array( $this->options['tracked_tables'] ) ) {
			$tracked_tables = array_intersect( $this->options['tracked_tables'], $this->get_tables() );
			return $tracked_tables;
		}
		return array();
	}

	/**
	 * Runs through a provided array of tables to perform an action.
	 * @access public
	 * @param  string $action The action to perform.
	 * @param  array  $tables The tables to act on.
	 * @param  string $args   Optional additional arguements to pass to the action.
	 * @return boolean
	 */
	public function run( $action, $tables = array(), $args = '' ) {
		// Initialize the response array.
		$status = array();

		// Iterate through the tables and perform the action.
		foreach ( $tables as $table ) {
			switch ( $action ) {
				case 'backup':
					$status[$table] = $this->backup_table( $table );
					break;
				case 'revert':
					$status[$table] = $this->revert_table( $table, $args );
					break;
				case 'import':
					$status[$table] = $this->import_table( $table, $args );
					break;
				default:
					return false;
			}
		}

		// Process the results and alert the user.
		$callback = $action . '_callback';
		$this->$callback( $status );
	}

	/**
	 * Adds a table to version control.
	 * @access private
	 * @param  string $table The table to add.
	 */
	private function add_table( $table ) {
		$this->git->run( "add {$this->upload_dir['basedir']}/revisr-backups/revisr_$table.sql" );
	}

	/**
	 * Callback for the "Backup Database" AJAX button.
	 * @access public
	 */
	public function backup() {
		// Get the tables to backup.
		$tables = $this->get_tracked_tables();
		if ( empty( $tables ) ) {
			$tables = $this->get_tables();
		}

		// Run the backup.
		$this->run( 'backup', $tables );

		// Commit any changed database files and insert a post if necessary.
		if ( isset( $_REQUEST['source'] ) && $_REQUEST['source'] == 'ajax_button' ) {
			$this->commit_db( true );
		} else {
			$this->commit_db();
		}
	}

	/**
	 * Backs up a database table.
	 * @access private
	 * @param  string $table The table to backup.
	 */
	private function backup_table( $table ) {
		$conn = $this->build_conn( $table );
		exec( "{$this->path}mysqldump $conn > revisr_$table.sql --skip-comments" );
		$this->add_table( $table );
		return $this->verify_backup( $table );
	}

	/**
	 * Callback for the backup action.
	 * @access private
	 * @param  array $status The status of the backup.
	 */
	private function backup_callback( $status ) {
		if ( in_array( false, $status ) ) {
			$msg = __( 'Error backing up the database.', 'revisr' );
			Revisr_Admin::log( $msg, 'error' );
			Revisr_Admin::alert( $msg, true );
		} else {
			$msg = __( 'Successfully backed up the database.', 'revisr' );
			Revisr_Admin::log( $msg, 'backup' );
			Revisr_Admin::alert( $msg );
		}
	}

	/**
	 * Commits the database to the repository and pushes if needed.
	 * @access public
	 * @param  boolean $insert_post Whether to insert a new commit custom_post_type.
	 */
	public function commit_db( $insert_post = false ) {
		$commit_msg  = __( 'Backed up the database with Revisr.', 'revisr' );
		$this->git->commit( $commit_msg );
		// Insert the corresponding post if necessary.
		if ( $insert_post === true ) {
			$post = array(
				'post_title' 	=> $commit_msg,
				'post_content' 	=> '',
				'post_type' 	=> 'revisr_commits',
				'post_status' 	=> 'publish',
			);
			$post_id 		= wp_insert_post( $post );
			$commit_hash 	= $this->git->current_commit();
			add_post_meta( $post_id, 'commit_hash', $commit_hash );
			add_post_meta( $post_id, 'db_hash', $commit_hash );
			add_post_meta( $post_id, 'branch', $this->git->branch );
			add_post_meta( $post_id, 'files_changed', '0' );
			add_post_meta( $post_id, 'committed_files', array() );
		}
		// Push changes if necessary.
		$this->git->auto_push();
	}	

	/**
	 * Imports a table from a Revisr .sql file to the database.
	 * 
	 * Partly adapted/modified from VaultPress.
	 * @link https://wordpress.org/plugins/vaultpress/
	 * 
	 * @access public
	 * @param  string $table 		The table to import.
	 * @param  string $replace_url 	Replace this URL in the database with the live URL. 
	 */
	public function import_table( $table, $replace_url = '' ) {
		$live_url = site_url();
		// Only import if the file exists and is valid.
		if ( $this->verify_backup( $table ) == false ) {
			return false;
		}
		// Try to pass the file directly to MySQL.
		if ( $mysql = exec( 'which mysql' ) ) {
			$conn = $this->build_conn();
			exec( "{$mysql} {$conn} < revisr_$table.sql" );
			if ( $replace_url != '' ) {
				$this->revisr_srdb( $table, $replace_url, $live_url );
			}
			return true;
		}
		// Fallback on manually querying the file.
		$fh 	= fopen( "revisr_$table.sql", 'r' );
		$size	= filesize( "revisr_$table.sql" );
		$status = array(
			'errors' 	=> 0,
			'updates' 	=> 0
		);

		while( !feof( $fh ) ) {
			$query = trim( stream_get_line( $fh, $size, ";\n" ) );
			if ( empty( $query ) ) {
				$status['dropped_queries'][] = $query;
				continue;
			}
			if ( $this->wpdb->query( $query ) === false ) {
				$status['errors']++;
				$status['bad_queries'][] = $query;
			} else {
				$status['updates']++;
				$status['good_queries'][] = $query;
			}
		}
		fclose( $fh );

		if ( $replace_url != '' ) {
			$this->revisr_srdb( $table, $replace_url, $live_url );
		}

		if ( $status['errors'] !== 0 ) {
			return false;
		}
		return true;
	}

	/**
	 * Callback for the import action.
	 * @access private
	 * @param  array $status The status of the import.
	 */
	private function import_callback( $status ) {
		if ( in_array( false, $status ) ) {
			$msg = __( 'Error importing the database.', 'revisr' );
			Revisr_Admin::log( $msg, 'error' );
			Revisr_Admin::alert( $msg, true );
		} else {
			$msg = __( 'Successfully imported the database.', 'revisr' );
			Revisr_Admin::log( $msg, 'import' );
			Revisr_Admin::alert( $msg );
		}		
	}

	/**
	 * Reverts a table to an earlier commit.
	 * @access private
	 * @param  string $table  The table to revert.
	 * @param  string $commit The commit to revert to.
	 * @return boolean
	 */
	private function revert_table( $table, $commit ) {
		$checkout = $this->git->run( "checkout $commit {$this->upload_dir['basedir']}/revisr-backups/revisr_$table.sql" );
		if ( $checkout !== false ) {
			return $this->import_table( $table );
		}
		return false;
	}

	/**
	 * Callback for the revert_table action.
	 * @access private
	 * @param  array $status The status of the revert.
	 */
	private function revert_callback( $status ) {
		if ( in_array( false, $status ) ) {
			$msg = __( 'Error reverting the database.', 'revisr' );
			Revisr_Admin::log( $msg, 'error' );
			Revisr_Admin::alert( $msg, true );
		} else {
			$msg = __( 'Successfully reverted the database.', 'revisr' );
			Revisr_Admin::log( $msg, 'revert' );
			Revisr_Admin::alert( $msg );
		}
	}

	/**
	 * Verifies a backup for a table.
	 * @access public
	 * @param  string $table The table to check.
	 * @return boolean
	 */
	public function verify_backup( $table ) {
		if ( ! file_exists( "revisr_$table.sql" ) || filesize( "revisr_$table.sql" ) < 1000 ) {
			return false;
		}
		return true;
	}

	/**
	 * Adapated from interconnect/it's search/replace script.
	 * Modified to use WordPress wpdb functions instead of PHP's native mysql() functions.
	 * 
	 * @link https://interconnectit.com/products/search-and-replace-for-wordpress-databases/
	 * 
	 * @access public
	 * @param  string $table 	The table to run the replacement on.
	 * @param  string $search 	The string to replace.
	 * @param  string $replace 	The string to replace with.
	 * @return array   			Collection of information gathered during the run.
	 */
	public function revisr_srdb( $table, $search = '', $replace = '' ) {

		// Get a list of columns in this table.
		$columns = array();
		$fields  = $this->wpdb->get_results( 'DESCRIBE ' . $table );
		foreach ( $fields as $column ) {
			$columns[$column->Field] = $column->Key == 'PRI' ? true : false;
		}
		$this->wpdb->flush();

		// Count the number of rows we have in the table if large we'll split into blocks, This is a mod from Simon Wheatley
		$this->wpdb->get_results( 'SELECT COUNT(*) FROM ' . $table );
		$row_count = $this->wpdb->num_rows;
		if ( $row_count == 0 )
			continue;

		$page_size 	= 50000;
		$pages 		= ceil( $row_count / $page_size );

		for( $page = 0; $page < $pages; $page++ ) {

			$current_row 	= 0;
			$start 			= $page * $page_size;
			$end 			= $start + $page_size;
			
			// Grab the content of the table.
			$data = $this->wpdb->get_results( "SELECT * FROM $table LIMIT $start, $end", ARRAY_A );
			
			// Loop through the data.
			foreach ( $data as $row ) {
				$current_row++;
				$update_sql = array();
				$where_sql 	= array();
				$upd 		= false;

				foreach( $columns as $column => $primary_key ) {
					$edited_data = $data_to_fix = $row[ $column ];

					// Run a search replace on the data that'll respect the serialisation.
					$edited_data = $this->recursive_unserialize_replace( $search, $replace, $data_to_fix );

					// Something was changed
					if ( $edited_data != $data_to_fix ) {
						$update_sql[] = $column . ' = "' . $this->mysql_escape_mimic( $edited_data ) . '"';
						$upd = true;
					}

					if ( $primary_key )
						$where_sql[] = $column . ' = "' .  $this->mysql_escape_mimic( $data_to_fix ) . '"';
				}

				if ( $upd && ! empty( $where_sql ) ) {
					$sql = 'UPDATE ' . $table . ' SET ' . implode( ', ', $update_sql ) . ' WHERE ' . implode( ' AND ', array_filter( $where_sql ) );
					$result = $this->wpdb->query( $sql );
					if ( ! $result ) {
						$error_msg = sprintf( __( 'Error updating the table: %s.', 'revisr' ), $table );
					}
				} elseif ( $upd ) {
					$error_msg = sprintf( __( 'The table "%s" has no primary key. Manual change needed on row %s.', 'revisr' ), $table, $current_row );
				}
			}
		}
		$this->wpdb->flush();
		if ( isset( $error_msg ) ) {
			Revisr_Admin::log( $error_msg, 'error' );
			return false;
		}
	}

	/**
	 * Adapated from interconnect/it's search/replace script.
	 * 
	 * @link https://interconnectit.com/products/search-and-replace-for-wordpress-databases/
	 * 
	 * Take a serialised array and unserialise it replacing elements as needed and
	 * unserialising any subordinate arrays and performing the replace on those too.
	 * 
	 * @access private
	 * @param  string $from       String we're looking to replace.
	 * @param  string $to         What we want it to be replaced with
	 * @param  array  $data       Used to pass any subordinate arrays back to in.
	 * @param  bool   $serialised Does the array passed via $data need serialising.
	 *
	 * @return array	The original array with all elements replaced as needed.
	 */
	public function recursive_unserialize_replace( $from = '', $to = '', $data = '', $serialised = false ) {
		try {

			if ( is_string( $data ) && ( $unserialized = @unserialize( $data ) ) !== false ) {
				$data = $this->recursive_unserialize_replace( $from, $to, $unserialized, true );
			}

			elseif ( is_array( $data ) ) {
				$_tmp = array( );
				foreach ( $data as $key => $value ) {
					$_tmp[ $key ] = $this->recursive_unserialize_replace( $from, $to, $value, false );
				}

				$data = $_tmp;
				unset( $_tmp );
			}

			// Submitted by Tina Matter
			elseif ( is_object( $data ) ) {
				$dataClass 	= get_class( $data );
				$_tmp  		= new $dataClass();
				foreach ( $data as $key => $value ) {
					$_tmp->$key = $this->recursive_unserialize_replace( $from, $to, $value, false );
				}

				$data = $_tmp;
				unset( $_tmp );
			}
			
			else {
				if ( is_string( $data ) )
					$data = str_replace( $from, $to, $data );
			}

			if ( $serialised )
				return serialize( $data );

		} catch( Exception $error ) {
			Revisr_Admin::log( $error, 'error' );
		}

		return $data;
	}

	/**
	 * Checks if a given host is using a port, if so, return the port.
	 * @access public
	 * @param  string $url The URL to check.
	 * @return string
	 */
	public function check_port( $url ) {
		$parsed_url = parse_url( $url );
		if ( isset( $parsed_url['port'] ) && $parsed_url['port'] != '' ) {
			return $parsed_url['port'];
		} else {
			return false;
		}
	}

	/**
	 * Mimics the mysql_real_escape_string function. Adapted from a post by 'feedr' on php.net.
	 * @link   http://php.net/manual/en/function.mysql-real-escape-string.php#101248
	 * @access public
	 * @param  string $input The string to escape.
	 */
	public function mysql_escape_mimic( $input ) {

	    if( is_array( $input ) ) 
	        return array_map( __METHOD__, $input ); 

	    if( ! empty( $input ) && is_string( $input ) ) { 
	        return str_replace( array( '\\', "\0", "\n", "\r", "'", '"', "\x1a" ), array( '\\\\', '\\0', '\\n', '\\r', "\\'", '\\"', '\\Z' ), $input ); 
	    } 

	    return $input; 
	}		

}