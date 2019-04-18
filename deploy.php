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
    'db:init',
    'deploy:update_code',
    'db:clone',
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
    'db:init',
    'deploy:update_code',
    'db:clone',
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

after('release:build', 'sphinx:index');

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
})->onHosts(
    'test-frontend',
    'prod-frontend',
    'test-backend',
    'prod-backend'
);

// Executing initial SQL dump
task('db:init')->onHosts('test-frontend')->onStage('test');

// Cloning database repository
//TODO configure database as subrepo
task('db:clone')->onHosts(
    'test-frontend',
    'prod-frontend',
    'test-backend',
    'prod-backend'
);

// Create new database
task('db:create')->onHosts('prod-frontend');

// Inflate database
task('db:pipe')->onHosts('prod-frontend');

//TODO maybe better path procedure for shared dir
desc('Propagate configuration file');
task('config:clone', function () {
    run('cp {{env_example_file}} {{release_path}}/.env');
})->onHosts(
    'test-frontend',
    'prod-frontend',
    'test-backend',
    'prod-backend'
);

desc('Propagate configuration file');
task('config:inject', function () {
    $customEnv = get('inject_env', []);
    foreach ($customEnv as $key => $value) {
        $escapedValue = addcslashes($value, '/|&?!"\'');
        run("sed -i -E 's/$key=.*/$key=$escapedValue/g' {{release_path}}/.env");
    }
})->onHosts(
    'test-frontend',
    'prod-frontend',
    'test-backend',
    'prod-backend'
);

desc('Propagate configuration file');
task('config:switch', function () {
    $customEnv = get('inject_env_switched', []);
    foreach ($customEnv as $key => $value) {
        run("sed -i -E 's/$key=.*/$key=$value/g' {{release_path}}/.env");
    }
})->onHosts(
    'test-frontend',
    'prod-frontend',
    'test-backend',
    'prod-backend'
);

//Sphinx related tasks
set('bin/indexer', function () {
    return run('which indexer');
});

desc('Copy services config examples');
task('config:services', function() {
    run('cp {{sphinx_conf_src}} {{sphinx_conf_dest}}');
})->onHosts('prod-services');

desc('Reindex sphinx');
task('sphinx:index', function () {
    run('sudo -H -u sphinxsearch {{bin/indexer}} --rotate --all --quiet --config {{sphinx_conf_dest}}');
})->onHosts('prod-services');

desc('Infect app configuration with sphinx credentials');
task('sphinx:inject', function () {
    run("sed -i -E 's/sql_host[[:blank:]]*=.*/sql_host={{db_app_host}}/g' {{sphinx_config_path}}");
    run("sed -i -E 's/sql_db[[:blank:]]*=.*/sql_db={{db_name_releasing}}/g' {{sphinx_config_path}}");
    run("sed -i -E 's/sql_user[[:blank:]]*=.*/sql_user={{db_app_user}}/g' {{sphinx_config_path}}");
    run("sed -i -E 's/sql_pass[[:blank:]]*=.*/sql_pass={{db_app_pass}}/g' {{sphinx_config_path}}");
})->onHosts('prod-frontend');

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
})->onHosts(
    'test-frontend',
    'prod-frontend',
    'test-backend-client',
    'prod-backend-client',
    'test-photobank-client',
    'prod-photobank-client'
);

//TODO Try to copy tsd indtallation from previous release
desc('Install tsd packages');
task('tsd:install', function () {
    run('cd {{release_path}} && {{bin/npm}} run tsd -- install', ['timeout' => 1800]);
})->onHosts(
    'test-photobank-client',
    'prod-photobank-client'
);

desc('Build npm packages');
task('npm:build', function () {
    run('cd {{release_path}} && {{bin/npm}} run build', ['timeout' => 1800]);
})->onHosts(
    'test-backend-client',
    'prod-backend-client',
    'test-photobank-client',
    'prod-photobank-client'
);

desc('Build assets');
task('gulp', function () {
    run('cd {{release_path}} && gulp');
})->onHosts(
    'test-frontend',
    'prod-frontend'
);

task('gulp:switch', function () {
    run('cd {{release_path}} && gulp switch:new_version');
})->onHosts(
    'test-frontend',
    'prod-frontend'
);

