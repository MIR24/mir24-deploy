<?php

namespace Deployer;

set('sphinx', [
    'host' => '127.0.0.1',
    'port' => 9312,
    'prefix' => 'mir24_',
    //path to Sphinx config for indexer util
    'config' => '',
]);

function getSphinxParam($param, $default = '')
{
    $config = get('sphinx', []);
    return $config[$param] ?? $default;
}

set('bin/indexer', function () {
    return run('which indexer');
});

set('sphinx_host', function () {
    return getSphinxParam('host');
});

set('sphinx_port', function () {
    return getSphinxParam('port');
});

set('sphinx_prefix', function () {
    return getSphinxParam('prefix');
});

set('sphinx_config_param', function () {
    $configPath = getSphinxParam('config');
    return empty($configPath) ? '' : "--config {$configPath}";
});

desc('Infect app configuration with sphinx credentials');
task('config:sphinx', function () {
    run("sed -i -E 's/SPHINX_HOST=.*/SPHINX_HOST={{sphinx_host}}/g' {{release_path}}/.env");
    run("sed -i -E 's/SPHINX_PORT=.*/SPHINX_PORT={{sphinx_port}}/g' {{release_path}}/.env");
    run("sed -i -E 's/SPHINX_PREFIX=.*/SPHINX_PREFIX={{sphinx_prefix}}/g' {{release_path}}/.env");
});

desc('Reindex sphinx');
task('sphinx:index', function () {
    run('sudo -H -u sphinxsearch {{bin/indexer}} --rotate --all --quiet {{sphinx_config_param}}');
});
