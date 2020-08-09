#!/usr/bin/env php
<?php
/**
 * Update Pi-hole lists from remote sources
 *
 * @author  Jack'lul <jacklul.github.io>
 * @license MIT
 * @link    https://github.com/jacklul/pihole-updatelists
 */

define('VERSION_HASH', 'RQwj7ZKThUF8LmDdYtfHVV7v44QMtZ25'); // This is a secret.
define('GITHUB_LINK', 'https://github.com/jacklul/pihole-updatelists'); // Link to Github page
define('GITHUB_LINK_RAW', 'https://raw.githubusercontent.com/jacklul/pihole-updatelists'); // URL serving raw files from the repository

/**
 * Print (and optionally log) string
 *
 * @param string $str
 * @param string $severity
 * @param bool   $logOnly
 *
 * @throws RuntimeException
 */
function printAndLog($str, $severity = 'INFO', $logOnly = false)
{
    global $config, $lock;

    if (!in_array(strtoupper($severity), ['DEBUG', 'INFO', 'NOTICE', 'WARNING', 'ERROR'])) {
        throw new RuntimeException('Invalid log severity: ' . $severity);
    }

    if (!empty($config['LOG_FILE'])) {
        $flags = FILE_APPEND;

        if (strpos($config['LOG_FILE'], '-') === 0) {
            $flags              = 0;
            $config['LOG_FILE'] = substr($config['LOG_FILE'], 1);
        }

        // Do not overwrite log files until we have a lock (this could mess up log file if another instance is already running)
        if ($flags !== null || $lock !== null) {
            if (!file_exists($config['LOG_FILE']) && !@touch($config['LOG_FILE'])) {
                throw new RuntimeException('Unable to create log file: ' . $config['LOG_FILE']);
            }

            $lines = preg_split('/\r\n|\r|\n/', ucfirst(trim($str)));
            foreach ($lines as &$line) {
                $line = '[' . date('Y-m-d H:i:s') . '] [' . strtoupper($severity) . ']' . "\t" . $line;
            }
            unset($line);

            file_put_contents(
                $config['LOG_FILE'],
                implode(PHP_EOL, $lines) . PHP_EOL,
                $flags | LOCK_EX
            );
        }
    }

    if ($logOnly) {
        return;
    }

    print $str;
}

/**
 * Check for required stuff
 *
 * Setting invironment variable IGNORE_OS_CHECK allows to run this script on Windows
 */
function checkDependencies()
{
    // Do not run on PHP lower than 7.0
    if ((float) PHP_VERSION < 7.0) {
        printAndLog('Minimum PHP 7.0 is required to run this script!' . PHP_EOL, 'ERROR');
        exit(1);
    }

    // Windows is obviously not supported (invironment variable IGNORE_OS_CHECK overrides this)
    if (stripos(PHP_OS, 'WIN') === 0 && empty(getenv('IGNORE_OS_CHECK'))) {
        printAndLog('Windows is not supported!' . PHP_EOL, 'ERROR');
        exit(1);
    }

    if ((!function_exists('posix_getuid') || !function_exists('posix_kill')) && empty(getenv('IGNORE_OS_CHECK'))) {
        printAndLog('Make sure PHP\'s functions \'posix_getuid\' and \'posix_kill\' are available!' . PHP_EOL, 'ERROR');
        exit(1);
    }

    $extensions = [
        'pdo',
        'pdo_sqlite',
        'json',
    ];

    // Required PHP extensions
    foreach ($extensions as $extension) {
        if (!extension_loaded($extension)) {
            printAndLog('Missing required PHP extension: ' . $extension . PHP_EOL, 'ERROR');
            exit(1);
        }
    }
}

/**
 * Returns array of defined options
 *
 * @return array
 */
function getDefinedOptions()
{
    return [
        'help'       => [
            'long'        => 'help',
            'short'       => 'h',
            'function'    => 'printHelp',
            'description' => 'This help message',
        ],
        'no-gravity' => [
            'long'        => 'no-gravity',
            'short'       => 'n',
            'description' => 'Force no gravity update',
        ],
        'no-reload'  => [
            'long'        => 'no-reload',
            'short'       => 'b',
            'description' => 'Force no lists reload',
        ],
        'no-vacuum'  => [
            'long'        => 'no-vacuum',
            'short'       => 'm',
            'description' => 'Force no database vacuuming',
        ],
        'verbose'    => [
            'long'        => 'verbose',
            'short'       => 'v',
            'description' => 'Turn on verbose mode',
        ],
        'debug'      => [
            'long'        => 'debug',
            'short'       => 'd',
            'description' => 'Turn on debug mode',
        ],
        'update'     => [
            'long'        => 'update',
            'function'    => 'updateScript',
            'description' => 'Update the script using selected git branch',
        ],
        'version'    => [
            'long'        => 'version',
            'function'    => 'printVersion',
            'description' => 'Show script checksum (and also if update is available)',
        ],
        'config'     => [
            'long'                  => 'config::',
            'description'           => 'Load alternative configuration file',
            'parameter-description' => 'file',
        ],
        'git-branch' => [
            'long'                  => 'git-branch::',
            'description'           => 'Select git branch to pull remote checksum and update from',
            'parameter-description' => 'branch',
        ],
    ];
}

/**
 * Re-run the script with sudo when not running as root
 *
 * This check is ignored if script is not installed
 */
function requireRoot()
{
    if (function_exists('posix_getuid') && posix_getuid() !== 0 && strpos(basename($_SERVER['argv'][0]), '.php') === false) {
        passthru('sudo ' . implode(' ', $_SERVER['argv']), $return);
        exit($return);
    }
}

/**
 * Parse command-line options
 */
