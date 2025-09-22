#!/usr/bin/env node

/**
 * Branding Verification Script
 * 
 * This script verifies that the Eventforce branding has been properly implemented
 * across all key UI components by checking the source code.
 */

import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

console.log('🔍 Verifying Eventforce Branding Implementation...\n');

// Test results tracking
const results = {
  passed: 0,
  failed: 0,
  tests: []
};

function test(description, assertion) {
  try {
    if (assertion()) {
      console.log(`✅ ${description}`);
      results.passed++;
      results.tests.push({ description, status: 'PASS' });
    } else {
      console.log(`❌ ${description}`);
      results.failed++;
      results.tests.push({ description, status: 'FAIL' });
    }
  } catch (error) {
    console.log(`❌ ${description} - Error: ${error.message}`);
    results.failed++;
    results.tests.push({ description, status: 'ERROR', error: error.message });
  }
}

// Helper function to read file content
function readFile(filePath) {
  const fullPath = path.join(__dirname, '../../..', filePath);
  if (!fs.existsSync(fullPath)) {
    throw new Error(`File not found: ${filePath}`);
  }
  return fs.readFileSync(fullPath, 'utf8');
}

// Helper function to check if file exists
function fileExists(filePath) {
  const fullPath = path.join(__dirname, '../../..', filePath);
  return fs.existsSync(fullPath);
}

console.log('📋 Environment Configuration Tests');
console.log('─'.repeat(50));

test('Environment file contains VITE_APP_NAME=Eventforce', () => {
  const envContent = readFile('.env');
  return envContent.includes('VITE_APP_NAME=Eventforce');
});

test('Environment file contains VITE_APP_LOGO_DARK=/eventforce.svg', () => {
  const envContent = readFile('.env');
  return envContent.includes('VITE_APP_LOGO_DARK=/eventforce.svg');
});

test('Environment file contains VITE_APP_LOGO_LIGHT=/eventforce.svg', () => {
  const envContent = readFile('.env');
  return envContent.includes('VITE_APP_LOGO_LIGHT=/eventforce.svg');
});

console.log('\n🖼️  Logo Asset Tests');
console.log('─'.repeat(50));

test('Eventforce SVG logo exists in public directory', () => {
  return fileExists('public/eventforce.svg');
});

test('Eventforce PNG logo exists in public directory', () => {
  return fileExists('public/eventforce_logocolor@4x.png');
});

console.log('\n🏗️  Component Implementation Tests');
console.log('─'.repeat(50));

test('AuthLayout uses getConfig for logo source', () => {
  const authLayoutContent = readFile('src/components/layouts/AuthLayout/index.tsx');
  return authLayoutContent.includes('getConfig("VITE_APP_LOGO_DARK"') &&
         authLayoutContent.includes('getConfig("VITE_APP_NAME"');
});

test('AuthLayout displays Eventforce logo alt text', () => {
  const authLayoutContent = readFile('src/components/layouts/AuthLayout/index.tsx');
  return authLayoutContent.includes('${getConfig("VITE_APP_NAME"') &&
         authLayoutContent.includes('logo`');
});

test('Sidebar uses getConfig for logo source', () => {
  const sidebarContent = readFile('src/components/layouts/AppLayout/Sidebar/index.tsx');
  return sidebarContent.includes('getConfig("VITE_APP_LOGO_LIGHT"') &&
         sidebarContent.includes('getConfig("VITE_APP_NAME"');
});

test('Sidebar displays Eventforce logo alt text', () => {
  const sidebarContent = readFile('src/components/layouts/AppLayout/Sidebar/index.tsx');
  return sidebarContent.includes('${getConfig("VITE_APP_NAME"') &&
         sidebarContent.includes('logo`');
});

test('ErrorDisplay uses getConfig for logo source', () => {
  const errorDisplayContent = readFile('src/components/common/ErrorDisplay/index.tsx');
  return errorDisplayContent.includes('getConfig("VITE_APP_LOGO_DARK"') &&
         errorDisplayContent.includes('getConfig("VITE_APP_NAME"');
});

test('ErrorDisplay displays Eventforce logo alt text', () => {
  const errorDisplayContent = readFile('src/components/common/ErrorDisplay/index.tsx');
  return errorDisplayContent.includes('getConfig("VITE_APP_NAME"') &&
         errorDisplayContent.includes('Logo"');
});

test('GenericErrorPage uses getConfig for logo source', () => {
  const genericErrorContent = readFile('src/components/common/GenericErrorPage/index.tsx');
  return genericErrorContent.includes('getConfig("VITE_APP_LOGO_DARK"') &&
         genericErrorContent.includes('getConfig("VITE_APP_NAME"');
});

test('GenericErrorPage displays Eventforce logo alt text', () => {
  const genericErrorContent = readFile('src/components/common/GenericErrorPage/index.tsx');
  return genericErrorContent.includes('getConfig("VITE_APP_NAME"') &&
         genericErrorContent.includes('Logo"');
});

console.log('\n🎨 Responsive Design Tests');
console.log('─'.repeat(50));

test('Sidebar logo has responsive styling', () => {
  const sidebarContent = readFile('src/components/layouts/AppLayout/Sidebar/index.tsx');
  return sidebarContent.includes('maxWidth: \'160px\'') &&
         sidebarContent.includes('margin: "10px auto"');
});

test('Error page logos have proper sizing', () => {
  const errorDisplayContent = readFile('src/components/common/ErrorDisplay/index.tsx');
  const genericErrorContent = readFile('src/components/common/GenericErrorPage/index.tsx');
  return errorDisplayContent.includes('w={rem(140)}') &&
         genericErrorContent.includes('w={rem(140)}');
});

console.log('\n📊 Test Summary');
console.log('─'.repeat(50));
console.log(`Total Tests: ${results.passed + results.failed}`);
console.log(`✅ Passed: ${results.passed}`);
console.log(`❌ Failed: ${results.failed}`);

if (results.failed > 0) {
  console.log('\n❌ Failed Tests:');
  results.tests
    .filter(test => test.status !== 'PASS')
    .forEach(test => {
      console.log(`   • ${test.description}`);
      if (test.error) {
        console.log(`     Error: ${test.error}`);
      }
    });
  process.exit(1);
} else {
  console.log('\n🎉 All branding tests passed! Eventforce branding is properly implemented.');
  process.exit(0);
}