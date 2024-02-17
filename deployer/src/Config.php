<?php

namespace Changwoo\Pushup\Dashboard\Deployer;

use Deployer\Exception\Exception;
use Deployer\Host\Host;
use Deployer\Support\ObjectProxy;
use Dotenv\Dotenv;

use function Deployer\cd;
use function Deployer\host;
use function Deployer\run;
use function Deployer\runLocally;
use function Deployer\set;

class LOCAL
{
    public const COMPOSER = 'LOCAL_COMPOSER';
    public const DUMP     = 'LOCAL_DUMP';
    public const GIT      = 'LOCAL_GIT';
    public const PLUGIN   = 'LOCAL_PLUGIN';
    public const ROOT     = 'LOCAL_ROOT';
    public const RSYNC    = 'LOCAL_RSYNC';
    public const URL      = 'LOCAL_URL';
    public const WP       = 'LOCAL_WP';
    public const ZCAT     = 'LOCAL_ZCAT';
}

class REMOTE
{
    public const COMPOSER = 'REMOTE_COMPOSER';
    public const DUMP     = 'REMOTE_DUMP';
    public const GIT      = 'REMOTE_GIT';
    public const GZIP     = 'REMOTE_GZIP';
    public const PLUGIN   = 'REMOTE_PLUGIN';
    public const ROOT     = 'REMOTE_ROOT';
    public const RSYNC    = 'REMOTE_RSYNC';
    public const THEME    = 'REMOTE_THEME';
    public const URL      = 'REMOTE_URL';
    public const WP       = 'REMOTE_WP';
    public const YARN     = 'REMOTE_YARN';
}

class Config
{
    private string         $repository;
    private HostInfo       $local;
    private RemoteHostInfo $remote;

    public function __construct()
    {
        $this->repository = '';
        $this->local      = new HostInfo();
        $this->remote     = new RemoteHostInfo();
    }

    public static function load(string $dir = ''): Config
    {
        if (empty($dir)) {
            $dir = dirname(__DIR__);
        }

        $dotenv = Dotenv::createImmutable($dir);
        $dotenv->load();
        $dotenv->required(
            [
                'REPO',
                // Local.
                'LOCAL_DUMP',
                'LOCAL_ROOT',
                // Remote.
                'REMOTE_DUMP',
                'REMOTE_HOST',
                'REMOTE_ROOT',
                'REMOTE_USER',
            ]
        );

        $instance = new static();

        $instance->repository = $instance->getEnv('REPO');

        $instance->local->dump = $instance->getEnv('LOCAL_DUMP');
        $instance->local->root = $instance->getEnv('LOCAL_ROOT');
        $instance->local->url  = $instance->getEnv('LOCAL_URL');

        $instance->remote->dump = $instance->getEnv('REMOTE_DUMP');
        $instance->remote->host = $instance->getEnv('REMOTE_HOST');
        $instance->remote->port = (int)($instance->getEnv('REMOTE_PORT') ?: '22');
        $instance->remote->root = $instance->getEnv('REMOTE_ROOT');
        $instance->remote->user = $instance->getEnv('REMOTE_USER');
        $instance->remote->url  = $instance->getEnv('REMOTE_URL');

        return $instance;
    }

    /**
     * @throws Exception
     */
    public function setupGlobal(string $pluginName): void
    {
        set('repository', $this->repository);
        set('PLUGIN_NAME', $pluginName);
    }

    /**
     * LOCAL_COMPOSER
     * LOCAL_DUMP
     * LOCAL_GIT
     * LOCAL_PLUGIN
     * LOCAL_ROOT
     * LOCAL_RSYNC
     * LOCAL_URL
     * LOCAL_WP
     * LOCAL_ZCAT
     *
     * @throws Exception
     */
    public function setupLocal(): void
    {
        set(LOCAL::DUMP, $this->local->dump);
        set(LOCAL::ROOT, $this->local->root);

        $commands = [
            'composer',
            'git',
            'rsync',
            'wp',
            'zcat',
        ];

        foreach ($commands as $command) {
            set('LOCAL_' . strtoupper($command), fn() => runLocally("which $command"));
        }

        set(LOCAL::PLUGIN, '{{LOCAL_ROOT}}/wp-content/plugins/{{PLUGIN_NAME}}');
        set(LOCAL::URL, $this->local->url);
    }

    /**
     * REMOTE_COMPOSER
     * REMOTE_DUMP
     * REMOTE_GIT
     * REMOTE_GZIP
     * REMOTE_PLUGIN
     * REMOTE_ROOT
     * REMOTE_RSYNC
     * REMOTE_THEME
     * REMOTE_URL
     * REMOTE_WP
     * REMOTE_YARN
     */
    public function setupRemote(): Host|ObjectProxy
    {
        $remote = host($this->remote->host)
            ->set(REMOTE::DUMP, $this->remote->dump)
            ->set(REMOTE::ROOT, $this->remote->root)
            ->setRemoteUser($this->remote->user)
            ->setPort($this->remote->port)
            ->setDeployPath('{{REMOTE_ROOT}}/wp-content/plugins/{{PLUGIN_NAME}}')
        ;

        $commands = [
            'composer',
            'git',
            'gzip',
            'rsync',
            'wp',
            'yarn',
        ];

        foreach ($commands as $command) {
            $remote->set('REMOTE_' . strtoupper($command), fn() => run("which $command"));
        }

        $remote->set(REMOTE::PLUGIN, '{{REMOTE_ROOT}}/wp-content/plugins/{{PLUGIN_NAME}}');

        $remote->set(REMOTE::THEME, function () {
            cd('{{REMOTE_ROOT}}');
            return run('{{REMOTE_WP}} option get stylesheet');
        });

        $remote->set(REMOTE::URL, function () {
            if (empty($this->remote->url)) {
                cd('{{REMOTE_ROOT}}');
                return run('{{REMOTE_WP}} option get siteurl');
            } else {
                return $this->remote->url;
            }
        });

        return $remote;
    }

    private function getEnv(string $key): string
    {
        return $_SERVER[$key] ?? $_ENV[$key] ?? '';
    }
}
