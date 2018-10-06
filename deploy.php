<?php
namespace Deployer;

require 'recipe/laravel.php';

inventory('hosts.yml');

set('default_timeout', 1800);
set('copy_dirs', ['vendor']);
set('composer_action','update');

// Project name
set('application', 'my_project');

// Project repository
set('repository', '');

set('bin/npm', function () {
    return run('which npm');
});

// Tasks

desc('Deploy complete process');
task('deploy', [
    'deploy:info',
    'deploy:prepare',
    'deploy:lock',
    'db:init',
    'deploy:release',
    'deploy:update_code',
    'db:clone',
    'deploy:shared',
    'config:clone',
    'deploy:copy_dirs',
    'deploy:vendors',
    'tsd:install',
    'npm:install',
    'npm:build',
    'gulp',
    'deploy:writable',
    'artisan:storage:link',
    'artisan:cache:clear',
    'artisan:config:cache',
    'artisan:optimize',
    'artisan:migrate',
    'deploy:symlink',
    'deploy:unlock',
    'cleanup',
]);

//Executing initial SQL dump
desc('Executing initial dump may took a minute');
task('db:init', function () {
    writeln('<info>Check if {{dump_file}} exists</info>');

    if(test('[ ! -r {{dump_file}} ]')) {
        writeln('<comment>No dump file found, proceed</comment>');

        return;
    }
	writeln('<info>SQL dump execution, please wait..</info>');
	run('cd {{deploy_path}} && mysql -h{{dbhost}} -u{{dbuser}} -p{{dbpass}} mir24_7 < {{dump_file}}');
})->onHosts('test-frontend');

//TODO configure database as subrepo
desc('Cloning database repository');
task('db:clone', function () {
    run('cd {{release_path}} && git clone git@github.com:MIR24/database.git');
})->onHosts('test-frontend');

//TODO maybe better path procedure for shared dir
desc('Propagate configuration file');
task('config:clone', function () {
    if(test('[ -s {{deploy_path}}/shared/.env ]'))
    {
        writeln('<comment>Config file already shared, check and edit shared_folder/.env</comment>');
    } else {
        run('cp {{env_example_file}} {{deploy_path}}/shared/.env');
    }
})->onHosts('test-frontend', 'test-backend');

// Did not include npm recipe because of low timeout and poor messaging
desc('Install npm packages');
task('npm:install', function () {
    if (has('previous_release')) {
        if (test('[ -d {{previous_release}}/node_modules ]')) {
            run('cp -R {{previous_release}}/node_modules {{release_path}}');
        } else
			writeln('<info>Packages installation may take a while for the first time..</info>');
    }
    run("cd {{release_path}} && {{bin/npm}} install", ["timeout" => 1800]);
})->onHosts('test-frontend','test-backend-client','test-photobank-client');

//TODO Try to copy tsd indtallation from previous release
desc('Install tsd packages');
task('tsd:install', function () {
    run("cd {{release_path}} && tsd install", ["timeout" => 1800]);
})->onHosts('test-photobank-client');

desc('Build npm packages');
task('npm:build', function () {
    run("cd {{release_path}} && {{bin/npm}} run build", ["timeout" => 1800]);
})->onHosts('test-backend-client','test-photobank-client');

desc('Build assets');
task('gulp', function () {
    run('cd {{release_path}} && gulp');
})->onHosts('test-frontend');

desc('Generate application key');
task('artisan:key:generate', function () {
	$output = run('if [ -f {{deploy_path}}/current/artisan ]; then {{bin/php}} {{deploy_path}}/current/artisan key:generate; fi');
	writeln('<info>' . $output . '</info>');
});

//Filter external recipes
task('artisan:migrate')->onHosts('test-frontend');
task('artisan:storage:link')->onHosts('test-frontend', 'test-backend');
task('artisan:cache:clear')->onHosts('test-frontend', 'test-backend');
task('artisan:config:cache')->onHosts('test-frontend', 'test-backend');
task('artisan:optimize')->onHosts('test-frontend', 'test-backend');
task('deploy:vendors')->onHosts('test-frontend', 'test-backend');
task('deploy:shared')->onHosts('test-frontend', 'test-backend');
task('deploy:writable')->onHosts('test-frontend', 'test-backend');
task('deploy:copy_dirs')->onHosts('test-frontend', 'test-backend');
