#!/usr/bin/env php
<?php
/**
 * The MIT License
 *
 * Copyright (c) 2014 Nikolay Bondarenko
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 *
 * PHP version 5.4
 *
 * @package Ko
 * @author Nikolay Bondarenko
 * @copyright 2014 Nikolay Bondarenko. All rights reserved.
 * @license MIT http://opensource.org/licenses/MIT
 */
use Ulrichsg\Getopt\Getopt;
use Ulrichsg\Getopt\Option;

define('KO_PACKAGE_VERSION', '0.0.1');

$files = [
    __DIR__ . '/../../../../autoload.php',
    __DIR__ . '/../../../autoload.php',
    __DIR__ . '/../../autoload.php',
    __DIR__ . '/../autoload.php',
    __DIR__ . '/vendor/autoload.php'
];

foreach ($files as $file) {
    if (file_exists($file)) {
        break;
    }
}

if (empty($file)) {
    die(
        'You need to set up the project dependencies using the following commands:' . PHP_EOL .
        'wget http://getcomposer.org/composer.phar' . PHP_EOL .
        'php composer.phar install' . PHP_EOL
    );
}

/** @noinspection PhpIncludeInspection */
require_once $file;

$cmdArguments = [
    (new Option('p', 'path', Getopt::REQUIRED_ARGUMENT))
        ->setDescription('Path to application code')
        ->setValidation(function($value) {
            return file_exists($value);
        }),
    (new Option('o', 'output', Getopt::OPTIONAL_ARGUMENT))
        ->setDefaultValue('app.phar')
        ->setDescription('Output file name'),
    (new Option('v', 'version', Getopt::OPTIONAL_ARGUMENT))
        ->setDefaultValue('1.0.0')
        ->setDescription('Application version'),
    (new Option('n', 'name', Getopt::OPTIONAL_ARGUMENT))
        ->setDefaultValue('MyApp')
        ->setDescription('Application name'),
    (new Option('e', 'exclude', Getopt::OPTIONAL_ARGUMENT))
        ->setDefaultValue('')
        ->setDescription('Excluded folders like logs, test, etc.'),
];

$opts = new Getopt($cmdArguments);
$opts->setBanner(sprintf("ko-package version %s)", KO_PACKAGE_VERSION) . PHP_EOL);

try {
    $opts->parse();
} catch (\UnexpectedValueException $e) {
    showAbout($opts);
};

if (empty($opts['path'])) {
    showAbout($opts);
}

$files = [];
$exclude = explode(',', $opts['exclude']);

/** @var SplFileInfo[] $rd */
$rd = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($opts['path']));
foreach ($rd as $file) {
    if (!$file->isFile()) {
        continue;
    }

    $path = $file->getPathname();
    foreach ($exclude as $ignorePattern) {
        if (strpos($path, $ignorePattern) === 0) {
            continue 2;
        }
    }
    $files[substr($path, 2)] = $path;
}

$stub = <<<FILE
    require_once './vendor/autoload.php';

    \$app = new \Ko\Worker\Application();
    \$app->setProcessManager(new \Ko\ProcessManager())
        ->setVersion('{$opts['version']}')
        ->setName('{$opts['name']}')
        ->run()
FILE;

$phar = new Phar($opts['output'], 0, $opts['output']);
$phar->buildFromIterator(new ArrayIterator($files));
$phar->addFromString('ko', $stub);
$phar->setStub($phar->createDefaultStub('ko'));
$phar->setSignatureAlgorithm(Phar::SHA1);
$phar->compressFiles(Phar::GZ);

$sig = $phar->getSignature();
echo sprintf('%s signature %s (%s)', $opts['output'], $sig['hash'], $sig['hash_type']) . PHP_EOL;

/**
 * @param Getopt $opts
 */
function showAbout($opts) {
    echo $opts->getHelpText();
    exit;
}