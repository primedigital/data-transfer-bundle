## Usage ##

To transfer database+files from the remote server to your develop environment simply call.

```bash
php app/console data-transfer:fetch
```

NOTE: The bundle must be already deployed on the remote side in order to work.

To limit the transfer to database or files only, use

```bash
php app/console data-transfer:fetch --db-only
```

or 

```bash
php app/console data-transfer:fetch --files-only
```
