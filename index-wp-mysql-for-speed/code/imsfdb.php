<?php
require_once( 'getindexes.php' );
require_once( 'getqueries.php' );
require_once( ABSPATH . 'wp-admin/includes/class-wp-upgrader.php' );

class ImfsDb {
  /** @var bool true if this server can support reindexing at all */
  public $canReindex = false;
  /** @var int 1 if we have Barracuda, 0 if we have Antelope */
  public $unconstrained;
  public $queries;
  public $messages = [];
  public $semver;
  public $stats;
  public $oldEngineTables;
  public $newEngineTables;
  public $timings;
  private $initialized = false;
  private $hasHrTime;
  /** @var string[] list of index prefixes to ignore. */
  private $indexStopList = [ 'woo_' ];
  private $pluginOldVersion;
  private $pluginVersion;

  /**
   * @param float $pluginVersion
   * @param float $pluginOldVersion
   */
  public function __construct( float $pluginVersion, float $pluginOldVersion ) {
    $this->pluginOldVersion = $pluginOldVersion;
    $this->pluginVersion    = $pluginVersion;
  }

  /**
   * @throws ImfsException
   */
  public function init() {
    global $wpdb;
    if ( ! $this->initialized ) {
      $this->initialized   = true;
      $this->timings       = [];
      $this->queries       = getQueries();
      $this->semver        = getMySQLVersion();
      $this->canReindex    = $this->semver->canreindex;
      $this->unconstrained = $this->semver->unconstrained;

      if ( $this->canReindex ) {
        $this->stats     = $this->getStats();
        $oldEngineTables = [];
        $newEngineTables = [];
        /* make sure we only upgrade the engine on WordPress's own tables */
        $wpTables = array_flip( $wpdb->tables( 'blog', true ) );
        if ( is_main_site() ) {
          $wpTables = $wpTables + array_flip( $wpdb->tables( 'global', true ) );
        }
        $tablesData = $this->stats[2];
        foreach ( $tablesData as $name => $info ) {
          $activeTable = false;
          if ( isset( $wpTables[ $name ] ) ) {
            $activeTable = true;
          }
          if ( 0 === strpos( $name, $wpdb->prefix ) ) {
            $activeTable = true;
          }
          if ( $activeTable ) {
            /* not InnoDB, we should upgrade */
            $wrongEngine = $info->ENGINE !== 'InnoDB';
            /* one of the old row formats, probably compact. But ignore if old MySQL version. */
            $wrongRowFormat = $this->unconstrained && $info->ROW_FORMAT !== 'Dynamic' && $info->ROW_FORMAT !== 'Compressed';

            if ( $wrongEngine || $wrongRowFormat ) {
              $oldEngineTables[] = $name;
            } else {
              $newEngineTables[] = $name;
            }
          }
        }
        $this->oldEngineTables = $oldEngineTables;
        $this->newEngineTables = $newEngineTables;
      }
      try {
        $this->hasHrTime = function_exists( 'hrtime' );
      } catch ( Exception $ex ) {
        $this->hasHrTime = false;
      }
    }
  }

  /** Fetch server status data
   * @return array server information
   * @throws ImfsException
   */
  public function getStats(): array {
    global $wpdb;
    $wpdb->flush();
    $output  = [];
    $dbstats = $this->queries['dbstats'];
    foreach ( $dbstats as $q ) {
      $results = $this->get_results( $q );
      array_push( $output, $results );
    }
    $results = $this->getInnodbMetrics();
    array_push( $output, $results );

    return $output;
  }

  /** run a SELECT
   *
   * @param $sql
   * @param bool $doTiming
   * @param string $outputFormat default OBJECT_K
   *
   * @return array|object|null
   * @throws ImfsException
   */
  public function get_results( $sql, bool $doTiming = false, string $outputFormat = OBJECT_K ) {
    global $wpdb;
    $thentime = $doTiming ? $this->getTime() : - 1;
    $results  = $wpdb->get_results( $this->tagQuery( $sql ), $outputFormat );
    if ( false === $results || $wpdb->last_error ) {
      throw new ImfsException( $wpdb->last_error, $wpdb->last_query );
    }
    if ( $doTiming ) {
      $delta           = round( floatval( $this->getTime() - $thentime ), 3 );
      $this->timings[] = [ 't' => $delta, 'q' => $sql ];
    }

    return $results;

  }

