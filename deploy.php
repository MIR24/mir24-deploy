<?php
namespace Deployer;

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/recipe/laravel.php';
require __DIR__ . '/recipe/db.php';
require 'recipe/rsync.php';

$releaseDate = date('d_M_H_i');

$hostsDev = 'hosts.dev.yml';
$hostsProd = 'hosts.yml';
$hostsInventory = (file_exists($hostsDev) && is_readable($hostsDev)) ? $hostsDev : $hostsProd;
inventory($hostsInventory);

function escapeForSed($value) {
    return addcslashes($value, '/|&?!"\'');
}

set('release_name', function () use ($releaseDate) {
    return $releaseDate;
});

set('ssh_multiplexing', true);

set('default_timeout', 1800);
set('copy_dirs', ['vendor']);

// Project name
set('application', 'my_project');

// Project repository
set('repository', '');
set('git_cache', true);

set('bin/npm', function () {
    return run('which npm');
});

// Tasks

desc('Deploy complete process');
task('deploy', [
    'deploy:info',
    'deploy:prepare',
    'deploy:lock',
    'deploy:release',
    'db:create',
    'db:pipe',
    'deploy:update_code',
    'deploy:shared',
    'config:clone',
    'config:services',
    'config:inject',
    'sphinx:inject',
    'deploy:copy_dirs',
    'deploy:vendors',
    'npm:install',
    'tsd:install',
    'npm:build',
    'gulp',
    'gulp:switch',
    'rsync:setup',
    'rsync',
    'artisan:storage:link',
    'artisan:cache:clear',
    'artisan:key:generate',
    'artisan:config:cache',
    'artisan:optimize',
    'artisan:migrate',
    'symlink:uploaded',
    'deploy:permissions',
    'deploy:clear_paths',
    'memcached:restart',
    'deploy:symlink',
    'deploy:unlock',
    'cleanup',
    'success',
]);

after('deploy', 'sphinx:index');

desc('Build release');
task('release:build', [
    'deploy:info',
    'deploy:prepare',
    'deploy:lock',
    'deploy:release',
    'db:create',
    'db:pipe',
    'deploy:update_code',
    'deploy:shared',
    'config:clone',
    'config:services',
    'config:inject',
    'sphinx:inject',
    'deploy:copy_dirs',
    'deploy:vendors',
    'npm:install',
    'tsd:install',
    'npm:build',
    'gulp',
    'gulp:switch',
    'rsync:setup',
    'rsync',
    'artisan:storage:link',
    'artisan:cache:clear',
    'artisan:key:generate',
    'artisan:config:cache',
    'artisan:optimize',
    'artisan:migrate',
    'symlink:uploaded',
    'deploy:permissions',
    'deploy:clear_paths',
    'deploy:unlock'
]);

desc('Switch to release built');
task('release:switch', [
    'deploy:lock',
    'config:switch',
    'artisan:config:cache',
    'deploy:symlink',
    'memcached:restart',
    'deploy:unlock',
    'cleanup',
    'success'
]);

task('hotfix', [
    'deploy:info',
    'deploy:prepare',
    'deploy:lock',
    'deploy:release',
    'rsync:warmup',
    'pull_code',
    'deploy:vendors',
    'deploy:shared',
    'npm:install',
    'tsd:install',
    'npm:build',
    'gulp',
    'gulp:switch',
    'rsync:setup',
    'rsync',
    'artisan:storage:link',
    'deploy:permissions',
    'artisan:cache:clear',
    'artisan:key:generate',
    'artisan:config:cache',
    'artisan:optimize',
    'symlink:uploaded',
    'deploy:clear_paths',
    'memcached:flush',
    'deploy:symlink',
    'deploy:unlock',
    'cleanup',
    'success',
]);

desc('Pull the code from repo');
task('pull_code', function () {
    $branch = get('branch');
    $git = get('bin/git');
    $branchName = !empty($branch) ? "origin/$branch" : 'origin/master';
    $options = [
        'tty' => get('git_tty', false),
    ];

    // Enter deploy_path if present
    if (has('deploy_path')) {
        cd('{{deploy_path}}');
    }

    run("cd {{release_path}} && $git fetch --all", $options);
    run("cd {{release_path}} && $git checkout --force $branchName", $options);
});

