<?php

namespace Tests\Config;

use CodeIgniter\Test\CIUnitTestCase;
use Config\App;

class AppTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        putenv('app.allowedHostnames');
        putenv('ALLOWED_HOSTNAMES');
        unset($_ENV['app.allowedHostnames'], $_ENV['ALLOWED_HOSTNAMES']);
        unset($_SERVER['app.allowedHostnames'], $_SERVER['ALLOWED_HOSTNAMES']);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        // Clean up environment
        is_cli(true); // MockCommon's is_cli() is a process-wide static; always restore
        putenv('CI_ENVIRONMENT');
        putenv('app.allowedHostnames');
        putenv('ALLOWED_HOSTNAMES');
        unset($_SERVER['HTTP_HOST']);
        unset($_ENV['app.allowedHostnames'], $_ENV['ALLOWED_HOSTNAMES']);
        unset($_SERVER['app.allowedHostnames'], $_SERVER['ALLOWED_HOSTNAMES']);
        $_SERVER['CI_ENVIRONMENT'] = 'testing';
    }

    public function testGetValidHostReturnsHostWhenValid(): void
    {
        $app = new class extends App {
            public array $allowedHostnames = ['example.com', 'www.example.com'];

            public function __construct() {}
        };

        $reflection = new \ReflectionClass($app);
        $method = $reflection->getMethod('getValidHost');
        $method->setAccessible(true);

        $_SERVER['HTTP_HOST'] = 'example.com';
        $host = $method->invoke($app);
        $this->assertEquals('example.com', $host);

        $_SERVER['HTTP_HOST'] = 'www.example.com';
        $host = $method->invoke($app);
        $this->assertEquals('www.example.com', $host);
    }

    public function testGetValidHostReturnsFallbackForInvalidHost(): void
    {
        $app = new class extends App {
            public array $allowedHostnames = ['example.com', 'www.example.com'];

            public function __construct() {}
        };

        $reflection = new \ReflectionClass($app);
        $method = $reflection->getMethod('getValidHost');
        $method->setAccessible(true);

        $_SERVER['HTTP_HOST'] = 'malicious.com';
        $host = $method->invoke($app);
        $this->assertEquals('example.com', $host);

        $_SERVER['HTTP_HOST'] = 'evil.org';
        $host = $method->invoke($app);
        $this->assertEquals('example.com', $host);
    }

    public function testGetValidHostReturnsLocalhostInDevelopmentWhenNoWhitelist(): void
    {
        // Set development environment
        putenv('CI_ENVIRONMENT=development');

        $app = new class extends App {
            public array $allowedHostnames = [];

            public function __construct() {}
        };

        $reflection = new \ReflectionClass($app);
        $method = $reflection->getMethod('getValidHost');
        $method->setAccessible(true);

        $_SERVER['HTTP_HOST'] = 'malicious.com';
        $host = $method->invoke($app);
        $this->assertEquals('localhost', $host);

        $_SERVER['HTTP_HOST'] = 'example.com';
        $host = $method->invoke($app);
        $this->assertEquals('localhost', $host);
    }

    public function testGetValidHostThrowsExceptionInProductionWhenNoWhitelist(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('allowedHostnames is not configured');

        $_SERVER['CI_ENVIRONMENT'] = 'production';
        putenv('CI_ENVIRONMENT=production');

        $app = new class extends App {
            public array $allowedHostnames = [];

            public function __construct() {}
        };

        $reflection = new \ReflectionClass($app);
        $method = $reflection->getMethod('getValidHost');
        $method->setAccessible(true);

        $_SERVER['HTTP_HOST'] = 'malicious.com';
        $method->invoke($app);
    }

    public function testGetValidHostHandlesMissingHttpHost(): void
    {
        $app = new class extends App {
            public array $allowedHostnames = ['example.com'];

            public function __construct() {}
        };

        $reflection = new \ReflectionClass($app);
        $method = $reflection->getMethod('getValidHost');
        $method->setAccessible(true);

        unset($_SERVER['HTTP_HOST']);
        $host = $method->invoke($app);
        $this->assertEquals('example.com', $host);
    }

    public function testBaseURLContainsValidHost(): void
    {
        $_SERVER['HTTP_HOST'] = 'example.com';
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['HTTPS'] = null;

        is_cli(false);
        try {
            $app = new class extends App {
                public array $allowedHostnames = ['example.com'];
            };

            $this->assertStringContainsString('example.com', $app->baseURL);
        } finally {
            is_cli(true);
        }
    }

    public function testBaseURLUsesFallbackHostWhenInvalidHostProvided(): void
    {
        $_SERVER['HTTP_HOST'] = 'malicious.com';
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['HTTPS'] = null;

        is_cli(false);
        try {
            $app = new class extends App {
                public array $allowedHostnames = ['example.com'];
            };

            $this->assertStringContainsString('example.com', $app->baseURL);
            $this->assertStringNotContainsString('malicious.com', $app->baseURL);
        } finally {
            is_cli(true);
        }
    }

    public function testEnvAllowedHostnamesParsedAsCommaSeparated(): void
    {
        // Set environment variable
        putenv('app.allowedHostnames=example.com,www.example.com,demo.example.com');

        $_SERVER['HTTP_HOST'] = 'www.example.com';
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['HTTPS'] = null;

        $app = $this->newAppInWebContext();

        // Constructor should parse comma-separated values
        $this->assertEquals(['example.com', 'www.example.com', 'demo.example.com'], $app->allowedHostnames);
        $this->assertStringContainsString('www.example.com', $app->baseURL);

        // Clean up
        putenv('app.allowedHostnames');
    }

    public function testEnvAllowedHostnamesTrimmedWhitespace(): void
    {
        // Set environment variable with whitespace
        putenv('app.allowedHostnames= example.com , www.example.com , demo.example.com ');

        $_SERVER['HTTP_HOST'] = 'example.com';
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['HTTPS'] = null;

        $app = new App();

        // Values should be trimmed
        $this->assertEquals(['example.com', 'www.example.com', 'demo.example.com'], $app->allowedHostnames);

        // Clean up
        putenv('app.allowedHostnames');
    }

    public function testEnvAllowedHostnamesSingleValue(): void
    {
        // Set environment variable with single value
        putenv('app.allowedHostnames=localhost');

        $_SERVER['HTTP_HOST'] = 'localhost';
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['HTTPS'] = null;

        $app = new App();

        // Single value should work
        $this->assertEquals(['localhost'], $app->allowedHostnames);
        $this->assertStringContainsString('localhost', $app->baseURL);

        // Clean up
        putenv('app.allowedHostnames');
    }

    public function testEnvAllowedHostnamesEmptyStringNotConfigured(): void
    {
        // Set environment variable to empty string
        putenv('app.allowedHostnames=');

        // Set development environment
        putenv('CI_ENVIRONMENT=development');

        $_SERVER['HTTP_HOST'] = 'example.com';
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['HTTPS'] = null;

        $app = new App();

        // Empty string should be treated as not configured
        $this->assertEquals([], $app->allowedHostnames);

        // In development, should fall back to localhost
        $this->assertStringContainsString('localhost', $app->baseURL);

        // Clean up
        putenv('app.allowedHostnames');
        putenv('CI_ENVIRONMENT');
    }

    public function testEnvAllowedHostnamesFiltersEmptyEntries(): void
    {
        // Trailing comma should not produce empty entry
        putenv('app.allowedHostnames=example.com,');
        $_SERVER['HTTP_HOST'] = 'example.com';
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['HTTPS'] = null;

        $app = new App();
        $this->assertEquals(['example.com'], $app->allowedHostnames);

        // Clean up
        putenv('app.allowedHostnames');

        // Leading comma should not produce empty entry
        putenv('app.allowedHostnames=,example.com');
        $_SERVER['HTTP_HOST'] = 'example.com';

        $app = new App();
        $this->assertEquals(['example.com'], $app->allowedHostnames);

        // Clean up
        putenv('app.allowedHostnames');

        // Whitespace-only entry should be filtered
        putenv('app.allowedHostnames=example.com, ,www.example.com');
        $_SERVER['HTTP_HOST'] = 'example.com';

        $app = new App();
        $this->assertEquals(['example.com', 'www.example.com'], $app->allowedHostnames);

        // Clean up
        putenv('app.allowedHostnames');

        // All-whitespace value should be treated as not configured
        putenv('CI_ENVIRONMENT=development');
        putenv('app.allowedHostnames= , , ');
        $_SERVER['HTTP_HOST'] = 'example.com';

        $app = new App();
        $this->assertEquals([], $app->allowedHostnames);
        $this->assertStringContainsString('localhost', $app->baseURL);

        // Clean up
        putenv('app.allowedHostnames');
        putenv('CI_ENVIRONMENT');
    }

    public function testAllowedHostnamesEnvVarParsedAsCommaSeparated(): void
    {
        // Set ALLOWED_HOSTNAMES environment variable
        putenv('ALLOWED_HOSTNAMES=example.com,www.example.com,demo.example.com');

        $_SERVER['HTTP_HOST'] = 'www.example.com';
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['HTTPS'] = null;

        $app = $this->newAppInWebContext();

        // Constructor should parse comma-separated values
        $this->assertEquals(['example.com', 'www.example.com', 'demo.example.com'], $app->allowedHostnames);
        $this->assertStringContainsString('www.example.com', $app->baseURL);

        // Clean up
        putenv('ALLOWED_HOSTNAMES');
    }

    public function testAllowedHostnamesEnvVarTakesPrecedenceOverDotEnv(): void
    {
        // Set both environment variables
        putenv('ALLOWED_HOSTNAMES=allowed1.com,allowed2.com');
        putenv('app.allowedHostnames=dotenv1.com,dotenv2.com');

        $_SERVER['HTTP_HOST'] = 'allowed1.com';
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['HTTPS'] = null;

        $app = $this->newAppInWebContext();

        // ALLOWED_HOSTNAMES should take precedence
        $this->assertEquals(['allowed1.com', 'allowed2.com'], $app->allowedHostnames);
        $this->assertStringContainsString('allowed1.com', $app->baseURL);

        // Clean up
        putenv('ALLOWED_HOSTNAMES');
        putenv('app.allowedHostnames');
    }

    public function testAllowedHostnamesEnvVarFallsBackToDotEnv(): void
    {
        // Only set app.allowedHostnames, not ALLOWED_HOSTNAMES
        putenv('app.allowedHostnames=dotenv1.com,dotenv2.com');

        $_SERVER['HTTP_HOST'] = 'dotenv1.com';
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['HTTPS'] = null;

        $app = $this->newAppInWebContext();

        // Should fall back to app.allowedHostnames
        $this->assertEquals(['dotenv1.com', 'dotenv2.com'], $app->allowedHostnames);
        $this->assertStringContainsString('dotenv1.com', $app->baseURL);

        // Clean up
        putenv('app.allowedHostnames');
    }

    public function testAllowedHostnamesEnvVarTrimmedWhitespace(): void
    {
        // Set environment variable with whitespace
        putenv('ALLOWED_HOSTNAMES= example.com , www.example.com , demo.example.com ');

        $_SERVER['HTTP_HOST'] = 'example.com';
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['HTTPS'] = null;

        $app = $this->newAppInWebContext();

        // Values should be trimmed
        $this->assertEquals(['example.com', 'www.example.com', 'demo.example.com'], $app->allowedHostnames);

        // Clean up
        putenv('ALLOWED_HOSTNAMES');
    }

    public function testAllowedHostnamesEnvVarFiltersEmptyEntries(): void
    {
        // Trailing comma should not produce empty entry
        putenv('ALLOWED_HOSTNAMES=example.com,');
        $_SERVER['HTTP_HOST'] = 'example.com';
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['HTTPS'] = null;

        $app = new App();
        $this->assertEquals(['example.com'], $app->allowedHostnames);

        // Clean up
        putenv('ALLOWED_HOSTNAMES');

        // Whitespace-only entry should be filtered
        putenv('ALLOWED_HOSTNAMES=example.com, ,www.example.com');
        $_SERVER['HTTP_HOST'] = 'example.com';

        $app = new App();
        $this->assertEquals(['example.com', 'www.example.com'], $app->allowedHostnames);

        // Clean up
        putenv('ALLOWED_HOSTNAMES');
    }

    public function testEnvAllowedHostnamesLiteralFalse(): void
    {
        putenv('app.allowedHostnames=false');

        $_SERVER['HTTP_HOST'] = 'false';
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['HTTPS'] = null;

        $app = new App();

        $this->assertEquals(['false'], $app->allowedHostnames);

        putenv('app.allowedHostnames');
    }

    public function testEnvAllowedHostnamesLiteralNull(): void
    {
        putenv('app.allowedHostnames=null');

        $_SERVER['HTTP_HOST'] = 'null';
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['HTTPS'] = null;

        $app = new App();

        $this->assertEquals(['null'], $app->allowedHostnames);

        putenv('app.allowedHostnames');
    }

    public function testEnvAllowedHostnamesWhitespaceOnly(): void
    {
        putenv('app.allowedHostnames=   ');
        putenv('CI_ENVIRONMENT=development');

        $_SERVER['HTTP_HOST'] = 'example.com';
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['HTTPS'] = null;

        $app = new App();

        $this->assertEquals([], $app->allowedHostnames);
        $this->assertStringContainsString('localhost', $app->baseURL);

        putenv('app.allowedHostnames');
        putenv('CI_ENVIRONMENT');
    }

    /**
     * Captures the current app.baseURL environment so it can be restored afterward.
     *
     * @return array{0: string|null, 1: string|null, 2: string|false}
     */
    private function backupBaseURLEnv(): array
    {
        return [$_ENV['app.baseURL'] ?? null, $_SERVER['app.baseURL'] ?? null, getenv('app.baseURL')];
    }

    /**
     * Restores the app.baseURL environment captured by backupBaseURLEnv().
     *
     * @param array{0: string|null, 1: string|null, 2: string|false} $backup
     */
    private function restoreBaseURLEnv(array $backup): void
    {
        [$env, $server, $getenv] = $backup;

        if ($getenv === false) {
            putenv('app.baseURL');
        } else {
            putenv('app.baseURL=' . $getenv);
        }

        if ($env === null) {
            unset($_ENV['app.baseURL']);
        } else {
            $_ENV['app.baseURL'] = $env;
        }

        if ($server === null) {
            unset($_SERVER['app.baseURL']);
        } else {
            $_SERVER['app.baseURL'] = $server;
        }
    }

    /**
     * Instantiates App as if handling a real web request.
     *
     * The test bootstrap loads MockCommon::is_cli(), which reports true by default;
     * flipping it to false lets the web derivation branch run under PHPUnit.
     */
    private function newAppInWebContext(): App
    {
        is_cli(false);

        try {
            return new App();
        } finally {
            is_cli(true);
        }
    }

    public function testBaseURLKeepsConfiguredValueUnderCliWindowsScriptName(): void
    {
        $backup = $this->backupBaseURLEnv();
        unset($_ENV['app.baseURL'], $_SERVER['app.baseURL']);
        putenv('app.baseURL=http://configured.test/');

        $_SERVER['HTTP_HOST'] = 'unknown.test';
        $_SERVER['SCRIPT_NAME'] = 'D:\\positions\\repo\\vendor\\bin\\phpunit';
        $_SERVER['HTTPS'] = null;
        putenv('app.allowedHostnames=localhost');

        try {
            $app = new App();

            $this->assertSame('http://configured.test/', $app->baseURL);
        } finally {
            $this->restoreBaseURLEnv($backup);
        }
    }

    public function testBaseURLKeepsConfiguredValueUnderCliLinuxScriptName(): void
    {
        $backup = $this->backupBaseURLEnv();
        unset($_ENV['app.baseURL'], $_SERVER['app.baseURL']);
        putenv('app.baseURL=http://configured.test/');

        $_SERVER['HTTP_HOST'] = 'unknown.test';
        $_SERVER['SCRIPT_NAME'] = '/home/user/repo/vendor/bin/phpunit';
        $_SERVER['HTTPS'] = null;
        putenv('app.allowedHostnames=localhost');

        try {
            $app = new App();

            $this->assertSame('http://configured.test/', $app->baseURL);
        } finally {
            $this->restoreBaseURLEnv($backup);
        }
    }

    public function testBaseURLDerivedForRootWebRequest(): void
    {
        $_SERVER['HTTP_HOST'] = 'example.com';
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['HTTPS'] = null;
        putenv('app.allowedHostnames=example.com');

        $app = $this->newAppInWebContext();

        $this->assertSame('http://example.com//', $app->baseURL);
    }

    public function testBaseURLDerivedForSubdirectoryWebRequest(): void
    {
        $_SERVER['HTTP_HOST'] = 'example.com';
        $_SERVER['SCRIPT_NAME'] = '/ospos/index.php';
        $_SERVER['HTTPS'] = null;
        putenv('app.allowedHostnames=example.com');

        $app = $this->newAppInWebContext();

        $this->assertSame('http://example.com//ospos/', $app->baseURL);
    }
}
