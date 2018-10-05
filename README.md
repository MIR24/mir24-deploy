`php-imap` `php-curl` `php-gmp` must be installed

Run to deploy whole test application:
```
dep deploy test
```
Or to deploy particular components:
```
dep deploy test --hosts=test-frontend
```
```
dep deploy test --hosts=test-backend
```
