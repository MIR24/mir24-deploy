<?php

namespace Deployer;

set('db_name_releasing', function () {
    return 'mir24_dep_' . get('release_name');
});

set('db_name_previous', function () {
    $releaseList = get('releases_list');
    if(count($releaseList) > 1) $prevReleaseName = $releaseList[count($releaseList)-2];
    else $prevReleaseName = null;
    return $prevReleaseName ? ('mir24_dep_' . $prevReleaseName) : '';
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
    if (get('db_name_previous')) {
        writeln('<info>Trying to inflate database {{db_name_releasing}} with release data from {{db_name_previous}}</info>');
        run('mysqldump --single-transaction --insert-ignore -h{{db_app_host}} -u{{db_dep_user}} -p{{db_dep_pass}} {{db_name_previous}}' .
            ' | mysql  -u{{db_dep_user}} -p{{db_dep_pass}} -h{{db_app_host}} {{db_name_releasing}}');
    } elseif (get('db_source_name')) {
        writeln('<info>Trying to inflate database {{db_name_releasing}} with initial data from {{db_source_name}}</info>');
        run('mysqldump --single-transaction --insert-ignore -h{{db_source_host}} -u{{db_source_user}} -p{{db_source_pass}} {{db_source_name}}' .
            ' | mysql  -u{{db_dep_user}} -p{{db_dep_pass}} -h{{db_app_host}} {{db_name_releasing}}');
    } else {
        writeln('<error>No previous release found, can`t inflate database, stop.</error>');
        die;
    }
});