  public function getTime() {
    try {
      /** @noinspection PhpElementIsNotAvailableInCurrentPhpVersionInspection */
      return $this->hasHrTime ? hrtime( true ) * 0.000000001 : time();
    } catch ( Exception $ex ) {
      return time();
    }
  }

  /** place a tag comment at the end of a SQL statement,
   * including text to recognize our queries and
   * a random cache-busting number.
   * (So WP Total Cache and others won't cache.)
   *
   * @param $q
   *
   * @return string
   */
  private function tagQuery( $q ): string {
    return $q . '/*' . index_wp_mysql_for_speed_querytag . strval( rand( 0, 999999999 ) ) . '*/';
  }

  private function getInnodbMetrics(): array {
    global $wpdb;
    $suppressing = $wpdb->suppress_errors( true );
    try {
      $r = $this->get_results( $this->queries['innodb_metrics'][0], false, OBJECT );
      if ( is_array( $r ) && count( $r ) === 1 && $r[0]->num > 0 ) {
        return $this->get_results( $this->queries['innodb_metrics'][1] );
      }

      return [
        (object) [
          "Variable_name" => "INNODB_METRICS",
          "Value"         => "not present on this server version",
        ],
      ];
    } catch ( ImfsException $ex ) {
      /* this INNODB_METRICS lookup sometimes fails */
      return [
        (object) [
          "Variable_name" => "INNODB_METRICS",
          "Value"         => $ex->getMessage(),
        ],
      ];
    } finally {
      $wpdb->suppress_errors( $suppressing );
    }
  }

  /**
   * @param $targetAction int   0 - WordPress standard  1 -- high performance
   * @param $tables array  tables like ['postmeta','termmeta']
   * @param $alreadyPrefixed bool false if wp_ prefix needs to be added to table names
   *
   * @return string message to display in SettingNotice.
   * @throws ImfsException
   *
   */
  public function rekeyTables( int $targetAction, array $tables, float $version, bool $alreadyPrefixed = false ): string {
    $count = 0;
    if ( count( $tables ) > 0 ) {
      foreach ( $tables as $name ) {
        $this->rekeyTable( $targetAction, $name, $version, $alreadyPrefixed );
        $count ++;
      }
    }
    $msg = '';
    switch ( $targetAction ) {
      case 1:
        $msg = __( 'High-performance keys added to %d tables.', index_wp_mysql_for_speed_domain );
        break;
      case 0:
        $msg = __( 'Keys on %d tables reverted to WordPress standard.', index_wp_mysql_for_speed_domain );
        break;
    }

    return sprintf( $msg, $count );

  }

  /** Redo the keys on the selected table.
   *
   * @param int $targetAction 0 -- WordPress standard.  1 -- high-perf
   * @param string $name table name without prefix
   *
   * @throws ImfsException
   */
  public function rekeyTable( int $targetAction, string $name, $version, $alreadyPrefixed = false ) {
    global $wpdb;

    $unprefixedName = $alreadyPrefixed ? ImfsStripPrefix( $name ) : $name;
    $prefixedName   = $alreadyPrefixed ? $name : $wpdb->prefix . $name;

    $actions = $this->getConversionList( $targetAction, $unprefixedName, $version );

    if ( count( $actions ) === 0 ) {
      return;
    }

    $q = 'ALTER TABLE ' . $prefixedName . ' ' . implode( ', ', $actions );
    set_time_limit( 120 );
    $this->query( $q, true );
  }