function parseOptions()
{
    $definedOptions = getDefinedOptions();
    $shortOpts      = [];
    $longOpts       = [];

    foreach ($definedOptions as $i => $data) {
        if (isset($data['long'])) {
            if (in_array($data['long'], $longOpts)) {
                throw new RuntimeException('Unable to define long option because it is already defined: ' . $data['long']);
            }

            $longOpts[] = $data['long'];
        }

        if (isset($data['short'])) {
            if (in_array($data['short'], $longOpts)) {
                throw new RuntimeException('Unable to define short option because it is already defined: ' . $data['short']);
            }

            $shortOpts[] = $data['short'];
        }
    }

    $options = getopt(implode('', $shortOpts), $longOpts);

    // If short is used set the long one
    foreach ($options as $option => $data) {
        foreach ($definedOptions as $definedOptionsIndex => $definedOptionsData) {
            $definedOptionsData['short'] = isset($definedOptionsData['short']) ? str_replace(':', '', $definedOptionsData['short']) : '';
            $definedOptionsData['long']  = isset($definedOptionsData['long']) ? str_replace(':', '', $definedOptionsData['long']) : '';

            if (
                $definedOptionsData['short'] === $option ||
                $definedOptionsData['long'] === $option
            ) {
                if (
                    !empty($definedOptionsData['short']) && $definedOptionsData['short'] === $option &&
                    !empty($definedOptionsData['long'])
                ) {
                    $optionStr                            = '-' . $definedOptionsData['short'];
                    $options[$definedOptionsData['long']] = $data;

                    unset($options[$option]);
                } elseif (!empty($definedOptionsData['long']) && $definedOptionsData['long'] === $option) {
                    $optionStr = '--' . $definedOptionsData['long'];
                }

                // Set function to run if it is defined for this option
                if (!isset($runFunction) && isset($definedOptionsData['function']) && function_exists($definedOptionsData['function'])) {
                    $runFunction = $definedOptionsData['function'];
                }
            }
        }
    }

    global $argv;
    unset($argv[0]); // Remove path to self

    // Remove recognized options from argv[]
    foreach ($options as $option => $data) {
        $result = array_filter($argv, function ($el) use ($option) {
            return strpos($el, $option) !== false;
        });

        if (!empty($result)) {
            unset($argv[key($result)]);
        }

        if (isset($definedOptions[$option]['short'])) {
            $shortOption = $definedOptions[$option]['short'];

            $result = array_filter($argv, function ($el) use ($shortOption) {
                return strpos($el, $shortOption) !== false;
            });

            if (!empty($result) && !preg_match('/^--' . $shortOption . '/', $argv[key($result)])) {
                $argv[key($result)] = str_replace($shortOption, '', $argv[key($result)]);

                if ($argv[key($result)] === '-') {
                    unset($argv[key($result)]);
                }
            }
        }
    }

    // When unknown option is used
    if (count($argv) > 0) {
        print 'Unknown option(s): ' . implode(' ', $argv) . PHP_EOL;
        exit(1);
    }

    // Run the function
    if (isset($runFunction)) {
        $runFunction($options, loadConfig($options));
        exit;
    }

    requireRoot(); // Require root privileges

    return $options;
}

/**
 * Fetch remote script file
 *
 * @param string $branch
 *
 * @return false|string
 */
function fetchRemoteScript($branch = 'master')
{
    global $remoteScript;

    if (isset($remoteScript[$branch])) {
        return $remoteScript[$branch];
    }

    $remoteScript[$branch] = @file_get_contents(
        GITHUB_LINK_RAW . '/' . $branch . '/pihole-updatelists.php',
        false,
        stream_context_create(
            [
                'http' => [
                    'timeout' => 10,
                ],
            ]
        )
    );

    $isSuccessful = false;
    foreach ($http_response_header as $header) {
        if (strpos($header, '200 OK') !== false) {
            $isSuccessful = true;
            break;
        }
    }

    if ($isSuccessful) {
        $firstLine = strtok($remoteScript[$branch], "\n");

        if (strpos($firstLine, '#!/usr/bin/env php') === false) {
            print 'Returned remote script data doesn\'t seem to be valid!' . PHP_EOL;
            print 'First line: ' . $firstLine . PHP_EOL;
            exit(1);
        }

        return $remoteScript[$branch];
    }

    return false;
}

/**
 * Check if script is up to date
 *
 * @param string $branch
 *
 * @return string
 */
function isUpToDate($branch = 'master')
{
    $md5Self      = md5_file(__FILE__);
    $remoteScript = fetchRemoteScript($branch);

    if ($remoteScript === false) {
        return null;
    }

    if ($md5Self !== md5($remoteScript)) {
        return false;
    }

    return true;
}

/**
 * Returns currently selected branch
 *
 * @param array $options
 * @param array $config
 */
function getBranch($options = [], $config = [])
{
    $branch = 'master';

    if (!empty($options['git-branch'])) {
        $branch = $options['git-branch'];
    } elseif (!empty($config['GIT_BRANCH'])) {
        $branch = $config['GIT_BRANCH'];
    }

    return $branch;
}

/**
 * Print help
 *
 * @param array $options
 * @param array $config
 */
function printHelp($options = [], $config = [])
{
    $definedOptions = getDefinedOptions();
    $help           = [];
    $maxLen         = 0;

    foreach ($definedOptions as $option) {
        $line = ' ';

        if (!isset($option['description'])) {
            continue;
        }

        if (isset($option['short'])) {
            $line .= '-' . $option['short'];
        }

        if (isset($option['long'])) {
            if (!empty(trim($line))) {
                $line .= ', ';
            }

            $line .= '--' . str_replace(':', '', $option['long']);

            if (isset($option['parameter-description'])) {
                $line .= '=<' . $option['parameter-description'] . '>';
            }
        }

        if (strlen($line) > $maxLen) {
            $maxLen = strlen($line);
        }

        $help[$line] = $option['description'];
    }

    printHeader();
    print 'Usage: ' . basename(__FILE__) . ' [OPTIONS...] ' . PHP_EOL . PHP_EOL;
    print 'Options:' . PHP_EOL;

    foreach ($help as $option => $description) {
        $whitespace = str_repeat(' ', $maxLen - strlen($option) + 2);
        print $option . $whitespace . $description . PHP_EOL;
    }

    print PHP_EOL;
}

/**
 * This will update the script to newest version
 *
 * @param array $options
 * @param array $config
 */
function updateScript($options = [], $config = [])
{
    if (strpos(basename($_SERVER['argv'][0]), '.php') !== false) {
        print 'It seems like this script haven\'t been installed - unable to update!' . PHP_EOL;
        exit(1);
    }

    requireRoot(); // Only root should be able to run this command
    $status = printVersion($options, $config, true);
    $branch = getBranch($options, $config);

    if ($status === false) {
        print PHP_EOL;
        passthru('wget -nv -O - ' . GITHUB_LINK_RAW . '/' . $branch . '/install.sh | sudo bash /dev/stdin ' . $branch, $return);
        exit($return);
    }
}

/**
 * Check local and remote version and print it
 *
 * @param array $options
 * @param array $config
 * @param bool  $return
 */
