<?php

namespace Deployer;

use Symfony\Component\Dotenv\Dotenv;

set('db_source_mode', 'current');

function inflateDb($type='') {
    $dbSourceMode = get('db_source_mode');
    switch ($dbSourceMode) {
        case 'current':
            $excludeMigrationTable = $type==='repipe' ? ' --ignore-table={{db_name_previous}}.migrations ' : '';
            $condition = get('db_name_previous');
            $message = 'Trying to inflate database {{db_app_name}} with release data from {{db_name_previous}}, please wait..';
            $cmd = 'mysqldump --single-transaction --insert-ignore' . $excludeMigrationTable .
                '-h{{db_app_host}} -u{{db_dep_user}} -p{{db_dep_pass}} {{db_name_previous}}' .
                ' | mysql  -u{{db_dep_user}} -p{{db_dep_pass}} -h{{db_app_host}} {{db_app_name}}';
            break;
        case 'source':
            $condition = get('db_source_name');
            $message = 'Trying to inflate database {{db_app_name}} with initial data from {{db_source_name}}, please wait..';
            $cmd = 'mysqldump --single-transaction --insert-ignore -h{{db_source_host}} -u{{db_source_user}} -p{{db_source_pass}} {{db_source_name}}' .
                ' | mysql  -u{{db_dep_user}} -p{{db_dep_pass}} -h{{db_app_host}} {{db_app_name}}';
            break;
        case 'file':
            $condition = get('dump_file') && test('[ -r {{dump_file}} ]');
            $message = 'Trying to inflate database {{db_app_name}} with initial data from {{dump_file}}, please wait..';
            $cmd = 'cd {{deploy_path}} && mysql -u{{db_dep_user}} -p{{db_dep_pass}} -h{{db_app_host}} {{db_app_name}} < {{dump_file}}';
            break;
        default:
            writeln('<info>No source DB will be used, proceed.</info>');
            return;
    }

    if ($condition) {
        writeln("<info>$message</info>");
        run($cmd);
    } else {
        writeln('<warning>No source DB found, can`t inflate database, proceed.</warning>');
    }
}

set('db_app_name', function () {
    return 'mir24_dep_' . get('release_name');
});

set('db_name_previous', function () {
    $releasedDBName = '';
    try{
        $currentReleasePath = get('current_path');
        $dotenv = new Dotenv();
        $envConfig = $dotenv->parse(run("cat $currentReleasePath/.env"));
        $releasedDBName = $envConfig['DB_DATABASE'] ?? '';
        writeln('<info>Released DB found: ' . $releasedDBName . '</info>');
    } catch(\Deployer\Exception\RuntimeException $e){
        writeln('<comment>No released DB found</comment>');
    }

    return $releasedDBName;
});

desc('Create new database to proceed release');
task('db:create', function () {
    if (get('db_source_mode') === 'none') {
        writeln('<info>db_source_name set to none. No DB will be created</info>');
        return;
    }
    writeln('<info>Trying to create database {{db_app_name}}</info>');
    run('mysql -h{{db_app_host}} -u{{db_dep_user}} -p{{db_dep_pass}} -e "CREATE DATABASE {{db_app_name}}"');
});

desc('Inflate database with data from current released version');
task('db:pipe', function () {
    inflateDb();
});

desc('Inflate database of releasing built with data from source configured. Run standalone.');
task('db:repipe', function () {
    $releaseExists = test('[ -h {{deploy_path}}/release ]');
    if($releaseExists){
        $releaseInProgressName = get('releases_list')[0];
        set('release_name', $releaseInProgressName);
        inflateDb('repipe');
    }
    else {
        writeln("<comment>Can't define target DB, no release built found.</comment>");
    }
});
