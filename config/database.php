
<?php

/**
 * HumanMatrix Database Loader
 *
 * Local development:
 *   config/database.local.php
 *
 * Production:
 *   External private credentials file configured through
 *   HUMANMATRIX_DB_CONFIG.
 */

$environment = getenv('HUMANMATRIX_ENV');

$environment = ($environment === false || $environment === '')
    ? 'local'
    : strtolower(trim($environment));

try {

    if ($environment === 'production') {

        // Production must use an external private credentials file.
        $credentialsFile = getenv('HUMANMATRIX_DB_CONFIG');

        if (
            $credentialsFile === false ||
            $credentialsFile === '' ||
            $credentialsFile[0] !== DIRECTORY_SEPARATOR
        ) {
            throw new RuntimeException(
                'Production database configuration is missing.'
            );
        }

    } elseif (in_array($environment, ['local', 'development'], true)) {

        // Local development credentials.
        $credentialsFile = __DIR__ . '/database.local.php';

    } else {

        throw new RuntimeException(
            'Invalid application environment.'
        );
    }

    if (
        !is_file($credentialsFile) ||
        !is_readable($credentialsFile)
    ) {
        throw new RuntimeException(
            'Database credentials file is unavailable.'
        );
    }

    $config = require $credentialsFile;

    if (!is_array($config)) {
        throw new RuntimeException(
            'Invalid database credentials configuration.'
        );
    }

    foreach (['host', 'dbname', 'username', 'password'] as $key) {
        if (!isset($config[$key]) || !is_string($config[$key])) {
            throw new RuntimeException(
                'Invalid database configuration.'
            );
        }
    }

    $host = $config['host'];
    $dbname = $config['dbname'];
    $username = $config['username'];
    $password = $config['password'];

    if ($host === '' || $dbname === '' || $username === '') {
        throw new RuntimeException(
            'Required database configuration is empty.'
        );
    }

    $dsn = "mysql:host={$host};dbname={$dbname};charset=utf8mb4";

    $pdo = new PDO(
        $dsn,
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]
    );

} catch (Throwable $e) {

    // Technical details stay in the server's private error log.
    error_log(
        '[HumanMatrix] Database initialization failed: '
        . $e->getMessage()
    );

    http_response_code(500);

    exit('A temporary application error occurred. Please try again later.');
}