function printVersion($options = [], $config = [], $return = false)
{
    global $remoteScript;

    $config['DEBUG'] === true && printDebugHeader($config, $options);
    $branch = getBranch($options, $config);

    print 'Git branch: ' . $branch . PHP_EOL;
    print 'Local checksum: ' . md5_file(__FILE__) . PHP_EOL;

    $updateCheck = isUpToDate($branch);
    if ($updateCheck === null) {
        print 'Failed to check remote script: ' . parseLastError() . PHP_EOL;
        exit(1);
    }

    print 'Remote checksum: ' . md5($remoteScript[$branch]) . PHP_EOL;

    if ($updateCheck === true) {
        print 'The script is up to date!' . PHP_EOL;

        if ($return === true) {
            return true;
        }

        exit;
    }

    print 'Update is available!' . PHP_EOL;

    if ($return === true) {
        return false;
    }
}

/**
 * Validate important configuration variables
 *
 * @param $config
 */
function validateConfig($config)
{
    if ($config['COMMENT'] === '') {
        printAndLog('Variable COMMENT must be a string at least 1 characters long!' . PHP_EOL, 'ERROR');
        exit(1);
    }

    if (!is_int($config['GROUP_ID'])) {
        printAndLog('Variable GROUP_ID must be a number!' . PHP_EOL, 'ERROR');
        exit(1);
    }
}

/**
 * Load config file, if exists
 *
 * @param array $options
 *
 * @return array
 */
function loadConfig($options = [])
{
    // Default configuration
    $config = [
        'CONFIG_FILE'             => '/etc/pihole-updatelists.conf',
        'GRAVITY_DB'              => '/etc/pihole/gravity.db',
        'LOCK_FILE'               => '/var/lock/pihole-updatelists.lock',
        'LOG_FILE'                => '',
        'ADLISTS_URL'             => '',
        'WHITELIST_URL'           => '',
        'REGEX_WHITELIST_URL'     => '',
        'BLACKLIST_URL'           => '',
        'REGEX_BLACKLIST_URL'     => '',
        'COMMENT'                 => 'Managed by pihole-updatelists',
        'GROUP_ID'                => 0,
        'REQUIRE_COMMENT'         => true,
        'UPDATE_GRAVITY'          => true,
        'VACUUM_DATABASE'         => false,
        'VERBOSE'                 => false,
        'DEBUG'                   => false,
        'DOWNLOAD_TIMEOUT'        => 60,
        'IGNORE_DOWNLOAD_FAILURE' => false,
        'GIT_BRANCH'              => 'master',
    ];

    if (isset($options['config'])) {
        if (!file_exists($options['config'])) {
            printAndLog('Invalid file: ' . $options['config'] . PHP_EOL, 'ERROR');
            exit(1);
        }

        $config['CONFIG_FILE'] = $options['config'];
    }

    if (file_exists($config['CONFIG_FILE'])) {
        $configFile = file_get_contents($config['CONFIG_FILE']);

        // Convert any hash-commented lines to semicolons
        $configFile = preg_replace('/^\s{0,}(#)(.*)$/m', ';$2', $configFile);

        $loadedConfig = @parse_ini_string($configFile, false, INI_SCANNER_TYPED);
        if ($loadedConfig === false) {
            printAndLog('Failed to load configuration file: ' . parseLastError() . PHP_EOL, 'ERROR');
            exit(1);
        }

        unset($loadedConfig['CONFIG_FILE']);

        $config = array_merge($config, $loadedConfig);
    }

    validateConfig($config);
    $config['COMMENT'] = trim($config['COMMENT']);

    if (isset($options['no-gravity']) && $config['UPDATE_GRAVITY'] === true) {
        $config['UPDATE_GRAVITY'] = false;
    }

    if (isset($options['no-reload']) && $config['UPDATE_GRAVITY'] === false) {
        $config['UPDATE_GRAVITY'] = null;
    }

    if (isset($options['no-vacuum'])) {
        $config['VACUUM_DATABASE'] = false;
    }

    if (isset($options['verbose'])) {
        $config['VERBOSE'] = true;
    }

    if (isset($options['debug'])) {
        $config['DEBUG'] = true;
    }

    return $config;
}

/**
 * Acquire process lock
 *
 * @param string $lockfile
 * @param bool   $debug
 *
 * @return resource
 */
function acquireLock($lockfile, $debug = false)
{
    if (empty($lockfile)) {
        printAndLog('Lock file not defined!' . PHP_EOL, 'ERROR');
        exit(1);
    }

    if ($lock = @fopen($lockfile, 'wb+')) {
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            printAndLog('Another process is already running!' . PHP_EOL, 'ERROR');
            exit(6);
        }

        $debug === true && printAndLog('Acquired process lock through file: ' . $lockfile . PHP_EOL, 'DEBUG');

        return $lock;
    }

    printAndLog('Unable to access path or lock file: ' . $lockfile . PHP_EOL, 'ERROR');
    exit(1);
}

/**
 * Shutdown related tasks
 */
function shutdownCleanup()
{
    global $config, $lock;

    if ($config['DEBUG'] === true) {
        printAndLog('Releasing lock and removing lockfile: ' . $config['LOCK_FILE'] . PHP_EOL, 'DEBUG');
    }

    flock($lock, LOCK_UN) && fclose($lock) && unlink($config['LOCK_FILE']);
}

/**
 * Just print the header
 */
function printHeader()
{
    $header[] = 'Pi-hole\'s Lists Updater by Jack\'lul';
    $header[] = GITHUB_LINK;
    $offset   = ' ';

    $maxLen = 0;
    foreach ($header as $string) {
        $strlen                      = strlen($string);
        $strlen > $maxLen && $maxLen = $strlen;
    }

    foreach ($header as &$string) {
        $strlen = strlen($string);

        if ($strlen < $maxLen) {
            $diff = $maxLen - $strlen;
            $addL = ceil($diff / 2);
            $addR = $diff - $addL;

            $string = str_repeat(' ', $addL) . $string . str_repeat(' ', $addR);
        }

        $string = $offset . $string;
    }
    unset($string);

    printAndLog(trim($header[0]) . ' started' . PHP_EOL, 'INFO', true);
    print PHP_EOL . implode(PHP_EOL, $header) . PHP_EOL . PHP_EOL;
}

/**
 * Print debug information
 *
 * @param array $config
 * @param array $options
 */
