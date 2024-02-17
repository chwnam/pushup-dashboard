<?php
/**
 * 서버 배포, 로컬 동기화의 자동화를 담당하는 deployer 스크립트입니다.
 *
 * @noinspection PhpUnhandledExceptionInspection
 */

namespace Deployer;

use Changwoo\Pushup\Dashboard\Deployer\Config;
use Changwoo\Pushup\Dashboard\Deployer\REMOTE;
use Changwoo\Pushup\Dashboard\Deployer\LOCAL;

require_once __DIR__ . '/vendor/autoload.php';

if ('cli' !== php_sapi_name()) {
    die('This is a CLI script.');
}

const PLUGIN_NAME = 'pushup-dashboard';

$config = Config::load();
$config->setupGlobal(PLUGIN_NAME);
$config->setupLocal();
$config->setupRemote();

//task('whoami', function () {
//    writeln(run('whoami'));
//});

task('db:dump', function () {
    $dir = dirname(get(REMOTE::DUMP));
    if (!test("[ -d $dir ]")) {
        run("mkdir -p '$dir'");
    }
    cd('{{REMOTE_ROOT}}');
    run("{{REMOTE_WP}} db export - | {{REMOTE_GZIP}} -9 > {{REMOTE_DUMP}}");
})->desc('Dump remote WP tables into a gzipped SQL query file');

task('db:download', function () {
    if (!test('[ -f {{REMOTE_DUMP}} ]')) {
        error('The remote dump file does not exist. Please run the "db:dump" task first.');
    }

    $dir = dirname(get(LOCAL::DUMP));
    if (!testLocally("[ -d $dir ]")) {
        runLocally("mkdir -p $dir");
    }

    download('{{REMOTE_DUMP}}', '{{LOCAL_DUMP}}', ['options' => ['--bwlimit=1024']]);
})->desc('Download remote server\'s dump file to local');

task('db:implant', function () {
    runLocally('{{LOCAL_ZCAT}} < {{LOCAL_DUMP}} | {{LOCAL_WP}} db import -');
})->desc('Decompress the query file and push its contents to the local database');

task('db:search_replace', function () {
    $remoteHost = parse_url(get(REMOTE::URL), PHP_URL_HOST);
    $localHost  = parse_url(get(LOCAL::URL), PHP_URL_HOST);

    cd('{{LOCAL_ROOT}}');
    runLocally("{{LOCAL_WP}} search-replace '{{REMOTE_URL}}' '{{LOCAL_URL}}'");
    runLocally("{{LOCAL_WP}} search-replace '$remoteHost' '$localHost'");
})->desc('Search for remote URLs and replace with local URLs');

task('db:snr', ['db:search_replace'])->desc('Alias of db:search_replace');

task('plugin:build', function () {

})->desc('Run `yarn run build` on the remote');

task('plugin:update', function () {
    cd('{{REMOTE_ROOT}}');

})->desc('Run `git pull` on the remote');

task('plugin:update_build', ['plugin:update', 'plugin:build'])->desc(
    'Run plugin:update, and plugin:build respectively'
);

task('plugin:unb', ['plugin:update_build'])->desc('Alias of plugin:update_build');

task('sync:all', [
    'sync:plugins',
    'sync:theme',
    'sync:uploads',
    function () {
        chdir(parse('{{LOCAL_PLUGIN}}'));
        runLocally('git pull');
    },
    'sync:activation_deactivation',
])->desc('Do all sync tasks, including `git pull`');

task('sync:activation_deactivation', function () { })->desc('Activate or deactivate local Wordpress plugins');
task('sync:and', ['sync:activation_deactivation'])->desc('Alias of sync:activation_deactivation');
task('sync:uploads', function () { })->desc('Sync wp-content/uploads directory');
task('sync:plugins', function () { })->desc('Sync wp-content/plugins directory excluding our plugin');
task('sync:theme', function () { })->desc('Sync current theme directory');
