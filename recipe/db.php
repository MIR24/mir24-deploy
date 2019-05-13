<?php

namespace Deployer;

use Symfony\Component\Dotenv\Dotenv;

function inflateDb() {
    $dbSourceMode = get('db_source_mode', 'current');
    switch ($dbSourceMode) {
        case 'current':
            $condition = get('db_name_previous');
            $message = 'Trying to inflate database {{db_name_releasing}} with release data from {{db_name_previous}}, please wait..';
            $cmd = 'mysqldump --single-transaction --insert-ignore -h{{db_app_host}} -u{{db_dep_user}} -p{{db_dep_pass}} {{db_name_previous}}' .
                ' | mysql  -u{{db_dep_user}} -p{{db_dep_pass}} -h{{db_app_host}} {{db_name_releasing}}';
            break;
        case 'source':
            $condition = get('db_source_name');
            $message = 'Trying to inflate database {{db_name_releasing}} with initial data from {{db_source_name}}, please wait..';
            $cmd = 'mysqldump --single-transaction --insert-ignore -h{{db_source_host}} -u{{db_source_user}} -p{{db_source_pass}} {{db_source_name}}' .
                ' | mysql  -u{{db_dep_user}} -p{{db_dep_pass}} -h{{db_app_host}} {{db_name_releasing}}';
            break;
        case 'file':
            $condition = get('dump_file') && test('[ -r {{dump_file}} ]');
            $message = 'Trying to inflate database {{db_name_releasing}} with initial data from {{dump_file}}, please wait..';
            $cmd = 'cd {{deploy_path}} && mysql -u{{db_dep_user}} -p{{db_dep_pass}} -h{{db_app_host}} {{db_name_releasing}} < {{dump_file}}';
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

set('db_name_releasing', function () {
    return 'mir24_dep_' . get('release_name');
});

set('db_name_previous', function () {
    try{
        $currentReleasePath = get('current_path');
    } catch(\Deployer\Exception\RuntimeException $e){
        $currentReleasePath = null;
    }

    if($currentReleasePath){
        $dotenv = new Dotenv();
        $releasedDBName = $dotenv->parse(run('cat ' . $currentReleasePath . '/.env'))["DB_DATABASE"];
        writeln('<info>Released DB found: '.$releasedDBName.'</info>');

        return $releasedDBName;
    } else {
        writeln('<comment>Any released DB not found</comment>');

        return '';
    }
});

//TODO configure database as subrepo
desc('Cloning database repository');
task('db:clone', function () {
    $defaultBranch = get('branch');
    $branch = get('db_clone_branch', $defaultBranch);
    $at = '';
    if (!empty($branch)) {
        $at = "-b $branch";
    }
    run("cd {{release_path}} && {{bin/git}} clone $at git@github.com:MIR24/database.git");
});

//Executing initial SQL dump
desc('Executing initial dump may took a minute');
task('db:init', function () {
    writeln('<info>Check if {{dump_file}} exists</info>');

    if (test('[ ! -r {{dump_file}} ]')) {
        writeln('<comment>No dump file found, proceed</comment>');

        return;
    }
    writeln('<info>SQL dump execution, please wait..</info>');
    run('cd {{deploy_path}} && mysql -h{{db_app_host}} -u{{db_app_user}} -p{{db_app_pass}} {{db_app_name}} < {{dump_file}}');
});

desc('Create new database to proceed release');
task('db:create', function () {
    writeln('<info>Trying to create database {{db_name_releasing}}</info>');
    run('mysql -h{{db_app_host}} -u{{db_dep_user}} -p{{db_dep_pass}} -e "CREATE DATABASE {{db_name_releasing}}"');
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
        inflateDb();
    }
    else {
        writeln("<comment>Can't define target DB, no release built found.</comment>");
    }
})->onHosts('prod-frontend');