function printDebugHeader($config, $options)
{
    printAndLog('Checksum: ' . md5_file(__FILE__) . PHP_EOL, 'DEBUG');
    printAndLog('Git branch: ' . getBranch($options, $config) . PHP_EOL, 'DEBUG');
    printAndLog('OS: ' . php_uname() . PHP_EOL, 'DEBUG');
    printAndLog('PHP: ' . PHP_VERSION . (ZEND_THREAD_SAFE ? '' : ' NTS') . PHP_EOL, 'DEBUG');
    printAndLog('SQLite: ' . (new PDO('sqlite::memory:'))->query('select sqlite_version()')->fetch()[0] . PHP_EOL, 'DEBUG');

    $piholeVersions = @file_get_contents('/etc/pihole/localversions') ?? '';
    $piholeVersions = explode(' ', $piholeVersions);
    $piholeBranches = @file_get_contents('/etc/pihole/localbranches') ?? '';
    $piholeBranches = explode(' ', $piholeBranches);

    if (count($piholeVersions) === 3 && count($piholeBranches) === 3) {
        printAndLog('Pi-hole Core: ' . $piholeVersions[0] . ' (' . $piholeBranches[0] . ')' . PHP_EOL, 'DEBUG');
        printAndLog('Pi-hole Web: ' . $piholeVersions[1] . ' (' . $piholeBranches[1] . ')' . PHP_EOL, 'DEBUG');
        printAndLog('Pi-hole FTL: ' . $piholeVersions[2] . ' (' . $piholeBranches[2] . ')' . PHP_EOL, 'DEBUG');
    } else {
        printAndLog('Pi-hole: version info unavailable, make sure files `localversions` and `localbranches` exist in `/etc/pihole` and are valid!' . PHP_EOL, 'WARNING');
    }

    ob_start();
    var_dump($config);
    printAndLog('Configuration: ' . preg_replace('/=>\s+/', ' => ', ob_get_clean()), 'DEBUG');

    ob_start();
    var_dump($options);
    printAndLog('Options: ' . preg_replace('/=>\s+/', ' => ', ob_get_clean()), 'DEBUG');

    print PHP_EOL;
}

/**
 * Register PDO logging class
 */
function registerPDOLogger()
{
    class LoggedPDOStatement extends PDOStatement
    {
        private $queryParameters = [];
        private $parsedQuery     = '';

        public function bindValue($parameter, $value, $data_type = PDO::PARAM_STR): bool
        {
            $this->queryParameters[$parameter] = [
                'value' => $value,
                'type'  => $data_type,
            ];

            return parent::bindValue($parameter, $value, $data_type);
        }

        public function bindParam($parameter, &$variable, $data_type = PDO::PARAM_STR, $length = null, $driver_options = null): bool
        {
            $this->queryParameters[$parameter] = [
                'value' => $variable,
                'type'  => $data_type,
            ];

            return parent::bindParam($parameter, $variable, $data_type, $length, $driver_options);
        }

        public function execute($input_parameters = null): bool
        {
            printAndLog('SQL Query: ' . $this->parseQuery() . PHP_EOL, 'DEBUG');

            return parent::execute($input_parameters);
        }

        private function parseQuery(): string
        {
            if (!empty($this->parsedQuery)) {
                return $this->parsedQuery;
            }

            $query = $this->queryString;
            foreach ($this->queryParameters as $parameter => $data) {
                switch ($data['type']) {
                    case PDO::PARAM_STR:
                        $value = '"' . $data['value'] . '"';
                        break;
                    case PDO::PARAM_INT:
                        $value = (int) $data['value'];
                        break;
                    case PDO::PARAM_BOOL:
                        $value = (bool) $data['value'];
                        break;
                    default:
                        $value = null;
                }

                $query = str_replace($parameter, $value, $query);
            }

            return $this->parsedQuery = $query;
        }
    }
}

/**
 * Open the database
 *
 * @param string $db_file
 * @param bool   $verbose
 * @param bool   $debug
 *
 * @return PDO
 */
function openDatabase($db_file, $verbose = true, $debug = false)
{
    $dbh = new PDO('sqlite:' . $db_file);
    $dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $dbh->exec('PRAGMA foreign_keys = ON;'); // Require foreign key constraints

    if ($debug) {
        !class_exists('LoggedPDOStatement') && registerPDOLogger();
        $dbh->setAttribute(PDO::ATTR_STATEMENT_CLASS, ['LoggedPDOStatement']);
    }

    if ($verbose) {
        clearstatcache();
        printAndLog('Opened gravity database: ' . $db_file . ' (' . formatBytes(filesize($db_file)) . ')' . PHP_EOL);
    }

    return $dbh;
}

/**
 * Convert text files from one-entry-per-line to array
 *
 * @param string $text
 *
 * @return array
 *
 * @noinspection OnlyWritesOnParameterInspection
 */
function textToArray($text)
{
    global $comments;

    $array    = preg_split('/\r\n|\r|\n/', $text);
    $comments = [];

    foreach ($array as $var => &$val) {
        // Ignore empty lines and those with only a comment
        if (empty($val) || strpos(trim($val), '#') === 0) {
            unset($array[$var]);
            continue;
        }

        $comment = '';

        // Extract value from lines ending with comment
        if (preg_match('/^(.*)\s+#\s*(\S.*)$/U', $val, $matches)) {
            list(, $val, $comment) = $matches;
        }

        $val                                = trim($val);
        !empty($comment) && $comments[$val] = trim($comment);
    }
    unset($val);

    return array_values($array);
}

/**
 * Parse last error from error_get_last()
 *
 * @param string $default
 *
 * @return string
 */
function parseLastError($default = 'Unknown error')
{
    $lastError = error_get_last();

    return preg_replace('/file_get_contents(.*): /U', '', trim($lastError['message'] ?? $default));
}

/**
 * @param int $bytes
 * @param int $precision
 *
 * @return string
 */
function formatBytes($bytes, $precision = 2)
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];

    $bytes = max($bytes, 0);
    $pow   = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow   = min($pow, count($units) - 1);
    $bytes /= (1 << (10 * $pow));

    return round($bytes, $precision) . ' ' . $units[$pow];
}

/** PROCEDURAL CODE STARTS HERE */
$startTime = microtime(true);
checkDependencies(); // Check script requirements
$options = parseOptions(); // Parse options
$config  = loadConfig($options); // Load config and process variables

// Exception handler, always log detailed information
set_exception_handler(
    function (Exception $e) use (&$config) {
        if ($config['DEBUG'] === false) {
            print 'Exception: ' . $e->getMessage() . PHP_EOL;
        }

        printAndLog($e . PHP_EOL, 'ERROR', $config['DEBUG'] === false);
        exit(1);
    }
);

