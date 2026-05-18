<?php
/**
 * Bootstrap PHPUnit: definisce le costanti, carica gli stub e le classi del plugin.
 */
declare( strict_types=1 );

// Impedisce l'uscita per ABSPATH non definita nelle classi del plugin.
define( 'ABSPATH', dirname( __DIR__ ) . '/' );

// Costanti del plugin.
define( 'MVD_WAI_CTRL_VERSION',      '1.0.0' );
define( 'MVD_WAI_CTRL_DIR',          dirname( __DIR__ ) . '/' );
define( 'MVD_WAI_CTRL_URL',          'http://localhost/wp-content/plugins/mvd-wp-all-import-controller/' );
define( 'MVD_WAI_CTRL_CRON_HOOK',    'mvd_wai_ctrl_run' );
define( 'MVD_WAI_CTRL_STATE_OPTION', 'mvd_wai_ctrl_state' );
define( 'MVD_WAI_CTRL_LOCK_KEY',     'mvd_wai_ctrl_running_lock' );
define( 'MVD_WAI_CTRL_CAPABILITY',   'manage_options' );
define( 'MVD_WAI_CTRL_IDS',          [ 13, 2, 1, 14 ] );

// Costanti WordPress di tempo e altro usate dal plugin e dai test.
defined( 'ARRAY_A' )           || define( 'ARRAY_A',           'ARRAY_A' );
defined( 'OBJECT' )            || define( 'OBJECT',            'OBJECT' );
defined( 'MINUTE_IN_SECONDS' ) || define( 'MINUTE_IN_SECONDS', 60 );
defined( 'DAY_IN_SECONDS' )    || define( 'DAY_IN_SECONDS',    86400 );

// Nuove costanti del plugin per error handling e log.
define( 'MVD_WAI_CTRL_WATCHDOG_THRESHOLD', 180 );
define( 'MVD_WAI_CTRL_LOG_DIR',            'mvd-wai-ctrl-logs' );
define( 'MVD_WAI_CTRL_LOG_RETENTION_DAYS', 30 );

// Autoload Composer (PHPUnit, Brain Monkey, Mockery).
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// Stub per le classi esterne non disponibili nell'ambiente di test.
require_once __DIR__ . '/stubs/wpdb-stub.php';
require_once __DIR__ . '/stubs/pmxi-stubs.php';

// Carica le classi del plugin (esclude il file principale che richiede ABSPATH WP completo).
require_once dirname( __DIR__ ) . '/includes/class-state.php';
require_once dirname( __DIR__ ) . '/includes/class-logger.php';
require_once dirname( __DIR__ ) . '/includes/class-runner.php';
require_once dirname( __DIR__ ) . '/includes/class-plugin.php';
