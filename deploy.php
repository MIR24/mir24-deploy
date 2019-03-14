<?php
namespace Deployer;

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/recipe/laravel.php';
require __DIR__ . '/recipe/sphinx.php';
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
    'db:create',
    'db:pipe',
    'db:init',
    'deploy:release',
    'deploy:update_code',
    'db:clone',
    'deploy:shared',
    'config:clone',
    'config:inject:DB',
    'config:inject:sphinx',
    'config:sphinx',
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
    'deploy:writable',
    'artisan:cache:clear',
    'artisan:key:generate',
    'artisan:config:cache',
    'artisan:optimize',
    'artisan:migrate',
    'symlink:uploaded',
    'deploy:clear_paths',
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
    'db:create',
    'db:pipe',
    'db:init',
    'deploy:release',
    'deploy:update_code',
    'db:clone',
    'deploy:shared',
    'config:clone',
    'config:inject:DB',
    'config:sphinx',
    'deploy:copy_dirs',
    'deploy:vendors',
    'npm:install',
    'tsd:install',
    'npm:build',
    'gulp',
    'gulp:switch',
    'rsync',
    'artisan:storage:link',
    'deploy:writable',
    'artisan:cache:clear',
    'artisan:key:generate',
    'artisan:config:cache',
    'artisan:optimize',
    'artisan:migrate',
    'symlink:uploaded',
    'deploy:clear_paths',
    'deploy:unlock'
]);

after('release:build', 'sphinx:index');

desc('Switch to release built');
task('release:switch', [
    'deploy:lock',
    'deploy:symlink',
    'memcached:restart',
    'deploy:unlock',
    'cleanup',
    'success'
]);

//Hotfixes
task('hotfix:FS:server', [
    'deploy:info',
    'deploy:lock',
    'pull_code',
    'artisan:config:cache',
    'artisan:optimize',
    'memcached:flush',
    'deploy:unlock',
    'success',
])->onHosts('prod-frontend');

task('hotfix:FS:assets', [
    'deploy:info',
    'deploy:lock',
    'pull_code',
    'gulp',
    'gulp:switch',
    'memcached:flush',
    'deploy:unlock',
    'success',
])->onHosts('prod-frontend');

task('hotfix:BS', [
    'deploy:info',
    'deploy:lock',
    'pull_code',
    'artisan:cache:clear',
    'deploy:unlock',
    'success',
])->onHosts('prod-backend');

task('hotfix:BC', [
    'deploy:info',
    'deploy:lock',
    'pull_code',
    'npm:install',
    'npm:build',
    'rsync:setup',
    'rsync',
    'deploy:unlock',
    'success',
])->onHosts('prod-backend-client');

task('hotfix:PB', [
    'deploy:info',
    'deploy:lock',
    'pull_code',
    'npm:install',
    'tsd:install',
    'npm:build',
    'rsync:setup',
    'rsync',
    'deploy:unlock',
    'success',
])->onHosts('prod-photobank-client');

desc('Pull the code from repo');
task('pull_code', function () {
    $branch = get('branch');
    $git = get('bin/git');
    $quiet = isQuiet() ? '-q' : '';
    $branchName = !empty($branch) ? $branch : '';
    $options = [
        'tty' => get('git_tty', false),
    ];

    // Enter deploy_path if present
    if (has('deploy_path')) {
        cd('{{deploy_path}}');
    }
    run("cd {{release_path}} && $git pull origin $branchName $quiet --depth=1 --no-commit 2>&1", $options);
});

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

// Inject db config into env
task('config:inject:DB')->onHosts(
    'prod-frontend',
    'prod-backend'
);

task('config:inject:sphinx')->onHosts('prod-frontend');

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

//Sphinx tasks filter
task('config:sphinx')->onHosts(
    'prod-frontend',
    'prod-backend'
);
task('sphinx:index')->onHosts('prod-frontend');

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