$lock = acquireLock($config['LOCK_FILE'], $config['DEBUG']); // Make sure this is the only instance
register_shutdown_function('shutdownCleanup'); // Cleanup when script finishes

// Handle script interruption / termination
if (function_exists('pcntl_signal')) {
    declare (ticks = 1);

    function signalHandler($signo)
    {
        $definedConstants = get_defined_constants(true);
        $signame          = null;

        if (isset($definedConstants['pcntl'])) {
            foreach ($definedConstants['pcntl'] as $name => $num) {
                if ($num === $signo && strpos($name, 'SIG') === 0 && $name[3] !== '_') {
                    $signame = $name;
                }
            }
        }

        printAndLog(PHP_EOL . 'Interrupted by ' . ($signame ?? $signo) . PHP_EOL, 'NOTICE');
        exit(130);
    }

    pcntl_signal(SIGHUP, 'signalHandler');
    pcntl_signal(SIGTERM, 'signalHandler');
    pcntl_signal(SIGINT, 'signalHandler');
}

// This array holds some state data
$stat = [
    'errors'   => 0,
    'invalid'  => 0,
    'conflict' => 0,
];

// Hi
printHeader();

// Show warning when php-intl isn't installed
if (!extension_loaded('intl')) {
    printAndLog('Missing recommended PHP extension: intl' . PHP_EOL . PHP_EOL, 'WARNING');
}

// Show initial debug messages
$config['DEBUG'] === true && printDebugHeader($config, $options);

// Open the database
$dbh = openDatabase($config['GRAVITY_DB'], true, $config['DEBUG']);

print PHP_EOL;

// Make sure group exists
if (($absoluteGroupId = abs($config['GROUP_ID'])) > 0) {
    $sth = $dbh->prepare('SELECT `id` FROM `group` WHERE `id` = :id');
    $sth->bindParam(':id', $absoluteGroupId, PDO::PARAM_INT);

    if ($sth->execute() && $sth->fetch(PDO::FETCH_ASSOC) === false) {
        printAndLog('Group with ID=' . $absoluteGroupId . ' does not exist!' . PHP_EOL, 'ERROR');
        exit(1);
    }
}

// Helper function that checks if comment field matches when required
$checkIfTouchable = static function ($array) use (&$config) {
    return $config['REQUIRE_COMMENT'] === false || strpos($array['comment'] ?? '', $config['COMMENT']) !== false;
};

// Set download timeout
$streamContext = stream_context_create(
    [
        'http' => [
            'timeout'    => $config['DOWNLOAD_TIMEOUT'],
            'user_agent' => 'Pi-hole\'s Lists Updater (' . preg_replace('#^https?://#', '', rtrim(GITHUB_LINK, '/')) . ')',
        ],
    ]
);

