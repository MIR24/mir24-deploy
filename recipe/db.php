<?php

namespace Deployer;

set('db_name_releasing', function () {
    return 'mir24_dep_' . get('release_name');
});

set('db_name_previous', function () {
    $releaseList = get('releases_list');
    $prevReleaseName = array_shift($releaseList);
    return $prevReleaseName ? ('mir24_dep_' . $prevReleaseName) : '';
});

//TODO configure database as subrepo
desc('Cloning database repository');
task('db:clone', function () {
    run('cd {{release_path}} && git clone git@github.com:MIR24/database.git');
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
    run('cd {{deploy_path}} && mysql -h{{dbhost}} -u{{dbuser}} -p{{dbpass}} {{dbname}} < {{dump_file}}');
});

desc('Create new database to proceed release');
task('db:create', function () {
    writeln('<info>Trying to create database {{db_name_releasing}}</info>');
    run('mysql -h{{dbhost}} -u{{db_dep_user}} -p{{db_dep_pass}} -e "CREATE DATABASE {{db_name_releasing}}"');
});

desc('Inflate database with data from current released version');
task('db:pipe', function () {
    if (get('db_name_previous')) {
        writeln('<info>Trying to inflate database {{db_name_releasing}} with release data from {{db_name_previous}}</info>');
        run('mysqldump --single-transaction --insert-ignore -u{{db_dep_user}} -p{{db_dep_pass}} {{db_name_previous}}' .
            ' | mysql  -u{{db_dep_user}} -p{{db_dep_pass}} -h{{dbhost}} {{db_name_releasing}}');
    } else {
        writeln('<error>No previous release found, can`t inflate database, stop.</error>');
        die;
    }
});

desc('Infect app configuration with DB credentials');
task('config:configure:DB', function () {
    run("sed -i -E 's/DB_HOST=.*/DB_HOST={{dbhost}}/g' {{release_path}}/.env");
    run("sed -i -E 's/DB_DATABASE=.*/DB_DATABASE={{db_name_releasing}}/g' {{release_path}}/.env");
    run("sed -i -E 's/DB_USERNAME=.*/DB_USERNAME={{dbuser}}/g' {{release_path}}/.env");
    run("sed -i -E 's/DB_PASSWORD=.*/DB_PASSWORD={{dbpass}}/g' {{release_path}}/.env");
});
