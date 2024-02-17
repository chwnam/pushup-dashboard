<?php

namespace Changwoo\Pushup\Dashboard\Deployer;

class HostInfo
{
    /** SSH host */
    public string $host = '';

    /** SSH user */
    public string $user = '';

    /** SQL dump path */
    public string $dump = '';

    /** WP root */
    public string $root = '';

    /** WordPress URL, including http(s):// */
    public string $url = '';
}