// Fetch ADLISTS
if (!empty($config['ADLISTS_URL'])) {
    $multipleLists = false;

    // Fetch all adlists
    $adlistsAll = [];
    if (($sth = $dbh->prepare('SELECT * FROM `adlist`'))->execute()) {
        $adlistsAll = $sth->fetchAll(PDO::FETCH_ASSOC);

        $tmp = [];
        foreach ($adlistsAll as $key => $value) {
            $tmp[$value['id']] = $value;
        }

        $adlistsAll = $tmp;
        unset($tmp);
    }

    if (preg_match('/\s+/', trim($config['ADLISTS_URL']))) {
        $adlistsUrl    = preg_split('/\s+/', $config['ADLISTS_URL']);
        $multipleLists = true;

        $contents = '';
        foreach ($adlistsUrl as $url) {
            if (!empty($url)) {
                printAndLog('Fetching ADLISTS from \'' . $url . '\'...');

                $listContents = @file_get_contents($url, false, $streamContext);

                if ($listContents !== false) {
                    printAndLog(' done' . PHP_EOL);

                    $contents .= PHP_EOL . $listContents;
                } else {
                    if ($config['IGNORE_DOWNLOAD_FAILURE'] === false) {
                        printAndLog(' ' . parseLastError() . PHP_EOL, 'ERROR');

                        $stat['errors']++;
                        $contents = false;
                        break;
                    } else {
                        printAndLog(' ' . parseLastError() . PHP_EOL, 'WARNING');
                    }
                }
            }
        }

        $contents !== false && printAndLog('Merging multiple lists...');
    } else {
        printAndLog('Fetching ADLISTS from \'' . $config['ADLISTS_URL'] . '\'...');

        $contents = @file_get_contents($config['ADLISTS_URL'], false, $streamContext);
    }

    if ($contents !== false) {
        $adlists = textToArray($contents);
        printAndLog(' done (' . count($adlists) . ' entries)' . PHP_EOL);

        printAndLog('Processing...' . PHP_EOL);
        $dbh->beginTransaction();

        // Get enabled adlists managed by this script from the DB
        $sql = 'SELECT * FROM `adlist` WHERE `enabled` = 1';

        if ($config['REQUIRE_COMMENT'] === true) {
            $sth = $dbh->prepare($sql .= ' AND `comment` LIKE :comment');
            $sth->bindValue(':comment', '%' . $config['COMMENT'] . '%', PDO::PARAM_STR);
        } else {
            $sth = $dbh->prepare($sql);
        }

        // Fetch all enabled touchable adlists
        $enabledLists = [];
        if ($sth->execute()) {
            foreach ($sth->fetchAll(PDO::FETCH_ASSOC) as $adlist) {
                $enabledLists[$adlist['id']] = trim($adlist['address']);
            }
        }

        // Entries that no longer exist in remote list
        $removedLists = array_diff($enabledLists, $adlists);
        foreach ($removedLists as $id => $address) {
            // Disable entries instead of removing them
            $sth = $dbh->prepare('UPDATE `adlist` SET `enabled` = 0 WHERE `id` = :id');
            $sth->bindParam(':id', $id, PDO::PARAM_INT);

            if ($sth->execute()) {
                $adlistsAll[$id]['enabled'] = false;

                printAndLog('Disabled: ' . $address . PHP_EOL);
            }
        }

        // Helper function to check whenever adlist already exists
        $checkAdlistExists = static function ($address) use (&$adlistsAll) {
            $result = array_filter(
                $adlistsAll,
                static function ($array) use ($address) {
                    return isset($array['address']) && $array['address'] === $address;
                }
            );

            return count($result) === 1 ? array_values($result)[0] : false;
        };

        foreach ($adlists as $address) {
            // Check 'borrowed' from `scripts/pi-hole/php/groups.php` - 'add_adlist'
            if (!filter_var($address, FILTER_VALIDATE_URL) || preg_match('/[^a-zA-Z0-9$\\-_.+!*\'(),;\/?:@=&%]/', $address) !== 0) {
                printAndLog('Invalid: ' . $address . PHP_EOL);

                if (!isset($stat['invalids']) || !in_array($address, $stat['invalids'], true)) {
                    $stat['invalid']++;
                    $stat['invalids'][] = $address;
                }

                continue;
            }

            $adlistUrl = $checkAdlistExists($address);
            if ($adlistUrl === false) {
                // Add entry if it doesn't exist
                $sth = $dbh->prepare('INSERT INTO `adlist` (address, enabled, comment) VALUES (:address, 1, :comment)');
                $sth->bindParam(':address', $address, PDO::PARAM_STR);

                $comment = $config['COMMENT'];
                if (isset($comments[$address])) {
                    $comment = $comments[$address] . ($comment !== '' ? ' | ' . $comment : '');
                }
                $sth->bindParam(':comment', $comment, PDO::PARAM_STR);

                if ($sth->execute()) {
                    $lastInsertId = $dbh->lastInsertId();

                    // Insert this adlist into cached list of all adlists to prevent future duplicate errors
                    $adlistsAll[$lastInsertId] = [
                        'id'      => $lastInsertId,
                        'address' => $address,
                        'enabled' => true,
                        'comment' => $comment,
                    ];

                    if ($absoluteGroupId > 0) {
                        // Add to the specified group
                        $sth = $dbh->prepare('INSERT OR IGNORE INTO `adlist_by_group` (adlist_id, group_id) VALUES (:adlist_id, :group_id)');
                        $sth->bindParam(':adlist_id', $lastInsertId, PDO::PARAM_INT);
                        $sth->bindParam(':group_id', $absoluteGroupId, PDO::PARAM_INT);
                        $sth->execute();

                        if ($config['GROUP_ID'] < 0) {
                            // Remove from the default group
                            $sth = $dbh->prepare('DELETE FROM `adlist_by_group` WHERE adlist_id = :adlist_id AND group_id = :group_id');
                            $sth->bindParam(':adlist_id', $lastInsertId, PDO::PARAM_INT);
                            $sth->bindValue(':group_id', 0, PDO::PARAM_INT);
                            $sth->execute();
                        }
                    }

                    printAndLog('Inserted: ' . $address . PHP_EOL);
                }
            } else {
                $isTouchable          = $checkIfTouchable($adlistUrl);
                $adlistUrl['enabled'] = (bool) $adlistUrl['enabled'] === true;

                // Enable existing entry but only if it's managed by this script
                if ($adlistUrl['enabled'] !== true && $isTouchable === true) {
                    $sth = $dbh->prepare('UPDATE `adlist` SET `enabled` = 1 WHERE `id` = :id');
                    $sth->bindParam(':id', $adlistUrl['id'], PDO::PARAM_INT);

                    if ($sth->execute()) {
                        $adlistsAll[$adlistUrl['id']]['enabled'] = true;

                        printAndLog('Enabled: ' . $address . PHP_EOL);
                    }
                } elseif ($config['VERBOSE'] === true) {
                    // Show other entry states only in verbose mode
                    if ($adlistUrl['enabled'] !== false && $isTouchable === true) {
                        printAndLog('Exists: ' . $address . PHP_EOL);
                    } elseif ($isTouchable === false) {
                        printAndLog('Ignored: ' . $address . PHP_EOL);
                    }
                }
            }
        }

        $dbh->commit();
    } else {
        if ($multipleLists) {
            printAndLog('One of the lists failed to download, operation aborted!' . PHP_EOL, 'NOTICE');
        } else {
            printAndLog(' ' . parseLastError() . PHP_EOL, 'ERROR');
            $stat['errors']++;
        }
    }

    print PHP_EOL;
} elseif ($config['REQUIRE_COMMENT'] === true) {
    // In case user decides to unset the URL - disable previously added entries
    $sth = $dbh->prepare('SELECT `id` FROM `adlist` WHERE `comment` LIKE :comment AND `enabled` = 1 LIMIT 1');
    $sth->bindValue(':comment', '%' . $config['COMMENT'] . '%', PDO::PARAM_STR);

    if ($sth->execute() && count($sth->fetchAll()) > 0) {
        printAndLog('No remote list set for ADLISTS, disabling orphaned entries in the database...', 'NOTICE');

        $dbh->beginTransaction();
        $sth = $dbh->prepare('UPDATE `adlist` SET `enabled` = 0 WHERE `comment` LIKE :comment');
        $sth->bindValue(':comment', '%' . $config['COMMENT'] . '%', PDO::PARAM_STR);

        if ($sth->execute()) {
            printAndLog(' done' . PHP_EOL);
        }

        $dbh->commit();

        print PHP_EOL;
    }
}

// This array binds type of list to 'domainlist' table 'type' field, thanks to this we can use foreach loop instead of duplicating code
$domainListTypes = [
    'WHITELIST'       => 0,
    'REGEX_WHITELIST' => 2,
    'BLACKLIST'       => 1,
    'REGEX_BLACKLIST' => 3,
];

// Fetch all domains from domainlist
$domainsAll = [];
if (($sth = $dbh->prepare('SELECT * FROM `domainlist`'))->execute()) {
    $domainsAll = $sth->fetchAll(PDO::FETCH_ASSOC);

    $tmp = [];
    foreach ($domainsAll as $key => $value) {
        $tmp[$value['id']] = $value;
    }

    $domainsAll = $tmp;
    unset($tmp);
}

// Helper function to check whenever domain already exists
$checkDomainExists = static function ($domain) use (&$domainsAll) {
    $result = array_filter(
        $domainsAll,
        static function ($array) use ($domain) {
            return isset($array['domain']) && $array['domain'] === $domain;
        }
    );

    return count($result) === 1 ? array_values($result)[0] : false;
};

