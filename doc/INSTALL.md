## INSTALLATION ##

### 1. Composer

Install the dependency via composer.

```bash
composer require primedigital/data-transfer-bundle
```

### 2. Configuration

Import configuration into main config (app/config/config.yml). Add the following line

```yaml
imports:
    - {resource: @DataTransferBundle/Resources/config/parameters.yml}
```
into your config.yml

### 3. Register Bundle

Add the bundle in app/AppKernel.php

```php
$bundles[] = new Prime\DataTransferBundle\DataTransferBundle();
```

### 4. Configuration

* Adapt configuration (parameters.yml + parameters.yml.dist) to your project's needs (Server, Path, siteaccess, ...)
* make sure you or your docker-container have properly setup ssh key and that is on 'authorized_keys' on the server

## Configuration ##

See [config](../lib/Resources/config/parameters.yml) for details
