<?php

namespace Deployer;

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/recipe/laravel.php';
require __DIR__ . '/recipe/db.php';
require 'recipe/rsync.php';

const ROLE_SS = 'service-scripts';
const ROLE_FS = 'frontend-server';
const ROLE_BS = 'backend-server';
const ROLE_BC = 'backend-client';
const ROLE_PB = 'photobank-client';

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

set('rsync', [
    'exclude' => [],
    'exclude-file' => false,
    'include' => [],
    'include-file' => false,
    'filter' => [],
    'filter-file' => false,
    'filter-perdir' => false,
    'flags' => 'rzuEa',
    'options' => [],
]);

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
    'symlink:uploaded',
    'config:clone',
    'config:services',
    'config:inject',
    'config:switch',
    'sphinx:inject',
    'supervisor:inject',
    'deploy:copy_dirs',
    'deploy:vendors',
    'npm:install',
    'tsd:install',
    'npm:build',
    'artisan:storage:link',
    'artisan:cache:clear',
    'artisan:cache:clear_table',
    'artisan:key:generate',
    'artisan:config:cache',
    'artisan:optimize',
    'artisan:migrate',
    'deploy:permissions',
    'deploy:clear_paths',
    'memcached:restart',
    'deploy:symlink',
    'deploy:unlock',
    'cleanup',
    'success',
]);

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
    'symlink:uploaded',
    'config:clone',
    'config:services',
    'config:inject',
    'sphinx:inject',
    'supervisor:inject',
    'deploy:copy_dirs',
    'deploy:vendors',
    'npm:install',
    'tsd:install',
    'npm:build',
    'artisan:storage:link',
    'artisan:cache:clear',
    'artisan:key:generate',
    'artisan:config:cache',
    'artisan:optimize',
    'artisan:migrate',
    'deploy:permissions',
    'deploy:clear_paths',
    'deploy:unlock'
]);

desc('Switch to release built');
task('release:switch', [
    'deploy:lock',
    'db:repipe',
    'config:switch',
    'artisan:config:cache',
    'artisan:migrate',
    'artisan:cache:clear_table',
    'deploy:symlink',
    'deploy:permissions',
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

/* Before and after task filters */
before('deploy', 'artisan:down');
before('db:repipe', 'artisan:down');
after('deploy:failed', 'artisan:up');
after('deploy', 'services:restart');
after('release:switch', 'services:restart');

task('services:restart', [
    'supervisor:reread',
    'supervisor:reload',
])->onStage('prod');

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
    ROLE_FS,
    ROLE_BS
);

// Create new database
task('db:create')->onStage('test', 'prod')->onRoles(ROLE_FS);

// Inflate database
task('db:pipe')->onRoles(ROLE_FS);
task('db:repipe')->onRoles(ROLE_FS);

desc('Propagate configuration file');
task('config:clone', function () {
    run('cp {{env_example_file}} {{release_path}}/.env');
})->onRoles(
    ROLE_FS,
    ROLE_BS
);

desc('Propagate configuration file');
task('config:inject', function () {
    $customEnv = get('inject_env', []);
    foreach ($customEnv as $key => $value) {
        $escapedValue = escapeForSed($value);
        run("sed -i -E 's|^$key=.*|$key=$escapedValue|g' {{release_path}}/.env");
    }
})->onRoles(
    ROLE_FS,
    ROLE_BS
);

desc('Propagate configuration file');
task('config:switch', function () {
    $customEnv = get('inject_env_switched', []);
    foreach ($customEnv as $key => $value) {
        $escapedValue = escapeForSed($value);
        run("sed -i -E 's|^$key=.*|$key=$escapedValue|g' {{release_path}}/.env");
    }
})->onRoles(
    ROLE_FS,
    ROLE_BS
);

//Sphinx related tasks
set('bin/indexer', function () {
    return run('which indexer');
});

desc('Copy services config examples');
task('config:services', function() {
    $mountFiles = get('mount_files', []);
    foreach ($mountFiles as $mountFile) {
        [$src, $dest] = explode(':', $mountFile);
        run("cp $src $dest");
    }
})->onRoles(ROLE_SS);

desc('Reindex sphinx');
task('sphinx:index', function () {
    run('sudo -H -u sphinxsearch {{bin/indexer}} --rotate --all --quiet --config {{sphinx_conf_dest}}');
})->onStage('prod')->onRoles(ROLE_SS);

desc('Reread supervisor config');
task('supervisor:reread', function () {
    run('sudo supervisorctl reread');
})->onStage('prod')->onRoles(ROLE_SS);

desc('Reload supervisor');
task('supervisor:reload', function () {
    run('sudo supervisorctl reload');
})->onStage('prod')->onRoles(ROLE_SS);

desc('Configure supervisor');
task('supervisor:inject', function () {
    $supervisorParams = get('supervisor_params', []);
    foreach ($supervisorParams as $param => $value) {
        $key = '{' . $param . '}';
        $escapedValue = escapeForSed($value);
        run("sed -i 's|$key|$escapedValue|g' {{supervisor_conf_dest}}");
    }
})->onRoles(ROLE_SS);

desc('Infect app configuration with sphinx credentials');
task('sphinx:inject', function () {
    on(roles(ROLE_SS), function() {
        run("sed -i -E 's|sql_host[[:blank:]]*=.*|sql_host={{db_app_host}}|g' {{sphinx_conf_dest}}");
        run("sed -i -E 's|sql_db[[:blank:]]*=.*|sql_db={{db_app_name}}|g' {{sphinx_conf_dest}}");
        run("sed -i -E 's|sql_user[[:blank:]]*=.*|sql_user={{db_app_user}}|g' {{sphinx_conf_dest}}");
        run("sed -i -E 's|sql_pass[[:blank:]]*=.*|sql_pass={{db_app_pass}}|g' {{sphinx_conf_dest}}");
    });
})->onStage('test', 'prod')->onRoles(ROLE_FS);

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
    ROLE_FS,
    ROLE_BC,
    ROLE_PB
);