// Fetch DOMAINLISTS
foreach ($domainListTypes as $typeName => $typeId) {
    $url_key = $typeName . '_URL';

    if (!empty($config[$url_key])) {
        $multipleLists = false;

        if (preg_match('/\s+/', trim($config[$url_key]))) {
            $domainlistUrl = preg_split('/\s+/', $config[$url_key]);
            $multipleLists = true;

            $contents = '';
            foreach ($domainlistUrl as $url) {
                if (!empty($url)) {
                    printAndLog('Fetching ' . $typeName . ' from \'' . $url . '\'...');

                    $listContents = @file_get_contents($url, false, $streamContext);

                    if ($listContents !== false) {
                        printAndLog(' done' . PHP_EOL);

                        $contents .= PHP_EOL . $listContents;
                    } else {
                        if ($config['IGNORE_DOWNLOAD_FAILURE'] === false) {
                            printAndLog(' ' . parseLastError() . PHP_EOL, 'ERROR');

                            $stat['errors']++;
                            $contents = false;
                            break;
                        } else {
                            printAndLog(' ' . parseLastError() . PHP_EOL, 'WARNING');
                        }
                    }
                }
            }

            $contents !== false && printAndLog('Merging multiple lists...');
        } else {
            printAndLog('Fetching ' . $typeName . ' from \'' . $config[$url_key] . '\'...');

            $contents = @file_get_contents($config[$url_key], false, $streamContext);
        }

        if ($contents !== false) {
            $domainlist = textToArray($contents);
            printAndLog(' done (' . count($domainlist) . ' entries)' . PHP_EOL);

            printAndLog('Processing...' . PHP_EOL);
            $dbh->beginTransaction();

            // Get enabled domains of this type managed by this script from the DB
            $sql = 'SELECT * FROM `domainlist` WHERE `enabled` = 1 AND `type` = :type';

            if ($config['REQUIRE_COMMENT'] === false) {
                $sth = $dbh->prepare($sql);
            } else {
                $sth = $dbh->prepare($sql .= ' AND `comment` LIKE :comment');
                $sth->bindValue(':comment', '%' . $config['COMMENT'] . '%', PDO::PARAM_STR);
            }

            $sth->bindParam(':type', $typeId, PDO::PARAM_INT);

            // Fetch all enabled touchable domainlists
            $enabledDomains = [];
            if ($sth->execute()) {
                foreach ($sth->fetchAll(PDO::FETCH_ASSOC) as $domain) {
                    if (strpos($typeName, 'REGEX_') === false) {
                        $enabledDomains[$domain['id']] = strtolower(trim($domain['domain']));
                    } else {
                        $enabledDomains[$domain['id']] = trim($domain['domain']);
                    }
                }
            }

            // Entries that no longer exist in remote list
            $removedDomains = array_diff($enabledDomains, $domainlist);

            foreach ($removedDomains as $id => $domain) {
                // Disable entries instead of removing them
                $sth = $dbh->prepare('UPDATE `domainlist` SET `enabled` = 0 WHERE `id` = :id');
                $sth->bindParam(':id', $id, PDO::PARAM_INT);

                if ($sth->execute()) {
                    $domainsAll[$id]['enabled'] = false;

                    printAndLog('Disabled: ' . $domain . PHP_EOL);
                }
            }

            foreach ($domainlist as $domain) {
                if (strpos($typeName, 'REGEX_') === false) {
                    $domain = strtolower($domain);

                    // Conversion code 'borrowed' from `scripts/pi-hole/php/groups.php` - 'add_domain'
                    if (extension_loaded('intl')) {
                        $idn_domain = false;

                        if (defined('INTL_IDNA_VARIANT_UTS46')) {
                            $idn_domain = idn_to_ascii($domain, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);
                        }

                        if ($idn_domain === false && defined('INTL_IDNA_VARIANT_2003')) {
                            $idn_domain = idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_2003);
                        }

                        if ($idn_domain !== false) {
                            $domain = $idn_domain;
                        }
                    }

                    // Check 'borrowed' from `scripts/pi-hole/php/groups.php` - 'add_domain'
                    if (filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
                        printAndLog('Invalid: ' . $domain . PHP_EOL);

                        if (!isset($stat['invalids']) || !in_array($domain, $stat['invalids'], true)) {
                            $stat['invalid']++;
                            $stat['invalids'][] = $domain;
                        }

                        continue;
                    }
                }

                $domainlistDomain = $checkDomainExists($domain);
                if ($domainlistDomain === false) {
                    // Add entry if it doesn't exist
                    $sth = $dbh->prepare('INSERT INTO `domainlist` (domain, type, enabled, comment) VALUES (:domain, :type, 1, :comment)');
                    $sth->bindParam(':domain', $domain, PDO::PARAM_STR);
                    $sth->bindParam(':type', $typeId, PDO::PARAM_INT);

                    $comment = $config['COMMENT'];
                    if (isset($comments[$domain])) {
                        $comment = $comments[$domain] . ($comment !== '' ? ' | ' . $comment : '');
                    }
                    $sth->bindParam(':comment', $comment, PDO::PARAM_STR);

                    if ($sth->execute()) {
                        $lastInsertId = $dbh->lastInsertId();

                        // Insert this domain into cached list of all domains to prevent future duplicate errors
                        $domainsAll[$lastInsertId] = [
                            'id'      => $lastInsertId,
                            'domain'  => $domain,
                            'type'    => $typeId,
                            'enabled' => true,
                            'comment' => $comment,
                        ];

                        if ($absoluteGroupId > 0) {
                            // Add to the specified group
                            $sth = $dbh->prepare('INSERT OR IGNORE INTO `domainlist_by_group` (domainlist_id, group_id) VALUES (:domainlist_id, :group_id)');
                            $sth->bindParam(':domainlist_id', $lastInsertId, PDO::PARAM_INT);
                            $sth->bindParam(':group_id', $absoluteGroupId, PDO::PARAM_INT);
                            $sth->execute();

                            if ($config['GROUP_ID'] < 0) {
                                // Remove from the default group
                                $sth = $dbh->prepare('DELETE FROM `domainlist_by_group` WHERE domainlist_id = :domainlist_id AND group_id = :group_id');
                                $sth->bindParam(':domainlist_id', $lastInsertId, PDO::PARAM_INT);
                                $sth->bindValue(':group_id', 0, PDO::PARAM_INT);
                                $sth->execute();
                            }
                        }

                        printAndLog('Inserted: ' . $domain . PHP_EOL);
                    }
                } else {
                    $isTouchable                 = $checkIfTouchable($domainlistDomain);
                    $domainlistDomain['enabled'] = (bool) $domainlistDomain['enabled'] === true;
                    $domainlistDomain['type']    = (int) $domainlistDomain['type'];

                    // Enable existing entry but only if it's managed by this script
                    if ($domainlistDomain['type'] === $typeId && $domainlistDomain['enabled'] !== true && $isTouchable === true) {
                        $sth = $dbh->prepare('UPDATE `domainlist` SET `enabled` = 1 WHERE `id` = :id');
                        $sth->bindParam(':id', $domainlistDomain['id'], PDO::PARAM_INT);

                        if ($sth->execute()) {
                            $domainsAll[$domainlistDomain['id']]['enabled'] = true;

                            printAndLog('Enabled: ' . $domain . PHP_EOL);
                        }
                    } elseif ($domainlistDomain['type'] !== $typeId) {
                        printAndLog('Conflict: ' . $domain . ' (' . (array_search($domainlistDomain['type'], $domainListTypes, true) ?: 'type=' . $domainlistDomain['type']) . ')' . PHP_EOL, 'WARNING');
                        if (!isset($stat['conflicts']) || !in_array($domain, $stat['conflicts'], true)) {
                            $stat['conflict']++;
                            $stat['conflicts'][] = $domain;
                        }
                    } elseif ($config['VERBOSE'] === true) {
                        // Show other entry states only in verbose mode
                        if ($domainlistDomain['enabled'] !== false && $isTouchable === true) {
                            printAndLog('Exists: ' . $domain . PHP_EOL);
                        } elseif ($isTouchable === false) {
                            printAndLog('Ignored: ' . $domain . PHP_EOL);
                        }
                    }
                }
            }

            $dbh->commit();
        } else {
            if ($multipleLists) {
                printAndLog('One of the lists failed to download, operation aborted!' . PHP_EOL, 'NOTICE');
            } else {
                printAndLog(' ' . parseLastError() . PHP_EOL, 'ERROR');
                $stat['errors']++;
            }
        }

        print PHP_EOL;
    } elseif ($config['REQUIRE_COMMENT'] === true) {
        // In case user decides to unset the URL - disable previously added entries
        $sth = $dbh->prepare('SELECT id FROM `domainlist` WHERE `comment` LIKE :comment AND `enabled` = 1 AND `type` = :type LIMIT 1');
        $sth->bindValue(':comment', '%' . $config['COMMENT'] . '%', PDO::PARAM_STR);
        $sth->bindParam(':type', $typeId, PDO::PARAM_INT);

        if ($sth->execute() && count($sth->fetchAll()) > 0) {
            printAndLog('No remote list set for ' . $typeName . ', disabling orphaned entries in the database...', 'NOTICE');

            $dbh->beginTransaction();
            $sth = $dbh->prepare('UPDATE `domainlist` SET `enabled` = 0 WHERE `comment` LIKE :comment AND `type` = :type');
            $sth->bindValue(':comment', '%' . $config['COMMENT'] . '%', PDO::PARAM_STR);
            $sth->bindParam(':type', $typeId, PDO::PARAM_INT);

            if ($sth->execute()) {
                printAndLog(' done' . PHP_EOL);
            }

            $dbh->commit();

            print PHP_EOL;
        }
    }
}

