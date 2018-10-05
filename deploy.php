<?php
namespace Deployer;

require 'recipe/laravel.php';

inventory('hosts.yml');

// Project name
set('application', 'my_project');

// Project repository
set('repository', '');

// [Optional] Allocate tty for git clone. Default value is false.
set('git_tty', true); 

set('bin/npm', function () {
    return run('which npm');
});

// Tasks

desc('Deploy frontend-server test bench');
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
    'deploy:vendors',
    'npm:install',
    'build',
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
        writeln('<error>DB dump file not found, upload file, than configure hosts</error>');
        writeln('<comment>Stop deployment</comment>');

        invoke('deploy:unlock');
        die;
    }

    if(askConfirmation("Going to overwrite existing database, confirm..", false)) {
        run('cd {{deploy_path}} && mysql -h{{dbhost}} -u{{dbuser}} -p{{dbpass}} mir24_7 < {{dump_file}}');
    } else {
        writeln('<comment>Stop deployment</comment>');

        invoke('deploy:unlock');
        die;
    }
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
});

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
})->onHosts('test-frontend');

desc('Build assets');
task('build', function () {
    run('cd {{release_path}} && gulp');
})->onHosts('test-frontend');

desc('Generate application key');
task('artisan:key:generate', function () {
	$output = run('if [ -f {{deploy_path}}/current/artisan ]; then {{bin/php}} {{deploy_path}}/current/artisan key:generate; fi');
	writeln('<info>' . $output . '</info>');
});

task('artisan:migrate')->onHosts('test-frontend');