//TODO Try to copy tsd indtallation from previous release
desc('Install tsd packages');
task('tsd:install', function () {
    run('cd {{release_path}} && {{bin/npm}} run tsd -- install', ['timeout' => 1800]);
})->onRoles(ROLE_PB);

desc('Build npm packages');
task('npm:build', function () {
    run('cd {{release_path}} && {{bin/npm}} run build', ['timeout' => 1800]);
})->onRoles(
    ROLE_FS,
    ROLE_BC,
    ROLE_PB
);

desc('Generate application key');
task('artisan:key:generate', function () {
    $output = run('cd {{release_path}} && {{bin/php}} artisan key:generate');
    writeln('<info>' . $output . '</info>');
})->onRoles(ROLE_FS);

desc('Clear cache table');
task('artisan:cache:clear_table', function () {
    run('cd {{release_path}} && {{bin/php}} artisan cachetable:clear --truncate');
})->onRoles(ROLE_FS);

desc('Creating symlink to uploaded folder at backend server');
task('symlink:uploaded', function () {
    // Will use simple≤ two steps switch.
    run('cd {{release_path}} && {{bin/symlink}} {{uploaded_path}} public/uploaded'); // Atomic override symlink.
})->onRoles(ROLE_FS);

desc('Restart memcached');
task('memcached:restart', function () {
    if (has('previous_release')) {
        run('cd {{previous_release}} && {{bin/php}} artisan cache:restart');
    }
})->onStage('test', 'prod')->onRoles(ROLE_FS);

desc('Flush memcached');
task('memcached:flush', function () {
    if (has('previous_release')) {
        run('cd {{previous_release}} && {{bin/php}} artisan cache:flush');
    }
})->onStage('test', 'prod')->onRoles(ROLE_FS);

// Application maintenance mode tasks
task('artisan:down')->onStage('test', 'prod')->onRoles(ROLE_BS);
task('artisan:up')->onStage('test', 'prod')->onRoles(ROLE_BS);

task('rsync:static', function() {
    if(has('rsync_marker') && test('[ ! -f {{rsync_marker}} ]')) {
        writeln('<info>File marker not found, shutdown.</info>');
        return;
    }

    run("sudo rsync -a '{{rsync_src}}/' '{{rsync_dest}}/'");
})->onStage('prod')->onRoles(ROLE_BS);

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
task('artisan:migrate')->onRoles(ROLE_BS);
task('artisan:config:cache')->onRoles(ROLE_FS);
task('artisan:optimize')->onRoles(ROLE_FS);
task('artisan:storage:link')->onRoles(
    ROLE_FS,
    ROLE_BS
);
task('artisan:cache:clear')->onRoles(
    ROLE_FS,
    ROLE_BS
);
task('deploy:vendors')->onRoles(
    ROLE_FS,
    ROLE_BS
);
task('deploy:shared')->onRoles(
    ROLE_FS,
    ROLE_BS
);
task('deploy:writable')->onRoles(
    ROLE_FS,
    ROLE_BS
);
task('deploy:copy_dirs')->onRoles(
    ROLE_FS,
    ROLE_BS
);
task('deploy:clear_paths')->onRoles(
    ROLE_FS,
    ROLE_BS
);