// Update gravity (run `pihole updateGravity`) or sends signal to pihole-FTL to reload lists
if ($config['UPDATE_GRAVITY'] === true) {
    $sth = $dbh = null; // Close any database handles

    if ($config['DEBUG'] === true) {
        printAndLog('Closed database handles.' . PHP_EOL, 'DEBUG');
    }

    printAndLog('Updating Pi-hole\'s gravity...' . PHP_EOL);

    passthru('/usr/local/bin/pihole updateGravity', $return);

    if ($return !== 0) {
        printAndLog('Error occurred while updating gravity!' . PHP_EOL, 'ERROR');
        $stat['errors']++;
    } else {
        printAndLog('Done' . PHP_EOL, 'INFO', true);
    }
} elseif ($config['UPDATE_GRAVITY'] === false) {
    printAndLog('Sending reload signal to Pi-hole\'s DNS server...');

    exec('pidof pihole-FTL 2>/dev/null', $return);
    if (isset($return[0])) {
        $pid = $return[0];

        if (strpos($pid, ' ') !== false) {
            $pid = explode(' ', $pid);
            $pid = $pid[count($pid) - 1];
        }

        if (!defined('SIGRTMIN')) {
            $config['DEBUG'] === true && printAndLog('Signal SIGRTMIN is not defined!' . PHP_EOL, 'DEBUG');
            define('SIGRTMIN', 34);
        }

        if (posix_kill($pid, SIGRTMIN)) {
            printAndLog(' done' . PHP_EOL);
        } else {
            printAndLog(' failed to send signal' . PHP_EOL, 'ERROR');
            $stat['errors']++;
        }
    } else {
        printAndLog(' failed to find process PID' . PHP_EOL, 'ERROR');
        $stat['errors']++;
    }
}

// Vacuum database (run `VACUUM` command)
if ($config['VACUUM_DATABASE'] === true) {
    $dbh === null && $dbh = openDatabase($config['GRAVITY_DB'], $config['DEBUG'], $config['DEBUG']);

    printAndLog('Vacuuming database...');
    if ($dbh->query('VACUUM')) {
        clearstatcache();
        printAndLog(' done (' . formatBytes(filesize($config['GRAVITY_DB'])) . ')' . PHP_EOL);
    }

    $dbh = null;
}

if ($config['UPDATE_GRAVITY'] !== null || $config['VACUUM_DATABASE'] !== false) {
    print PHP_EOL;
}

if ($config['DEBUG'] === true) {
    printAndLog('Memory reached peak usage of ' . formatBytes(memory_get_peak_usage()) . PHP_EOL, 'DEBUG');
}

if ($stat['invalid'] > 0) {
    printAndLog('Ignored ' . $stat['invalid'] . ' invalid ' . ($stat['invalid'] === 1 ? 'entry' : 'entries') . '.' . PHP_EOL, 'NOTICE');
}

if ($stat['conflict'] > 0) {
    printAndLog('Found ' . $stat['conflict'] . ' conflicting ' . ($stat['conflict'] === 1 ? 'entry' : 'entries') . ' across your lists.' . PHP_EOL, 'NOTICE');
}

$elapsedTime = round(microtime(true) - $startTime, 2) . ' seconds';

if ($stat['errors'] > 0) {
    printAndLog('Finished with ' . $stat['errors'] . ' error(s) in ' . $elapsedTime . '.' . PHP_EOL);
    exit(1);
}

printAndLog('Finished successfully in ' . $elapsedTime . '.' . PHP_EOL);