  /**
   * @param int $targetState 0 -- WordPress default    1 -- high-performance
   * @param string $name table name
   * @param float $version
   *
   * @return array
   * @throws ImfsException
   */
  public function getConversionList( int $targetState, string $name, float $version ): array {
    $target  = $targetState === 0
      ? imfsGetIndexes::getStandardIndexes( $this->unconstrained )
      : imfsGetIndexes::getHighPerformanceIndexes( $this->unconstrained, $version );
    $target  = array_key_exists( $name, $target ) ? $target[ $name ] : [];
    $current = $this->getKeyDDL( $name );

    /* build a list of all index names, target first so UNIQUEs come first */
    $indexes = [];
    foreach ( $target as $key => $value ) {
      if ( ! isset( $indexes[ $key ] ) ) {
        $indexes[ $key ] = $key;
      }
    }
    foreach ( $current as $key => $value ) {
      if ( ! isset( $indexes[ $key ] ) ) {
        $indexes[ $key ] = $key;
      }
    }

    /* Ignore index names prefixed with anything ins
     * the indexStoplist array (skip woocommerce indexes) */
    foreach ( $this->indexStopList as $stop ) {
      foreach ( $indexes as $index => $val ) {
        if ( substr_compare( $index, $stop, null, true ) === 0 ) {
          unset ( $indexes[ $index ] );
        }
      }
    }
    $actions = [];

    $curDexes = 'current indexes ' . $name . ':';
    foreach ( $current as $value ) {
      $curDexes .= $value->add . ' ';
    }

    foreach ( $indexes as $key => $value ) {
      if ( array_key_exists( $key, $current ) && array_key_exists( $key, $target ) && $current[ $key ]->add === $target[ $key ] ) {
        /* no action required */
      } else if ( array_key_exists( $key, $current ) && array_key_exists( $key, $target ) && $current[ $key ]->add !== $target[ $key ] ) {
        $actions[] = $current[ $key ]->drop;
        $actions[] = $target[ $key ];
      } else if ( array_key_exists( $key, $current ) && ! array_key_exists( $key, $target ) ) {
        $actions[] = $current[ $key ]->drop;
      } else if ( ! array_key_exists( $key, $current ) && array_key_exists( $key, $target ) ) {
        $actions[] = $target[ $key ];
      } else {
        throw new ImfsException( 'weird key compare failure' );
      }
    }

    return $actions;
  }

  /**
   * Retrieve DML for the keys in the named table.
   *
   * @param string $name table name (without prefix)
   * @param bool $addPrefix
   *
   * @return array
   * @throws ImfsException
   */
  public function getKeyDDL( string $name, bool $addPrefix = true ) {
    global $wpdb;
    if ( $addPrefix ) {
      $name = $wpdb->prefix . $name;
    }

    $stmt = $wpdb->prepare( $this->queries['indexes'], $name );

    return $this->get_results( $stmt );
  }

  /** run a query*
   *
   * @param $sql
   * @param bool $doTiming
   *
   * @return bool|int
   * @throws ImfsException
   */
  public function query( $sql, bool $doTiming = false ) {
    global $wpdb;
    $thentime = $doTiming ? $this->getTime() : - 1;
    $results  = $wpdb->query( $this->tagQuery( $sql ) );
    if ( false === $results || $wpdb->last_error ) {
      throw new ImfsException( $wpdb->last_error, $wpdb->last_query );
    }
    if ( $doTiming ) {
      $delta           = round( floatval( $this->getTime() - $thentime ), 3 );
      $this->timings[] = [ 't' => $delta, 'q' => $sql ];
    }

    return $results;

  }

  /** get the current index situation
   * @return array
   */
  public function getIndexList(): array {

    $results = [];
    try {
      $rekeying = $this->getRekeying();
      foreach ( $rekeying as $key => $list ) {
        if ( is_array( $list ) && count( $list ) > 0 ) {
          if ( $key === 'standard' || $key === 'old' || $key === 'fast' || $key === 'upgrade' ) {
            $results[ $key ] = $list;
          }
        }
      }
      /* nonstandard? show the actual keys */
      $nonstandard = $rekeying['nonstandard'];
      if ( is_array( $nonstandard ) && count( $nonstandard ) > 0 ) {
        $tables = [];
        foreach ( $nonstandard as $table ) {
          $keys    = [];
          $current = $this->getKeyDDL( $table );
          foreach ( $current as $key => $value ) {
            $dex    = preg_replace( '/^ADD +/', '', $value->add, 1 );
            $keys[] = $dex;
          }
          $tables[ $table ] = $keys;
        }
        $results['nonstandard'] = $tables;
      }
    } catch ( ImfsException $ex ) {
      $results[] = $ex->getMessage();
    }

    return $results;
  }