desc('Generate application key');
task('artisan:key:generate', function () {
    $output = run('cd {{release_path}} && {{bin/php}} artisan key:generate');
    writeln('<info>' . $output . '</info>');
})->onHosts(
    'test-frontend',
    'prod-frontend'
);

desc('Creating symlink to uploaded folder at backend server');
task('symlink:uploaded', function () {
    // Will use simple≤ two steps switch.
    run('cd {{release_path}} && {{bin/symlink}} {{uploaded_path}} public/uploaded'); // Atomic override symlink.
})->onHosts(
    'test-frontend',
    'prod-frontend'
);

desc('Restart memcached');
task('memcached:restart', function () {
    if (has('previous_release')) {
        run('cd {{previous_release}} && {{bin/php}} artisan cache:restart');
    }
})->onHosts('prod-frontend');

desc('Flush memcached');
task('memcached:flush', function () {
    if (has('previous_release')) {
        run('cd {{previous_release}} && {{bin/php}} artisan cache:flush');
    }
})->onHosts('prod-frontend');

//Rsync tasks

desc('Setup rsync destination path');
task('rsync:setup', function () {
    $dest = 'release';
    if(test('[ ! -r {{rsync_dest_base}}/release ]')) {
        writeln('<comment>Looks like BC component is built lonely</comment>');
        $dest = 'current';
    }
    set('rsync_dest', parse("{{rsync_dest_base}}/{$dest}/{{rsync_dest_relative}}"));
})->onHosts(
    'test-backend-client',
    'prod-backend-client',
    'test-photobank-client',
    'prod-photobank-client'
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
})->onHosts(
    'test-backend-client',
    'test-photobank-client',
    'prod-backend-client',
    'prod-photobank-client'
);

task('rsync:static', function() {
    if(has('rsync_marker') && test('[ ! -f {{rsync_marker}} ]')) {
        writeln('<info>File marker not found, shutdown.</info>');
        return;
    }

    run("sudo rsync -a '{{rsync_src}}/' '{{rsync_dest}}/'");
})->onHosts(
    'prod-backend'
);

desc('Purge project folder');
task('deploy:purge', function() {
    $hostName = Task\Context::get()->getHost()->getHostname();
    $message = "You're about to purge $hostName, check options:";
    $availableChoices = array("Purge host", "Continue without purge");
    $purgeChoise = askConfirmation($message, $default = false);
    if($purgeChoise) {
        if (test('[ -d {{deploy_path}} ]')) {
            run('sudo -H -u deploy rm {{deploy_path}} -r');
        } else {
            writeln("<comment>No such directory {{deploy_path}}</comment>");
        }
    }
    else {
        writeln("<info>Continue without purging host</info>");
    }
})->onHosts(
    'test-frontend',
    'prod-frontend',
    'test-backend',
    'prod-backend',
    'test-backend-client',
    'test-photobank-client',
    'prod-backend-client',
    'prod-photobank-client'
);

//Filter external recipes
task('artisan:migrate')->onHosts(
    'test-frontend',
    'prod-frontend'
);
task('artisan:storage:link')->onHosts(
    'test-frontend',
    'prod-frontend',
    'test-backend',
    'prod-backend'
);
task('artisan:cache:clear')->onHosts(
    'test-frontend',
    'prod-frontend',
    'test-backend',
    'prod-backend'
);
task('artisan:config:cache')->onHosts(
    'test-frontend',
    'prod-frontend'
);
task('artisan:optimize')->onHosts(
    'test-frontend',
    'prod-frontend'
);
task('deploy:vendors')->onHosts(
    'test-frontend',
    'prod-frontend',
    'test-backend',
    'prod-backend'
);
task('deploy:shared')->onHosts(
    'test-frontend',
    'prod-frontend',
    'test-backend',
    'prod-backend'
);
task('deploy:writable')->onHosts(
    'test-frontend',
    'prod-frontend',
    'test-backend',
    'prod-backend'
);
task('deploy:copy_dirs')->onHosts(
    'test-frontend',
    'prod-frontend',
    'test-backend',
    'prod-backend'
);
task('deploy:clear_paths')->onHosts(
    'prod-frontend',
    'prod-backend'
);
