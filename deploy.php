<?php
namespace Deployer;

require 'recipe/common.php';

inventory('hosts.yml');

// Project name
set('application', 'my_project');

// Project repository
set('repository', '');

// [Optional] Allocate tty for git clone. Default value is false.
set('git_tty', true); 

// Shared files/dirs between deploys 
set('shared_files', []);
set('shared_dirs', []);

// Writable dirs by web server 
set('writable_dirs', []);

// Tasks

desc('Deploy frontend-server test bench');
task('deploy-test-frontend', [
    'deploy:info',
    'deploy:prepare',
    'deploy:lock',
    'db:init',
])->onHosts('test-frontend');

desc('Deploy backend-server test bench');
task('deploy-test-backend', [
    'deploy:info',
])->onHosts('test-backend');

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
});