desc('Execute special commands if any or run deploy:writable otherwise');
task('deploy:permissions', function() {
    if (has('deploy_permissions')) {
        cd('{{release_path}}');
        $commands = get('deploy_permissions');
        foreach ($commands as $key => $command) {
            writeln("Executing command: $key");
            run($command);
        }
    } else {
        invoke('deploy:writable');
    }
})->onRoles(
    'frontend-server',
    'backend-server'
);

// Create new database
task('db:create')->onStage('test', 'prod')->onRoles('frontend-server');

// Inflate database
task('db:pipe')->onRoles('frontend-server');
task('db:repipe')->onHosts('frontend-server');

desc('Propagate configuration file');
task('config:clone', function () {
    run('cp {{env_example_file}} {{release_path}}/.env');
})->onRoles(
    'frontend-server',
    'backend-server'
);

desc('Propagate configuration file');
task('config:inject', function () {
    $customEnv = get('inject_env', []);
    foreach ($customEnv as $key => $value) {
        $escapedValue = escapeForSed($value);
        run("sed -i -E 's/^$key=.*/$key=$escapedValue/g' {{release_path}}/.env");
    }
})->onRoles(
    'frontend-server',
    'backend-server'
);

desc('Propagate configuration file');
task('config:switch', function () {
    $customEnv = get('inject_env_switched', []);
    foreach ($customEnv as $key => $value) {
        $escapedValue = escapeForSed($value);
        run("sed -i -E 's/^$key=.*/$key=$escapedValue/g' {{release_path}}/.env");
    }
})->onRoles(
    'frontend-server',
    'backend-server'
);

//Sphinx related tasks
set('bin/indexer', function () {
    return run('which indexer');
});

desc('Copy services config examples');
task('config:services', function() {
    run('cp {{sphinx_conf_src}} {{sphinx_conf_dest}}');
})->onRoles('service-scripts');

desc('Reindex sphinx');
task('sphinx:index', function () {
    run('sudo -H -u sphinxsearch {{bin/indexer}} --rotate --all --quiet --config {{sphinx_conf_dest}}');
})->onStage('prod')->onRoles('service-scripts');

desc('Infect app configuration with sphinx credentials');
task('sphinx:inject', function () {
    run("sed -i -E 's/sql_host[[:blank:]]*=.*/sql_host={{db_app_host}}/g' {{sphinx_config_path}}");
    run("sed -i -E 's/sql_db[[:blank:]]*=.*/sql_db={{db_app_name}}/g' {{sphinx_config_path}}");
    run("sed -i -E 's/sql_user[[:blank:]]*=.*/sql_user={{db_app_user}}/g' {{sphinx_config_path}}");
    run("sed -i -E 's/sql_pass[[:blank:]]*=.*/sql_pass={{db_app_pass}}/g' {{sphinx_config_path}}");
})->onStage('test', 'prod')->onRoles('frontend-server');

// Did not include npm recipe because of low timeout and poor messaging
desc('Install npm packages');
task('npm:install', function () {
    if (has('previous_release')) {
        if (test('[ -d {{previous_release}}/node_modules ]')) {
            run('cp -R {{previous_release}}/node_modules {{release_path}}');
        } else {
            writeln('<info>Packages installation may take a while for the first time..</info>');
        }
    }
    run('cd {{release_path}} && {{bin/npm}} install', ['timeout' => 1800]);
})->onRoles(
    'frontend-server',
    'backend-client',
    'photobank'
);

//TODO Try to copy tsd indtallation from previous release
desc('Install tsd packages');
task('tsd:install', function () {
    run('cd {{release_path}} && {{bin/npm}} run tsd -- install', ['timeout' => 1800]);
})->onRoles('photobank');

desc('Build npm packages');
task('npm:build', function () {
    run('cd {{release_path}} && {{bin/npm}} run build', ['timeout' => 1800]);
})->onRoles(
    'backend-client',
    'photobank'
);

desc('Build assets');
task('gulp', function () {
    run('cd {{release_path}} && gulp');
})->onRoles('frontend-server');

task('gulp:switch', function () {
    run('cd {{release_path}} && gulp switch:new_version');
})->onRoles('frontend-server');

desc('Generate application key');
task('artisan:key:generate', function () {
    $output = run('cd {{release_path}} && {{bin/php}} artisan key:generate');
    writeln('<info>' . $output . '</info>');
})->onRoles('frontend-server');