  /** figure out, based on current DDL and target DDL,
   * what tables need to change, and how they could change.
   *
   * @return array
   * @throws ImfsException
   */
  public function getRekeying(): array {
    global $wpdb;
    $originalTables = $this->tables();
    /* don't process tables still on old storage engines */
    $tables = [];
    if ( is_array( $this->oldEngineTables ) ) {
      foreach ( $originalTables as $name ) {
        if ( ! in_array( $wpdb->prefix . $name, $this->oldEngineTables ) ) {
          $tables[] = $name;
        }
      }
    }
    /* any rekeyable tables? */
    $updatable    = [];
    $newToThisVer = [];

    $addable    = [];
    $revertable = [];
    $repairable = [];
    $enableList = [];
    $oldList    = [];
    $fastList   = [];

    if ( is_array( $tables ) && count( $tables ) > 0 ) {
      sort( $tables );
      foreach ( $tables as $name ) {
        $hasFastIndexes     = ! $this->anyIndexChangesNeededForAction( 1, $name, $this->pluginVersion );
        $hasStandardIndexes = ! $this->anyIndexChangesNeededForAction( 0, $name, $this->pluginVersion );
        $hasOldIndexes      = ! $this->anyIndexChangesNeededForAction( 1, $name, $this->pluginOldVersion );

        if ( ! $hasFastIndexes && ! $hasStandardIndexes && ! $hasOldIndexes ) {
          /* some index config we don't recognize */
          $repairable[] = $name;
          $revertable[] = $name;
        } else if ( ! $hasFastIndexes && ! $hasStandardIndexes && $hasOldIndexes ) {
          /* indexes from old version of plugin */
          $updatable[]  = $name;
          $revertable[] = $name;
        } else if ( ! $hasFastIndexes && $hasStandardIndexes && ! $hasOldIndexes ) {
          /* standard indexes, not rekeyed by this or older plugin version */
          $addable[] = $name;
        } else if ( ! $hasFastIndexes && $hasStandardIndexes && $hasOldIndexes ) {
          /* not rekeyed in older version, but rekeyed in this version */
          $newToThisVer[] = $name;
        } else if ( $hasFastIndexes && ! $hasStandardIndexes && ! $hasOldIndexes ) {
          /* already rekeyed by this version */
          $revertable[] = $name;
          $fastList[]   = $name;
        } else {
          throw new Exception( "Table $name invalid state $hasFastIndexes $hasStandardIndexes $hasOldIndexes" );
        }
      }
      /* handle version update logic */
      if ( count( $updatable ) > 0 ) {
        /* version update time: offer to update both new and upd */
        $oldList    = array_merge( $newToThisVer, $updatable );
        $enableList = $addable;
      } else {
        $enableList = array_merge( $newToThisVer, $addable );
      }
    }
    sort( $enableList );
    sort( $oldList );

    /* What do we return here?
     * enable: list of tables that can have fast keys added.
     * old: list of tables that have previous-version fast keys,
     *      including tables the previous version did not touch.
     * disable: list of tables that have indexes that can be
     *          restored to the default.
     * fast: list of tables that have this plugin version's fast indexes.
     * nonstandard:  list of tables with indexes this plugin doesn't recognize.
     * standard: list of tables with WordPress standard keys
     * upgrade: list of MyISAM and / or COMPACT row-format tables
     *          needing upgrading.
     */

    return [
      'enable'      => $enableList,
      'old'         => $oldList,
      'disable'     => $revertable,
      'fast'        => $fastList,
      'nonstandard' => $repairable,
      'standard'    => $addable,
      'upgrade'     => $this->oldEngineTables,
    ];
  }

  /** List of tables to manipulate
   *
   * @param bool $prefixed true if you want wp_postmeta, false if you want postmeta
   *
   * @return array tables manipulated by this module
   * @throws ImfsException
   */
  public function tables( bool $prefixed = false ): array {
    global $wpdb;
    $avail = $wpdb->tables;
    if ( is_main_site() ) {
      foreach ( $wpdb->global_tables as $table ) {
        $avail[] = $table;
      }
    }
    /* match to the tables we know how to reindex */
    $allTables = imfsGetIndexes::getIndexableTables( $this->unconstrained );
    $tables    = [];
    foreach ( $allTables as $table ) {
      if ( array_search( $table, $avail ) !== false ) {
        $tables[] = $table;
      }
    }
    sort( $tables );
    if ( ! $prefixed ) {
      return $tables;
    }
    $result = [];
    foreach ( $tables as $table ) {
      $result[] = $wpdb->prefix . $table;
    }

    return $result;
  }

