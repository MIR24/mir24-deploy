## Requirements
`Nodejs`, `npm`, Linux `acl`, `php-imap` `php-curl`, `php-gmp`, [deployer](https://deployer.org/docs/installation) must be installed.

`php*-memcached` must be installed.<br>
`Memcached` and `mysql-server` must be installed and served.

## Start
Assume you're you going to deploy test bench at `/home/www/dev7.mir24.tv`. So `/home/www/dev7.mir24.tv` is a `deploy_path`.

Create database via `mysql` console command:
```mysql
mysql> CREATE DATABASE mir24_7 CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
```
Clone deploy project:
```
$ git clone git@github.com:MIR24/mir24-deploy.git /home/www/dev7.mir24.tv/mir24-deploy
```
Install dependencies with `$ composer install`;

Configure `deploy_path` and DB connection at `hosts.yml`.<br>

Download initial dump file (you can get example dump file [here](https://drive.google.com/open?id=1L2vvkscPZYIWjAU8QA_TtN3wbay4Yi3A)).<br>
Copy mysql dump into the root folder of this deploy project:
```
$ cp /tmp/mir24_7.sql /home/www/dev7.mir24.tv/
```
Command executing SQL runs at `frontend` host, so specify dump filename at `hosts.yml` at `test-frontend` section:
```yml
test-frontend:
    dump_file: mir24_7.sql
```
If deploy procedure fails to locate dump file it just proceeds with comment message.

Configure path to .env file `env_example_file`.<br>
Edit `.env` file if needed (e.g. to configure DB connection for application).<br>
It will be propageted to the shared folder within `config:clone` task.

Initial project structure should look like this:<br>
![Deploy procedure](https://raw.githubusercontent.com/MIR24/frontend-server-deploy/master/images/deploy_structure.png "Deploy procedure")

Now run:
```
$ cd /home/www/dev7.mir24.tv
$ dep deploy test
```

Complete deploy procedure should start.
After it finished released version located in `current` folder. 
E.g. `/home/www/dev7.mir24.tv/frontend-server/current`.

Configure web-server document roots at `/home/www/dev7.mir24.tv/frontend-server/current/public` and `/home/www/dev7.mir24.tv/backend-server/current/public`.

## Routine

`$ dep deploy test` builds whole application.

Default branch deployed is `master` except photobank-client.<br>
Photobank-client being deployed at `external-client-dev` branch by default.

Use `test` for stage, `--branch` option to deploy branch, and `--hosts` for particular component.

For example if you're going to build application for test run after `frontend-server` has been patched at `MIRSCR-42-view-fix` branch, you should execute: 
```
$ dep deploy test --hosts=backend-server,backend-client,photobank-client
```
First command builds three components at default branch.
Then run to build `frontend-server` at `MIRSCR-42-view-fix` branch.
```
$ dep deploy test --branch=MIRSCR-42-view-fix --hosts=frontend-server
```
Done.

Or
```
$ dep deploy test
$ dep deploy test --branch=MIRSCR-42-view-fix --hosts=frontend-server
```
This one takes more time, because frontend-server deployed twice, at default branch within first command than being rebuilt at fix branch.

## Tips
You have to execute `$ dep rsync test` after deploy if `backend-server` was built solo.<br>
`rsync` commands infects `backend-server` with `backend-client` and `photobank-components`.
________
Use `dep` verbose option to examine deploy procedure, e.g. `-v` shows timing for each task execution.
Three verbose levels available:
```
$ dep deploy test -v
$ dep deploy test -vv
$ dep deploy test -vvv
```
________
Use `--parallel` option to deploy hosts in parallel. 
By default Deployer runs builts in series.
________

`git clone` command may lag sometimes at `update_code` task due to still unknown reasons.
________

Run `dep artisan:key:generate test` if `APP_KEY` in `shared/.env` still empty even after deploy complete.

## TODO
Deployer default procedure clones repo each time into the new `release/*` folder. 
It's not neccesary for test bench.
May be better is to write deploy recipe with `git checkout` `git pull` to use it for test stage deploy.

DB connection being configured twice: for deploy at `hosts.yml` and for application at `.env`. Better is to inflate configuration files with DB connection credentials from unified source.
