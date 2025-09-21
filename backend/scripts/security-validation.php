<?php

/**
 * Razorpay Security Validation Script
 * 
 * This script performs automated security validation for the Razorpay integration
 * to ensure all security requirements are met before deployment.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Illuminate\Support\Facades\Config;

class RazorpaySecurityValidator
{
    private array $results = [];
    private int $passed = 0;
    private int $failed = 0;
    private int $warnings = 0;

    public function runValidation(): void
    {
        echo "🔒 Razorpay Security Validation\n";
        echo "==============================\n\n";

        $this->validateCredentialHandling();
        $this->validateSignatureVerification();
        $this->validateInputValidation();
        $this->validateErrorHandling();
        $this->validateConfigurationSecurity();
        $this->validateTestCoverage();

        $this->printSummary();
    }

    private function validateCredentialHandling(): void
    {
        echo "📋 Validating Credential Handling...\n";

        // Check environment variable usage
        $this->checkEnvironmentVariables();
        
        // Check configuration service
        $this->checkConfigurationService();
        
        // Check for credential exposure in code
        $this->checkCredentialExposure();
        
        echo "\n";
    }

    private function checkEnvironmentVariables(): void
    {
        $requiredVars = [
            'RAZORPAY_KEY_ID',
            'RAZORPAY_KEY_SECRET', 
            'RAZORPAY_WEBHOOK_SECRET'
        ];

        foreach ($requiredVars as $var) {
            if ($this->isEnvironmentVariableConfigured($var)) {
                $this->pass("✅ {$var} properly configured");
            } else {
                $this->warn("⚠️  {$var} not set (expected in production)");
            }
        }
    }

    private function checkConfigurationService(): void
    {
        $configFile = __DIR__ . '/../app/Services/Infrastructure/Razorpay/RazorpayConfigurationService.php';
        
        if (!file_exists($configFile)) {
            $this->fail("❌ RazorpayConfigurationService not found");
            return;
        }

        $content = file_get_contents($configFile);

        // Check for proper exception handling
        if (strpos($content, 'RazorpayConfigurationException') !== false) {
            $this->pass("✅ Proper exception handling implemented");
        } else {
            $this->fail("❌ Missing proper exception handling");
        }

        // Check for configuration summary method
        if (strpos($content, 'getConfigurationSummary') !== false) {
            $this->pass("✅ Configuration summary method exists");
        } else {
            $this->warn("⚠️  Configuration summary method not found");
        }
    }

    private function checkCredentialExposure(): void
    {
        $files = $this->getPhpFiles(__DIR__ . '/../app/Services');
        $exposureFound = false;

        foreach ($files as $file) {
            $content = file_get_contents($file);
            
            // Check for potential credential exposure in logs
            if (preg_match('/logger.*(?:key_secret|webhook_secret|RAZORPAY_KEY_SECRET|RAZORPAY_WEBHOOK_SECRET)/', $content)) {
                $this->fail("❌ Potential credential exposure in " . basename($file));
                $exposureFound = true;
            }
        }

        if (!$exposureFound) {
            $this->pass("✅ No credential exposure found in service files");
        }
    }

    private function validateSignatureVerification(): void
    {
        echo "🔐 Validating Signature Verification...\n";

        $clientFile = __DIR__ . '/../app/Services/Infrastructure/Razorpay/RazorpayClient.php';
        
        if (!file_exists($clientFile)) {
            $this->fail("❌ RazorpayClient not found");
            return;
        }

        $content = file_get_contents($clientFile);

        // Check for hash_equals usage (constant-time comparison)
        if (strpos($content, 'hash_equals') !== false) {
            $this->pass("✅ Constant-time comparison implemented");
        } else {
            $this->fail("❌ Missing constant-time comparison (timing attack vulnerability)");
        }

        // Check for HMAC SHA256 usage
        if (strpos($content, 'hash_hmac') !== false && strpos($content, 'sha256') !== false) {
            $this->pass("✅ HMAC SHA256 signature verification implemented");
        } else {
            $this->fail("❌ Missing proper HMAC SHA256 implementation");
        }

        // Check for proper error handling in signature verification
        if (strpos($content, 'verifyWebhookSignature') !== false && strpos($content, 'try') !== false) {
            $this->pass("✅ Error handling in signature verification");
        } else {
            $this->warn("⚠️  Error handling in signature verification may be incomplete");
        }

        echo "\n";
    }

    private function validateInputValidation(): void
    {
        echo "🛡️  Validating Input Validation...\n";

        // Check for request validation classes
        $requestFiles = glob(__DIR__ . '/../app/Http/Requests/**/Razorpay*.php');
        
        if (count($requestFiles) > 0) {
            $this->pass("✅ Razorpay request validation classes found");
        } else {
            $this->warn("⚠️  No specific Razorpay request validation classes found");
        }

        // Check for DTO usage
        $dtoFiles = glob(__DIR__ . '/../app/Services/Domain/Payment/Razorpay/DTOs/*.php');
        
        if (count($dtoFiles) > 0) {
            $this->pass("✅ DTOs implemented for type safety");
        } else {
            $this->warn("⚠️  DTOs not found for type safety");
        }

        echo "\n";
    }

    private function validateErrorHandling(): void
    {
        echo "⚠️  Validating Error Handling...\n";

        $exceptionFiles = glob(__DIR__ . '/../app/Exceptions/Razorpay/*.php');
        
        if (count($exceptionFiles) > 0) {
            $this->pass("✅ Custom Razorpay exceptions implemented");
        } else {
            $this->warn("⚠️  Custom Razorpay exceptions not found");
        }

        // Check for proper logging without sensitive data
        $serviceFiles = $this->getPhpFiles(__DIR__ . '/../app/Services/Domain/Payment/Razorpay');
        $properLogging = true;

        foreach ($serviceFiles as $file) {
            $content = file_get_contents($file);
            
            // Check if logging includes context but excludes sensitive data
            if (strpos($content, 'logger()') !== false || strpos($content, '$this->logger') !== false) {
                if (strpos($content, 'payment_id') !== false && strpos($content, 'signature') === false) {
                    // Good: logs payment_id but not signature
                    continue;
                } elseif (strpos($content, 'signature') !== false) {
                    $this->warn("⚠️  Potential signature logging in " . basename($file));
                    $properLogging = false;
                }
            }
        }

        if ($properLogging) {
            $this->pass("✅ Proper logging without sensitive data");
        }

        echo "\n";
    }

    private function validateConfigurationSecurity(): void
    {
        echo "⚙️  Validating Configuration Security...\n";

        // Check CORS configuration
        $corsFile = __DIR__ . '/../config/cors.php';
        if (file_exists($corsFile)) {
            $content = file_get_contents($corsFile);
            
            if (strpos($content, "'*'") !== false) {
                $this->warn("⚠️  CORS allows all origins (review for production)");
            } else {
                $this->pass("✅ CORS properly configured");
            }
        }

        // Check services configuration
        $servicesFile = __DIR__ . '/../config/services.php';
        if (file_exists($servicesFile)) {
            $content = file_get_contents($servicesFile);
            
            if (strpos($content, 'env(\'RAZORPAY_KEY_ID\')') !== false) {
                $this->pass("✅ Razorpay configuration uses environment variables");
            } else {
                $this->fail("❌ Razorpay configuration not using environment variables");
            }
        }

        echo "\n";
    }

    private function validateTestCoverage(): void
    {
        echo "🧪 Validating Test Coverage...\n";

        $securityTests = [
            'RazorpaySecurityReviewTest.php',
            'RazorpayVulnerabilityTest.php'
        ];

        foreach ($securityTests as $testFile) {
            $testPath = __DIR__ . '/../tests/Feature/Security/' . $testFile;
            
            if (file_exists($testPath)) {
                $this->pass("✅ {$testFile} exists");
            } else {
                $this->fail("❌ {$testFile} missing");
            }
        }

        // Check for unit tests
        $unitTestFiles = glob(__DIR__ . '/../tests/Unit/Services/Domain/Payment/Razorpay/*.php');
        
        if (count($unitTestFiles) > 0) {
            $this->pass("✅ Unit tests for Razorpay services found");
        } else {
            $this->warn("⚠️  Unit tests for Razorpay services not found");
        }

        echo "\n";
    }

    private function getPhpFiles(string $directory): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    private function isEnvironmentVariableConfigured(string $var): bool
    {
        // Check if variable is referenced in config files
        $configFiles = glob(__DIR__ . '/../config/*.php');
        
        foreach ($configFiles as $file) {
            $content = file_get_contents($file);
            if (strpos($content, $var) !== false) {
                return true;
            }
        }

        return false;
    }

    private function pass(string $message): void
    {
        echo $message . "\n";
        $this->passed++;
        $this->results[] = ['status' => 'pass', 'message' => $message];
    }

    private function fail(string $message): void
    {
        echo $message . "\n";
        $this->failed++;
        $this->results[] = ['status' => 'fail', 'message' => $message];
    }

    private function warn(string $message): void
    {
        echo $message . "\n";
        $this->warnings++;
        $this->results[] = ['status' => 'warn', 'message' => $message];
    }

    private function printSummary(): void
    {
        echo "📊 Security Validation Summary\n";
        echo "=============================\n";
        echo "✅ Passed: {$this->passed}\n";
        echo "❌ Failed: {$this->failed}\n";
        echo "⚠️  Warnings: {$this->warnings}\n\n";

        if ($this->failed === 0) {
            echo "🎉 Security validation completed successfully!\n";
            echo "The Razorpay integration meets all security requirements.\n\n";
        } else {
            echo "🚨 Security validation failed!\n";
            echo "Please address the failed checks before deployment.\n\n";
        }

        // Print failed items
        if ($this->failed > 0) {
            echo "❌ Failed Checks:\n";
            foreach ($this->results as $result) {
                if ($result['status'] === 'fail') {
                    echo "  - " . $result['message'] . "\n";
                }
            }
            echo "\n";
        }

        // Print warnings
        if ($this->warnings > 0) {
            echo "⚠️  Warnings (Review Recommended):\n";
            foreach ($this->results as $result) {
                if ($result['status'] === 'warn') {
                    echo "  - " . $result['message'] . "\n";
                }
            }
            echo "\n";
        }

        echo "For detailed security analysis, see: tests/Feature/Security/RazorpaySecurityAuditReport.md\n";
    }
}

// Run the validation
$validator = new RazorpaySecurityValidator();
$validator->runValidation();