  /** Check whether a table is ready to be acted upon
   *
   * @param int $targetAction "enable" or "disable"
   * @param $name
   * @param $version
   *
   * @return bool
   * @throws ImfsException
   */
  public function anyIndexChangesNeededForAction( int $targetAction, $name, $version ): bool {
    $actions = $this->getConversionList( $targetAction, $name, $version );

    return count( $actions ) > 0;
  }

  /**
   * @param array $list of tables
   *
   * @return string
   * @throws ImfsException
   */
  public function upgradeStorageEngine( array $list ): string {
    $counter = 0;
    try {
      $this->lock( $list, true );
      foreach ( $list as $table ) {
        $this->upgradeTableStorageEngine( $table );
        $counter ++;
      }
      $msg = __( '%d tables upgraded', index_wp_mysql_for_speed_domain );
    } catch ( ImfsException $ex ) {
      $msg = implode( ', ', $this->clearMessages() );
      throw ( $ex );
    } finally {
      $this->unlock();
    }

    return sprintf( $msg, $counter );
  }

  /** Put the site into maintenance mode and lock a list of tables
   *
   * @param $tableList
   * @param $alreadyPrefixed
   *
   * @throws ImfsException
   */
  public function lock( $tableList, $alreadyPrefixed ) {
    global $wpdb;
    if ( ! is_array( $tableList ) || count( $tableList ) === 0 ) {
      throw new ImfsException( "Invalid attempt to lock. At least one table must be locked" );
    }
    $tablesToLock = [];
    foreach ( $tableList as $tbl ) {
      if ( ! $alreadyPrefixed ) {
        $tbl = $wpdb->prefix . $tbl;
      }
      array_push( $tablesToLock, $tbl );
    }

    /* always specify locks in the same order to avoid starving the philosophers */
    sort( $tablesToLock );
    $tables = [];
    foreach ( $tablesToLock as $tbl ) {
      array_push( $tables, $tbl . ' WRITE' );
    }

    $this->enterMaintenanceMode();
    $q = "LOCK TABLES " . implode( ', ', $tables );
    $this->query( $q );
  }

  /**
   * @param int $duration how many seconds until maintenance expires
   */
  public function enterMaintenanceMode( int $duration = 60 ) {
    $maintenanceFileName = ABSPATH . '.maintenance';
    if ( is_writable( ABSPATH ) ) {
      $maintain     = [];
      $expirationTs = time() + $duration - 600;
      array_push( $maintain,
        '<?php',
        '/* Maintenance Mode was entered by ' .
        index_wp_mysql_for_speed_PLUGIN_NAME .
        ' for table reindexing at ' .
        date( "Y-m-d H:i:s" ) . ' */',
        /* notice that maintenance expires ten minutes, 600 sec, after the time in the file. */
        '$upgrading = ' . $expirationTs . ';',
        '?>' );

      file_put_contents( $maintenanceFileName, implode( PHP_EOL, $maintain ) );
    }
  }

  /**
   * @throws ImfsException
   */
  public function upgradeTableStorageEngine( $table ): bool {
    set_time_limit( 120 );
    $sql = 'ALTER TABLE ' . $table . ' ENGINE=InnoDb, ROW_FORMAT=DYNAMIC';
    $this->query( $sql, true );

    return true;
  }

  /** Resets the messages in this class and returns the previous messages.
   * @return array
   */
  public function clearMessages(): array {
    $msgs           = $this->messages;
    $this->messages = [];

    return $msgs;
  }

  /** Undo lock.  This is ideally called from a finally{} clause.
   * @throws ImfsException
   */
  public function unlock() {
    $this->query( "UNLOCK TABLES" );
    $this->leaveMaintenanceMode();
  }

  public function leaveMaintenanceMode() {
    $maintenanceFileName = ABSPATH . '.maintenance';
    if ( is_writable( ABSPATH ) && file_exists( $maintenanceFileName ) ) {
      unlink( $maintenanceFileName );
    }
  }
}

class ImfsException extends Exception {
  protected $message;
  private $query;

  public function __construct( $message, $query = '', $code = 0, $previous = null ) {
    global $wpdb;
    $this->query = $query;
    parent::__construct( $message, $code, $previous );
    $wpdb->flush();
  }

  public function __toString() {
    return __CLASS__ . ": [$this->code]: $this->message in $this->query\n";
  }
}
