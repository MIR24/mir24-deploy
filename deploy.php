<?php
namespace Deployer;

require __DIR__ . '/vendor/autoload.php';
require 'recipe/laravel.php';
require 'recipe/rsync.php';

$releaseDate = date('d_M_H_i');

$hostsDev = 'hosts.dev.yml';
$hostsProd = 'hosts.yml';
$hostsInventory = (file_exists($hostsDev) && is_readable($hostsDev)) ? $hostsDev : $hostsProd;
inventory($hostsInventory);

set('release_name', function () use ($releaseDate) {
    return $releaseDate;
});

set('db_name_releasing', function () {
    return 'mir24_dep_'.get('release_name');
});

set('ssh_multiplexing', true);

//Override laravel recipe due to 'Not a git repo' error
set('laravel_version', function () {
    $result = run('cd {{release_path}} && {{bin/php}} artisan --version');
    preg_match_all('/(\d+\.?)+/', $result, $matches);
    $version = $matches[0][0] ?? 5.5;
    return $version;
});

set('default_timeout', 1800);
set('copy_dirs', ['vendor']);

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
    'db:create',
    'db:pipe',
    'db:init',
    'deploy:release',
    'deploy:update_code',
    'db:clone',
    'deploy:shared',
    'config:clone',
    'config:configure:DB',
    'deploy:copy_dirs',
    'deploy:vendors',
    'tsd:install',
    'npm:install',
    'npm:build',
    'gulp',
    'rsync',
    'deploy:writable',
    'artisan:storage:link',
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
})->onHosts('test-frontend')->onStage('test');

//TODO configure database as subrepo
desc('Cloning database repository');
task('db:clone', function () {
    run('cd {{release_path}} && git clone git@github.com:MIR24/database.git');
})->onHosts(
    'test-frontend',
    'prod-frontend',
    'test-backend',
    'prod-backend');

desc('Create new database to proceed release');
task('db:create', function (){
    writeln('<info>Trying to create database '.get('db_name_releasing').'</info>');
    run('mysql -h{{dbhost}} -u{{dbuser}} -p{{dbpass}} -e "CREATE DATABASE '.get('db_name_releasing').'"');
})->onHosts('prod-frontend');

desc('Inflate database with data from current released version');
task('db:pipe', function (){
    $releaseList = get('releases_list');
    $prevReleaseName = array_shift($releaseList);
    if($prevReleaseName){
        writeln('<info>Trying to inflate database '.get('db_name_releasing').' with release data from mir24_dep_'.$prevReleaseName.'</info>');
        run('mysqldump --single-transaction --insert-ignore -u{{dbuser}} -p{{dbpass}} mir24_dep_'.$prevReleaseName.
            ' | mysql  -u{{dbuser}} -p{{dbpass}} -h{{dbhost}} '.get('db_name_releasing'));
    } else {
        writeln('<error>No previous release found, can`t inflate database, stop.</error>');
        die;
    }
})->onHosts('prod-frontend');

desc('Infect app configuration with DB credentials');
task('config:configure:DB', function () {
    run("sed -i -E 's/DB_HOST=.*/DB_HOST=".get('dbhost')."/g' ".get('release_path').'/.env');
    run("sed -i -E 's/DB_DATABASE=.*/DB_DATABASE=".get('db_name_releasing')."/g' ".get('release_path').'/.env');
    run("sed -i -E 's/DB_USERNAME=.*/DB_USERNAME=".get('dbuser')."/g' ".get('release_path').'/.env');
    run("sed -i -E 's/DB_PASSWORD=.*/DB_PASSWORD=".get('dbpass')."/g' ".get('release_path').'/.env');
})->onHosts(
    'prod-frontend',
    'prod-backend');

//TODO maybe better path procedure for shared dir
desc('Propagate configuration file');
task('config:clone', function () {
        run('cp {{env_example_file}} {{release_path}}/.env');
})->onHosts(
    'test-frontend',
    'prod-frontend',
    'test-backend',
    'prod-backend');

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
})->onHosts(
    'test-frontend',
    'prod-frontend',
    'test-backend-client',
    'test-photobank-client');

//TODO Try to copy tsd indtallation from previous release
desc('Install tsd packages');
task('tsd:install', function () {
    run("cd {{release_path}} && tsd install", ["timeout" => 1800]);
})->onHosts('test-photobank-client');

desc('Build npm packages');
task('npm:build', function () {
    run("cd {{release_path}} && {{bin/npm}} run build", ["timeout" => 1800]);
})->onHosts(
    'test-backend-client',
    'test-photobank-client');

desc('Build assets');
task('gulp', function () {
    run('cd {{release_path}} && gulp');
})->onHosts(
    'test-frontend',
    'prod-frontend');

desc('Generate application key');
task('artisan:key:generate', function () {
	$output = run('cd {{release_path}} && {{bin/php}} artisan key:generate');
	writeln('<info>' . $output . '</info>');
})->onHosts(
    'test-frontend',
    'prod-frontend');

desc('Creating symlink to uploaded folder at backend server');
task('symlink:uploaded', function () {
    // Will use simple≤ two steps switch.
    run("cd {{release_path}} && {{bin/symlink}} {{uploaded_path}} public/uploaded"); // Atomic override symlink.
})->onHosts(
    'test-frontend',
    'prod-frontend');

//Filter external recipes
task('artisan:migrate')->onHosts(
    'test-frontend',
    'prod-frontend');
task('artisan:storage:link')->onHosts(
    'test-frontend',
    'prod-frontend',
    'test-backend',
    'prod-backend');
task('artisan:cache:clear')->onHosts(
    'test-frontend',
    'prod-frontend',
    'test-backend',
    'prod-backend');
task('artisan:config:cache')->onHosts(
    'test-frontend',
    'prod-frontend');
task('artisan:optimize')->onHosts(
    'test-frontend',
    'prod-frontend');
task('deploy:vendors')->onHosts(
    'test-frontend',
    'prod-frontend',
    'test-backend',
    'prod-backend');
task('deploy:shared')->onHosts(
    'test-frontend',
    'prod-frontend',
    'test-backend',
    'prod-backend');
task('deploy:writable')->onHosts(
    'test-frontend',
    'prod-frontend',
    'test-backend',
    'prod-backend');
task('deploy:copy_dirs')->onHosts(
    'test-frontend',
    'prod-frontend',
    'test-backend',
    'prod-backend');
task('deploy:clear_paths')->onHosts(
    'prod-frontend',
    'prod-backend');
task('rsync')->onHosts(
    'test-photobank-client',
    'test-backend-client');