desc('Clear cache table');
task('artisan:cache:clear_table', function () {
    run('cd {{release_path}} && {{bin/php}} artisan cachetable:clear --truncate');
})->onRoles('frontend-server');

desc('Creating symlink to uploaded folder at backend server');
task('symlink:uploaded', function () {
    // Will use simple≤ two steps switch.
    run('cd {{release_path}} && {{bin/symlink}} {{uploaded_path}} public/uploaded'); // Atomic override symlink.
})->onRoles('frontend-server');

desc('Restart memcached');
task('memcached:restart', function () {
    if (has('previous_release')) {
        run('cd {{previous_release}} && {{bin/php}} artisan cache:restart');
    }
})->onStage('test', 'prod')->onRoles('frontend-server');

desc('Flush memcached');
task('memcached:flush', function () {
    if (has('previous_release')) {
        run('cd {{previous_release}} && {{bin/php}} artisan cache:flush');
    }
})->onStage('test', 'prod')->onRoles('frontend-server');

//Rsync tasks

desc('Setup rsync destination path');
task('rsync:setup', function () {
    $dest = 'release';
    if(test('[ ! -r {{rsync_dest_base}}/release ]')) {
        writeln('<comment>Looks like BC component is built lonely</comment>');
        $dest = 'current';
    }
    set('rsync_dest', parse("{{rsync_dest_base}}/{$dest}/{{rsync_dest_relative}}"));
})->onRoles(
    'backend-client',
    'photobank'
);

desc('Rsync override');
task('rsync', function() {
    $config = get('rsync');

    $src = get('rsync_src');
    while (is_callable($src)) {
        $src = $src();
    }

    if (!trim($src)) {
        throw new \RuntimeException('You need to specify a source path.');
    }

    $dst = get('rsync_dest');
    while (is_callable($dst)) {
        $dst = $dst();
    }

    if (!trim($dst)) {
        throw new \RuntimeException('You need to specify a destination path.');
    }

    $server = \Deployer\Task\Context::get()->getHost();
    if ($server instanceof \Deployer\Host\Localhost) {
        runLocally("rsync -{$config['flags']} {{rsync_options}}{{rsync_includes}}{{rsync_excludes}}{{rsync_filter}} '$src/' '$dst/'", $config);
        return;
    }

    run("rsync -{$config['flags']} {{rsync_options}}{{rsync_includes}}{{rsync_excludes}}{{rsync_filter}} '$src/' '$dst/'", $config);
})->onRoles(
    'backend-client',
    'photobank'
);

task('rsync:static', function() {
    if(has('rsync_marker') && test('[ ! -f {{rsync_marker}} ]')) {
        writeln('<info>File marker not found, shutdown.</info>');
        return;
    }

    run("sudo rsync -a '{{rsync_src}}/' '{{rsync_dest}}/'");
})->onStage('prod')->onRoles('frontend-server');

desc('Purge project folder');
task('deploy:purge', function() {
    $hostName = Task\Context::get()->getHost()->getHostname();
    $message = "You're about to purge $hostName, are you sure?";
    $purgeChoise = askConfirmation($message, $default = false);
    if($purgeChoise) {
        if (test('[ -d {{deploy_path}} ]')) {
            run('sudo -H -u deploy rm {{deploy_path}} -r');
        } else {
            writeln('<comment>No such directory {{deploy_path}}</comment>');
        }
    }
    else {
        writeln('<info>Continue without purging host</info>');
    }
});

//Filter external recipes
task('artisan:migrate')->onRoles('backend-server');
task('artisan:config:cache')->onRoles('frontend-server');
task('artisan:optimize')->onRoles('frontend-server');
task('artisan:storage:link')->onRoles(
    'frontend-server',
    'backend-server'
);
task('artisan:cache:clear')->onRoles(
    'frontend-server',
    'backend-server'
);
task('deploy:vendors')->onRoles(
    'frontend-server',
    'backend-server'
);
task('deploy:shared')->onRoles(
    'frontend-server',
    'backend-server'
);
task('deploy:writable')->onRoles(
    'frontend-server',
    'backend-server'
);
task('deploy:copy_dirs')->onRoles(
    'frontend-server',
    'backend-server'
);
task('deploy:clear_paths')->onRoles(
    'frontend-server',
    'backend-server'
);
